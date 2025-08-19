
#include <algorithm>
#include <atomic>
#include <chrono>
#include <csignal>
#include <cstdint>
#include <cstdlib>
#include <cstring>
#include <deque>
#include <errno.h>
#include <fcntl.h>
#include <functional>
#include <map>
#include <memory>
#include <mutex>
#include <condition_variable>
#include <stdexcept>
#include <string>
#include <string_view>
#include <thread>
#include <unordered_map>
#include <unistd.h>
#include <vector>

#include <sys/epoll.h>
#include <sys/eventfd.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <sys/un.h>

#include "flexible_queue.hpp"

using namespace FlexibleQueue;

// ---- Little-endian helpers ----
namespace le {
static inline uint16_t rd16(const uint8_t* p){ return (uint16_t)p[0] | ((uint16_t)p[1]<<8); }
static inline uint32_t rd32(const uint8_t* p){ return (uint32_t)p[0] | ((uint32_t)p[1]<<8) | ((uint32_t)p[2]<<16) | ((uint32_t)p[3]<<24); }
static inline uint64_t rd64(const uint8_t* p){ uint64_t v=0; for(int i=0;i<8;i++) v |= (uint64_t)p[i]<<(8*i); return v; }
static inline void wr16(std::vector<uint8_t>& b, uint16_t v){ b.push_back(v & 0xFF); b.push_back((v>>8)&0xFF);}
static inline void wr32(std::vector<uint8_t>& b, uint32_t v){ for(int i=0;i<4;i++) b.push_back((v>>(8*i))&0xFF);}
static inline void wr64(std::vector<uint8_t>& b, uint64_t v){ for(int i=0;i<8;i++) b.push_back((v>>(8*i))&0xFF);}
}

// ---- Non-blocking IO helpers ----
static bool read_nb(int fd, void* buf, size_t n, size_t& got){
    uint8_t* p=reinterpret_cast<uint8_t*>(buf);
    while(got<n){
        ssize_t r = ::read(fd, p+got, n-got);
        if(r==0) return false; // EOF
        if(r<0){
            if(errno==EINTR) continue;
            if(errno==EAGAIN || errno==EWOULDBLOCK) return true; // partial ok
            return false;
        }
        got += (size_t)r;
    }
    return true;
}

// ---- Job and MPMC queue ----
struct Job{
    int fd;
    uint64_t gen;    // capture generation at read time
    uint16_t type;   // 0 = priority, 1 = non-priority
    uint16_t op;     // 1=PUSH, 2=POP, 3=REFRESH, 4=COUNT, 5=REMOVE
    std::vector<uint8_t> payload;
};

template <size_t CAP> class MPMC {
    static_assert((CAP& (CAP - 1)) == 0, "CAP must be power of two");
    static_assert(CAP <= (1ull << 63),   "CAP must be < 2^63 for wrap-safe signed diffs");

private:
    struct Slot {
        std::atomic<uint64_t> seq;
        Job job;
    };
    Slot ring_[CAP];
    std::atomic<uint64_t> tail_{ 0 };
    std::atomic<uint64_t> head_{ 0 };

public:
    MPMC() {
        for (size_t i = 0; i < CAP; i++)
            ring_[i].seq.store(i, std::memory_order_relaxed);
    }
    void enqueue(Job j) {
        uint64_t pos = tail_.fetch_add(1, std::memory_order_relaxed);
        Slot& s = ring_[pos & (CAP - 1)];
        for (;;) {
            uint64_t seq = s.seq.load(std::memory_order_acquire);
            int64_t dif = (int64_t)(seq - pos);
            if (dif == 0) break;
            std::this_thread::yield();
        }
        s.job = std::move(j);
        s.seq.store(pos + 1, std::memory_order_release);
    }
    bool dequeue(Job* out) {
        uint64_t pos = head_.load(std::memory_order_relaxed);
        for (;;) {
            Slot& s = ring_[pos & (CAP - 1)];
            uint64_t seq = s.seq.load(std::memory_order_acquire);
            int64_t dif = (int64_t)(seq - (pos + 1));
            if (dif == 0) {
                if (head_.compare_exchange_weak(pos, pos + 1, std::memory_order_acq_rel,
                    std::memory_order_relaxed)) {
                    *out = std::move(s.job);
                    s.job = Job{};
                    s.seq.store(pos + CAP, std::memory_order_release);
                    return true;
                }
                else {
                    continue;
                }
            }
            else if (dif < 0) {
                return false; // empty
            }
            else {
                pos = head_.load(std::memory_order_relaxed);
            }
        }
    }
};

