#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

extern "C" {
#include "php.h"
}
#include "php_ccegtbprobe.h"

#include <unistd.h>
#include <string>
#include <cstring>
#include <fcntl.h>
#include <sys/uio.h>
#include <pthread.h>
#include <sys/types.h>
#include <sys/stat.h>

#include <vector>
#include <memory>
#include <mutex>
#include <condition_variable>
#include <unordered_map>
#include <utility>

#include "xiangqi.h"
#include "piece_set.h"
#include "LZMA/LzmaLib.h"

static constexpr U64 EGTB_LOW20_MASK = 0xFFFFFull;
static constexpr U64 EGTB_OFFREL_SHIFT = 20ULL;

static inline bool pread_exact(int fd, void* buf, size_t n, off64_t off) {
    ssize_t r = pread64(fd, buf, n, off);
    return r == (ssize_t)n;
}

#define EGTB_DTC_DIR_COUNT 1
static char egtb_dtc_dir[EGTB_DTC_DIR_COUNT][256] = { "/data/EGTB_DTC/" };

#define EGTB_DTM_DIR_COUNT 1
static char egtb_dtm_dir[EGTB_DTM_DIR_COUNT][256] = { "/data/EGTB_DTM/" };

static void calc_egtb_index(Position& pos, char* names, S8& mirror, U64& offset);
static bool do_probe_egtb(const char* names, S8 side, bool isdtm, U64 offset, U16& score, U64& flags, void* session_ptr);
static bool probe_egtb(Position& pos, bool isdtm, U16& score, U64& flags, void* session_ptr);

#include "ccegtbprobe_arginfo.h"

zend_function_entry ccegtbprobe_functions[] = {
    PHP_FE(ccegtbprobe, arginfo_ccegtbprobe)
    {NULL, NULL, NULL}
};

