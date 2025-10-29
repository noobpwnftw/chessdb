
#pragma once
#include <atomic>
#include <chrono>
#include <cstdint>
#include <cstring>
#include <fcntl.h>
#include <functional>
#include <future>
#include <mutex>
#include <shared_mutex>
#include <string>
#include <string_view>
#include <sys/stat.h>
#include <sys/types.h>
#include <sys/wait.h>
#include <thread>
#include <unistd.h>
#include <vector>
#include <dirent.h>
#include <sys/syscall.h>
#include <linux/futex.h>
#include <limits.h>
#include "absl/container/btree_set.h"
#include "absl/container/btree_map.h"
#if defined(__x86_64__) || defined(_M_X64)
#include <immintrin.h>
#define CPU_RELAX() _mm_pause()
#else
#define CPU_RELAX() do {} while (0)
#endif

static inline int futex_wait(int* addr, int expected) {
	return syscall(SYS_futex, addr, FUTEX_WAIT_PRIVATE, expected, NULL, NULL, 0);
}
static inline int futex_wake(int* addr, int n) {
	return syscall(SYS_futex, addr, FUTEX_WAKE_PRIVATE, n, NULL, NULL, 0);
}

namespace FlexibleQueue {
	struct ByteSpan {
		const char* data{ nullptr };
		uint32_t size{ 0 };
		ByteSpan() = default;
		ByteSpan(const char* d, uint32_t n) : data(d), size(n) {}
		bool empty() const { return size == 0; }
		std::string to_string() const {
			return std::string(data ? data : "", data ? size : 0);
		}
		operator std::string_view() const {
			return std::string_view(data ? data : "", data ? size : 0);
		}
		bool operator==(std::string_view s) const {
			return std::string_view(data ? data : "", data ? size : 0) == s;
		}
	};

	static inline ByteSpan bs_dup(const char* s, size_t n) {
		if (!s || n == 0) return {};
		char* d = (char*)malloc(n);
		std::memcpy(d, s, n);
		return ByteSpan{ d, (uint32_t)n };
	}
	static inline void bs_free(ByteSpan b) {
		if (b.data && b.size)
			free(const_cast<char*>(b.data));
	}

	struct ByteSpanLess {
		using is_transparent = void;
		bool operator()(const ByteSpan& a, const ByteSpan& b) const noexcept {
			if (a.data == b.data) return a.size < b.size;
			size_t n = std::min<size_t>(a.size, b.size);
			int c = std::memcmp(a.data, b.data, n);
			return (c < 0) || (c == 0 && a.size < b.size);
		}
		bool operator()(const ByteSpan& a, std::string_view b) const noexcept {
			size_t n = std::min<size_t>(a.size, b.size());
			int c = std::memcmp(a.data, b.data(), n);
			return (c < 0) || (c == 0 && a.size < b.size());
		}
		bool operator()(std::string_view a, const ByteSpan& b) const noexcept {
			size_t n = std::min<size_t>(a.size(), b.size);
			int c = std::memcmp(a.data(), b.data, n);
			return (c < 0) || (c == 0 && a.size() < b.size);
		}
	};

	static inline bool write_all_(int fd, const void* buf, size_t n) {
		const char* p = static_cast<const char*>(buf);
		size_t w = 0;
		while (w < n) {
			ssize_t r = ::write(fd, p + w, n - w);
			if (r < 0) {
				if (errno == EINTR)
					continue;
				return false;
			}
			if (r == 0)
				return false;
			w += size_t(r);
		}
		return true;
	}
	static inline bool read_all_(int fd, void* buf, size_t n) {
		char* p = static_cast<char*>(buf);
		size_t rtot = 0;
		while (rtot < n) {
			ssize_t r = ::read(fd, p + rtot, n - rtot);
			if (r < 0) {
				if (errno == EINTR)
					continue;
				return false;
			}
			if (r == 0)
				return false;
			rtot += size_t(r);
		}
		return true;
	}
	static inline std::string dir_of_(const std::string& path) {
		auto pos = path.find_last_of('/');
		if (pos == std::string::npos)
			return "";
		return path.substr(0, pos);
	}

	enum : int { KIND_PUSH = 1, KIND_POP = 2, KIND_REFRESH = 3, KIND_REMOVE = 4, KIND_COUNT = 5, KIND_SNAPSHOT = 6 };

	template <bool W> struct PriExtStorage;
	template <> struct PriExtStorage<true> { uint16_t priority; };
	template <> struct PriExtStorage<false> {};

	template <bool W> struct OpExtStorage;
	template <> struct OpExtStorage<true> { int minp; int maxp; };
	template <> struct OpExtStorage<false> {};

	struct Msg {
		void* ptr;
		uint8_t kind;
	};

	constexpr size_t MAX_CAP = 1 << 16; // 65536
	template <class T, size_t CAP> class Queue {
		static_assert((CAP& (CAP - 1)) == 0, "CAP must be power of two");
		static_assert(CAP <= (1ull << 63), "CAP must be < 2^63 for wrap-safe signed diffs");
	private:
		struct alignas(64) Slot {
			std::atomic<uint64_t> seq;
			alignas(64) T item;
		};
		Slot ring_[CAP];

		alignas(64) std::atomic<uint64_t> tail_{ 0 };
		alignas(64) std::atomic<uint64_t> head_{ 0 };
	public:
		Queue() {
			for (size_t i = 0; i < CAP; i++)
				ring_[i].seq.store(i, std::memory_order_relaxed);
		}
		void push(T item) {
			uint64_t pos = tail_.fetch_add(1, std::memory_order_relaxed);
			Slot& s = ring_[pos & (CAP - 1)];
			for (;;) {
				uint64_t seq = s.seq.load(std::memory_order_acquire);
				int64_t dif = (int64_t)(seq - pos);
				if (dif == 0) break;
				CPU_RELAX();
			}
			s.item = std::move(item);
			s.seq.store(pos + 1, std::memory_order_release);
		}
		bool pop(T* item) {
			uint64_t pos = head_.load(std::memory_order_relaxed);
			for (;;) {
				Slot& s = ring_[pos & (CAP - 1)];
				uint64_t seq = s.seq.load(std::memory_order_acquire);
				int64_t dif = (int64_t)(seq - (pos + 1));
				if (dif == 0) {
					if (head_.compare_exchange_weak(pos, pos + 1, std::memory_order_acq_rel,
						std::memory_order_relaxed)) {
						*item = std::move(s.item);
						s.seq.store(pos + CAP, std::memory_order_release);
						return true;
					}
					else {
						CPU_RELAX();
						continue;
					}
				}
				else if (dif < 0) {
					return false; // empty
				}
				else {
					CPU_RELAX();
					pos = head_.load(std::memory_order_relaxed);
				}
			}
		}
	};