// ---- Global state ----
static std::atomic<bool> g_stop{false};
std::condition_variable g_cv;
std::mutex g_cv_mutex;
bool g_pending_work = false;

using QP  = FlexibleQueueImpl<true, 0>;   // priority queue
using QNP = FlexibleQueueImpl<false,-1>;  // non-priority queue

static std::unique_ptr<QP>  g_qP;
static std::unique_ptr<QNP> g_qNP;

constexpr size_t MAX_CAP = 1<<16; // 65536
static MPMC<MAX_CAP> g_queue;

// forward decl
struct ConnThread;

// fd -> (owner thread, generation) for routing responses safely
static std::mutex g_fd_owner_mu;
struct FdOwner { ConnThread* th; uint64_t gen; };
static std::unordered_map<int, FdOwner> g_fd_owner;
static std::atomic<uint64_t> g_next_gen{1};

// ---- Worker side: decode/execute and *enqueue* response for EPOLLOUT ----
static void post_response(int fd, uint64_t gen, std::vector<uint8_t>&& frame, bool close_after);

static void handle_job(const Job& job){
    try{
        const uint8_t* p = job.payload.data();
        size_t off = 0;
        auto make_frame = [&](const std::vector<uint8_t>& payload){
            std::vector<uint8_t> frame; frame.reserve(4 + payload.size());
            le::wr32(frame, (uint32_t)payload.size());
            frame.insert(frame.end(), payload.begin(), payload.end());
            return frame;
        };

        switch(job.op){
            case 1: { // PUSH
                if(job.payload.size() < (4+4+2+8+1+1)) { post_response(job.fd, job.gen, make_frame({}), true); return; }
                uint32_t klen = le::rd32(p+off); off+=4;
                uint32_t vlen = le::rd32(p+off); off+=4;
                uint16_t pri  = le::rd16(p+off); off+=2;
                uint64_t exp  = le::rd64(p+off); off+=8;
                uint8_t upsert= p[off++];
                uint8_t mutate= p[off++];
                if(job.type!=0 && mutate){ post_response(job.fd, job.gen, make_frame({}), true); return; }
                if(klen > 16*1024*1024u || vlen > 16*1024*1024u){ post_response(job.fd, job.gen, make_frame({}), true); return; }
                if(off + klen + vlen != job.payload.size()){ post_response(job.fd, job.gen, make_frame({}), true); return; }
                const char* key = reinterpret_cast<const char*>(p+off); off+=klen;
                const char* val = reinterpret_cast<const char*>(p+off); off+=vlen;

                bool exists=false, updated=false;
                if(job.type==0){
                    std::string buffer;
                    auto rP = (mutate
                        ? g_qP->push(ByteSpan{key,klen}, (Pri)pri, exp, ByteSpan{val,vlen},
                            [&buffer](const ByteSpan&, Pri& pri_old, uint64_t& exp_old, ByteSpan& val_old,
                                       Pri& pri_new, uint64_t& exp_new, ByteSpan& val_new) mutable {
                                std::vector<std::string_view> seen;
                                buffer.reserve(val_old.size + val_new.size);
                                auto scan=[&](std::string_view sv){
                                    size_t start=0; bool first=(buffer.empty());
                                    while(true){
                                        size_t pos = sv.find(',', start);
                                        std::string_view tok = sv.substr(start, (pos==std::string::npos? sv.size()-start : pos-start));
                                        if(!tok.empty()){
                                            bool dup=false;
                                            for(auto s: seen){ if(s==tok){ dup=true; break; } }
                                            if(!dup){
                                                seen.push_back(tok);
                                                if(!first) buffer.push_back(','); else first=false;
                                                buffer.append(tok.data(), tok.size());
                                            }
                                        }
                                        if(pos==std::string::npos) break; start = pos+1;
                                    }
                                };
                                seen.clear();
                                scan(std::string_view((const char*)val_old.data, val_old.size));
                                scan(std::string_view((const char*)val_new.data, val_new.size));
                                pri_old = std::max(pri_old, pri_new);
                                val_old = ByteSpan(buffer.data(), buffer.size());
                                return true;
                            },
                            upsert!=0)
                        : g_qP->push(ByteSpan{key,klen}, (Pri)pri, exp, ByteSpan{val,vlen}, {}, upsert!=0)
                    );
                    exists=rP.exists; updated=rP.updated;
                } else {
                    auto rNP = g_qNP->push(ByteSpan{key,klen}, exp, ByteSpan{val,vlen}, {}, upsert!=0);
                    exists=rNP.exists; updated=rNP.updated;
                }
                std::vector<uint8_t> out; out.reserve(2);
                out.push_back(exists?1:0);
                out.push_back(updated?1:0);
                post_response(job.fd, job.gen, make_frame(out), false);
                break;
            }
            case 2: { // POP
                if(job.payload.size() < 5){ post_response(job.fd, job.gen, make_frame({}), true); return; }
                uint32_t N  = le::rd32(p+off); off+=4;
                uint8_t dir = p[off++];
                auto order  = (dir==1) ? PopOrder::EDesc : PopOrder::EAsc;
                std::vector<uint8_t> out;
                if(job.type==0){
                    auto items = g_qP->pop((int)N, order);
                    le::wr32(out, (uint32_t)items.size());
                    for(const auto& it : items){
                        le::wr32(out, it.key.size); le::wr32(out, it.value.size);
                        le::wr16(out, (uint16_t)it.priority);
                        le::wr64(out, (uint64_t)it.expiry);
                        out.insert(out.end(), it.key.data,   it.key.data  + it.key.size);
                        out.insert(out.end(), it.value.data, it.value.data+ it.value.size);
                    }
                } else {
                    auto items = g_qNP->pop((int)N, order);
                    le::wr32(out, (uint32_t)items.size());
                    for(const auto& it : items){
                        le::wr32(out, it.key.size); le::wr32(out, it.value.size);
                        le::wr16(out, 0);
                        le::wr64(out, (uint64_t)it.expiry);
                        out.insert(out.end(), it.key.data,   it.key.data  + it.key.size);
                        out.insert(out.end(), it.value.data, it.value.data+ it.value.size);
                    }
                }
                post_response(job.fd, job.gen, make_frame(out), false);
                break;
            }
            case 3: { // REFRESH
                if(job.type==0){ post_response(job.fd, job.gen, make_frame({}), true); return; }
                if(job.payload.size() < (8+8+4)){ post_response(job.fd, job.gen, make_frame({}), true); return; }
                uint64_t th  = le::rd64(p+off); off+=8;
                uint64_t exp = le::rd64(p+off); off+=8;
                uint32_t N   = le::rd32(p+off); off+=4;
                std::vector<uint8_t> out;
                auto items = g_qNP->refresh(th, exp, (int)N);
                le::wr32(out, (uint32_t)items.size());
                for(const auto& it : items){
                    le::wr32(out, it.key.size); le::wr32(out, it.value.size);
                    le::wr16(out, 0);
                    le::wr64(out, (uint64_t)it.expiry);
                    out.insert(out.end(), it.key.data,   it.key.data  + it.key.size);
                    out.insert(out.end(), it.value.data, it.value.data+ it.value.size);
                }
                post_response(job.fd, job.gen, make_frame(out), false);
                break;
            }
            case 4: { // REMOVE
                if(job.payload.size() < 4){ post_response(job.fd, job.gen, make_frame({}), true); return; }
                uint32_t klen = le::rd32(p+off); off+=4;
                if(klen > 16*1024*1024u){ post_response(job.fd, job.gen, make_frame({}), true); return; }
                if(off + klen != job.payload.size()){ post_response(job.fd, job.gen, make_frame({}), true); return; }
                const char* key = reinterpret_cast<const char*>(p+off); off+=klen;
                bool found = (job.type==0) ? g_qP->remove(ByteSpan{key,klen}) : g_qNP->remove(ByteSpan{key,klen});
                std::vector<uint8_t> out;
                out.push_back(found?1:0);
                post_response(job.fd, job.gen, make_frame(out), false);
                break;
            }
            case 5: { // COUNT
                if(job.payload.size() < 8){ post_response(job.fd, job.gen, make_frame({}), true); return; }
                uint32_t minp = le::rd32(p+off); off+=4;
                uint32_t maxp = le::rd32(p+off); off+=4;
                uint64_t c = (job.type==0) ? g_qP->count((int)minp,(int)maxp) : g_qNP->count();
                std::vector<uint8_t> out; le::wr64(out, c);
                post_response(job.fd, job.gen, make_frame(out), false);
                break;
            }
            default:
                post_response(job.fd, job.gen, make_frame({}), true); return;
        }
    } catch(...){
        // On any exception: enqueue 0-len response and close
        std::vector<uint8_t> frame; le::wr32(frame, 0u);
        post_response(job.fd, job.gen, std::move(frame), true);
    }
}