zend_module_entry ccegtbprobe_module_entry = {
#if ZEND_MODULE_API_NO >= 20010901
    STANDARD_MODULE_HEADER,
#endif
    "ccegtbprobe",
    ccegtbprobe_functions,
    PHP_MINIT(ccegtbprobe),
    NULL,
    NULL,
    NULL,
    NULL,
#if ZEND_MODULE_API_NO >= 20010901
    "0.1",
#endif
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_CCEGTBPROBE
extern "C" {
ZEND_GET_MODULE(ccegtbprobe)
}
#endif

static inline int uncompress_lzma(char* dest, size_t* destLen, const char* src, size_t* srcLen, const char* props) {
    return LzmaUncompress(reinterpret_cast<unsigned char*>(dest), destLen,
                          reinterpret_cast<const unsigned char*>(src), srcLen,
                          reinterpret_cast<const unsigned char*>(props), 5);
}


static inline int decode_root_dtm(U16 raw) {
    const int flag = (raw >> 11) & 1;
    const int step = raw & 0x7ff;
    if (step == 0) return 0;
    return (flag != 0) ? (30000 - step) : (step - 30000);
}
static inline int decode_root_dtc(U16 raw, U64 flags, int& order) {
    order = (raw >> 10) & 0x3f;
    int step;
    if (flags) { if (raw & 512) order += 64; step = raw & 0x1ff; }
    else { step = raw & 0x3ff; }
    if (step == 0) return 0;
    const bool even = ((step & 1) == 0);
    if (order > 0) return even ? (20000 - step) : (step - 20000);
    return even ? (30000 - step) : (step - 30000);
}
static inline void decode_child_dtm(U16 raw, int& score, int& step) {
    const int flag = (raw >> 11) & 1;
    step = raw & 0x7ff;
    if (step == 0) { score = 0; return; }
    score = (flag != 0) ? (step - 30000) : (30000 - step);
}
static inline void decode_child_dtc(U16 raw, U64 flags, int& score, int& order, int& step) {
    order = (raw >> 10) & 0x3f;
    if (flags) { if (raw & 512) order += 64; step = raw & 0x1ff; }
    else { step = raw & 0x3ff; }
    if (step == 0) { score = 0; return; }
    const bool even = ((step & 1) == 0);
    if (order > 0) score = even ? (step - 20000) : (20000 - step);
    else score = even ? (step - 30000) : (30000 - step);
}

struct FileCtx {
    int  fd;
    U8   table_num;

    bool is_singular[2];
    int  single_val[2];
    U32  tail_size[2];
    U32  block_size[2];
    U32  block_cnt[2];
    U64  data_size[2];
    U64  data_start[2];
    U64  hdr_table_off[2];

    ~FileCtx() { if (fd != -1) close(fd); }
};

struct BlockKey {
    const FileCtx* file;
    S8 side;
    U64 blk_index;
    bool operator==(const BlockKey& o) const {
        return side == o.side && blk_index == o.blk_index && file == o.file;
    }
};
struct BlockKeyHash {
    std::size_t operator()(const BlockKey& k) const noexcept {
        auto p = reinterpret_cast<uintptr_t>(k.file);
        std::size_t h1 = std::hash<uintptr_t>{}(p);
        std::size_t h2 = std::hash<uint64_t>{}(static_cast<uint64_t>(k.blk_index) ^ (static_cast<uint64_t>(k.side) << 56));
        return h1 ^ (h2 + 0x9e3779b97f4a7c15ULL + (h1<<6) + (h1>>2));
    }
};

struct BlockSlot {
    std::mutex m;
    std::condition_variable cv;
    bool probing;
    bool done;
    bool error;
    std::vector<char> data;
    size_t decoded_size;
};

class ProbeSession {
public:
    ProbeSession() = default;
    ~ProbeSession() = default;

    std::shared_ptr<FileCtx> get_file_ctx(const char* file_name) {
        std::lock_guard<std::mutex> lk(files_mu_);
        auto it = files_.find(file_name);
        if (it != files_.end()) return it->second;

        auto ctx = std::make_shared<FileCtx>();
        ctx->fd = open(file_name, O_RDONLY);
        if (ctx->fd == -1) return nullptr;

        U32 ctrl[2];
        if (!pread_exact(ctx->fd, ctrl, sizeof(ctrl), 0)) {
            return nullptr;
        }
        ctx->table_num = ctrl[1] & 3;

        U64 off = sizeof(ctrl);
        for (int i = 0; i < ctx->table_num; ++i) {
            U8 head[2];
            if (!pread_exact(ctx->fd, head, sizeof(head), off)) {
                return nullptr;
            }
            off += sizeof(head);
            ctx->is_singular[i] = (head[0] & 0x80) != 0;
            ctx->single_val[i]  = head[1];

            if (!ctx->is_singular[i]) {
                struct __attribute__((packed)) {
                    U32 tail_sz;
                    U32 blk_sz;
                    U32 blk_cnt;
                    U64 data_sz;
                } hdr;
                if (!pread_exact(ctx->fd, &hdr, sizeof(hdr), off)) {
                    return nullptr;
                }
                ctx->tail_size[i]  = hdr.tail_sz;
                ctx->block_size[i] = hdr.blk_sz;
                ctx->block_cnt[i]  = hdr.blk_cnt;
                ctx->data_size[i]  = hdr.data_sz;
                off += sizeof(hdr);
            }
        }
        for (int i = 0; i < ctx->table_num; ++i) {
            if (!ctx->is_singular[i]) {
                ctx->hdr_table_off[i] = off;
                off += ctx->block_cnt[i] * sizeof(U64);
            }
        }
        for (int i = 0; i < ctx->table_num; ++i) {
            if (!ctx->is_singular[i]) {
                off = (off + 0x3F) & ~0x3F;
                ctx->data_start[i] = off;
                off += ctx->data_size[i];
            }
        }
        files_.emplace(file_name, ctx);
        return ctx;
    }

    std::shared_ptr<BlockSlot> get_block(const std::shared_ptr<FileCtx>& f,
                                         S8 side, U64 blk_index)
    {
        auto set_error_and_notify = [](std::shared_ptr<BlockSlot>& s) {
            std::lock_guard<std::mutex> lk(s->m);
            s->error = true;
            s->probing = false;
            s->cv.notify_all();
        };

        BlockKey key{f.get(), side, blk_index};

        std::shared_ptr<BlockSlot> slot;
        {
            std::lock_guard<std::mutex> lk(blocks_mu_);
            auto it = blocks_.find(key);
            if (it != blocks_.end()) {
                slot = it->second;
            } else {
                slot = std::make_shared<BlockSlot>();
                blocks_.emplace(std::move(key), slot);
            }
        }

        {
            std::unique_lock<std::mutex> lk(slot->m);
            if (slot->done || slot->error) return slot;
        }

        bool probe = false;
        {
            std::unique_lock<std::mutex> lk(slot->m);
            if (!slot->done && !slot->error && !slot->probing) {
                slot->probing = true;
                probe = true;
            }
        }

        if (probe) {
            U64 block_hdr;
            off64_t hdr_off = f->hdr_table_off[side] + static_cast<off64_t>(blk_index * sizeof(U64));
            if (!pread_exact(f->fd, &block_hdr, sizeof(block_hdr), hdr_off)) {
                set_error_and_notify(slot);
                return slot;
            }

            const size_t comp_size = block_hdr & EGTB_LOW20_MASK;
            const U64 off_rel = block_hdr >> EGTB_OFFREL_SHIFT;
            if (comp_size < 5) {
                set_error_and_notify(slot);
                return slot;
            }

            const off64_t abs_off = static_cast<off64_t>(f->data_start[side] + off_rel);
            std::vector<char> src(comp_size);
            if (!pread_exact(f->fd, src.data(), comp_size, abs_off)) {
                set_error_and_notify(slot);
                return slot;
            }

            const bool last_block = (blk_index == (U64)(f->block_cnt[side] - 1));
            size_t decode_size = last_block ? f->tail_size[side] : f->block_size[side];
            std::vector<char> dst(decode_size);

            size_t srclen  = comp_size - 5;
            const char* props = src.data() + (comp_size - 5);
            int rc = uncompress_lzma(dst.data(), &decode_size, src.data(), &srclen, props);
            {
                std::lock_guard<std::mutex> lk(slot->m);
                if (rc != SZ_OK) {
                    slot->error = true;
                } else {
                    slot->data.swap(dst);
                    slot->decoded_size = decode_size;
                    slot->done = true;
                }
                slot->probing = false;
                slot->cv.notify_all();
            }
        } else {
            std::unique_lock<std::mutex> lk(slot->m);
            slot->cv.wait(lk, [&]{ return slot->done || slot->error; });
        }
        return slot;
    }

private:
    std::mutex files_mu_;
    std::unordered_map<std::string, std::shared_ptr<FileCtx>> files_;

    std::mutex blocks_mu_;
    std::unordered_map<BlockKey, std::shared_ptr<BlockSlot>, BlockKeyHash> blocks_;
};

PHP_MINIT_FUNCTION(ccegtbprobe)
{
    piece_index_init();
    if (group_init())
        return SUCCESS;
    return FAILURE;
}

struct ProbeTask {
    pthread_t tid;
    bool isdtm;
    bool found;
    char names[33];
    S8 side;
    U64 offset;
    int move;
    bool cap;
    int check;
    U16 score;
    U64 flags;
    ProbeSession* session;
};

static void* probe_thread(void* arg) {
    auto* t = static_cast<ProbeTask*>(arg);
    if (!t->found) {
        t->found = do_probe_egtb(t->names, t->side, t->isdtm, t->offset, t->score, t->flags, t->session);
    }
    return nullptr;
}

PHP_FUNCTION(ccegtbprobe)
{
    char* fenstr;
    size_t fenstr_len;
    zend_bool isdtm = 0;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s|b", &fenstr, &fenstr_len, &isdtm) == FAILURE) {
        RETURN_NULL();
    }

    Position pos;
    if (!pos.from_fen(fenstr) || !pos.is_legal() ||
        pos.number[WhitePawn] >= 4 || pos.number[BlackPawn] >= 4) {
        RETURN_NULL();
    }

    ProbeSession session;

    U64 flags;
    U16 root_raw;
    const bool found = probe_egtb(pos, isdtm, root_raw, flags, &session);
    if (!found) {
        RETURN_NULL();
    }

    int root_score, root_order;
    if (isdtm) root_score = decode_root_dtm(root_raw);
    else       root_score = decode_root_dtc(root_raw, flags, root_order);

    Move_List list;
    pos.gen_legals(list);

    const int task_count = list.size();

    std::vector<ProbeTask> tasks(task_count);

    for (int i = 0; i < task_count; ++i) {
        auto& t = tasks[i];
        t.isdtm = isdtm;
        t.session = &session;

        pos.move_do(list.move(i));

        if ((pos.number[WhitePawn] + pos.number[BlackPawn] +
                               pos.number[WhiteRook] + pos.number[BlackRook] +
                               pos.number[WhiteKnight] + pos.number[BlackKnight] +
                               pos.number[WhiteCannon] + pos.number[BlackCannon]) == 0) {
            t.score = 0;
            t.flags = 0;
            t.found = true;
        } else {
            S8 mirror;
            calc_egtb_index(pos, t.names, mirror, t.offset);
            t.side = mirror ? color_opp(pos.turn) : pos.turn;
            t.found = false;
        }
        t.move  = list.move(i);
        t.cap   = (pos.stack[0].cap != 0);
        t.check = pos.stack[0].check;

        pos.move_undo();

        if (root_score == 0) {
            t.check += pos.is_chase(list.move(i));
        }
        pthread_create(&t.tid, nullptr, probe_thread, &t);
    }

    bool all_ok = true;
    for (int i = 0; i < task_count; ++i) {
        auto& t = tasks[i];
        pthread_join(t.tid, nullptr);
        if (!t.found) {
            all_ok = false;
        }
    }

    if (!all_ok) {
        RETURN_NULL();
    }

    array_init(return_value);

    for (int i = 0; i < task_count; ++i) {
        const auto& t = tasks[i];
        int score, order, step;

        if (isdtm) {
            decode_child_dtm(t.score, score, step);
        } else {
            decode_child_dtc(t.score, t.flags, score, order, step);
        }

        char movestr[5];
        move_to_string(t.move, movestr);

        zval moveinfo;
        array_init(&moveinfo);
        add_assoc_long(&moveinfo, "score", score);
        if (!isdtm) add_assoc_long(&moveinfo, "order", order);
        add_assoc_bool(&moveinfo, "cap", t.cap);
        add_assoc_long(&moveinfo, "check", t.check);
        add_assoc_long(&moveinfo, "step", step);

        add_assoc_zval(return_value, movestr, &moveinfo);
    }

    add_assoc_long(return_value, "score", root_score);
    if (!isdtm) add_assoc_long(return_value, "order", root_order);
}

