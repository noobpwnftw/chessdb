
#include <algorithm>
#include <chrono>
#include <csignal>
#include <cstdint>
#include <cstdlib>
#include <cstring>
#include <errno.h>
#include <fcntl.h>
#include <functional>
#include <memory>
#include <mutex>
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
	static inline uint16_t rd16(const uint8_t* p) { return (uint16_t)p[0] | ((uint16_t)p[1] << 8); }
	static inline uint32_t rd32(const uint8_t* p) { return (uint32_t)p[0] | ((uint32_t)p[1] << 8) | ((uint32_t)p[2] << 16) | ((uint32_t)p[3] << 24); }
	static inline uint64_t rd64(const uint8_t* p) { uint64_t v = 0; for (int i = 0; i < 8; i++) v |= (uint64_t)p[i] << (8 * i); return v; }
	static inline void wr16(std::vector<uint8_t>& b, uint16_t v) { b.push_back(v & 0xFF); b.push_back((v >> 8) & 0xFF); }
	static inline void wr32(std::vector<uint8_t>& b, uint32_t v) { for (int i = 0; i < 4; i++) b.push_back((v >> (8 * i)) & 0xFF); }
	static inline void wr64(std::vector<uint8_t>& b, uint64_t v) { for (int i = 0; i < 8; i++) b.push_back((v >> (8 * i)) & 0xFF); }
}

// ---- Non-blocking IO helpers ----
static bool read_nb(int fd, void* buf, size_t n, size_t& got) {
	uint8_t* p = reinterpret_cast<uint8_t*>(buf);
	while (got < n) {
		ssize_t r = ::read(fd, p + got, n - got);
		if (r == 0) return false; // EOF
		if (r < 0) {
			if (errno == EINTR) continue;
			if (errno == EAGAIN || errno == EWOULDBLOCK) return true; // partial ok
			return false;
		}
		got += (size_t)r;
	}
	return true;
}

// ---- Global state ----
static bool g_stop = false;

using QP = FlexibleQueueImpl<true, 0>;   // priority queue
using QNP = FlexibleQueueImpl<false, -1>;  // non-priority queue

static std::unique_ptr<QP>  g_qP;
static std::unique_ptr<QNP> g_qNP;

// =============================
// Connection threads (epoll)
// =============================
struct ConnState {
	int fd;
	enum Phase { READ_HDR, READ_PAYLOAD } phase = READ_HDR;
	uint8_t hdr[8]; size_t hdr_got = 0;
	uint16_t type = 0, op = 0; uint32_t len = 0;
	std::vector<uint8_t> payload; size_t pay_got = 0;
	// write side
	std::vector<uint8_t> out; size_t out_sent = 0; bool close_after = false;
};

struct ConnThread {
	int epfd{ -1 };
	int efd{ -1 };
	std::unordered_map<int, std::unique_ptr<ConnState>> conns;
	Queue<int, MAX_CAP> new_conns;

	void enqueue_new_conn(int fd) {
		new_conns.push(fd);
		uint64_t one = 1; ::write(efd, &one, sizeof(one));
	}

	void run() {
		epfd = ::epoll_create1(EPOLL_CLOEXEC);
		if (epfd < 0) throw std::runtime_error("epoll_create1 failed");
		efd = ::eventfd(0, EFD_NONBLOCK | EFD_CLOEXEC);
		if (efd < 0) throw std::runtime_error("eventfd failed");
		struct epoll_event ev {}; ev.events = EPOLLIN; ev.data.ptr = nullptr;
		if (::epoll_ctl(epfd, EPOLL_CTL_ADD, efd, &ev) < 0) throw std::runtime_error("epoll_ctl ADD efd failed");

		std::vector<struct epoll_event> evs(512);
		while (!g_stop) {
			int n = ::epoll_wait(epfd, evs.data(), (int)evs.size(), -1);
			if (n < 0) { if (errno == EINTR) continue; break; }
			for (int i = 0; i < n; i++) {
				uint32_t events = evs[i].events;
				if (evs[i].data.ptr == nullptr) {
					// drain eventfd and process queued tasks
					uint64_t v; while (::read(efd, &v, sizeof(v)) > 0) {}
					add_new_conns();
					continue;
				}
				ConnState* pcs = (ConnState*)evs[i].data.ptr;
				if (events & (EPOLLHUP | EPOLLERR)) { close_conn(pcs); continue; }
				if (events & EPOLLOUT) {
					if(!handle_writable(pcs))
						continue;
					if (pcs->out.size() == 0)
						mod_events(pcs, EPOLLIN);
				}
				if (events & EPOLLIN) { handle_readable(pcs); }
			}
		}
		while (!conns.empty()) {
			close_conn(conns.begin()->second.get());
		}
		if (efd >= 0) ::close(efd);
		if (epfd >= 0) ::close(epfd);
	}