static void worker_loop(){
    while (true) {
        {
            std::unique_lock<std::mutex> lk(g_cv_mutex);
            g_cv.wait(lk, []{
                return g_stop.load(std::memory_order_acquire) || g_pending_work;
            });
            if (g_stop.load(std::memory_order_acquire) && !g_pending_work)
                break;
            g_pending_work = false;
        }
        Job job;
        while (g_queue.dequeue(&job)) {
            handle_job(job);
        }
    }
}

// =============================
// Connection threads (epoll)
// =============================
struct ConnState{
    uint64_t gen{0};
    enum Phase { READ_HDR, READ_PAYLOAD } phase = READ_HDR;
    uint8_t hdr[8]; size_t hdr_got = 0;
    uint16_t type=0, op=0; uint32_t len=0;
    std::vector<uint8_t> payload; size_t pay_got=0;
    // write side
    std::vector<uint8_t> out; size_t out_sent=0; bool close_after=false;
};

static void set_nonblock(int fd){ int fl = fcntl(fd, F_GETFL, 0); fcntl(fd, F_SETFL, fl | O_NONBLOCK); }

struct WriteTask{ int fd; uint64_t gen; std::vector<uint8_t> data; bool close_after; };

struct ConnThread {
    int epfd{-1};
    int efd{-1}; // eventfd for wakeups (responses & shutdown)
    std::unordered_map<int, ConnState> conns; // fd -> state
    std::mutex wq_mu; std::deque<WriteTask> wq; // MPSC-ish via mutex
    int id{0};

