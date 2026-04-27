#pragma once

#include <cstddef>
#include <cstdint>
#include <cstring>
#include <vector>

using Lzma_Prob = uint16_t;

constexpr unsigned  LZMA_NUM_BIT_MODEL_TOTAL_BITS = 11;
constexpr uint32_t  LZMA_BIT_MODEL_TOTAL          = 1u << LZMA_NUM_BIT_MODEL_TOTAL_BITS;
constexpr unsigned  LZMA_NUM_MOVE_BITS            = 5;
constexpr Lzma_Prob LZMA_PROB_INIT                = LZMA_BIT_MODEL_TOTAL / 2;
constexpr uint32_t  LZMA_TOP_VALUE                = 1u << 24;

constexpr unsigned LZMA_NUM_STATES         = 12;
constexpr unsigned LZMA_NUM_POS_BITS_MAX   = 4;
constexpr unsigned LZMA_NUM_LEN_TO_POS     = 4;
constexpr unsigned LZMA_NUM_ALIGN_BITS     = 4;
constexpr unsigned LZMA_START_POS_MODEL    = 4;
constexpr unsigned LZMA_END_POS_MODEL      = 14;
constexpr unsigned LZMA_NUM_FULL_DISTANCES = 1u << (LZMA_END_POS_MODEL >> 1);
constexpr unsigned LZMA_MATCH_MIN_LEN      = 2;
constexpr unsigned LZMA_LEN_LOW_BITS       = 3;
constexpr unsigned LZMA_LEN_MID_BITS       = 3;
constexpr unsigned LZMA_LEN_HIGH_BITS      = 8;
constexpr unsigned LZMA_LEN_LOW_SYMBOLS    = 1u << LZMA_LEN_LOW_BITS;
constexpr unsigned LZMA_LEN_MID_SYMBOLS    = 1u << LZMA_LEN_MID_BITS;
constexpr unsigned LZMA_POS_STATES_MAX     = 1u << LZMA_NUM_POS_BITS_MAX;

// One flat probability array: the fixed models at these offsets, then the
// literal coders (0x300 per literal state).
constexpr unsigned LZMA_LEN_CHOICE  = 0;
constexpr unsigned LZMA_LEN_CHOICE2 = 1;
constexpr unsigned LZMA_LEN_LOW     = 2;
constexpr unsigned LZMA_LEN_MID     = LZMA_LEN_LOW + (LZMA_POS_STATES_MAX << LZMA_LEN_LOW_BITS);
constexpr unsigned LZMA_LEN_HIGH    = LZMA_LEN_MID + (LZMA_POS_STATES_MAX << LZMA_LEN_MID_BITS);
constexpr unsigned LZMA_LEN_PROBS   = LZMA_LEN_HIGH + (1u << LZMA_LEN_HIGH_BITS);

constexpr unsigned LZMA_IS_MATCH     = 0;
constexpr unsigned LZMA_IS_REP       = LZMA_IS_MATCH + (LZMA_NUM_STATES << LZMA_NUM_POS_BITS_MAX);
constexpr unsigned LZMA_IS_REP_G0    = LZMA_IS_REP + LZMA_NUM_STATES;
constexpr unsigned LZMA_IS_REP_G1    = LZMA_IS_REP_G0 + LZMA_NUM_STATES;
constexpr unsigned LZMA_IS_REP_G2    = LZMA_IS_REP_G1 + LZMA_NUM_STATES;
constexpr unsigned LZMA_IS_REP0_LONG = LZMA_IS_REP_G2 + LZMA_NUM_STATES;
constexpr unsigned LZMA_POS_SLOT =
  LZMA_IS_REP0_LONG + (LZMA_NUM_STATES << LZMA_NUM_POS_BITS_MAX);
constexpr unsigned LZMA_POS_SPECIAL  = LZMA_POS_SLOT + (LZMA_NUM_LEN_TO_POS << 6);
constexpr unsigned LZMA_ALIGN =
  LZMA_POS_SPECIAL + 1 + LZMA_NUM_FULL_DISTANCES - LZMA_END_POS_MODEL;
constexpr unsigned LZMA_LEN          = LZMA_ALIGN + (1u << LZMA_NUM_ALIGN_BITS);
constexpr unsigned LZMA_REP_LEN      = LZMA_LEN + LZMA_LEN_PROBS;
constexpr unsigned LZMA_LITERAL      = LZMA_REP_LEN + LZMA_LEN_PROBS;

class Lzma_Range_Decoder {
   public:
    Lzma_Range_Decoder(const uint8_t* p, const uint8_t* end) :
        ip(p),
        iend(end) {
        // The first byte is always 0; then the 32-bit code, big-endian.
        bad = next() != 0;
        for (int i = 0; i < 4; ++i)
            code = (code << 8) | next();
        bad |= code == range;
    }