	void enqueue_write(ConnState* cs, std::vector<uint8_t>&& payload, bool close_after) {
		cs->close_after = close_after || cs->close_after;
		// if already have pending data, append (should not happen with one-inflight semantics)
		if (!cs->out.empty()) {
			cs->out.insert(cs->out.end(), payload.begin(), payload.end());
		}
		else {
			cs->out = std::move(payload);
			if (!handle_writable(cs))
				return;
			if (cs->out.size() > 0)
				mod_events(cs, EPOLLIN | EPOLLOUT);
		}
	}

	void add_new_conns() {
		int fd;
		while (new_conns.pop(&fd)) {
			auto cs = std::make_unique<ConnState>();
			cs->fd = fd;
			struct epoll_event ev {}; ev.events = EPOLLIN; ev.data.ptr = cs.get();
			if (::epoll_ctl(epfd, EPOLL_CTL_ADD, fd, &ev) < 0) { ::close(fd); continue; }
			conns.emplace(fd, std::move(cs));
		}
	}

	bool handle_writable(ConnState* cs) {
		while (cs->out_sent < cs->out.size()) {
			ssize_t r = ::write(cs->fd, cs->out.data() + cs->out_sent, cs->out.size() - cs->out_sent);
			if (r < 0) { if (errno == EINTR) continue; if (errno == EAGAIN || errno == EWOULDBLOCK) break; close_conn(cs); return false; }
			if (r == 0) break;
			cs->out_sent += (size_t)r;
		}
		if (cs->out_sent == cs->out.size()) {
			cs->out_sent = 0;

			// If capacity ballooned beyond a cap, drop it to the floor.
			const size_t FLOOR = 16 * 1024;     // keep small buffers hot
			const size_t MAX_KEEP = 256 * 1024; // don't keep giant buffers

			size_t cap = cs->out.capacity();
			if (cap > MAX_KEEP) {
				std::vector<uint8_t>().swap(cs->out); // free capacity
				cs->out.reserve(FLOOR);
			}
			else {
				if (cap < FLOOR) cs->out.reserve(FLOOR);
				cs->out.clear(); // reuse existing capacity <= MAX_KEEP
			}

			if (cs->close_after) { close_conn(cs); return false; }
		}
		return true;
	}

