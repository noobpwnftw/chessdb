#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

extern "C" {
#include "php.h"
}
#include "php_cboard.h"

#include "cboard_arginfo.h"

zend_function_entry cboard_functions[] = {
	PHP_FE(cbgetfen, arginfo_cbgetfen)
	PHP_FE(cbmovegen, arginfo_cbmovegen)
	PHP_FE(cbmovemake, arginfo_cbmovemake)
	PHP_FE(cbmovesan, arginfo_cbmovesan)

	PHP_FE(cbgetBWfen, arginfo_cbgetBWfen)
	PHP_FE(cbgetBWmove, arginfo_cbgetBWmove)

	PHP_FE(cbincheck, arginfo_cbincheck)

	PHP_FE(cbfen2hexfen, arginfo_cbfen2hexfen)
	PHP_FE(cbhexfen2fen, arginfo_cbhexfen2fen)

	{NULL, NULL, NULL}
};

zend_module_entry cboard_module_entry = {
#if ZEND_MODULE_API_NO >= 20010901
	STANDARD_MODULE_HEADER,
#endif
	"cboard",
	cboard_functions,
	PHP_MINIT(cboard),
	NULL,
	NULL,
	NULL,
	NULL,
#if ZEND_MODULE_API_NO >= 20010901
	"0.1",
#endif
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_CBOARD
extern "C" {
ZEND_GET_MODULE(cboard)
}
#endif

#include <string>
#include <vector>
#include <cctype>
#include <algorithm>
#include <bitset>

#include "chess.hpp"   // Disservin's header-only library

using namespace chess;

// -------------------- utils --------------------
static inline uint8_t files_mask(Bitboard bb) {
	uint8_t m = 0;
	while (bb) m |= static_cast<uint8_t>(1u << Square(bb.pop()).file());
	return m;
}
constexpr inline bool is_file_tok(char c) {
	return (c >= 'A' && c <= 'H') || (c >= 'a' && c <= 'h');
}
constexpr inline bool is_fen_tok(char c) {
	return (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || c == '/' || c == '-';
}
constexpr inline bool is_sep_tok(char c) {
	return c == ' ' || c == '\t' || c == '\n' || c == '\r' || c == '_' || c == '+';
}
static std::vector<std::string> split_ws(const std::string& s) {
	std::vector<std::string> out; std::string cur; cur.reserve(s.size());
	for (char c : s) {
		if (is_sep_tok(c)) {
			if (!cur.empty()) { out.push_back(cur); cur.clear(); }
		}
		else if (is_fen_tok(c)) { cur.push_back(c); }
	}
	if (!cur.empty()) out.push_back(cur);
	return out;
}
static inline bool aligned3(Square a, Square k, Square b) {
	// same file or rank
	if (a.file() == k.file() && k.file() == b.file()) return true;
	if (a.rank() == k.rank() && k.rank() == b.rank()) return true;
	// same diagonal / anti-diagonal
	if (a.diagonal_of() == k.diagonal_of() && k.diagonal_of() == b.diagonal_of()) return true;
	if (a.antidiagonal_of() == k.antidiagonal_of() && k.antidiagonal_of() == b.antidiagonal_of()) return true;
	return false;
}
// -------------------- sanitizer --------------------
// Returns empty err on success; may normalize EP to "-"; returns normalized fen via reference.
static std::string sanitize_xfen(std::string& fen, bool& implied960) {
	auto f = split_ws(fen);
	if (f.size() < 2) return "FEN must have at least 2 fields";

	// 1) active color
	const std::string& active = f[1];
	if (!(active == "w" || active == "b")) return "Active color must be 'w' or 'b'";

	// 2) piece placement
	const std::string& board = f[0];
	int rank = 7, file = 0;

	// Build bitboards directly during parsing
	Bitboard WP = 0, WN = 0, WB = 0, WR = 0, WQ = 0, WK = 0;
	Bitboard BP = 0, BN = 0, BB = 0, BR = 0, BQ = 0, BK = 0;
	auto put = [&](char c)->const char* {
		const int sq_idx = rank * 8 + file;
		switch (c) {
		case 'K': WK.set(sq_idx); break;
		case 'Q': WQ.set(sq_idx); break;
		case 'R': WR.set(sq_idx); break;
		case 'B': WB.set(sq_idx); break;
		case 'N': WN.set(sq_idx); break;
		case 'P': WP.set(sq_idx); break;

		case 'k': BK.set(sq_idx); break;
		case 'q': BQ.set(sq_idx); break;
		case 'r': BR.set(sq_idx); break;
		case 'b': BB.set(sq_idx); break;
		case 'n': BN.set(sq_idx); break;
		case 'p': BP.set(sq_idx); break;

		default:
			return "Illegal piece char in placement";
		}
		++file;
		return nullptr;
		};

	for (size_t i = 0; i < board.size(); ++i) {
		char c = board[i];
		if (c == '/') {
			if (file != 8) return "Each rank must sum to 8";
			if (--rank < 0) return "Too many ranks";
			file = 0; continue;
		}
		if (std::isdigit((unsigned char)c)) {
			int n = c - '0';
			if (n < 1 || n > 8) return "Digit in placement must be 1..8";
			if (file + n > 8)   return "Rank overflows 8 files";
			file += n; continue;
		}
		if (file >= 8) return "Too many files in a rank";

		if (const char* err = put(c)) return err;
	}
	if (rank != 0 || file != 8) return "Placement must be 8 ranks of 8 files";

	if ((WP | BP) & (Bitboard(Rank::RANK_1) | Bitboard(Rank::RANK_8))) return "Pawns cannot be on rank 1/8";
	if (WK.count() != 1 || BK.count() != 1) return "Must have exactly one king per side";
	if (WP.count() > 8 || BP.count() > 8) return "Too many pawns";
	if (WQ.count() > 9 || BQ.count() > 9) return "Too many queens";
	if (WR.count() > 10 || BR.count() > 10) return "Too many rooks";
	if (WB.count() > 10 || BB.count() > 10) return "Too many bishops";
	if (WN.count() > 10 || BN.count() > 10) return "Too many knights";

	const Bitboard WHITE = WP | WN | WB | WR | WQ | WK;
	const Bitboard BLACK = BP | BN | BB | BR | BQ | BK;
	const Bitboard OCC = WHITE | BLACK;

	if (WHITE.count() > 16 || BLACK.count() > 16) return "Too many pieces";
	const int w_promos = std::max(WQ.count() - 1, 0) + std::max(WR.count() - 2, 0) + std::max((WB & 0x55AA55AA55AA55AAULL).count() - 1, 0) + std::max((WB & 0xAA55AA55AA55AA55ULL).count() - 1, 0) + std::max(WN.count() - 2, 0);
	const int b_promos = std::max(BQ.count() - 1, 0) + std::max(BR.count() - 2, 0) + std::max((BB & 0x55AA55AA55AA55AAULL).count() - 1, 0) + std::max((BB & 0xAA55AA55AA55AA55ULL).count() - 1, 0) + std::max(BN.count() - 2, 0);
	if ((w_promos > (8 - WP.count())) || (b_promos > (8 - BP.count()))) return "Promotions exceed missing pawns";

	// 3) Tactical impossibilities

	const Square wk_sq = WK.lsb();
	const Square bk_sq = BK.lsb();

	// Kings cannot be adjacent
	if (attacks::king(wk_sq) & BK) return "Kings cannot be adjacent";

	auto p_on_king = [&](Square ksq, bool by_white) -> Bitboard {
		return attacks::pawn(by_white ? Color::BLACK : Color::WHITE, ksq) & (by_white ? WP : BP);
		};
	const Bitboard p_w_checks = p_on_king(wk_sq, false);
	const Bitboard p_b_checks = p_on_king(bk_sq, true);

	auto n_on_king = [&](Square ksq, bool by_white) -> Bitboard {
		return attacks::knight(ksq) & (by_white ? WN : BN);
		};
	const Bitboard n_w_checks = n_on_king(wk_sq, false);
	const Bitboard n_b_checks = n_on_king(bk_sq, true);

	if ((p_w_checks | n_w_checks).count() > 1 || (p_b_checks | n_b_checks).count() > 1) return "Double checks cannot be from non-sliders";

	auto bq_diag_on_king = [&](Square ksq, bool by_white) -> Bitboard {
		return attacks::bishop(ksq, OCC) & (by_white ? (WB | WQ) : (BB | BQ));
		};
	const Bitboard bq_diag_w_checks = bq_diag_on_king(wk_sq, false);
	const Bitboard bq_diag_b_checks = bq_diag_on_king(bk_sq, true);

	if ((p_w_checks | bq_diag_w_checks).count() > 1 || (p_b_checks | bq_diag_b_checks).count() > 1) return "Double diagonal check is impossible";

	auto rq_orth_on_king = [&](Square ksq, bool by_white) -> Bitboard {
		return attacks::rook(ksq, OCC) & (by_white ? (WR | WQ) : (BR | BQ));
		};
	const Bitboard rq_orth_w_checks = rq_orth_on_king(wk_sq, false);
	const Bitboard rq_orth_b_checks = rq_orth_on_king(bk_sq, true);
	if ((rq_orth_w_checks.count() > 1
			&& (BP.count() > 7															// promoted
				|| WHITE.count() > 15													// captured
				|| !(attacks::king(wk_sq) & rq_orth_w_checks & Bitboard(Rank::RANK_1))	// adjacent to king
				))
		|| (rq_orth_b_checks.count() > 1
			&& (WP.count() > 7
				|| BLACK.count() > 15
				|| !(attacks::king(bk_sq) & rq_orth_b_checks & Bitboard(Rank::RANK_8))
				))
		) return "Double orthogonal check cannot be from a non-promotion capture";

	const Bitboard w_checks = p_w_checks | n_w_checks | bq_diag_w_checks | rq_orth_w_checks;
	const Bitboard b_checks = p_b_checks | n_b_checks | bq_diag_b_checks | rq_orth_b_checks;

	const int w_total = w_checks.count();
	const int b_total = b_checks.count();

	if ((active == "w" && b_total > 0) || (active == "b" && w_total > 0)) return "Side not to move cannot be in check";
	if (w_total > 2 || b_total > 2) return "More than double check is impossible";

	auto aligned_checks = [&](Bitboard bb, Square ksq) -> bool {
		if (bb.count() == 2) {
			Bitboard t = bb;
			const Square sq_a = t.pop();
			const Square sq_b = t.pop();
			if (aligned3(sq_a, ksq, sq_b))
				return true;
		}
		return false;
		};
	if (aligned_checks(w_checks, wk_sq) || aligned_checks(b_checks, bk_sq)) return "Discovered checks cannot be aligned";

	// 4) castling: validate, infer 960 if needed, normalize preferring KQkq,
	// but use letter tokens when ambiguity requires them.
	if (f.size() > 2) {
		std::string& cr_ref = f[2];

		if (cr_ref != "-") {
			// Step 1: filter allowed tokens & dedup
			std::string src = cr_ref;
			std::string cr_filt; cr_filt.reserve(src.size());
			std::bitset<256> seen;
			for (unsigned char c0 : src) {
				bool ok = (c0 == 'K' || c0 == 'Q' || c0 == 'k' || c0 == 'q' || is_file_tok(c0));
				if (ok && !seen[c0]) { cr_filt.push_back(char(c0)); seen[c0] = true; }
			}
			cr_ref = cr_filt.empty() ? std::string("-") : cr_filt;
		}

		if (cr_ref != "-") {
			const int wf = wk_sq.rank() == Rank::RANK_1 ? (int)wk_sq.file() : -1;
			const int bf = bk_sq.rank() == Rank::RANK_8 ? (int)bk_sq.file() : -1;

			const uint8_t wHomeRooks = files_mask(WR & Bitboard(Rank::RANK_1));
			const uint8_t bHomeRooks = files_mask(BR & Bitboard(Rank::RANK_8));

			// Step 2: validate letter tokens against actual home-rank rooks
			{
				std::string kept; kept.reserve(cr_ref.size());
				for (char c : cr_ref) {
					if (is_file_tok(c)) {
						const unsigned char cu = static_cast<unsigned char>(c);
						const int fidx = std::tolower(cu) - 'a';
						const bool white = std::isupper(cu);
						const uint8_t mask = white ? wHomeRooks : bHomeRooks;

						bool ok = (fidx >= 0 && fidx < 8) && (mask & (1u << fidx));
						if (ok) kept.push_back(c);
					}
					else {
						kept.push_back(c);
					}
				}
				cr_ref = kept.empty() ? std::string("-") : kept;
			}
			if (cr_ref != "-") {
				// Step 3: parse requested sides & specific letters from input
				const bool wantWK = cr_ref.find('K') != std::string::npos;
				const bool wantWQ = cr_ref.find('Q') != std::string::npos;
				const bool wantBK = cr_ref.find('k') != std::string::npos;
				const bool wantBQ = cr_ref.find('q') != std::string::npos;
				uint8_t reqWMask = 0, reqBMask = 0; // bit f set iff file f requested
				for (char c : cr_ref) if (is_file_tok(c)) {
					const unsigned char cu = static_cast<unsigned char>(c);
					const int fidx = std::tolower(cu) - 'a';
					if (fidx >= 0 && fidx < 8) {
						if (std::isupper(cu))	reqWMask |= (1u << fidx);
						else					reqBMask |= (1u << fidx);
					}
				}

				// Step 4: collect candidate rook files per side (relative to king)

				auto candidates_side = [&](bool white, bool kingSide) -> std::vector<int> {
					std::vector<int> out;
					const int kf = white ? wf : bf;
					const uint8_t mask = white ? wHomeRooks : bHomeRooks;
					if (kf < 0) return out;
					if (kingSide) {
						for (int f = 7; f > kf; --f) if (mask & (1u << f)) out.push_back(f);
					}
					else {
						for (int f = 0; f < kf; ++f) if (mask & (1u << f)) out.push_back(f);
					}
					// already naturally ordered
					return out;
					};

				std::string wLtrs, bLtrs;
				// cand: sorted ascending files for that side;
				auto emit_side = [&](bool white, bool kingSide,
					bool sideRequested,									// 'K'/'Q' (or 'k'/'q') present?
					uint8_t reqMask) -> bool							// requested files for this color (letters only)
					{
						auto cand = candidates_side(white, kingSide);
						if (!sideRequested && !reqMask) return true;	// nothing requested for this wing
						if (cand.empty()) return true;					// no rook on this wing

						auto& out = white ? wLtrs : bLtrs;
						bool found = false;
						for (int f : cand) {
							if (f == cand.front()) {					// outermost, A/H implies KQ/kq
								if (sideRequested || (reqMask & (1u << f))) {
									out.push_back(white
										? (kingSide ? 'K' : 'Q')
										: (kingSide ? 'k' : 'q')
									);
									found = true;
								}
								continue;
							}
							if (!(reqMask & (1u << f))) continue;		// keep only specifically requested files if any
							if (f == 0 || f == 7) continue;				// emit letters only for B..G/b..g
							if (!found) {
								out.push_back(white ? char('A' + f) : char('a' + f));
								found = true;
							}
							else
								return false;
						}
						return true;
					};
				// Step 5: build normalized CR
				if (!emit_side(true, true, wantWK, reqWMask)
					|| !emit_side(true, false, wantWQ, reqWMask)
					|| !emit_side(false, true, wantBK, reqBMask)
					|| !emit_side(false, false, wantBQ, reqBMask)
					) return "Too many castling rights";

				if (wLtrs.empty() && bLtrs.empty()) {
					cr_ref = "-";
				}
				else {
					std::string norm; norm.reserve(4);
					norm += wLtrs; // K,Q,B..G
					norm += bLtrs; // k,q,b..g
					cr_ref.swap(norm);
				}
				// Step 6: infer 960
				implied960 = false;
				// letters in output → clearly 960 needed for disambiguation
				for (char c : cr_ref) if (is_file_tok(c)) { implied960 = true; break; }
				// king not on e-file while having rights
				if (!implied960) {
					const bool w_wantCR = wantWK || wantWQ;
					const bool b_wantCR = wantBK || wantBQ;
					if ((wf != 4 && w_wantCR) || (bf != 4 && b_wantCR)) {
						implied960 = true;
					}
				}
				// classic corner-rook feasibility check (if generics exist but corners missing)

				auto corner_ok = [&](bool white, bool kingSide) -> bool {
					int kf = white ? wf : bf;
					if (kf != 4) return false; // classic expects king on e-file
					uint8_t mask = white ? wHomeRooks : bHomeRooks;
					int fidx = kingSide ? 7 : 0;
					return (mask & (1u << fidx)) != 0;
					};

				if (!implied960) {
					if ((cr_ref.find('K') != std::string::npos && !corner_ok(true, true)) ||
						(cr_ref.find('Q') != std::string::npos && !corner_ok(true, false)) ||
						(cr_ref.find('k') != std::string::npos && !corner_ok(false, true)) ||
						(cr_ref.find('q') != std::string::npos && !corner_ok(false, false))) {
						implied960 = true;
					}
				}
			}
		}
		else {
			implied960 = false;
		}
	}
	else {
		f.push_back("-");
		implied960 = false;
	}

	// 5) En passant
	if (f.size() > 3) {
		std::string& ep = f[3];

		if (ep != "-") {
			bool keep = false;
			if (Square::is_valid_string_sq(ep)) {
				const Square epSq = Square(ep);
				const Bitboard epTo = Bitboard::fromSquare(epSq ^ Square::SQ_A2);
				const Bitboard epFrom = Bitboard::fromSquare(epSq ^ Square::SQ_A4);
				if (!(OCC & epFrom) && ((active == "w" && epSq.rank() == Rank::RANK_6 && (BP & epTo)) || (active == "b" && epSq.rank() == Rank::RANK_3 && (WP & epTo)))) {
					if ((active == "w" && w_total > 1) || (active == "b" && b_total > 1)) return "More than one check after a double push is impossible";
					if ((active == "w" && !(w_checks & epTo)) || (active == "b" && !(b_checks & epTo))) {
						auto attacks_on_king = [&](Square ksq, Bitboard occ, bool by_white) -> Bitboard {
							return attacks::knight(ksq) & (by_white ? WN : BN)
								| attacks::pawn(by_white ? Color::BLACK : Color::WHITE, ksq) & (by_white ? WP : BP)
								| attacks::rook(ksq, occ) & (by_white ? (WR | WQ) : (BR | BQ))
								| attacks::bishop(ksq, occ) & (by_white ? (WB | WQ) : (BB | BQ));
							};
						if ((active == "w" && attacks_on_king(wk_sq, (OCC ^ epTo) | epFrom, false)) || (active == "b" && attacks_on_king(bk_sq, (OCC ^ epTo) | epFrom, true)))
							return "Non-discovered check after a double push is impossible";
					}
					// Build a minimal FEN for the position at hand.
					// NOTE: Set castling to '-' intentionally; it doesn't affect EP legality and sidesteps X-FEN parsing differences.
					const std::string fen_board = f[0];
					const std::string fen_turn = f[1];
					const std::string fen_castling = "-";
					const std::string fen_ep = ep;

					const std::string fen =
						fen_board + " " + fen_turn + " " + fen_castling + " " + fen_ep;

					// Construct board and it will check valid EP via movegen.
					Board b = Board(fen);
					if (b.enpassantSq() == epSq)
						keep = true;
				}
			}
			if (!keep) ep = "-";
		}
	}
	else {
		f.push_back("-");
	}
	std::string norm = f[0] + " " + f[1] + " " + f[2] + " " + f[3];
	fen.swap(norm);
	return {};
}

PHP_MINIT_FUNCTION(cboard)
{
	return SUCCESS;
}
PHP_FUNCTION(cbgetfen)
{
	char* fenstr;
	size_t fenstr_len;
	array_init(return_value);
	if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &fenstr, &fenstr_len) != FAILURE) {
		std::string fen(fenstr, fenstr_len);
		bool implied960;
		if (sanitize_xfen(fen, implied960).empty())
		{
			add_next_index_stringl(return_value, fen.c_str(), fen.size());
			add_next_index_bool(return_value, implied960);
			return;
		}
	}
	add_next_index_null(return_value);
	add_next_index_null(return_value);
}
PHP_FUNCTION(cbmovegen)
{
	char* fenstr;
	size_t fenstr_len;
	zend_bool frc = 0;
	array_init(return_value);
	if (zend_parse_parameters(ZEND_NUM_ARGS(), "s|b", &fenstr, &fenstr_len, &frc) != FAILURE) {
		Board b = Board(std::string_view(fenstr, fenstr_len), frc);
		Movelist ml;
		movegen::legalmoves(ml, b);
		std::string movestr;
		movestr.reserve(5);
		for (const Move& m : ml) {
			movestr.clear();
			uci::moveToUci(m, movestr, frc);
			add_assoc_long_ex(return_value, movestr.c_str(), movestr.size(), 0);
		}
	}
}
PHP_FUNCTION(cbincheck)
{
	char* fenstr;
	size_t fenstr_len;
	zend_bool frc = 0;
	if (zend_parse_parameters(ZEND_NUM_ARGS(), "s|b", &fenstr, &fenstr_len, &frc) != FAILURE) {
		Board b = Board(std::string_view(fenstr, fenstr_len), frc);
		RETURN_BOOL(b.inCheck());
	}
	RETURN_NULL();
}
PHP_FUNCTION(cbmovemake)
{
	char* fenstr;
	size_t fenstr_len;
	char* movestr;
	size_t movestr_len;
	zend_bool frc = 0;
	array_init(return_value);
	if (zend_parse_parameters(ZEND_NUM_ARGS(), "ss|b", &fenstr, &fenstr_len, &movestr, &movestr_len, &frc) != FAILURE) {
		Board b = Board(std::string_view(fenstr, fenstr_len), frc);
		Move move = uci::uciToMove(b, std::string_view(movestr, movestr_len));
		if (move != Move::NO_MOVE) {
			b.makeMove(move);
			std::string fen = b.getFen(false);
			bool implied960;
			// if we don't re-sanitize, then need strict EP filter + standard CR check
			if (sanitize_xfen(fen, implied960).empty())
			{
				add_next_index_stringl(return_value, fen.c_str(), fen.size());
				add_next_index_bool(return_value, implied960);
				return;
			}
		}
	}
	add_next_index_null(return_value);
	add_next_index_null(return_value);
}
PHP_FUNCTION(cbmovesan)
{
	char* fenstr;
	size_t fenstr_len;
	zval* arr;
	zend_bool frc = 0;
	array_init(return_value);
	if (zend_parse_parameters(ZEND_NUM_ARGS(), "sa|b", &fenstr, &fenstr_len, &arr, &frc) != FAILURE) {
		Board b = Board(std::string_view(fenstr, fenstr_len), frc);
		HashTable* arr_hash = Z_ARRVAL_P(arr);
		HashPosition pointer;
		zval* data;
		std::string movestr;
		movestr.reserve(8);
		for (zend_hash_internal_pointer_reset_ex(arr_hash, &pointer); data = zend_hash_get_current_data_ex(arr_hash, &pointer); zend_hash_move_forward_ex(arr_hash, &pointer)) {
			if (Z_TYPE_P(data) == IS_STRING) {
				Move move = uci::uciToMove(b, std::string_view(Z_STRVAL_P(data), Z_STRLEN_P(data)));
				if (move != Move::NO_MOVE) {
					movestr.clear();
					uci::moveToSan(b, move, movestr);
					add_next_index_stringl(return_value, movestr.c_str(), movestr.size());
					b.makeMove(move);
					std::string fen = b.getFen(false);
					bool implied960;
					// if we don't re-sanitize, then need strict EP filter + standard CR check
					if (sanitize_xfen(fen, implied960).empty())
					{
						b = Board(fen, implied960);
					}
					else
						break;
				}
				else
					break;
			}
		}
	}
}
char char2bithex(char ch)
{
	switch(ch)
	{
		case '1':
			return '0';
		case '2':
			return '1';
		case '3':
			return '2';
		case 'p':
			return '3';
		case 'n':
			return '4';
		case 'b':
			return '5';
		case 'r':
			return '6';
		case 'q':
			return '7';
			
		case 'k':
			return '9';
		case 'P':
			return 'a';
		case 'N':
			return 'b';
		case 'B':
			return 'c';
		case 'R':
			return 'd';
		case 'Q':
			return 'e';
		case 'K':
			return 'f';
		default:
			return '8';
	}
}
char bithex2char(unsigned char ch)
{
	switch(ch)
	{
		case '0':
			return '1';
		case '1':
			return '2';
		case '2':
			return '3';
		case '3':
			return 'p';
		case '4':
			return 'n';
		case '5':
			return 'b';
		case '6':
			return 'r';
		case '7':
			return 'q';
			
		case '9':
			return 'k';
		case 'a':
			return 'P';
		case 'b':
			return 'N';
		case 'c':
			return 'B';
		case 'd':
			return 'R';
		case 'e':
			return 'Q';
		case 'f':
			return 'K';
	}
}
char extra2bithex(char ch)
{
	switch(ch)
	{
		case '-':
			return '0';
		case 'K':
			return 'a';
		case 'Q':
			return 'b';
		case 'k':
			return 'c';
		case 'q':
			return 'd';
		case 'a':
			return '1';
		case 'b':
			return '2';
		case 'c':
			return '3';
		case 'd':
			return '4';
		case 'e':
			return '5';
		case 'f':
			return '6';
		case 'g':
			return '7';
		case 'h':
			return '8';
		case ' ':
			return '9';
		case 'B':
		case 'C':
		case 'D':
		case 'E':
		case 'F':
		case 'G':
			return 'e';
		default:
			return ch;
	}
}
char bithex2extra(unsigned char ch)
{
	switch(ch)
	{
		case '0':
			return '-';
		case 'a':
			return 'K';
		case 'b':
			return 'Q';
		case 'c':
			return 'k';
		case 'd':
			return 'q';
		case '1':
			return 'a';
		case '2':
			return 'b';
		case '3':
			return 'c';
		case '4':
			return 'd';
		case '5':
			return 'e';
		case '6':
			return 'f';
		case '7':
			return 'g';
		case '8':
			return 'h';
		case '9':
			return ' ';
	}
}
#define TRUNC(a, b) ((a) + ((b) - (((a) % (b)) ? ((a) % (b)) : (b))))
PHP_FUNCTION(cbfen2hexfen)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &fenstr, &fenstr_len) != FAILURE) {
		char bitstr[93];
		int index = 0;
		int tmpindex = 0;
		while(index < fenstr_len)
		{
			char curCh = fenstr[index];
			if(curCh == ' ')
			{
				if(fenstr[index+1] == 'b')
				{
					bitstr[tmpindex++] = '1';
				}
				else
				{
					bitstr[tmpindex++] = '0';
				}
				index += 3;
				while(index < fenstr_len)
				{
					bitstr[tmpindex++] = extra2bithex(fenstr[index++]);
					if(bitstr[tmpindex - 1] == 'e')
					{
						bitstr[tmpindex++] = extra2bithex(tolower(fenstr[index - 1]));
					}
				}
				break;
			}
			else if(curCh == '/')
			{
				index++;
			}
			else
			{
				bitstr[tmpindex++] = char2bithex(curCh);
				if(curCh >= '4' && curCh <= '8')
				{
					bitstr[tmpindex++] = curCh - 4;
				}
				index++;
			}
		}
		if(tmpindex % 2)
		{
			if(bitstr[tmpindex - 1] == '0')
			{
				bitstr[tmpindex - 1] = '\0';
			}
			else
			{
				bitstr[tmpindex++] = '0';
				bitstr[tmpindex] = '\0';
			}
		}
		else
		{
			bitstr[tmpindex] = '\0';
		}
		RETURN_STRING(bitstr);
	}
}
PHP_FUNCTION(cbhexfen2fen)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &fenstr, &fenstr_len) != FAILURE) {
		int index = 0;
		char fen[128];
		int tmpidx = 0;
		for(int sq = 0; sq < 64; sq++)
		{
			if(sq != 0 && (sq % 8) == 0)
			{
				fen[tmpidx++] = '/';
			}
			char tmpch = '0';
			if(index < fenstr_len)
			{
				tmpch = fenstr[index++];
			}
			if(tmpch == '1')
			{
				sq += 1;
			}
			else if(tmpch == '2')
			{
				sq += 2;
			}
			if(tmpch == '8')
			{
				tmpch = fenstr[index++];
				fen[tmpidx++] = tmpch + 4;
				sq += tmpch - '0' + 3;
			}
			else
			{
				fen[tmpidx++] = bithex2char(tmpch);
			}
		}
		fen[tmpidx] = '\0';

		if(fenstr[index++] != '0')
		{
			strcat(fen, " b ");
		}
		else
		{
			strcat(fen, " w ");
		}
		tmpidx += 3;
		do
		{
			if(fenstr[index] == 'e')
			{
				index++;
				fen[tmpidx++] = toupper(bithex2extra(fenstr[index++]));
			}
			else
			{
				fen[tmpidx++] = bithex2extra(fenstr[index++]);
			}
			
		}
		while(fen[tmpidx - 1] != ' ');
		if(index < fenstr_len)
		{
			fen[tmpidx++] = bithex2extra(fenstr[index++]);
			if(fen[tmpidx - 1] != '-')
				fen[tmpidx++] = fenstr[index];
			fen[tmpidx] = '\0';
		}
		else
		{
			fen[tmpidx++] = '-';
			fen[tmpidx] = '\0';
		}
		RETURN_STRING(fen);
	}
}