    void enqueue_write(WriteTask&& wt){
        {
            std::lock_guard<std::mutex> lk(wq_mu);
            wq.emplace_back(std::move(wt));
        }
        uint64_t one=1; ::write(efd,&one,sizeof(one)); // wake epoll
    }

    void run(){
        epfd = ::epoll_create1(EPOLL_CLOEXEC);
        if(epfd<0) throw std::runtime_error("epoll_create1 failed");
        efd = ::eventfd(0, EFD_NONBLOCK | EFD_CLOEXEC);
        if(efd<0) throw std::runtime_error("eventfd failed");
        struct epoll_event ev{}; ev.events = EPOLLIN; ev.data.ptr = (void*)(intptr_t)efd;
        if(::epoll_ctl(epfd, EPOLL_CTL_ADD, efd, &ev) < 0) throw std::runtime_error("epoll_ctl ADD efd failed");

        std::vector<struct epoll_event> evs(512);
        while(!g_stop.load(std::memory_order_acquire)){
            int n = ::epoll_wait(epfd, evs.data(), (int)evs.size(), -1);
            if(n<0){ if(errno==EINTR) continue; break; }
            for(int i=0;i<n;i++){
                int fd = (int)(intptr_t)evs[i].data.ptr;
                uint32_t events = evs[i].events;
                if(fd == efd){
                    // drain eventfd and process queued writes
                    uint64_t v; while(::read(efd,&v,sizeof(v))>0){}
                    flush_write_queue();
                    continue;
                }
                if(events & (EPOLLHUP|EPOLLERR)){ close_conn(fd); continue; }
                if(events & EPOLLIN){ handle_readable(fd); }
                if(events & EPOLLOUT){ handle_writable(fd); }
            }
        }
        std::vector<int> fds;
        fds.reserve(conns.size());
        for (auto& kv : conns) fds.push_back(kv.first);
        for (int fd : fds) close_conn(fd);
        if(efd>=0) ::close(efd);
        if(epfd>=0) ::close(epfd);
    }