static bool read_egtb_value_cached(const char* file_name, S8 side, U64 offset, U16& score, U64& flags, ProbeSession* session)
{
    auto fctx = session->get_file_ctx(file_name);
    if (!fctx) return false;

    if (side == Black && fctx->table_num != 2) {
        return false;
    }

    if (fctx->is_singular[side]) {
        score = fctx->single_val[side];
        flags = 0;
        return true;
    }

    flags = fctx->single_val[side];


    const U64 entries_per_block = fctx->block_size[side] >> 1;
    const U64 blk_index = offset / entries_per_block;
    const U64 blk_offset = offset - (entries_per_block * blk_index);

    std::shared_ptr<BlockSlot> slot = session->get_block(fctx, side, blk_index);
    if (!slot || slot->error || !slot->done) return false;
    std::memcpy(&score, slot->data.data() + (blk_offset << 1), sizeof(U16));
    return true;
}

static bool do_probe_egtb(const char* names, S8 side, bool isdtm, U64 offset, U16& score, U64& flags, void* session_ptr)
{
    ProbeSession* session = static_cast<ProbeSession*>(session_ptr);
    char path[256];
    if (isdtm) {
        for (int i = 0; i < EGTB_DTM_DIR_COUNT; ++i) {
            std::snprintf(path, sizeof(path), "%s%s.lzdtm", egtb_dtm_dir[i], names);
            if (read_egtb_value_cached(path, side, offset, score, flags, session)) return true;
        }
    } else {
        for (int i = 0; i < EGTB_DTC_DIR_COUNT; ++i) {
            std::snprintf(path, sizeof(path), "%s%s.lzdtc", egtb_dtc_dir[i], names);
            if (read_egtb_value_cached(path, side, offset, score, flags, session)) return true;
        }
    }
    return false;
}