	void handle_readable(ConnState* cs) {
		while (true) {
			if (cs->phase == ConnState::READ_HDR) {
				if (!read_nb(cs->fd, cs->hdr, 8, cs->hdr_got)) { close_conn(cs); return; }
				if (cs->hdr_got < 8) break; // need more later
				cs->type = le::rd16(cs->hdr + 0);
				cs->op = le::rd16(cs->hdr + 2);
				cs->len = le::rd32(cs->hdr + 4);
				if (cs->len > (16u << 20)) { enqueue_write(cs, {0,0,0,0}, true); return; }
				cs->payload.resize(cs->len); cs->pay_got = 0; cs->phase = ConnState::READ_PAYLOAD;
			}
			if (cs->phase == ConnState::READ_PAYLOAD) {
				if (cs->len > 0) { if (!read_nb(cs->fd, cs->payload.data(), cs->len, cs->pay_got)) { close_conn(cs); return; } }
				if (cs->pay_got < cs->len) break; // need more later
				// full request
				const uint8_t* p = cs->payload.data();
				size_t off = 0;
				switch (cs->op) {
				case 1: { // PUSH
					if (cs->payload.size() < (4 + 4 + 2 + 8 + 1 + 1)) { enqueue_write(cs, {0,0,0,0}, true); return; }
					uint32_t klen = le::rd32(p + off); off += 4;
					uint32_t vlen = le::rd32(p + off); off += 4;
					uint16_t pri = le::rd16(p + off); off += 2;
					uint64_t exp = le::rd64(p + off); off += 8;
					uint8_t upsert = p[off++];
					uint8_t mutate = p[off++];
					if (cs->type != 0 && mutate) { enqueue_write(cs, {0,0,0,0}, true); return; }
					if (klen > 16 * 1024 * 1024u || vlen > 16 * 1024 * 1024u) { enqueue_write(cs, {0,0,0,0}, true); return; }
					if (off + klen + vlen != cs->payload.size()) { enqueue_write(cs, {0,0,0,0}, true); return; }
					const char* key = reinterpret_cast<const char*>(p + off); off += klen;
					const char* val = reinterpret_cast<const char*>(p + off); off += vlen;

					bool exists = false, updated = false;
					if (cs->type == 0) {
						std::string buffer;
						auto rP = (mutate
							? g_qP->push(ByteSpan{ key,klen }, (Pri)pri, exp, ByteSpan{ val,vlen },
								[&buffer](const ByteSpan&, Pri& pri_old, uint64_t& exp_old, ByteSpan& val_old,
									Pri& pri_new, uint64_t& exp_new, ByteSpan& val_new) mutable {
										std::vector<std::string_view> seen;
										seen.reserve(16);
										buffer.reserve(val_old.size + val_new.size);
										auto scan = [&](std::string_view sv) {
											size_t start = 0; bool first = (buffer.empty());
											while (true) {
												size_t pos = sv.find(',', start);
												std::string_view tok = sv.substr(start, (pos == std::string::npos ? sv.size() - start : pos - start));
											if (!tok.empty()) {
												bool dup = false;
												for (auto s : seen) { if (s == tok) { dup = true; break; } }
												if (!dup) {
													seen.push_back(tok);
														if (!first) buffer.push_back(','); else first = false;
														buffer.append(tok.data(), tok.size());
													}
												}
												if (pos == std::string::npos) break; start = pos + 1;
											}
											};
										scan(std::string_view((const char*)val_old.data, val_old.size));
										scan(std::string_view((const char*)val_new.data, val_new.size));
										pri_old = std::max(pri_old, pri_new);
										val_old = ByteSpan(buffer.data(), buffer.size());
										return true;
								},
								upsert != 0)
							: g_qP->push(ByteSpan{ key,klen }, (Pri)pri, exp, ByteSpan{ val,vlen }, {}, upsert != 0)
							);
						exists = rP.exists; updated = rP.updated;
					}
					else {
						auto rNP = g_qNP->push(ByteSpan{ key,klen }, exp, ByteSpan{ val,vlen }, {}, upsert != 0);
						exists = rNP.exists; updated = rNP.updated;
					}
					std::vector<uint8_t> out; out.reserve(6);
					le::wr32(out, 2);
					out.push_back(exists ? 1 : 0);
					out.push_back(updated ? 1 : 0);
					enqueue_write(cs, std::move(out), false);
					break;
				}
				case 2: { // POP
					if (cs->payload.size() < 5) { enqueue_write(cs, {0,0,0,0}, true); return; }
					uint32_t N = le::rd32(p + off); off += 4;
					uint8_t dir = p[off++];
					auto order = (dir == 1) ? PopOrder::EDesc : PopOrder::EAsc;
					std::vector<uint8_t> out;
					if (cs->type == 0) {
						auto items = g_qP->pop((int)N, order);
						uint32_t len = 4 + items.size() * (4 + 4 + 2 + 8);
						for (const auto& it : items) {
							len += it.key.size + it.value.size;
						}
						out.reserve(len + 4);
						le::wr32(out, len);
						le::wr32(out, (uint32_t)items.size());
						for (const auto& it : items) {
							le::wr32(out, it.key.size); le::wr32(out, it.value.size);
							le::wr16(out, (uint16_t)it.priority);
							le::wr64(out, (uint64_t)it.expiry);
							out.insert(out.end(), it.key.data, it.key.data + it.key.size);
							out.insert(out.end(), it.value.data, it.value.data + it.value.size);
						}
					}
					else {
						auto items = g_qNP->pop((int)N, order);
						uint32_t len = 4 + items.size() * (4 + 4 + 2 + 8);
						for (const auto& it : items) {
							len += it.key.size + it.value.size;
						}
						out.reserve(len + 4);
						le::wr32(out, len);
						le::wr32(out, (uint32_t)items.size());
						for (const auto& it : items) {
							le::wr32(out, it.key.size); le::wr32(out, it.value.size);
							le::wr16(out, 0);
							le::wr64(out, (uint64_t)it.expiry);
							out.insert(out.end(), it.key.data, it.key.data + it.key.size);
							out.insert(out.end(), it.value.data, it.value.data + it.value.size);
						}
					}
					enqueue_write(cs, std::move(out), false);
					break;
				}
				case 3: { // REFRESH
					if (cs->type == 0) { enqueue_write(cs, {0,0,0,0}, true); return; }
					if (cs->payload.size() < (8 + 8 + 4)) { enqueue_write(cs, {0,0,0,0}, true); return; }
					uint64_t th = le::rd64(p + off); off += 8;
					uint64_t exp = le::rd64(p + off); off += 8;
					uint32_t N = le::rd32(p + off); off += 4;
					std::vector<uint8_t> out;
					auto items = g_qNP->refresh(th, exp, (int)N);
					uint32_t len = 4 + items.size() * (4 + 4 + 2 + 8);
					for (const auto& it : items) {
						len += it.key.size + it.value.size;
					}
					out.reserve(len + 4);
					le::wr32(out, len);
					le::wr32(out, (uint32_t)items.size());
					for (const auto& it : items) {
						le::wr32(out, it.key.size); le::wr32(out, it.value.size);
						le::wr16(out, 0);
						le::wr64(out, (uint64_t)it.expiry);
						out.insert(out.end(), it.key.data, it.key.data + it.key.size);
						out.insert(out.end(), it.value.data, it.value.data + it.value.size);
					}
					enqueue_write(cs, std::move(out), false);
					break;
				}
				case 4: { // REMOVE
					if (cs->payload.size() < 4) { enqueue_write(cs, {0,0,0,0}, true); return; }
					uint32_t klen = le::rd32(p + off); off += 4;
					if (klen > 16 * 1024 * 1024u) { enqueue_write(cs, {0,0,0,0}, true); return; }
					if (off + klen != cs->payload.size()) { enqueue_write(cs, {0,0,0,0}, true); return; }
					const char* key = reinterpret_cast<const char*>(p + off); off += klen;
					bool found = (cs->type == 0) ? g_qP->remove(ByteSpan{ key,klen }) : g_qNP->remove(ByteSpan{ key,klen });
					std::vector<uint8_t> out; out.reserve(5);
					le::wr32(out, 1);
					out.push_back(found ? 1 : 0);
					enqueue_write(cs, std::move(out), false);
					break;
				}
				case 5: { // COUNT
					if (cs->payload.size() < 8) { enqueue_write(cs, {0,0,0,0}, true); return; }
					uint32_t minp = le::rd32(p + off); off += 4;
					uint32_t maxp = le::rd32(p + off); off += 4;
					uint64_t c = (cs->type == 0) ? g_qP->count((int)minp, (int)maxp) : g_qNP->count();
					std::vector<uint8_t> out; out.reserve(12);
					le::wr32(out, 8);
					le::wr64(out, c);
					enqueue_write(cs, std::move(out), false);
					break;
				}
				default:
					enqueue_write(cs, {0,0,0,0}, true); return;
				}
				cs->phase = ConnState::READ_HDR; cs->hdr_got = 0; cs->type = cs->op = 0; cs->len = 0;
			}
		}
	}