    void add_conn(int fd){
        set_nonblock(fd);
        struct epoll_event ev{}; ev.events = EPOLLIN; ev.data.ptr = (void*)(intptr_t)fd;
        if(::epoll_ctl(epfd, EPOLL_CTL_ADD, fd, &ev)<0){ ::close(fd); return; }
        auto& cs = conns.emplace(fd, ConnState{}).first->second;
        cs.gen = g_next_gen.fetch_add(1, std::memory_order_relaxed);
        {
            std::lock_guard<std::mutex> lk(g_fd_owner_mu);
            g_fd_owner[fd] = FdOwner{this, cs.gen};
        }
    }

    void flush_write_queue(){
        std::deque<WriteTask> local;
        {
            std::lock_guard<std::mutex> lk(wq_mu);
            local.swap(wq);
        }
        for (auto& wt : local){
            auto it = conns.find(wt.fd);
            if(it==conns.end()){ // fd gone; drop
                continue;
            }
            ConnState& cs = it->second;
            if (cs.gen != wt.gen) {        // stale task for recycled fd
                continue;
            }
            // if already have pending data, append (should not happen with one-inflight semantics)
            if(!cs.out.empty() && !wt.data.empty()){
                cs.out.insert(cs.out.end(), wt.data.begin(), wt.data.end());
            } else if(!wt.data.empty()){
                cs.out = std::move(wt.data);
            }
            cs.close_after = wt.close_after || cs.close_after;
            if(!cs.out.empty()) mod_events(wt.fd, EPOLLIN | EPOLLOUT);
            else if(cs.close_after) { close_conn(wt.fd); }
        }
    }

    void handle_writable(int fd){
        auto it = conns.find(fd); if(it==conns.end()) return; ConnState& cs = it->second;
        while(cs.out_sent < cs.out.size()){
            ssize_t r = ::write(fd, cs.out.data()+cs.out_sent, cs.out.size()-cs.out_sent);
            if(r<0){ if(errno==EINTR) continue; if(errno==EAGAIN||errno==EWOULDBLOCK) break; close_conn(fd); return; }
            if(r==0) break;
            cs.out_sent += (size_t)r;
        }
        if (cs.out_sent == cs.out.size()) {
            cs.out_sent = 0;

            // If capacity ballooned beyond a cap, drop it to the floor.
            const size_t FLOOR = 16 * 1024;     // keep small buffers hot
            const size_t MAX_KEEP = 256 * 1024; // don't keep giant buffers

            size_t cap = cs.out.capacity();
            if (cap > MAX_KEEP) {
                std::vector<uint8_t>().swap(cs.out); // free capacity
                cs.out.reserve(FLOOR);
            } else {
                if (cap < FLOOR) cs.out.reserve(FLOOR);
                cs.out.clear(); // reuse existing capacity <= MAX_KEEP
            }

            if (cs.close_after) { close_conn(fd); return; }
            mod_events(fd, EPOLLIN);
        }
    }