const char MoveToBW[128] = {
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
        0,  '8',  '7',  '6',  '5',  '4',  '3',  '2',  '1',  0,  0,  0,  0,  0,  0,  0,
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
};
PHP_FUNCTION(cbgetBWfen)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &fenstr, &fenstr_len) != FAILURE) {
		char fen[128];
		char tmp[128];
		int index = 0;
		int tmpidx = 0;
		fen[0] = '\0';
		while(index < fenstr_len)
		{
			if(fenstr[index] == ' ')
			{
				tmp[tmpidx] = '\0';
				strcat(tmp, "/");
				strcat(tmp, fen);
				strcpy(fen, tmp);
				if(fenstr[index+1] == 'w')
				{
					strcat(fen, " b ");
				}
				else
				{
					strcat(fen, " w ");
				}
				index += 3;
				tmpidx = 0;
				char tmp2[4];
				int tmpidx2 = 0;
				do
				{
					if(isupper(fenstr[index]))
						tmp2[tmpidx2++] = tolower(fenstr[index++]);
					else
						tmp[tmpidx++] = toupper(fenstr[index++]);
				}
				while(fenstr[index] != ' ');
				tmp[tmpidx] = '\0';
				tmp2[tmpidx2++] = ' ';
				tmp2[tmpidx2] = '\0';
				strcat(fen, tmp);
				strcat(fen, tmp2);
				index++;

				while(index < fenstr_len)
				{
					char tmp = MoveToBW[fenstr[index]];
					if(tmp)
						fen[index] = tmp;
					else
						fen[index] = fenstr[index];
					index++;
				}
				fen[index] = '\0';
				break;
			}
			else if(fenstr[index] == '/')
			{
				tmp[tmpidx] = '\0';
				if(strlen(fen) > 0)
				{
					strcat(tmp, "/");
					strcat(tmp, fen);
				}
				strcpy(fen, tmp);
				tmpidx = 0;
				index++;
			}
			else
			{
				tmp[tmpidx] = fenstr[index++];
				if(isupper(tmp[tmpidx]))
				{
					tmp[tmpidx] = tolower(tmp[tmpidx]);
				}
				else
				{
					tmp[tmpidx] = toupper(tmp[tmpidx]);
				}
				tmpidx++;
			}
		}
		RETURN_STRING(fen);
	}
}
PHP_FUNCTION(cbgetBWmove)
{
	char* movestr;
	size_t movestr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &movestr, &movestr_len) != FAILURE) {
		char move[6];
		move[4] = '\0';
		for(int i = 0; i < movestr_len; i++)
		{
			if(i < 4)
			{
				char tmp = MoveToBW[movestr[i]];
				if(tmp)
					move[i] = tmp;
				else
					move[i] = movestr[i];
			}
			else
			{
				move[i] = movestr[i];
				move[5] = '\0';
			}
		}
		RETURN_STRING(move);
	}
	RETURN_FALSE;
}