	struct Options {
		std::string wal_path;
		std::string snap_path;
		uint64_t wal_sync_sec = 1;			   // 0 disables
		bool disable_wal = false;
		uint64_t snapshot_interval_sec = 3600; // 1h; 0 disables
		uint64_t snapshot_ops_threshold = 0;   // 0 disables
		int reactors = 4;					   // number of reactor threads
	};

	using Pri = uint16_t;
	enum class PopOrder { EAsc = 0, EDesc = 1 };

	template <bool WithPriority, int AutoExpiryThreshold = 0> class FlexibleQueueImpl {

		using RWLock = std::shared_mutex;
		using ReadLock = std::shared_lock<RWLock>;
		using WriteLock = std::unique_lock<RWLock>;

		struct Node : PriExtStorage<WithPriority> {
			uint64_t expiry;
			ByteSpan key;
			ByteSpan value;
		};
		using RmuFuncT = std::conditional_t<
			WithPriority, std::function<bool(const ByteSpan&, Pri&, uint64_t&, ByteSpan&, Pri&, uint64_t&, ByteSpan&)>,
			std::function<bool(const ByteSpan&, uint64_t&, ByteSpan&, uint64_t&, ByteSpan&)>>;

	public:
		struct PushResult {
			bool exists{ false };
			bool updated{ false };
		};
		struct QueueItem : Node {
			using Node::expiry;
			using Node::key;
			using Node::value;
			QueueItem() = default;
			QueueItem(const QueueItem& o) {
				if constexpr (WithPriority)
					this->priority = o.priority;
				this->expiry = o.expiry;
				this->key = bs_dup(o.key.data, o.key.size);
				this->value = bs_dup(o.value.data, o.value.size);
			}
			QueueItem& operator=(const QueueItem& o) {
				if (this != &o) {
					if constexpr (WithPriority)
						this->priority = o.priority;
					this->expiry = o.expiry;
					bs_free(this->key);
					this->key = bs_dup(o.key.data, o.key.size);
					bs_free(this->value);
					this->value = bs_dup(o.value.data, o.value.size);
				}
				return *this;
			}
			QueueItem(QueueItem&& o) noexcept {
				if constexpr (WithPriority)
					this->priority = o.priority;
				this->expiry = o.expiry;
				this->key = o.key;
				this->value = o.value;
				o.key = {};
				o.value = {};
			}
			QueueItem& operator=(QueueItem&& o) noexcept {
				if (this != &o) {
					if constexpr (WithPriority)
						this->priority = o.priority;
					this->expiry = o.expiry;
					bs_free(this->key);
					this->key = o.key;
					o.key = {};
					bs_free(this->value);
					this->value = o.value;
					o.value = {};
				}
				return *this;
			}
			~QueueItem() {
				bs_free(this->key);
				bs_free(this->value);
			}

		private:
			explicit QueueItem(Node& n) {
				if constexpr (WithPriority)
					this->priority = n.priority;
				this->expiry = n.expiry;
				this->key = n.key;
				n.key = {};
				this->value = n.value;
				n.value = {};
			}
		};

	private:
		struct Wal {
			int fd{ -1 };
			std::string path;
			std::string buf;
			size_t buf_limit = (1u << 20);

			explicit Wal(const std::string& p) : path(p) {
				if (!path.empty()) {
					fd = ::open(path.c_str(), O_CREAT | O_WRONLY | O_APPEND, 0644);
					buf.reserve(buf_limit);
				}
			}
			~Wal() {
				flush_();
				if (fd >= 0) {
					::fdatasync(fd);
					::close(fd);
				}
			}

			inline void sync() {
				flush_();
				if (fd >= 0)
					::fdatasync(fd);
			}
			inline bool roll_writer() {
				if (fd < 0) return false;
				flush_();
				::fdatasync(fd);
				off_t s = ::lseek(fd, 0, SEEK_END);
				uint64_t cut_lsn = s > 0 ? (uint64_t)s : 0;
				::close(fd);
				fd = -1;
				char suf[64];
				snprintf(suf, sizeof(suf), ".seg-%llu", cut_lsn);
				std::string archived = path + suf;
				::rename(path.c_str(), archived.c_str());
				fd = ::open(path.c_str(), O_CREAT | O_WRONLY | O_TRUNC, 0644);
				if (fd < 0) {
					::rename(archived.c_str(), path.c_str());
					fd = ::open(path.c_str(), O_CREAT | O_WRONLY | O_APPEND, 0644);
					return false;
				}
				int dfd = ::open(dir_of_(path).c_str(), O_DIRECTORY | O_RDONLY);
				if (dfd >= 0) {
					::fsync(dfd);
					::close(dfd);
				}
				return true;
			}

		private:
			inline void flush_() {
				if (fd >= 0 && !buf.empty()) {
					write_all_(fd, buf.data(), buf.size());
					buf.clear();
					if (buf.capacity() > buf_limit * 4)
						buf.shrink_to_fit();
				}
			}

		public:
			inline void append_push_from_node(const Node& n) {
				if (fd < 0)
					return;
				uint8_t tag = 'A';
				uint32_t klen = (uint32_t)n.key.size;
				uint32_t vlen = (uint32_t)n.value.size;

				const uint32_t hdr = 8 + 4 + 4; // expiry + klen + vlen
				const uint32_t pri = (WithPriority ? 2u : 0u);
				uint32_t payload = pri + hdr + klen + vlen;

				const size_t need = 1 + 4 + payload; // tag + payload_len + payload
				if (buf.size() + need > buf_limit)
					flush_();

				const size_t off = buf.size();
				buf.resize(off + need);
				char* w = &buf[off];
				size_t i = 0;

				std::memcpy(w + i, &tag, 1);
				i += 1;
				std::memcpy(w + i, &payload, 4);
				i += 4;
				if constexpr (WithPriority) {
					uint16_t pr = (uint16_t)n.priority;
					std::memcpy(w + i, &pr, 2);
					i += 2;
				}
				uint64_t ex = n.expiry;
				std::memcpy(w + i, &ex, 8);
				i += 8;
				std::memcpy(w + i, &klen, 4);
				i += 4;
				std::memcpy(w + i, &vlen, 4);
				i += 4;
				if (klen) {
					std::memcpy(w + i, n.key.data, klen);
					i += klen;
				}
				if (vlen) {
					std::memcpy(w + i, n.value.data, vlen);
					i += vlen;
				}
			}

			inline void append_remove_key(const ByteSpan key) {
				if (fd < 0)
					return;
				uint8_t tag = 'D';
				uint32_t n = 1;

				uint32_t payload = 4; // n
				payload += 4 + key.size;

				const size_t need = 1 + 4 + payload;
				if (buf.size() + need > buf_limit)
					flush_();

				const size_t off = buf.size();
				buf.resize(off + need);
				char* w = &buf[off];
				size_t i = 0;

				std::memcpy(w + i, &tag, 1);
				i += 1;
				std::memcpy(w + i, &payload, 4);
				i += 4;
				std::memcpy(w + i, &n, 4);
				i += 4;

				uint32_t klen = key.size;
				std::memcpy(w + i, &klen, 4);
				i += 4;
				if (klen) {
					std::memcpy(w + i, key.data, klen);
					i += klen;
				}
			}

			inline void append_pop_keys(const std::vector<QueueItem>& keys) {
				if (fd < 0 || keys.empty())
					return;
				uint8_t tag = 'D';
				uint32_t n = (uint32_t)keys.size();

				uint32_t payload = 4; // n
				for (const auto& k : keys)
					payload += 4 + k.key.size;

				const size_t need = 1 + 4 + payload;
				if (buf.size() + need > buf_limit)
					flush_();

				const size_t off = buf.size();
				buf.resize(off + need);
				char* w = &buf[off];
				size_t i = 0;

				std::memcpy(w + i, &tag, 1);
				i += 1;
				std::memcpy(w + i, &payload, 4);
				i += 4;
				std::memcpy(w + i, &n, 4);
				i += 4;

				for (const auto& k : keys) {
					uint32_t klen = k.key.size;
					std::memcpy(w + i, &klen, 4);
					i += 4;
					if (klen) {
						std::memcpy(w + i, k.key.data, klen);
						i += klen;
					}
				}
			}
		};

		struct PushOp : Node {
			std::promise<PushResult> done;
			RmuFuncT rmu;
			bool upsert;

			template <bool W = WithPriority, typename std::enable_if<W, int>::type = 0>
			PushOp(ByteSpan _key, RmuFuncT _rmu, bool _upsert, Pri _priority,
				uint64_t _expiry, ByteSpan _value) {
				this->key = _key;
				this->value = _value;
				this->priority = _priority;
				this->expiry = _expiry;
				this->rmu = std::move(_rmu);
				this->upsert = _upsert;
			}
			template <bool W = WithPriority, typename std::enable_if<!W, int>::type = 0>
			PushOp(ByteSpan _key, RmuFuncT _rmu, bool _upsert, uint64_t _expiry,
				ByteSpan _value) {
				this->key = _key;
				this->value = _value;
				this->expiry = _expiry;
				this->rmu = std::move(_rmu);
				this->upsert = _upsert;
			}
		};

		struct PopOp {
			std::promise<std::vector<QueueItem>> done;
			int N;
			PopOrder order;
		};

		struct RefreshOp {
			std::promise<std::vector<QueueItem>> done;
			uint64_t threshold;
			uint64_t expiry;
			int N;
		};

		struct RemoveOp {
			std::promise<bool> done;
			ByteSpan key;
		};

		struct CountOp : OpExtStorage<WithPriority> {
			std::promise<size_t> done;
		};

		struct SnapOp {
			std::promise<void> done;
		};

	public:

		explicit FlexibleQueueImpl(const Options& opt)
			: wal_(opt.wal_path), snap_path_(opt.snap_path),
			stop_(false), pending_work_(0), snapshot_child_pid_(0),
			wal_sync_sec_(opt.wal_sync_sec) {
			snap_tmp_path_ = snap_path_ + ".tmp";
			wal_disabled_ = opt.disable_wal;
			snapshot_interval_sec_ = opt.snapshot_interval_sec;
			snapshot_ops_threshold_ = opt.snapshot_ops_threshold;
			last_sync_sec_ = nowUnixSeconds_();
			last_snapshot_sec_ = nowUnixSeconds_();
			load_snapshot_();
			replay_wal_(wal_.path);
			for (int i = 0; i < std::max(1, opt.reactors); ++i) {
				reactors_.emplace_back([this] { loop_(); });
			}
		}

		~FlexibleQueueImpl() {
			{
				std::unique_lock<std::mutex> lk(fx_mu_);
				stop_ = true;
			}
			futex_wake(&pending_work_, INT_MAX);
			for (auto& t : reactors_)
				if (t.joinable())
					t.join();
			if (snapshot_child_pid_ > 0) {
				::waitpid(snapshot_child_pid_, NULL, 0);
				if (!wal_disabled_) {
					gc_all_wal_segments_(wal_.path);
				}
			}
			if (!wal_disabled_) {
				wal_.roll_writer();
				if (!snap_path_.empty()) write_snapshot_(dir_of_(snap_path_).c_str());
				gc_all_wal_segments_(wal_.path);
			}
			else {
				if (!snap_path_.empty()) write_snapshot_(dir_of_(snap_path_).c_str());
			}

			{
				WriteLock _g(rw_);
				if constexpr (WithPriority) {
					for (auto& kv : items_) {
						kv.second->clear();
						delete kv.second;
					}
				}
				items_.clear();
				while (!key_map_.empty()) {
					auto it = key_map_.begin();
					Node* x = it->second;
					key_map_.erase(it);
					bs_free(x->key);
					bs_free(x->value);
					free(x);
				}
			}
		}
		template <bool W = WithPriority, typename std::enable_if<W, int>::type = 0>
		PushResult push(ByteSpan key, Pri priority, uint64_t expiry,
			ByteSpan value = {}, RmuFuncT rmu = {}, bool upsert = true) {
			RmuFuncT effective =
				rmu ? std::move(rmu)
				: RmuFuncT([](const ByteSpan&, Pri& priority, uint64_t& expiry, ByteSpan& value, Pri& priority_new, uint64_t& expiry_new, ByteSpan& value_new) mutable {
				priority = priority_new;
				expiry = expiry_new;
				value = value_new;
				return true;
					});
			PushOp op{key, effective, upsert, priority,
				expiry, value};
			auto fut = op.done.get_future();
			enqueue_({ &op, KIND_PUSH });
			return fut.get();
		}
		template <bool W = WithPriority, typename std::enable_if<!W, int>::type = 0>
		PushResult push(ByteSpan key, uint64_t expiry, ByteSpan value = {},
			RmuFuncT rmu = {}, bool upsert = true) {
			RmuFuncT effective =
				rmu ? std::move(rmu)
				: RmuFuncT([](const ByteSpan&, uint64_t& expiry, ByteSpan& value, uint64_t& expiry_new, ByteSpan& value_new) mutable {
				expiry = expiry_new;
				value = value_new;
				return true;
					});
			PushOp op{key, effective, upsert, expiry, value};
			auto fut = op.done.get_future();
			enqueue_({ &op, KIND_PUSH });
			return fut.get();
		}
		std::vector<QueueItem> pop(int N = 1, PopOrder order = PopOrder::EAsc) {
			PopOp op{std::promise<std::vector<QueueItem>>(), N, order};
			auto fut = op.done.get_future();
			enqueue_({ &op, KIND_POP });
			return fut.get();
		}
		template <bool W = WithPriority, typename std::enable_if<!W, int>::type = 0>
		std::vector<QueueItem> refresh(uint64_t threshold, uint64_t expiry, int N = 1) {
			RefreshOp op{std::promise<std::vector<QueueItem>>(), threshold, expiry, N};
			auto fut = op.done.get_future();
			enqueue_({ &op, KIND_REFRESH });
			return fut.get();
		}
		bool remove(ByteSpan key) {
			RemoveOp op{std::promise<bool>(), key};
			auto fut = op.done.get_future();
			enqueue_({ &op, KIND_REMOVE });
			return fut.get();
		}
		template <bool W = WithPriority, typename std::enable_if<W, int>::type = 0>
		size_t count(int min_priority = -1, int max_priority = 65535) {
			if (min_priority > max_priority)
				std::swap(min_priority, max_priority);
			CountOp op{OpExtStorage<WithPriority>{ min_priority, max_priority }, std::promise<size_t>()};
			auto fut = op.done.get_future();
			enqueue_({ &op, KIND_COUNT });
			return fut.get();
		}
		template <bool W = WithPriority, typename std::enable_if<!W, int>::type = 0>
		size_t count() {
			CountOp op{OpExtStorage<WithPriority>{}, std::promise<size_t>()};
			auto fut = op.done.get_future();
			enqueue_({ &op, KIND_COUNT });
			return fut.get();
		}
		void snapshot_now() {
			SnapOp op{std::promise<void>()};
			auto fut = op.done.get_future();
			enqueue_({ &op, KIND_SNAPSHOT });
			fut.wait();
		}

	private:
		void enqueue_(Msg&& m) {
			q_.push(std::move(m));
			bool need_wake;
			{
				std::lock_guard<std::mutex> lk(fx_mu_);
				need_wake = (pending_work_ == 0);
				pending_work_ = 1;
			}
			if (need_wake)
				futex_wake(&pending_work_, 1);
		}

		void push_internal_(const Node& n, bool check = true, Node* current = nullptr) {
			ByteSpan value_owned = bs_dup(n.value.data, n.value.size);
			if (check) {
				auto _it = key_map_.find(n.key);
				if (_it != key_map_.end())
					current = _it->second;
			}
			if (!current) {
				ByteSpan key_owned = bs_dup(n.key.data, n.key.size);
				Node* x = (Node*)malloc(sizeof(Node));
				if constexpr (WithPriority) {
					x->priority = n.priority;
				}
				x->expiry = n.expiry;
				x->key = key_owned;
				x->value = value_owned;
				if constexpr (WithPriority) {
					auto it = items_.find(n.priority);
					if (it == items_.end()) {
						auto pr = items_.emplace(n.priority, new ItemSet());
						it = pr.first;
					}
					it->second->insert(x);
				}
				else {
					items_.insert(x);
				}
				key_map_.emplace(key_owned, x);
			}
			else {
				bs_free(current->value);
				if constexpr (WithPriority) {
					auto itb = items_.find(current->priority);
					if (itb != items_.end()) {
						auto S = itb->second;
						auto it = S->find(current);
						if (it != S->end())
							S->erase(it);
						if (S->empty()) {
							items_.erase(itb);
							delete S;
						}
					}
					current->priority = n.priority;
				}
				else {
					auto it = items_.find(current);
					if (it != items_.end())
						items_.erase(it);
				}
				current->expiry = n.expiry;
				current->value = value_owned;
				if constexpr (WithPriority) {
					auto it2 = items_.find(n.priority);
					if (it2 == items_.end()) {
						auto pr = items_.emplace(n.priority, new ItemSet());
						it2 = pr.first;
					}
					it2->second->insert(current);
				}
				else {
					items_.insert(current);
				}
			}
		}

		bool remove_internal_(ByteSpan key) {
			Node* _found = nullptr;
			{
				auto _it = key_map_.find(key);
				if (_it != key_map_.end())
					_found = _it->second;
			}
			if (!_found)
				return false;
			Node* x = _found;
			if constexpr (WithPriority) {
				auto itb = items_.find(x->priority);
				if (itb != items_.end()) {
					auto S = itb->second;
					auto it = S->find(x);
					if (it != S->end())
						S->erase(it);
					if (S->empty()) {
						items_.erase(itb);
						delete S;
					}
				}
			}
			else {
				auto it = items_.find(x);
				if (it != items_.end())
					items_.erase(it);
			}
			{
				auto __it = key_map_.find(x->key);
				if (__it != key_map_.end())
					key_map_.erase(__it);
			}
			bs_free(x->key);
			bs_free(x->value);
			free(x);
			return true;
		}

		std::vector<QueueItem> pop_internal_(int N, PopOrder order) {
			std::vector<QueueItem> out;
			out.reserve(N);
			if constexpr (WithPriority)
			{
				for (auto itb = items_.begin();
					itb != items_.end() && (int)out.size() < N;) {
					int p = itb->first;
					auto S = itb->second;
					if (order == PopOrder::EDesc) {
						while (!S->empty() && (int)out.size() < N) {
							auto it = std::prev(S->end());
							Node* x = *it;
							QueueItem oi;
							oi.priority = x->priority;
							oi.expiry = x->expiry;
							oi.key = bs_dup(x->key.data, x->key.size);
							oi.value = bs_dup(x->value.data, x->value.size);
							out.emplace_back(std::move(oi));
							S->erase(it);
							{
								auto __it = key_map_.find(x->key);
								if (__it != key_map_.end())
									key_map_.erase(__it);
							}
							bs_free(x->key);
							bs_free(x->value);
							free(x);
						}
					}
					else {
						while (!S->empty() && (int)out.size() < N) {
							auto it = S->begin();
							Node* x = *it;
							QueueItem oi;
							oi.priority = x->priority;
							oi.expiry = x->expiry;
							oi.key = bs_dup(x->key.data, x->key.size);
							oi.value = bs_dup(x->value.data, x->value.size);
							out.emplace_back(std::move(oi));
							S->erase(it);
							{
								auto __it = key_map_.find(x->key);
								if (__it != key_map_.end())
									key_map_.erase(__it);
							}
							bs_free(x->key);
							bs_free(x->value);
							free(x);
						}
					}
					if (S->empty()) {
						itb = items_.erase(itb);
						delete S;
					}
					else
						++itb;
				}
			}
			else {
				if (order == PopOrder::EDesc) {
					while (!items_.empty() && (int)out.size() < N) {
						auto it = std::prev(items_.end());
						Node* x = *it;
						QueueItem oi;
						oi.expiry = x->expiry;
						oi.key = bs_dup(x->key.data, x->key.size);
						oi.value = bs_dup(x->value.data, x->value.size);
						out.emplace_back(std::move(oi));
						items_.erase(it);
						{
							auto __it = key_map_.find(x->key);
							if (__it != key_map_.end())
								key_map_.erase(__it);
						}
						bs_free(x->key);
						bs_free(x->value);
						free(x);
					}
				}
				else {
					while (!items_.empty() && (int)out.size() < N) {
						auto it = items_.begin();
						Node* x = *it;
						QueueItem oi;
						oi.expiry = x->expiry;
						oi.key = bs_dup(x->key.data, x->key.size);
						oi.value = bs_dup(x->value.data, x->value.size);
						out.emplace_back(std::move(oi));
						items_.erase(it);
						{
							auto __it = key_map_.find(x->key);
							if (__it != key_map_.end())
								key_map_.erase(__it);
						}
						bs_free(x->key);
						bs_free(x->value);
						free(x);
					}
				}
			}
			if (!wal_disabled_ && !out.empty())
				wal_.append_pop_keys(out);
			return out;
		}

		template <bool W = WithPriority, typename std::enable_if<!W, int>::type = 0>
		std::vector<QueueItem> refresh_internal_(uint64_t now_sec, uint64_t threshold, uint64_t expiry, int N) {
			std::vector<QueueItem> out;
			out.reserve(N);
			while (!items_.empty() && (int)out.size() < N) {
				auto it = items_.begin();
				Node* x = *it;
				if constexpr (AutoExpiryThreshold >= 0) {
					if (x->expiry <= now_sec) {
						items_.erase(it);
						{
							auto __it = key_map_.find(x->key);
							if (__it != key_map_.end())
								key_map_.erase(__it);
						}
						bs_free(x->key);
						bs_free(x->value);
						free(x);
						continue;
					}
				}
				if (x->expiry <= threshold) {
					items_.erase(it);
					x->expiry = expiry;
					items_.insert(x);
					if (!wal_disabled_) {
						wal_.append_push_from_node(*x);
					}
					QueueItem oi;
					oi.expiry = x->expiry;
					oi.key = bs_dup(x->key.data, x->key.size);
					oi.value = bs_dup(x->value.data, x->value.size);
					out.emplace_back(std::move(oi));
				}
				else
					break;
			}
			return out;
		}

		template <class F> void for_each_live_(F&& f) {
			if constexpr (WithPriority) {
				for (auto& kv : items_) {
					auto S = kv.second;
					for (auto it = S->begin(); it != S->end(); ++it)
						f(*it);
				}
			}
			else {
				for (auto it = items_.begin(); it != items_.end(); ++it)
					f(*it);
			}
		}

		void loop_() {
			while (true) {
				{   // wait for work or stop
					std::unique_lock<std::mutex> lk(fx_mu_);
					while (!stop_ && pending_work_ == 0) {
						lk.unlock();
						futex_wait(&pending_work_, 0);
						lk.lock();
					}
					if (stop_ && pending_work_ == 0)
						break;
					pending_work_ = 0;
				}
				uint64_t now_sec = nowUnixSeconds_();
				size_t processed = 0;
				Msg m;
				while (q_.pop(&m)) {
					std::shared_lock<std::shared_mutex> __r(rw_, std::defer_lock);
					std::unique_lock<std::shared_mutex> __w(rw_, std::defer_lock);
					if (m.kind == KIND_COUNT) {
						__r.lock();
					}
					else {
						__w.lock();
					}

					if (m.kind == KIND_PUSH) {
						auto* op = static_cast<PushOp*>(m.ptr);
						Node* _found = nullptr;
						Node n;
						n.key = op->key;
						{
							auto _it = key_map_.find(n.key);
							if (_it != key_map_.end()) {
								if constexpr (WithPriority) {
									if (_it->second->priority > AutoExpiryThreshold || _it->second->expiry > now_sec) {
										_found = _it->second;
									}
									else {
										Node* x = _it->second;
										auto itb = items_.find(x->priority);
										if (itb != items_.end()) {
											auto S = itb->second;
											auto it = S->find(x);
											if (it != S->end())
												S->erase(it);
											if (S->empty()) {
												items_.erase(itb);
												delete S;
											}
										}
										key_map_.erase(_it);
										bs_free(x->key);
										bs_free(x->value);
										free(x);
									}
								}
								else if constexpr (AutoExpiryThreshold >= 0) {
									if (_it->second->expiry > now_sec) {
										_found = _it->second;
									}
									else {
										Node* x = _it->second;
										auto it = items_.find(x);
										if (it != items_.end())
											items_.erase(it);
										key_map_.erase(_it);
										bs_free(x->key);
										bs_free(x->value);
										free(x);
									}
								}
								else {
									_found = _it->second;
								}
							}
						}
						PushResult res{};
						res.exists = (_found != nullptr);
						res.updated = !res.exists;
						if (res.exists) {
							if (op->upsert) {
								if constexpr (WithPriority)
									n.priority = _found->priority;
								n.expiry = _found->expiry;
								n.value = _found->value;
								res.updated = ([&] {
									if constexpr (WithPriority)
										return op->rmu ? op->rmu(n.key, n.priority, n.expiry, n.value, op->priority, op->expiry, op->value) : false;
									else
										return op->rmu ? op->rmu(n.key, n.expiry, n.value, op->expiry, op->value) : false;
									})();
							}
						}
						else {
							if constexpr (WithPriority)
								n.priority = op->priority;
							n.expiry = op->expiry;
							n.value = op->value;
						}
						if (res.updated) {
							if (!wal_disabled_) {
								wal_.append_push_from_node(n);
							}
							push_internal_(n, false, _found);
						}
						op->done.set_value(std::move(res));
						processed++;
					}
					else if (m.kind == KIND_POP) {
						auto* op = static_cast<PopOp*>(m.ptr);
						clear_expired_items_(now_sec);
						auto items = pop_internal_(op->N, op->order);
						op->done.set_value(std::move(items));
						processed += (int)items.size();

					}
					else if (m.kind == KIND_REFRESH) {
						if constexpr (!WithPriority) {
							auto* op = static_cast<RefreshOp*>(m.ptr);
							if (op->expiry > op->threshold) {
								auto items = refresh_internal_(now_sec, op->threshold, op->expiry, op->N);
								op->done.set_value(std::move(items));
								processed++;
							}
							else {
								op->done.set_value({});
							}
						}
					}
					else if (m.kind == KIND_COUNT) {
						auto* op = static_cast<CountOp*>(m.ptr);
						size_t total = 0;
						if constexpr (WithPriority) {
							int lo = op->minp, hi = op->maxp;
							if (lo > hi)
								std::swap(lo, hi);
							for (auto& kv : items_) {
								int p = kv.first;
								if (p > hi)
									continue; // too high, skip
								if (p < lo)
									break; // too low (and all further will be lower), stop
								total += kv.second->size();
							}
						}
						else {
							total = items_.size();
						}
						op->done.set_value(total);
					}
					else if (m.kind == KIND_REMOVE) {
						auto* op = static_cast<RemoveOp*>(m.ptr);
						bool found = remove_internal_(op->key);
						if (!wal_disabled_ && found)
							wal_.append_remove_key(op->key);
						op->done.set_value(found);
						processed++;
					}
					else if (m.kind == KIND_SNAPSHOT) {
						auto* op = static_cast<SnapOp*>(m.ptr);
						spawn_snapshot_writer_(now_sec);
						op->done.set_value();
						processed = 0;
					}
				}
				{
					std::unique_lock<std::shared_mutex> lk(rw_);
					if (!processed)
						clear_expired_items_(now_sec);
					else
						ops_since_last_snapshot_ += processed;

					reap_snapshot_writer_(now_sec);
					bool time_due = (snapshot_interval_sec_ &&
						((now_sec - last_snapshot_sec_) >= snapshot_interval_sec_));
					bool ops_due = (snapshot_ops_threshold_ &&
						(ops_since_last_snapshot_ >= snapshot_ops_threshold_));

					if (time_due || ops_due) {
						spawn_snapshot_writer_(now_sec);
						processed = 0;
					}

					if (!wal_disabled_ && processed) {
						if (wal_sync_sec_ && ((now_sec - last_sync_sec_) >= wal_sync_sec_)) {
							wal_.sync();
							last_sync_sec_ = now_sec;
						}
					}
				}
			}
		}
		void clear_expired_items_(uint64_t now_sec) {
			if constexpr (WithPriority)
			{
				for (auto itb = items_.begin();
					itb != items_.end();) {
					int p = itb->first;
					auto S = itb->second;
					if (p > AutoExpiryThreshold) { ++itb; continue; }
					while (!S->empty()) {
						auto it = S->begin();
						Node* x = *it;
						if (x->expiry > now_sec) break;
						S->erase(it);
						{
							auto __it = key_map_.find(x->key);
							if (__it != key_map_.end())
								key_map_.erase(__it);
						}
						bs_free(x->key);
						bs_free(x->value);
						free(x);
					}
					if (S->empty()) {
						itb = items_.erase(itb);
						delete S;
					}
					else
						++itb;
				}
			}
			else if constexpr (AutoExpiryThreshold >= 0) {
				while (!items_.empty()) {
					auto it = items_.begin();
					Node* x = *it;
					if (x->expiry > now_sec) break;
					items_.erase(it);
					{
						auto __it = key_map_.find(x->key);
						if (__it != key_map_.end())
							key_map_.erase(__it);
					}
					bs_free(x->key);
					bs_free(x->value);
					free(x);
				}
			}
		}
		void spawn_snapshot_writer_(uint64_t now_sec) {
			if (snapshot_child_pid_ == 0) {
				if (!wal_disabled_) {
					if (!wal_.roll_writer()) {
						if (!snap_path_.empty()) write_snapshot_(dir_of_(snap_path_).c_str());
						gc_all_wal_segments_(wal_.path);
						ops_since_last_snapshot_ = 0;
						last_snapshot_sec_ = now_sec;
						return;
					}
				}
				if (!snap_path_.empty()) {
					std::string snap_path_dir = dir_of_(snap_path_);
					pid_t pid = ::fork();
					if (pid == 0) {
						write_snapshot_(snap_path_dir.c_str());
						::_exit(0);
					}
					else if (pid > 0) {
						snapshot_child_pid_ = pid;
					}
				}
			}
		}
		void reap_snapshot_writer_(uint64_t now_sec) {
			if (snapshot_child_pid_ > 0) {
				int status = 0;
				pid_t res = ::waitpid(snapshot_child_pid_, &status, WNOHANG);
				if (res == snapshot_child_pid_) {
					if (WIFEXITED(status) && WEXITSTATUS(status) == 0) {
						if (!wal_disabled_) {
							gc_all_wal_segments_(wal_.path);
						}
						ops_since_last_snapshot_ = 0;
						last_snapshot_sec_ = now_sec;
					}
					snapshot_child_pid_ = 0;
				}
			}
		}
		static std::vector<std::string> list_wal_segments_sorted_(const std::string& wal_path) {
			std::vector<std::string> out;
			if (wal_path.empty()) return out;

			const auto slash = wal_path.find_last_of('/');
			const std::string dir = (slash == std::string::npos) ? "." : wal_path.substr(0, slash);
			const std::string base = (slash == std::string::npos) ? wal_path : wal_path.substr(slash + 1);
			const std::string prefix = base + ".seg-";

			DIR* d = ::opendir(dir.c_str());
			if (!d) return out;

			while (auto* de = ::readdir(d)) {
				const char* name = de->d_name;
				if (!name || name[0] == '.') continue;
				if (std::strncmp(name, prefix.c_str(), prefix.size()) == 0) {
					out.push_back(dir + "/" + name);
				}
			}
			::closedir(d);
			auto seq = [](std::string const& s)->unsigned long long {
				auto p = s.rfind(".seg-");
				return (p == std::string::npos) ? 0ULL : std::strtoull(s.c_str() + p + 5, nullptr, 10);
				};
			std::sort(out.begin(), out.end(), [&](auto const& a, auto const& b) {
				return seq(a) < seq(b);
				});
			return out;
		}
		static void gc_all_wal_segments_(const std::string& wal_path) {
			auto segs = list_wal_segments_sorted_(wal_path);
			for (const auto& p : segs) {
				::unlink(p.c_str());
			}
			int dfd = ::open(dir_of_(wal_path).c_str(), O_DIRECTORY | O_RDONLY);
			if (dfd >= 0) {
				::fsync(dfd);
				::close(dfd);
			}
		}
		void replay_wal_(const std::string& path) {
			if (path.empty())
				return;

			for (const auto& seg : list_wal_segments_sorted_(path)) {
				replay_one_wal_file_(seg.c_str());
			}
			replay_one_wal_file_(path.c_str());
		}
		void replay_one_wal_file_(const char* cpath) {
			int rfd = ::open(cpath, O_RDONLY);
			if (rfd >= 0)
				::posix_fadvise(rfd, 0, 0, POSIX_FADV_SEQUENTIAL);
			if (rfd < 0)
				return;
			while (true) {
				uint8_t tag;
				if (!read_all_(rfd, &tag, 1))
					break;
				uint32_t len = 0;
				if (!read_all_(rfd, &len, 4))
					break;
				std::string buf(len, '\0');
				if (!read_all_(rfd, &buf[0], len))
					break;
				const char* rd = buf.data();
				size_t pos = 0;
				auto need = [&](size_t k) { return pos + k <= buf.size(); };
				if (tag == 'A') {
					if constexpr (WithPriority) {
						if (!need(2 + 8 + 4 + 4))
							break;
					}
					else {
						if (!need(8 + 4 + 4))
							break;
					}
					uint16_t priority = 0;
					if constexpr (WithPriority) {
						std::memcpy(&priority, rd + pos, 2);
						pos += 2;
					}
					uint64_t expiry;
					std::memcpy(&expiry, rd + pos, 8);
					pos += 8;
					uint32_t klen;
					std::memcpy(&klen, rd + pos, 4);
					pos += 4;
					uint32_t vlen;
					std::memcpy(&vlen, rd + pos, 4);
					pos += 4;
					if (!need(klen + vlen))
						break;
					std::string key(klen, '\0');
					if (klen)
						std::memcpy(&key[0], rd + pos, klen);
					pos += klen;
					std::string val(vlen, '\0');
					if (vlen)
						std::memcpy(&val[0], rd + pos, vlen);
					pos += vlen;
					Node n;
					if constexpr (WithPriority) {
						n.priority = priority;
					}
					n.expiry = expiry;
					n.key = ByteSpan(key.data(), (uint32_t)key.size());
					n.value = ByteSpan(val.data(), (uint32_t)val.size());
					push_internal_(n);
				}
				else if (tag == 'D') {
					if (!need(4))
						break;
					uint32_t n;
					std::memcpy(&n, rd + pos, 4);
					pos += 4;
					for (uint32_t i = 0; i < n; i++) {
						if (!need(4))
							break;
						uint32_t klen;
						std::memcpy(&klen, rd + pos, 4);
						pos += 4;
						if (!need(klen))
							break;
						std::string key(klen, '\0');
						if (klen)
							std::memcpy(&key[0], rd + pos, klen);
						pos += klen;
						remove_internal_(ByteSpan(key.data(), (uint32_t)key.size()));
					}
				}
				else
					break;
			}
			::close(rfd);
		}

		void write_snapshot_(const char* path_dir) {
			int fd = ::open(snap_tmp_path_.c_str(), O_CREAT | O_TRUNC | O_WRONLY, 0644);
			if (fd < 0)
				return;

			// placeholder count; patched after streaming out records
			uint64_t count_placeholder = 0;
			(void)write_all_(fd, &count_placeholder, 8);

			uint64_t real = 0;
			for_each_live_([&](Node* it) {
				const uint32_t klen = (uint32_t)it->key.size;
				const uint32_t vlen = (uint32_t)it->value.size;

				// small header, single advancing cursor
				char hdr[2 + 8 + 4 + 4];
				size_t i = 0;
				if constexpr (WithPriority) {
					const uint16_t pr = it->priority;
					std::memcpy(hdr + i, &pr, 2);
					i += 2;
				}
				std::memcpy(hdr + i, &it->expiry, 8);
				i += 8;
				std::memcpy(hdr + i, &klen, 4);
				i += 4;
				std::memcpy(hdr + i, &vlen, 4);
				i += 4;

				(void)write_all_(fd, hdr, i);
				if (klen)
					(void)write_all_(fd, it->key.data, klen);
				if (vlen)
					(void)write_all_(fd, it->value.data, vlen);
				++real;
				});

			// patch count, then commit atomically
			::lseek(fd, 0, SEEK_SET);
			(void)write_all_(fd, &real, 8);
			::fdatasync(fd);
			::close(fd);
			::rename(snap_tmp_path_.c_str(), snap_path_.c_str());
			int dfd = ::open(path_dir, O_DIRECTORY | O_RDONLY);
			if (dfd >= 0) {
				::fsync(dfd);
				::close(dfd);
			}
		}

		void load_snapshot_() {
			if (snap_path_.empty())
				return;

			int fd = ::open(snap_path_.c_str(), O_RDONLY);
			if (fd >= 0)
				::posix_fadvise(fd, 0, 0, POSIX_FADV_SEQUENTIAL);
			if (fd < 0)
				return;

			uint64_t count = 0;
			if (!read_all_(fd, &count, 8)) {
				::close(fd);
				return;
			}

			for (uint64_t idx = 0; idx < count; ++idx) {
				uint16_t priority = 0;
				uint64_t expiry = 0;
				uint32_t klen = 0, vlen = 0;

				if constexpr (WithPriority) {
					if (!read_all_(fd, &priority, 2))
						break;
				}
				if (!read_all_(fd, &expiry, 8))
					break;
				if (!read_all_(fd, &klen, 4))
					break;
				if (!read_all_(fd, &vlen, 4))
					break;

				std::string key(klen, '\0');
				if (klen && !read_all_(fd, key.data(), klen))
					break;
				std::string val(vlen, '\0');
				if (vlen && !read_all_(fd, val.data(), vlen))
					break;

				Node n;
				if constexpr (WithPriority) {
					n.priority = priority;
				}
				n.expiry = expiry;
				n.key = ByteSpan(key.data(), (uint32_t)key.size());
				n.value = ByteSpan(val.data(), (uint32_t)val.size());
				push_internal_(n);
			}
			::close(fd);
		}
		static uint64_t nowUnixSeconds_() {
			using namespace std::chrono;
			return (uint64_t)std::chrono::duration_cast<std::chrono::seconds>(
				std::chrono::system_clock::now().time_since_epoch())
				.count();
		}

		struct EPtrLess {
			bool operator()(const Node* a, const Node* b) const {
				if (a->expiry < b->expiry)
					return true;
				if (a->expiry > b->expiry)
					return false;
				return a < b;
			}
		};
		using ItemSet = absl::btree_multiset<Node*, EPtrLess>;
		using ItemSetMap = absl::btree_map<Pri, ItemSet*, std::greater<Pri>>;
		using KeyMap = absl::btree_map<ByteSpan, Node*, ByteSpanLess>;

		std::conditional_t<WithPriority, ItemSetMap, ItemSet> items_;
		KeyMap key_map_;

		Wal wal_;
		bool wal_disabled_;
		std::string snap_path_;
		std::string snap_tmp_path_;
		uint64_t wal_sync_sec_;
		uint64_t snapshot_interval_sec_;
		uint64_t snapshot_ops_threshold_;
		size_t ops_since_last_snapshot_;
		uint64_t last_sync_sec_;
		uint64_t last_snapshot_sec_;
		pid_t snapshot_child_pid_;

		mutable RWLock rw_;

		bool stop_;
		Queue<Msg, MAX_CAP> q_;
		std::vector<std::thread> reactors_;
		std::mutex fx_mu_;
		alignas(64) int pending_work_;
	};
}