	void mod_events(ConnState* cs, uint32_t flags) { struct epoll_event ev {}; ev.events = flags; ev.data.ptr = cs; ::epoll_ctl(epfd, EPOLL_CTL_MOD, cs->fd, &ev); }

	void close_conn(ConnState* cs) {
		::epoll_ctl(epfd, EPOLL_CTL_DEL, cs->fd, nullptr);
		::close(cs->fd);
		conns.erase(cs->fd);
	}
};

// ---- Accept loop: round-robin assign to connection threads ----
static void accept_loop(int lfd, std::vector<std::unique_ptr<ConnThread>>& cthreads) {
	size_t rr = 0; // round-robin index
	while (!g_stop) {
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
		th.enqueue_new_conn(cfd);
	}
}

// ---- Listener & misc ----
static int create_unix_listener(const std::string& sock_path) {
	::unlink(sock_path.c_str());
	int fd = ::socket(AF_UNIX, SOCK_STREAM | SOCK_NONBLOCK | SOCK_CLOEXEC, 0);
	if (fd < 0) throw std::runtime_error("socket() failed");

	struct sockaddr_un addr {}; addr.sun_family = AF_UNIX;
	if (sock_path.size() >= sizeof(addr.sun_path)) { ::close(fd); throw std::runtime_error("socket path too long"); }
	std::strncpy(addr.sun_path, sock_path.c_str(), sizeof(addr.sun_path) - 1);

	if (::bind(fd, reinterpret_cast<struct sockaddr*>(&addr), sizeof(addr)) < 0) { ::close(fd); throw std::runtime_error("bind() failed"); }
	if (::listen(fd, 512) < 0) { ::close(fd); throw std::runtime_error("listen() failed"); }
	return fd;
}