    void handle_readable(int fd){
        auto it = conns.find(fd); if(it==conns.end()) return; ConnState& cs = it->second;
        while(true){
            if(cs.phase == ConnState::READ_HDR){
                if(!read_nb(fd, cs.hdr, 8, cs.hdr_got)){ close_conn(fd); return; }
                if(cs.hdr_got < 8) break; // need more later
                cs.type = le::rd16(cs.hdr+0);
                cs.op   = le::rd16(cs.hdr+2);
                cs.len  = le::rd32(cs.hdr+4);
                if(cs.len > (16u<<20)){ enqueue_write({fd, cs.gen, std::vector<uint8_t>{0,0,0,0}, true}); return; }
                cs.payload.assign(cs.len, 0); cs.pay_got = 0; cs.phase = ConnState::READ_PAYLOAD;
            }
            if(cs.phase == ConnState::READ_PAYLOAD){
                if(cs.len>0){ if(!read_nb(fd, cs.payload.data(), cs.len, cs.pay_got)){ close_conn(fd); return; } }
                if(cs.pay_got < cs.len) break; // need more later
                // full request (capture current generation with the job)
                Job job{fd, cs.gen, cs.type, cs.op, std::move(cs.payload)};
                g_queue.enqueue(std::move(job));
                bool need_wake;
                {
                    std::lock_guard<std::mutex> lk(g_cv_mutex);
                    need_wake = !g_pending_work;
                    g_pending_work = true;
                }
                if (need_wake)
                    g_cv.notify_one();
                // reset for next request
                cs.phase = ConnState::READ_HDR; cs.hdr_got=0; cs.type=cs.op=0; cs.len=0;
            }
        }
    }

    void mod_events(int fd, uint32_t flags){ struct epoll_event ev{}; ev.events = flags; ev.data.ptr = (void*)(intptr_t)fd; ::epoll_ctl(epfd, EPOLL_CTL_MOD, fd, &ev); }

    void close_conn(int fd){
        ::epoll_ctl(epfd, EPOLL_CTL_DEL, fd, nullptr);
        ::close(fd);
        conns.erase(fd);
        std::lock_guard<std::mutex> lk(g_fd_owner_mu);
        g_fd_owner.erase(fd);
    }
};

static void post_response(int fd, uint64_t gen, std::vector<uint8_t>&& frame, bool close_after){
    ConnThread* owner = nullptr; uint64_t current = 0;
    {
        std::lock_guard<std::mutex> lk(g_fd_owner_mu);
        auto it = g_fd_owner.find(fd);
        if(it != g_fd_owner.end()) { owner = it->second.th; current = it->second.gen; }
    }
    if(owner && current == gen){
        owner->enqueue_write(WriteTask{fd, gen, std::move(frame), close_after});
    }
    else {
        // Fallback: fd is gone; ignore.
    }
}

// ---- Accept loop: round-robin assign to connection threads ----
static void accept_loop(int lfd, std::vector<std::unique_ptr<ConnThread>>& cthreads){
    size_t rr=0; // round-robin index
    while(!g_stop.load(std::memory_order_acquire)){
        int cfd = ::accept4(lfd, nullptr, nullptr, SOCK_CLOEXEC | SOCK_NONBLOCK);
        if (cfd < 0) {
            const int e = errno;
            if (e == EINTR) continue;
            if (e == EAGAIN || e == EWOULDBLOCK) {
                std::this_thread::sleep_for(std::chrono::milliseconds(1));
                continue;
            }
            if (e == EMFILE || e == ENFILE || e == ENOBUFS || e == ENOMEM) {
                std::this_thread::sleep_for(std::chrono::milliseconds(100));
                continue;
            }
            break;
        }
        auto& th = *cthreads[rr % cthreads.size()]; rr++;
        th.add_conn(cfd);
    }
}

// ---- Listener & misc ----
static int create_unix_listener(const std::string& sock_path){
    ::unlink(sock_path.c_str());
    int fd = ::socket(AF_UNIX, SOCK_STREAM | SOCK_NONBLOCK | SOCK_CLOEXEC, 0);
    if(fd < 0) throw std::runtime_error("socket() failed");

    struct sockaddr_un addr{}; addr.sun_family = AF_UNIX;
    if(sock_path.size() >= sizeof(addr.sun_path)){ ::close(fd); throw std::runtime_error("socket path too long"); }
    std::strncpy(addr.sun_path, sock_path.c_str(), sizeof(addr.sun_path)-1);

    if(::bind(fd, reinterpret_cast<struct sockaddr*>(&addr), sizeof(addr))<0){ ::close(fd); throw std::runtime_error("bind() failed"); }
    if(::listen(fd, 512)<0){ ::close(fd); throw std::runtime_error("listen() failed"); }
    return fd;
}