    unsigned bit(Lzma_Prob& p) {
        normalize();
        const uint32_t bound = (range >> LZMA_NUM_BIT_MODEL_TOTAL_BITS) * p;
        unsigned       b;
        if (code < bound)
        {
            range = bound;
            p += (LZMA_BIT_MODEL_TOTAL - p) >> LZMA_NUM_MOVE_BITS;
            b = 0;
        }
        else
        {
            range -= bound;
            code -= bound;
            p -= p >> LZMA_NUM_MOVE_BITS;
            b = 1;
        }
        return b;
    }

    uint32_t direct(unsigned num_bits) {
        uint32_t res = 0;
        do
        {
            normalize();
            range >>= 1;
            code -= range;
            const uint32_t t = 0u - (code >> 31);
            code += range & t;
            bad |= code == range;
            res = (res << 1) + (t + 1);
        } while (--num_bits);
        return res;
    }

    unsigned tree(Lzma_Prob* probs, unsigned num_bits) {
        unsigned m = 1;
        for (unsigned i = 0; i < num_bits; ++i)
            m = (m << 1) + bit(probs[m]);
        return m - (1u << num_bits);
    }

    unsigned tree_reverse(Lzma_Prob* probs, unsigned num_bits) {
        unsigned m = 1, sym = 0;
        for (unsigned i = 0; i < num_bits; ++i)
        {
            const unsigned b = bit(probs[m]);
            m                = (m << 1) + b;
            sym |= b << i;
        }
        return sym;
    }

    bool bad = false;

    // Bits normalize before decoding; this does the last one's.
    void normalize() {
        if (range < LZMA_TOP_VALUE)
        {
            range <<= 8;
            code = (code << 8) | next();
        }
    }

   private:
    uint8_t next() {
        if (ip == iend)
        {
            bad = true;
            return 0;
        }
        return *ip++;
    }

    const uint8_t* ip;
    const uint8_t* iend;
    uint32_t       range = 0xFFFFFFFFu;
    uint32_t       code  = 0;
};

inline unsigned lzma_decode_len(Lzma_Range_Decoder& rc, Lzma_Prob* p, unsigned pos_state) {
    if (!rc.bit(p[LZMA_LEN_CHOICE]))
        return rc.tree(p + LZMA_LEN_LOW + (pos_state << LZMA_LEN_LOW_BITS), LZMA_LEN_LOW_BITS);
    if (!rc.bit(p[LZMA_LEN_CHOICE2]))
        return LZMA_LEN_LOW_SYMBOLS
             + rc.tree(p + LZMA_LEN_MID + (pos_state << LZMA_LEN_MID_BITS), LZMA_LEN_MID_BITS);
    return LZMA_LEN_LOW_SYMBOLS + LZMA_LEN_MID_SYMBOLS
         + rc.tree(p + LZMA_LEN_HIGH, LZMA_LEN_HIGH_BITS);
}