static void ensure_dir(const std::string& path) { if (::mkdir(path.c_str(), 0755) < 0) { if (errno != EEXIST) throw std::runtime_error("mkdir failed: " + path); } }

static void on_sigint(int) { g_stop = true; }

int main(int argc, char** argv) {
	std::signal(SIGPIPE, SIG_IGN);

	std::string sock_path = "/tmp/fqdb.sock";
	std::string base_dir = "";
	int N_threads = std::max(1, (int)std::thread::hardware_concurrency() / 2);

	for (int i = 1; i < argc; i++) {
		std::string a = argv[i];
		if (a == "--socket" && i + 1 < argc) { sock_path = argv[++i]; }
		else if (a == "--threads" && i + 1 < argc) { N_threads = std::max(1, std::atoi(argv[++i])); }
		else if (a == "--base-dir" && i + 1 < argc) { base_dir = argv[++i]; }
	}

	if (!base_dir.empty()) {
		if (base_dir.back() == '/') base_dir.pop_back();
		ensure_dir(base_dir);
		ensure_dir(base_dir + "/qp");
		ensure_dir(base_dir + "/qnp");
	}
	// Construct queues (Options ctor takes raw args)
	std::string qp_wal = base_dir.empty() ? "" : base_dir + "/qp/data.wal";
	std::string qp_snap = base_dir.empty() ? "" : base_dir + "/qp/data.snap";
	std::string qnp_wal = base_dir.empty() ? "" : base_dir + "/qnp/data.wal";
	std::string qnp_snap = base_dir.empty() ? "" : base_dir + "/qnp/data.snap";

	bool disable_wal = base_dir.empty(); // in-memory mode if no base-dir

	g_qP = std::make_unique<QP>(Options{ qp_wal, qp_snap, 0, disable_wal });
	g_qNP = std::make_unique<QNP>(Options{ qnp_wal, qnp_snap, 0, disable_wal });

	std::signal(SIGINT, on_sigint); std::signal(SIGTERM, on_sigint);

	// Start connection thread pool (each with its own epoll + eventfd)
	std::vector<std::unique_ptr<ConnThread>> cthreads; cthreads.reserve(N_threads);
	std::vector<std::thread> cthreads_run;
	for (int i = 0; i < N_threads; i++) { cthreads.emplace_back(std::make_unique<ConnThread>()); }
	for (auto& ct : cthreads) { cthreads_run.emplace_back(&ConnThread::run, ct.get()); }

	int lfd = create_unix_listener(sock_path);
	std::thread acc(accept_loop, lfd, std::ref(cthreads));

	// Wait for acceptor to stop
	acc.join();

	// Ensure all other threads actually exit (accept_loop may have exited due to error, with g_stop still false)
	g_stop = true;

	for (auto& ct : cthreads) {
		if (ct->efd >= 0) {
			uint64_t one = 1;
			::write(ct->efd, &one, sizeof(one));  // wake epoll threads
		}
	}
	for (auto& th : cthreads_run) th.join();
	::close(lfd); ::unlink(sock_path.c_str());
	return 0;
}