static bool probe_egtb(Position& pos, bool isdtm, U16& score, U64& flags, void* session_ptr)
{
    if (pos.number[WhitePawn] + pos.number[BlackPawn] +
        pos.number[WhiteRook] + pos.number[BlackRook] +
        pos.number[WhiteKnight] + pos.number[BlackKnight] +
        pos.number[WhiteCannon] + pos.number[BlackCannon] == 0) {
        score = 0;
        flags = 0;
        return true;
    }

    char names[33];
    S8 mirror;
    U64 offset;
    calc_egtb_index(pos, names, mirror, offset);
    const S8 side = mirror ? color_opp(pos.turn) : pos.turn;
    return do_probe_egtb(names, side, isdtm, offset, score, flags, session_ptr);
}

static void calc_egtb_index(Position& pos, char* names, S8& mirror, U64& offset)
{
    S8 piece;
    S8 piece_list[16][8] = {0};

    int scores[2] = {0};
    for (int i = 0; i < 2; ++i)
    {
        for (int j = King; j <= Pawn; ++j)
        {
            S8 sq;
            int cnt = 0;
            piece = piece_make(i, j);
            BITBOARD pieces_bb = pos.pieces[piece];
            while (pieces_bb)
            {
                sq = pieces_bb.pop_1st_sq();
                piece_list[piece][cnt++] = sq;
                scores[i] += Piece_Order_Value[piece];
            }
        }
    }
    mirror = (scores[1] > scores[0] || (scores[1] == scores[0] && pos.turn == Black)) ? 1 : 0;

    S8 set_id[10] = {0};
    S8 square_list[10][16] = {0};
    S8 ix, id;
    size_t nam_ix = 0;

    if (mirror)
    {
        for (int i = 0; i < 2; ++i)
        {
            ix = 0;
            S8 color = color_opp(i);
            id = WSet_Defend + 5*i;
            set_id[id] = defend_set(pos.number[piece_make(color, Advisor)], pos.number[piece_make(color, Bishop)], i);
            piece = piece_make(color, King);
            square_list[id][ix++] = sq_rank_mirror(piece_list[piece][0]);
            names[nam_ix++] = piece_to_char(King);

            piece = piece_make(color, Advisor);
            for (int j = 0; j < pos.number[piece]; ++j)
            {
                square_list[id][ix++] = sq_rank_mirror(piece_list[piece][j]);
                names[nam_ix++] = piece_to_char(Advisor);
            }
            piece = piece_make(color, Bishop);
            for (int j = 0; j < pos.number[piece]; ++j)
            {
                square_list[id][ix++] = sq_rank_mirror(piece_list[piece][j]);
                names[nam_ix++] = piece_to_char(Bishop);
            }
            piece = piece_make(color, Rook);
            if (pos.number[piece])
            {
                ix = 0;
                id = WSet_Rook + 5*i;
                set_id[id] = Rook_Set_ID[i][pos.number[piece]];
                for (int j = 0; j < pos.number[piece]; ++j)
                {
                    square_list[id][ix++] = sq_rank_mirror(piece_list[piece][j]);
                    names[nam_ix++] = piece_to_char(Rook);
                }
            }
            piece++;
            if (pos.number[piece])
            {
                ix = 0;
                id = WSet_Knight + 5*i;
                set_id[id] = Knight_Set_ID[i][pos.number[piece]];
                for (int j = 0; j < pos.number[piece]; ++j)
                {
                    square_list[id][ix++] = sq_rank_mirror(piece_list[piece][j]);
                    names[nam_ix++] = piece_to_char(Knight);
                }
            }
            piece++;
            if (pos.number[piece])
            {
                ix = 0;
                id = WSet_Cannon + 5*i;
                set_id[id] = Cannon_Set_ID[i][pos.number[piece]];
                for (int j = 0; j < pos.number[piece]; ++j)
                {
                    square_list[id][ix++] = sq_rank_mirror(piece_list[piece][j]);
                    names[nam_ix++] = piece_to_char(Cannon);
                }
            }
            piece += 3;
            if (pos.number[piece])
            {
                ix = 0;
                id = WSet_Pawn + 5*i;
                set_id[id] = Pawn_Set_ID[i][pos.number[piece]];
                for (int j = 0; j < pos.number[piece]; ++j)
                {
                    square_list[id][ix++] = sq_rank_mirror(piece_list[piece][j]);
                    names[nam_ix++] = piece_to_char(Pawn);
                }
            }
        }
    }
    else
    {
        for (int i = 0; i < 2; ++i)
        {
            ix = 0;
            id = WSet_Defend + 5*i;
            set_id[id] = defend_set(pos.number[piece_make(i, Advisor)], pos.number[piece_make(i, Bishop)], i);
            piece = piece_make(i, King);
            square_list[id][ix++] = piece_list[piece][0];
            names[nam_ix++] = piece_to_char(King);

            piece = piece_make(i, Advisor);
            for (int j = 0; j < pos.number[piece]; ++j)
            {
                square_list[id][ix++] = piece_list[piece][j];
                names[nam_ix++] = piece_to_char(Advisor);
            }
            piece = piece_make(i, Bishop);
            for (int j = 0; j < pos.number[piece]; ++j)
            {
                square_list[id][ix++] = piece_list[piece][j];
                names[nam_ix++] = piece_to_char(Bishop);
            }
            piece = piece_make(i, Rook);
            if (pos.number[piece])
            {
                ix = 0;
                id = WSet_Rook + 5*i;
                set_id[id] = piece_set(piece, pos.number[piece]);
                for (int j = 0; j < pos.number[piece]; ++j)
                {
                    square_list[id][ix++] = piece_list[piece][j];
                    names[nam_ix++] = piece_to_char(Rook);
                }
            }
            piece++;
            if (pos.number[piece])
            {
                ix = 0;
                id = WSet_Knight + 5*i;
                set_id[id] = piece_set(piece, pos.number[piece]);
                for (int j = 0; j < pos.number[piece]; ++j)
                {
                    square_list[id][ix++] = piece_list[piece][j];
                    names[nam_ix++] = piece_to_char(Knight);
                }
            }
            piece++;
            if (pos.number[piece])
            {
                ix = 0;
                id = WSet_Cannon + 5*i;
                set_id[id] = piece_set(piece, pos.number[piece]);
                for (int j = 0; j < pos.number[piece]; ++j)
                {
                    square_list[id][ix++] = piece_list[piece][j];
                    names[nam_ix++] = piece_to_char(Cannon);
                }
            }
            piece += 3;
            if (pos.number[piece])
            {
                ix = 0;
                id = WSet_Pawn + 5*i;
                set_id[id] = piece_set(piece, pos.number[piece]);
                for (int j = 0; j < pos.number[piece]; ++j)
                {
                    square_list[id][ix++] = piece_list[piece][j];
                    names[nam_ix++] = piece_to_char(Pawn);
                }
            }
        }
    }
    names[nam_ix] = 0;

    int compress_id = 0;
    const Group_Info* info;
    float best_ratio = 100.0f;
    for (int i = 0; i < 10; ++i)
    {
        if (set_id[i] == 0) continue;
        info = get_set_info(set_id[i]);
        const float ratio = (float)info->compress_size / info->table_size;
        if (ratio < best_ratio) { best_ratio = ratio; compress_id = i; }
    }

    offset = 0ULL;
    info = get_set_info(set_id[compress_id]);
    U64 weight = 1;
    const U32 compress_ix = info->get_list_pos(square_list[compress_id]);

    if ((compress_ix & 0xffffU) < info->compress_size)
    {
        for (int i = 0; i < 10; ++i)
        {
            if (set_id[i] == 0) continue;
            info = get_set_info(set_id[i]);
            if (i == compress_id) {
                offset += (compress_ix & 0xffffU) * weight;
                weight *= info->compress_size;
            } else {
                offset += (info->get_list_pos(square_list[i]) & 0xffffU) * weight;
                weight *= info->table_size;
            }
        }
    }
    else
    {
        for (int i = 0; i < 10; ++i)
        {
            if (set_id[i] == 0) continue;
            info = get_set_info(set_id[i]);
            if (i == compress_id) {
                offset += ((compress_ix >> 16) & 0xffffU) * weight;
                weight *= info->compress_size;
            } else {
                const U32 v = info->get_list_pos(square_list[i]);
                offset += ((v >> 16) & 0xffffU) * weight;
                weight *= info->table_size;
            }
        }
    }
}