// Decodes `src` (compressed data, then the 5 property bytes) into exactly
// `dst_size` bytes at `dst`. False on malformed input; `dst` is then partly
// written.
inline bool lzma_decode(uint8_t* dst, size_t dst_size, const uint8_t* src, size_t src_size) {
    constexpr size_t props_size = 5;
    if (src_size < props_size)
        return false;
    const uint8_t* props = src + src_size - props_size;
    unsigned       d     = props[0];
    if (d >= 9 * 5 * 5)
        return false;
    const unsigned lc = d % 9;
    d /= 9;
    const unsigned lp = d % 5;
    const unsigned pb = d / 5;

    // Each thread keeps its probability array; sized by lc + lp.
    thread_local std::vector<Lzma_Prob> storage;
    storage.assign(LZMA_LITERAL + (size_t{0x300} << (lc + lp)), LZMA_PROB_INIT);
    Lzma_Prob* const p        = storage.data();
    Lzma_Prob* const literals = p + LZMA_LITERAL;

    Lzma_Range_Decoder rc(src, props);

    const size_t pb_mask = (size_t{1} << pb) - 1;
    const size_t lp_mask = (size_t{1} << lp) - 1;

    uint32_t rep0 = 0, rep1 = 0, rep2 = 0, rep3 = 0;
    unsigned state = 0, prev = 0;  // prev: the last byte written
    size_t   pos   = 0;

    while (pos < dst_size && !rc.bad)
    {
        const unsigned pos_state = unsigned(pos & pb_mask);

        if (!rc.bit(p[LZMA_IS_MATCH + (state << LZMA_NUM_POS_BITS_MAX) + pos_state]))
        {
            Lzma_Prob* const probs =
              literals + 0x300 * (((pos & lp_mask) << lc) + (prev >> (8 - lc)));
            unsigned sym = 1;
            if (state >= 7)
            {
                // After a match: decode against the byte the last match points at.
                if (rep0 >= pos)
                    return false;
                unsigned match_byte = dst[pos - rep0 - 1];
                do
                {
                    const unsigned match_bit = (match_byte >> 7) & 1;
                    match_byte <<= 1;
                    const unsigned b = rc.bit(probs[((1 + match_bit) << 8) + sym]);
                    sym              = (sym << 1) | b;
                    if (match_bit != b)
                        break;
                } while (sym < 0x100);
            }
            else
                for (int i = 0; i < 8; ++i)
                    sym = (sym << 1) | rc.bit(probs[sym]);
            while (sym < 0x100)
                sym = (sym << 1) | rc.bit(probs[sym]);
            prev       = sym & 0xFF;
            dst[pos++] = uint8_t(prev);
            state      = state < 4 ? 0 : state < 10 ? state - 3 : state - 6;
            continue;
        }

        unsigned len;
        if (rc.bit(p[LZMA_IS_REP + state]))
        {
            if (pos == 0)
                return false;
            if (!rc.bit(p[LZMA_IS_REP_G0 + state]))
            {
                if (!rc.bit(p[LZMA_IS_REP0_LONG + (state << LZMA_NUM_POS_BITS_MAX) + pos_state]))
                {
                    // Short rep: one byte at rep0.
                    if (rep0 >= pos)
                        return false;
                    state    = state < 7 ? 9 : 11;
                    prev     = dst[pos - rep0 - 1];
                    dst[pos] = uint8_t(prev);
                    ++pos;
                    continue;
                }
            }
            else
            {
                uint32_t dist;
                if (!rc.bit(p[LZMA_IS_REP_G1 + state]))
                    dist = rep1;
                else
                {
                    if (!rc.bit(p[LZMA_IS_REP_G2 + state]))
                        dist = rep2;
                    else
                    {
                        dist = rep3;
                        rep3 = rep2;
                    }
                    rep2 = rep1;
                }
                rep1 = rep0;
                rep0 = dist;
            }
            len   = lzma_decode_len(rc, p + LZMA_REP_LEN, pos_state);
            state = state < 7 ? 8 : 11;
        }
        else
        {
            rep3  = rep2;
            rep2  = rep1;
            rep1  = rep0;
            len   = lzma_decode_len(rc, p + LZMA_LEN, pos_state);
            state = state < 7 ? 7 : 10;

            const unsigned len_state =
              len < LZMA_NUM_LEN_TO_POS - 1 ? len : LZMA_NUM_LEN_TO_POS - 1;
            const unsigned slot = rc.tree(p + LZMA_POS_SLOT + (len_state << 6), 6);
            if (slot < LZMA_START_POS_MODEL)
                rep0 = slot;
            else
            {
                const unsigned direct_bits = (slot >> 1) - 1;
                uint32_t       dist        = (2 | (slot & 1)) << direct_bits;
                if (slot < LZMA_END_POS_MODEL)
                    dist += rc.tree_reverse(p + LZMA_POS_SPECIAL + dist - slot, direct_bits);
                else
                {
                    dist += rc.direct(direct_bits - LZMA_NUM_ALIGN_BITS) << LZMA_NUM_ALIGN_BITS;
                    dist += rc.tree_reverse(p + LZMA_ALIGN, LZMA_NUM_ALIGN_BITS);
                }
                rep0 = dist;
            }
            if (rep0 == 0xFFFFFFFFu)  // end marker
                break;
        }

        if (rep0 >= pos)
            return false;
        len += LZMA_MATCH_MIN_LEN;
        if (len > dst_size - pos)
            len = unsigned(dst_size - pos);
        const uint8_t* from = dst + pos - rep0 - 1;
        uint8_t*       to   = dst + pos;
        pos += len;
        if (rep0 + 1 >= len)
            std::memcpy(to, from, len);
        else if (rep0 == 0)
            std::memset(to, *from, len);
        else
        {
            // The source runs into the bytes this copy writes: the output repeats
            // with period rep0 + 1, so copy whole periods, doubling each time.
            unsigned done = 0, chunk = rep0 + 1;
            while (done < len)
            {
                const unsigned n = chunk < len - done ? chunk : len - done;
                std::memcpy(to + done, from, n);
                done += n;
                chunk += n;
            }
        }
        prev = dst[pos - 1];
    }

    rc.normalize();
    return pos == dst_size && !rc.bad;
}