static void ensure_dir(const std::string& path){ if(::mkdir(path.c_str(), 0755) < 0){ if(errno != EEXIST) throw std::runtime_error("mkdir failed: "+path); }}

static void on_sigint(int){ { std::unique_lock<std::mutex> lk(g_cv_mutex); g_stop.store(true, std::memory_order_release); } g_cv.notify_all(); }

int main(int argc, char** argv){
    std::signal(SIGPIPE, SIG_IGN);

    std::string sock_path = "/tmp/fqdb.sock";
    std::string base_dir = "";
    int N_workers = std::max(2, (int)std::thread::hardware_concurrency());
    int N_conn_threads = std::max(1, (int)std::thread::hardware_concurrency()/2);

    for(int i=1;i<argc;i++){
        std::string a = argv[i];
        if(a == "--socket" && i+1<argc){ sock_path = argv[++i]; }
        else if(a == "--workers" && i+1<argc){ N_workers = std::max(1, std::atoi(argv[++i])); }
        else if(a == "--conn-threads" && i+1<argc){ N_conn_threads = std::max(1, std::atoi(argv[++i])); }
        else if(a == "--base-dir" && i+1<argc){ base_dir = argv[++i]; }
    }

    if(!base_dir.empty()){
        if(base_dir.back() == '/') base_dir.pop_back();
        ensure_dir(base_dir);
        ensure_dir(base_dir+"/qp");
        ensure_dir(base_dir+"/qnp");
    }
    // Construct queues (Options ctor takes raw args)
    std::string qp_wal   = base_dir.empty() ? "" : base_dir + "/qp/data.wal";
    std::string qp_snap  = base_dir.empty() ? "" : base_dir + "/qp/data.snap";
    std::string qnp_wal  = base_dir.empty() ? "" : base_dir + "/qnp/data.wal";
    std::string qnp_snap = base_dir.empty() ? "" : base_dir + "/qnp/data.snap";

    bool disable_wal = base_dir.empty(); // in-memory mode if no base-dir

    g_qP  = std::make_unique<QP>(Options{qp_wal, qp_snap, 0, disable_wal});
    g_qNP = std::make_unique<QNP>(Options{qnp_wal, qnp_snap, 0, disable_wal});

    // Start worker pool
    std::vector<std::thread> workers; workers.reserve(N_workers);
    for(int i=0;i<N_workers;i++) workers.emplace_back(worker_loop);

    std::signal(SIGINT, on_sigint); std::signal(SIGTERM, on_sigint);

    // Start connection thread pool (each with its own epoll + eventfd)
    std::vector<std::unique_ptr<ConnThread>> cthreads; cthreads.reserve(N_conn_threads);
    std::vector<std::thread> cthreads_run;
    for(int i=0;i<N_conn_threads;i++){
        auto t = std::make_unique<ConnThread>(); t->id = i; cthreads.push_back(std::move(t));
    }
    for(auto& ct : cthreads){ cthreads_run.emplace_back(&ConnThread::run, ct.get()); }

    int lfd = create_unix_listener(sock_path);
    std::thread acc(accept_loop, lfd, std::ref(cthreads));

    // Wait for acceptor to stop
    acc.join();

    // Ensure all other threads actually exit (accept_loop may have exited due to error, with g_stop still false)
    {
        std::unique_lock<std::mutex> lk(g_cv_mutex);
        g_stop.store(true, std::memory_order_release);
    }
    g_cv.notify_all();  // let workers leave wait()
    for (auto& ct : cthreads) {
        if (ct->efd >= 0) {
            uint64_t one = 1;
            ::write(ct->efd, &one, sizeof(one));  // wake epoll threads
        }
    }
    for(auto& th : cthreads_run) th.join();
    for(auto& t: workers) t.join();
    ::close(lfd); ::unlink(sock_path.c_str());
    return 0;
}
