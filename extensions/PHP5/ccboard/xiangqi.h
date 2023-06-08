#ifndef _XIANGQI_H_
#define _XIANGQI_H_
#include <vector>
#ifdef _MSC_VER
#include <intrin.h>
#endif
typedef signed char S8, INT8, int8, sint8;
typedef unsigned char U8, UINT8, uint8;
typedef signed short S16, INT16, SINT16, int16, sint16;
typedef unsigned short U16, UINT16, uint16;
typedef signed int S32, INT, INT32, SINT32, int32, sint32;
typedef unsigned int U32, UINT, UINT32, uint32;
typedef signed long long S64, INT64, int64, sint64;
typedef unsigned long long U64, UINT64, uint64;

enum {
	White = 0, Black, ColorNb, ColorNone
};
enum {
	King = 1, Rook, Knight, Cannon, Advisor, Bishop, Pawn
};
enum {
	WhiteOccupy, WhiteKing, WhiteRook, WhiteKnight, WhiteCannon, WhiteAdvisor, WhiteBishop, WhitePawn,
	BlackOccupy, BlackKing, BlackRook, BlackKnight, BlackCannon, BlackAdvisor, BlackBishop, BlackPawn
};
enum {
	KingFlag = 1 << King, RookFlag = 1 << Rook, KnightFlag = 1 << Knight, CannonFlag = 1 << Cannon,
	AdvisorFlag = 1 << Advisor, BishopFlag = 1 << Bishop, PawnFlag = 1 << Pawn,
};
enum {
	ValueNone = -32767, ValueMate = 30000, ValueInf = 30000, ValueBan = 32000, ValueEval = 29000, ValueUnKnow = -32500
};
enum {
	Opening, Endgame
};
enum Square {
	SQ_A0, SQ_B0, SQ_C0, SQ_D0, SQ_E0, SQ_F0, SQ_G0, SQ_H0, SQ_I0,
	SQ_A1, SQ_B1, SQ_C1, SQ_D1, SQ_E1, SQ_F1, SQ_G1, SQ_H1, SQ_I1,
	SQ_A2, SQ_B2, SQ_C2, SQ_D2, SQ_E2, SQ_F2, SQ_G2, SQ_H2, SQ_I2,
	SQ_A3, SQ_B3, SQ_C3, SQ_D3, SQ_E3, SQ_F3, SQ_G3, SQ_H3, SQ_I3,
	SQ_A4, SQ_B4, SQ_C4, SQ_D4, SQ_E4, SQ_F4, SQ_G4, SQ_H4, SQ_I4,
	SQ_A5, SQ_B5, SQ_C5, SQ_D5, SQ_E5, SQ_F5, SQ_G5, SQ_H5, SQ_I5,
	SQ_A6, SQ_B6, SQ_C6, SQ_D6, SQ_E6, SQ_F6, SQ_G6, SQ_H6, SQ_I6,
	SQ_A7, SQ_B7, SQ_C7, SQ_D7, SQ_E7, SQ_F7, SQ_G7, SQ_H7, SQ_I7,
	SQ_A8, SQ_B8, SQ_C8, SQ_D8, SQ_E8, SQ_F8, SQ_G8, SQ_H8, SQ_I8,
	SQ_A9, SQ_B9, SQ_C9, SQ_D9, SQ_E9, SQ_F9, SQ_G9, SQ_H9, SQ_I9,
	SQ_End
};

enum File {
	File_A, File_B, File_C, File_D, File_E, File_F, File_G, File_H, File_I, File_End
};

enum Rank {
	Rank_0, Rank_1, Rank_2, Rank_3, Rank_4, Rank_5, Rank_6, Rank_7, Rank_8, Rank_9, Rank_End
};

extern const U8 Sq_Rank[90];
extern const U8 Sq_File[90];
extern const U8 Sq_Color[90];
extern const U8 Sq_File_Mirror[90];
extern const U8 Sq_Rank_Mirror[90];
extern const S8 White_King_Pos[90];
extern const S8 White_Advisor_Pos[90];
extern const S8 White_Bishop_Pos[90];
extern const S8 White_Pawn_Pos[90];
extern const S8 King_Pos_Index[90];
extern const S8 Advisor_Pos_Index[90];
extern const S8 Bishop_Pos_Index[90];

inline int lsb(U64 b)
{
#ifndef _MSC_VER
	return (__builtin_ctzll(b));
#else
	unsigned long ret;
	_BitScanForward64(&ret, b);
	return (int)ret;
#endif
}
inline int msb(U64 b)
{
#ifndef _MSC_VER
	return (63 ^ __builtin_clzll(b));
#else
	unsigned long ret;
	_BitScanReverse64(&ret, b);
	return (int)ret;
#endif
}
inline int pop_1st(U64& b)
{
	int r = lsb(b);
	b &= b - 1;
	return r;
}
inline int pop_cnt(U64 b)
{
#ifndef _MSC_VER
	return __builtin_popcountll(b);
#else
	return (int)__popcnt64(b);
#endif
}

#ifndef _MSC_VER
inline U64 __shiftleft128(U64 LowPart, U64 HighPart, U8 Shift)
{
	unsigned __int128 val = ((unsigned __int128)HighPart << 64) | LowPart;
	unsigned __int128 res = val << (Shift & 63);
	return (U64)(res >> 64);
}
inline U64 __shiftright128(U64 LowPart, U64 HighPart, U8 Shift)
{
	unsigned __int128 val = ((unsigned __int128)HighPart << 64) | LowPart;
	unsigned __int128 res = val >> (Shift & 63);
	return (U64)res;
}
#endif

const U64 WhiteBits = 0xfffffffffff80000;
const U64 BlackBits = 0x00001fffffffffff;
const int Sq_Lsb_Inc[2] = { -19, 45 };

struct BITBOARD
{
	uint64 bits[2];
	inline BITBOARD() {}
	inline BITBOARD(const BITBOARD& bb)
	{
		bits[0] = bb.bits[0];
		bits[1] = bb.bits[1];
	}
	inline BITBOARD(const uint64& a, const uint64& b)
	{
		bits[0] = a;
		bits[1] = b;
	}
	inline BITBOARD& operator = (const BITBOARD& bb)
	{
		bits[0] = bb.bits[0];
		bits[1] = bb.bits[1];
		return *this;
	}
	inline BITBOARD& operator &= (const BITBOARD& bb)
	{
		bits[0] &= bb.bits[0];
		bits[1] &= bb.bits[1];
		return *this;
	}
	inline BITBOARD& operator |= (const BITBOARD& bb)
	{
		bits[0] |= bb.bits[0];
		bits[1] |= bb.bits[1];
		return *this;
	}
	inline BITBOARD& operator ^= (const BITBOARD& bb)
	{
		bits[0] ^= bb.bits[0];
		bits[1] ^= bb.bits[1];
		return *this;
	}
	inline U64& operator[](INT index)
	{
		return bits[index];
	}
	inline const U64& operator[](INT index)const
	{
		return bits[index];
	}
	inline operator bool()const
	{
		return (bits[0] | bits[1]) != 0;
	}

	inline int peek_1st()const
	{
		if (bits[0])
			return lsb(bits[0]);
		else
			return lsb(bits[1]) + 64;
	}
	inline int pop_1st()
	{
		register int sq;
		if (bits[0])
		{
			sq = lsb(bits[0]);
			bits[0] &= bits[0] - 1;
			return sq;
		}
		else
		{
			sq = lsb(bits[1]) + 64;
			bits[1] &= bits[1] - 1;
			return sq;
		}
	}
	inline int count_bits()const
	{
		return pop_cnt(bits[0]) + pop_cnt(bits[1]);
	}
	inline bool only_1_bit_set()const
	{
		U64 bit = bits[0] | bits[1];
		if (bit)
		{
			return !(bit & (bit - 1));
		}
		return false;
	}
	inline bool only_2_bit_set()const
	{
		if (bits[0] && bits[1])
		{
			return !(bits[0] & (bits[0] - 1)) && !(bits[1] & (bits[1] - 1));
		}
		U64 bit = bits[0] | bits[1];
		if (bit)
		{
			bit &= bit - 1;
			if (bit)
			{
				return !(bit & (bit - 1));
			}
		}
		return false;
	}
	inline BITBOARD& set_bit(int sq)
	{
		if (sq < 45)
			bits[0] |= 1ULL << (sq + 19);
		else
			bits[1] |= 1ULL << (sq - 45);
		return *this;
	}
	inline BITBOARD& clear_bit(int sq)
	{
		if (sq < 45)
			bits[0] |= ~(1ULL << (sq + 19));
		else
			bits[1] |= ~(1ULL << (sq - 45));
		return *this;
	}
	inline bool check_bit(int sq)const
	{
		if (sq < 45)
			return (bits[0] & (1ULL << (sq + 19))) != 0;
		else
			return (bits[1] & (1ULL << (sq - 45))) != 0;
	}
	inline int pop_1st_sq()
	{
		register int sq;
		if (bits[0])
		{
			sq = lsb(bits[0]) - 19;
			bits[0] &= bits[0] - 1;
			return sq;
		}
		else
		{
			sq = lsb(bits[1]) + 45;
			bits[1] &= bits[1] - 1;
			return sq;
		}
	}
	inline int peek_1st_sq()const
	{
		if (bits[0])
			return lsb(bits[0]) - 19;
		else
			return lsb(bits[1]) + 45;
	}
	inline int pop_last_sq()
	{
		register int sq;
		if (bits[1])
		{
			sq = msb(bits[1]);
			bits[1] ^= 1ULL << sq;
			return sq + 45;
		}
		else
		{
			sq = msb(bits[0]);
			bits[0] ^= 1ULL << sq;
			return sq - 19;
		}
	}
	inline int peek_last_sq()const
	{
		if (bits[1])
			return msb(bits[1]) + 45;
		else
			return msb(bits[0]) - 19;
	}
	inline int peek_sq(int dir)
	{
		return dir == 0 ? peek_1st_sq() : peek_last_sq();
	}
	inline int pop_sq(int dir)
	{
		return dir == 0 ? pop_1st_sq() : pop_last_sq();
	}
	inline void clear()
	{
		bits[0] = bits[1] = 0ULL;
	}
};

inline BITBOARD operator&(const BITBOARD& a, const BITBOARD& b)
{
	return BITBOARD(a[0] & b[0], a[1] & b[1]);
}

inline BITBOARD operator|(const BITBOARD& a, const BITBOARD& b)
{
	return BITBOARD(a[0] | b[0], a[1] | b[1]);
}
inline BITBOARD operator^(const BITBOARD& a, const BITBOARD& b)
{
	return BITBOARD(a[0] ^ b[0], a[1] ^ b[1]);
}
inline BITBOARD operator ~(const BITBOARD& a)
{
	return BITBOARD(~a[0], ~a[1]);
}

inline BITBOARD shift_left(const BITBOARD& bb, int bit)
{
	if (bit >= 64)
		return BITBOARD(0ULL, (bb.bits[0] << (bit - 64)));
	return BITBOARD((bb.bits[0] << bit), __shiftleft128(bb.bits[0], bb.bits[1], bit));
}
inline BITBOARD shift_right(const BITBOARD& bb, int bit)
{
	if (bit >= 64)
		return BITBOARD(bb.bits[1] >> (bit - 64), 0ULL);
	return BITBOARD(__shiftright128(bb.bits[0], bb.bits[1], bit), bb.bits[1] >> bit);
}
inline BITBOARD operator>>(const BITBOARD& bb, int bit)
{
	if (bit >= 64)
		return BITBOARD(bb.bits[1] >> (bit - 64), 0ULL);
	return BITBOARD(__shiftright128(bb.bits[0], bb.bits[1], bit), bb.bits[1] >> bit);
}
inline BITBOARD operator<<(const BITBOARD& bb, int bit)
{
	if (bit >= 64)
		return BITBOARD(0ULL, (bb.bits[0] << (bit - 64)));
	return BITBOARD((bb.bits[0] << bit), __shiftleft128(bb.bits[0], bb.bits[1], bit));
}

inline bool operator == (const BITBOARD& a, const BITBOARD& b)
{
	return (a[0] == b[0] && a[1] == b[1]);
}
inline bool operator != (const BITBOARD& a, const BITBOARD& b)
{
	return  !(a == b);
}

inline bool operator < (const BITBOARD& a, const BITBOARD& b)
{
	if (a[1] < b[1])
		return true;
	if (a[1] == b[1] && a[0] < b[0])
		return true;
	return false;
}
inline bool operator > (const BITBOARD& a, const BITBOARD& b)
{
	if (a[1] > b[1])
		return true;
	if (a[1] == b[1] && a[0] > b[0])
		return true;
	return false;
}

inline UINT64 operator*(const BITBOARD& a, const BITBOARD& b)
{
	return (a[0] * b[0]) ^ (a[1] * b[1]);
}

inline UINT64 operator*(const BITBOARD& a, const UINT64 b)
{
	return (a[0] ^ a[1]) * b;
}

inline UINT64 operator*(const UINT64 b, const BITBOARD& a)
{
	return (a[0] ^ a[1]) * b;
}

inline U32 fold_2_u32(U64 bit)
{
	return (U32)bit ^ (U32)(bit >> 32);
}
inline UINT32 operator*(const BITBOARD& a, const UINT32 b)
{
	return fold_2_u32(a[0] ^ a[1]) * b;
}

inline UINT32 operator*(const UINT32 b, const BITBOARD& a)
{
	return fold_2_u32(a[0] ^ a[1]) * b;
}

inline int peek_1st_sq(const U64& b, int color)
{
	return lsb(b) + Sq_Lsb_Inc[color];
}
inline int pop_1st_sq(U64& b, int color)
{
	int r = lsb(b) + Sq_Lsb_Inc[color];
	b &= b - 1;
	return r;
}

const BITBOARD Board_Mask = BITBOARD(WhiteBits, BlackBits);
inline const BITBOARD& board_mask()
{
	return Board_Mask;
}

const S8 Pawn_Move_Inc[2] = { 9, -9 };

const BITBOARD King_Pos_Mask(0x0000070381c00000, 0x00000381c0e00000);
const BITBOARD Advisor_Pos_Mask(0x0000050101400000, 0x0000028080a00000);
const BITBOARD Bishop_Pos_Mask(0x2200222002200000, 0x0000044004440044);
const BITBOARD Pawn_Pos_Mask[2] = {
	BITBOARD(0xaad5400000000000,0x00001fffffffffff),
	BITBOARD(0xfffffffffff80000,0x000000000002ab55)
};

extern const BITBOARD SQ_BB_MASK[92];
const char StartFen[] = "rnbakabnr/9/1c5c1/p1p1p1p1p/9/9/P1P1P1P1P/1C5C1/9/RNBAKABNR w";

inline const BITBOARD& sq_2_bb(int sq)
{
	return SQ_BB_MASK[sq];
}
inline const BITBOARD& bb_set(int sq)
{
	return SQ_BB_MASK[sq];
}

inline int pawn_move_inc(int color)
{
	return Pawn_Move_Inc[color];
}
inline bool color_is_ok(int color)
{
	return (color == White || color == Black);
}
inline int color_opp(int color)
{
	return (color ^ 1);
}
inline bool piece_is_ok(int piece)
{
	if ((piece >= WhiteKing && piece <= WhitePawn) || (piece >= BlackKing && piece <= BlackPawn))
		return true;
	else
		return false;
}

inline int piece_type(int piece)
{
	return (piece & 7);
}
inline int piece_color(int piece)
{
	return (piece ? (piece >> 3) : ColorNone);
}

inline int piece_make(int color, int type)
{
	return ((color << 3) + type);
}

inline int piece_opp_color(int piece)
{
	return piece_make(color_opp(piece_color(piece)), piece_type(piece));
}
inline bool sq_is_ok(int sq)
{
	return sq >= SQ_A0 && sq < SQ_End;
}
inline bool file_is_ok(int file)
{
	return file >= File_A && file < File_End;
}
inline bool rank_is_ok(int rank)
{
	return rank >= Rank_0 && rank < Rank_End;
}
inline int sq_file(int sq)
{
	return Sq_File[sq];
}
inline int sq_rank(int sq)
{
	return Sq_Rank[sq];
}
inline int sq_make(int rank, int file)
{
	return (((rank) << 3) + (rank)+(file));
}
inline bool sq_equal_rank(int sq1, int sq2)
{
	return Sq_Rank[sq1] == Sq_Rank[sq2];
}
inline bool sq_equal_file(int sq1, int sq2)
{
	return Sq_File[sq1] == Sq_File[sq2];
}
inline int sq_color(int sq)
{
	return Sq_Color[sq];
}
inline int sq_file_mirror(int sq)
{
	return Sq_File_Mirror[sq];
}
inline int sq_rank_mirror(int sq)
{
	return Sq_Rank_Mirror[sq];
}
inline int king_pos_index(int sq)
{
	return King_Pos_Index[sq];
}
inline int advisor_pos_index(int sq)
{
	return Advisor_Pos_Index[sq];
}
inline int bishop_pos_index(int sq)
{
	return Bishop_Pos_Index[sq];
}
inline bool is_king_pos(int sq)
{
	return King_Pos_Index[sq] >= 0;
}
inline bool is_advisor_pos(int sq)
{
	return Advisor_Pos_Index[sq] >= 0;
}
inline bool is_bishop_pos(int sq)
{
	return Bishop_Pos_Index[sq] >= 0;
}

inline bool is_king_pos(int sq, int color)
{
	return King_Pos_Index[sq] >= 0 && sq_color(sq) == color;
}
inline bool is_advisor_pos(int sq, int color)
{
	return Advisor_Pos_Index[sq] >= 0 && sq_color(sq) == color;
}
inline bool is_bishop_pos(int sq, int color)
{
	return Bishop_Pos_Index[sq] >= 0 && sq_color(sq) == color;
}
inline const BITBOARD& king_pos_bb()
{
	return King_Pos_Mask;
}
inline const BITBOARD& advisor_pos_bb()
{
	return Advisor_Pos_Mask;
}
inline const BITBOARD& bishop_pos_bb()
{
	return Bishop_Pos_Mask;
}
inline const BITBOARD& pawn_pos_bb(int color)
{
	return Pawn_Pos_Mask[color];
}

const int MoveNone = 0;

typedef U16 mv_t;

inline int move_make(int from, int to)
{
	return ((from) << 7) | (to);
}
inline int move_from(int move)
{
	return (((move) >> 7) & 0x7f);
}
inline int move_to(int move)
{
	return ((move) & 0x7f);
}

extern void move_to_string(int move, char string[]);
extern int move_from_string(const char string[]);

extern const U64 ZobristPlayer;
extern const U64 ZobristTable[3584];

inline U64 turn_key()
{
	return ZobristPlayer;
}
inline U64 piece_key(int piece, int sq)
{
	return ZobristTable[piece * 90 + sq];
}

extern int piece_from_char(int c);
extern int piece_to_char(int p);
extern void square_to_string(int sq, char string[]);
extern int square_from_string(const char string[]);



enum {
	Cap_Min, Cap_Max
};

enum {
	Rank_Shift = 57, File_Shift = 56, Knight_Shift = 60, Bishop_Shift = 60,
};

extern const U8 Rank_Shift_Right[10];
extern const U8 Rank_Shift_Left[10];

extern const S8 Knight_Legal_Leg[40];

extern const UINT64 FileMagic[9];
extern const UINT64 RankMagic[10];

extern const UINT64 BishopMagic[14];
extern const UINT64 KnightMagic[90];
extern const UINT64 ByKnightMagic[90];

extern BITBOARD File_Block_Mask[9];
extern BITBOARD Rank_Block_Mask[10];
extern BITBOARD Bishop_Block_Mask[14];
extern BITBOARD Knight_Block_Mask[90];
extern BITBOARD Knight_Attd_Mask[90];
extern BITBOARD Bishop_Att_BB[14][16];
extern BITBOARD Knight_Att_BB[90][16];
extern BITBOARD Knight_Attd_BB[90][16];
extern BITBOARD Rook_Rank_Att_Table[90][128];
extern BITBOARD Rook_File_Att_Table[90][256];
extern BITBOARD Cannon_Rank_Att_Table[90][128];
extern BITBOARD Cannon_File_Att_Table[90][256];

extern BITBOARD King_Att[18];
extern BITBOARD Advisor_Att[10];
extern BITBOARD Pawn_Att[90][2];
extern BITBOARD Attd_Pawn[90][2];
extern BITBOARD Bishop_Att_No_Mask[14];
extern BITBOARD Knight_Att_No_Mask[90];

extern BITBOARD Rank_Mask[10];
extern BITBOARD File_Mask[9];
extern BITBOARD Rank_Between_Mask[9][16];
extern BITBOARD File_Between_Mask[10][16];
extern BITBOARD Up_Down_Mask[10][2];
extern BITBOARD Left_Right_Mask[9][2];

inline const BITBOARD& sq_rank_mask(int sq)
{
	return Rank_Mask[sq_rank(sq)];
}
inline const BITBOARD& sq_file_mask(int sq)
{
	return File_Mask[sq_file(sq)];
}
inline const BITBOARD& file_mask(int file)
{
	return File_Mask[file];
}
inline const BITBOARD& rank_mask(int rank)
{
	return Rank_Mask[rank];
}
inline BITBOARD file_between_bb(int sq1, int sq2)
{
	return (File_Between_Mask[sq_rank(sq1)][sq_rank(sq2)] & File_Mask[sq_file(sq1)]);
}

inline BITBOARD rank_between_bb(int sq1, int sq2)
{
	return (Rank_Between_Mask[sq_file(sq1)][sq_file(sq2)] & Rank_Mask[sq_rank(sq1)]);
}
inline BITBOARD sq_between_bb(int sq1, int sq2)
{
	if (sq_equal_rank(sq1, sq2))
		return rank_between_bb(sq1, sq2);
	return file_between_bb(sq1, sq2);
}

inline const BITBOARD& lr_dir_bb(int sq, int dir)
{
	return Left_Right_Mask[sq_file(sq)][dir];
}
inline const BITBOARD& ud_dir_bb(int sq, int dir)
{
	return Up_Down_Mask[sq_rank(sq)][dir];
}
inline const BITBOARD& up_down_bb(int rank, int dir)
{
	return Up_Down_Mask[rank][dir];
}
inline const BITBOARD& left_right_bb(int file, int dir)
{
	return Left_Right_Mask[file][dir];
}

inline BITBOARD rank_dir_bb(int sq, int dir)
{
	return Left_Right_Mask[sq_file(sq)][dir] & Rank_Mask[sq_rank(sq)];
}
inline BITBOARD file_dir_bb(int sq, int dir)
{
	return Up_Down_Mask[sq_rank(sq)][dir] & File_Mask[sq_file(sq)];
}
inline int knight_legal_leg(int from, int to)
{
	return from + Knight_Legal_Leg[to - from + 20];
}
inline bool is_leg_pos(int leg, int k_pos)
{
	int dlt = leg - k_pos > 0 ? leg - k_pos : k_pos - leg;
	return (dlt == 8 || dlt == 10);
}

inline const BITBOARD& rook_rank_attack_bb(int sq, const BITBOARD& block)
{
	int ix = int(block[sq_color(sq)] >> Rank_Shift_Right[sq_rank(sq)]) & 127;
	return Rook_Rank_Att_Table[sq][ix];
}

inline const BITBOARD& cannon_rank_attack_bb(int sq, const BITBOARD& block)
{
	int ix = int(block[sq_color(sq)] >> Rank_Shift_Right[sq_rank(sq)]) & 127;
	return Cannon_Rank_Att_Table[sq][ix];
}
inline const BITBOARD& rook_file_attack_bb(int sq, const BITBOARD& block)
{
	int ix = int(((block & File_Block_Mask[sq_file(sq)]) * FileMagic[sq_file(sq)]) >> File_Shift);
	return Rook_File_Att_Table[sq][ix];
}
inline const BITBOARD& cannon_file_attack_bb(int sq, const BITBOARD& block)
{
	int ix = int(((block & File_Block_Mask[sq_file(sq)]) * FileMagic[sq_file(sq)]) >> File_Shift);
	return Cannon_File_Att_Table[sq][ix];
}
inline const BITBOARD& knight_attack_bb(int sq, const BITBOARD& block)
{
	int ix = int(((block & Knight_Block_Mask[sq]) * KnightMagic[sq]) >> Knight_Shift);
	return Knight_Att_BB[sq][ix];
}
inline const BITBOARD& bishop_attack_bb(int sq, const BITBOARD& block)
{
	int ix = int(((block & Bishop_Block_Mask[bishop_pos_index(sq)]) * BishopMagic[bishop_pos_index(sq)]) >> Bishop_Shift);
	return Bishop_Att_BB[bishop_pos_index(sq)][ix];
}

inline const BITBOARD& knight_attacked_bb(int sq, const BITBOARD& block)
{
	int ix = int(((block & Knight_Attd_Mask[sq]) * ByKnightMagic[sq]) >> Knight_Shift);
	return Knight_Attd_BB[sq][ix];
}

inline const BITBOARD& pawn_attack_bb(int sq, int color)
{
	return Pawn_Att[sq][color];
}
inline const BITBOARD& pawn_attacked_bb(int sq, int color)
{
	return Attd_Pawn[sq][color];
}
inline const BITBOARD& advisor_attack_bb(int sq)
{
	return Advisor_Att[advisor_pos_index(sq)];
}
inline const BITBOARD& king_attack_bb(int sq)
{
	return King_Att[king_pos_index(sq)];
}

inline const BITBOARD& bishop_att_no_mask(int sq)
{
	return Bishop_Att_No_Mask[bishop_pos_index(sq)];
}
inline const BITBOARD& knight_att_no_mask(int sq)
{
	return Knight_Att_No_Mask[sq];
}
inline BITBOARD slide_att_no_mask(int sq)
{
	return sq_rank_mask(sq) | sq_file_mask(sq);
}
inline BITBOARD rook_attack_bb(int sq, const BITBOARD& block)
{
	return (rook_rank_attack_bb(sq, block) | rook_file_attack_bb(sq, block));
}
inline BITBOARD cannon_attack_bb(int sq, const BITBOARD& block)
{
	return (cannon_rank_attack_bb(sq, block) | cannon_file_attack_bb(sq, block));
}

extern void attack_init();

enum {
	Rep_None, Rep_Draw, Rep_Me_Ban, Rep_Opp_Ban
};
enum {
	Flag_Rep_None, Flag_Me_Ban = 1 << Rep_Me_Ban, Flag_Opp_Ban = 1 << Rep_Opp_Ban
};
enum {
	Long_Rep_Draw = 0, Long_Check_Win = 1, Long_Chase_Win = 2
};

struct HistoryList
{
	U64   lock;
	U16   move;
	U8    cap;
	U8    check;
	void clear()
	{
		lock = 0ULL;
		move = MoveNone;
		cap = 0;
		check = 0;
	}
};

struct Move_List
{
	inline void clear()
	{
		cnt = 0;
	}
	inline void add(U16 move)
	{
		moves[cnt++] = move;
	}
	inline void set(int pos, U16 move)
	{
		moves[pos] = move;
	}
	inline int size()const
	{
		return cnt;
	}
	inline int move(int pos)const
	{
		return moves[pos];
	}
	//private:
	U16 moves[127];
	U16 cnt;
};

struct Position
{
	BITBOARD blockers;
	BITBOARD pieces[16];
	INT8 number[16];
	INT8 square[90];
	S8 turn;
	U64 key;
	std::vector<HistoryList> stack;

	//////////////////////////////////////////////////////////////////////////
	inline int king(int color)const
	{
		return pieces[piece_make(color, King)].peek_1st_sq();
	}
	inline const BITBOARD& piece_block(int color)const
	{
		return pieces[color << 3];
	}
	inline int sq_piece_color(int sq)const
	{
		return piece_color(square[sq]);
	}
	inline int sq_piece_type(int sq)const
	{
		return piece_type(square[sq]);
	}
	inline const BITBOARD& piece_bb(int color, int type)const
	{
		return pieces[piece_make(color, type)];
	}
	//////////////////////////////////////////////////////////////////////////
	bool in_check(int color)const;
	inline bool in_check()const
	{
		return in_check(turn);
	}
	bool is_legal()const;

	//position.cpp
	void clear();
	void to_fen(char fen[])const;
	bool from_fen(const char fen[]);
	U64 pos_key()const;

	bool is_draw()
	{
		if ((number[WhiteRook] + number[WhiteKnight] + number[WhiteCannon] + number[WhitePawn] +
			number[BlackRook] + number[BlackKnight] + number[BlackCannon] + number[BlackPawn]) == 0)
			return true;
		return false;
	}

	//move_legal.cpp
	bool move_is_pseudo(int move)const;
	bool pseudo_is_legal(int move)const;
	bool pseudo_is_legal_incheck(int move)const;

	void move_do(int move);
	void move_undo();

	//
	//rep_check.cpp
	int rep_check(int check_times);
	int rep_check(int check_times, int& win_trun, int& win_flags);
	void new_game_init()
	{
		from_fen("rnbakabnr/9/1c5c1/p1p1p1p1p/9/9/P1P1P1P1P/1C5C1/9/RNBAKABNR w");
	}
	void rep_move_do(int move);
	void rep_move_undo(int move);
	int check_move_catch();
	int check_static_catch(int flag);
	void gen_static_cap(BITBOARD& bb, int color);
	//
	bool move_attack_sq(int move, int sq, bool passive);
	bool sq_rank_pinned(int sq, const BITBOARD& block);
	bool sq_file_pinned(int sq, const BITBOARD& block);
	bool sq_knight_pinned(int sq, const BITBOARD& block);
	bool sq_other_knight_pinned(int sq, int knight_sq, const BITBOARD& block);
	bool sq_is_pinned(int sq, const BITBOARD& block);
	bool sq_is_protected(int sq, int att_sq);
	bool sq_king_protected(int sq, int att_sq);

	bool sq_rank_pinned(int sq)
	{
		return sq_rank_pinned(sq, blockers);
	}
	bool sq_file_pinned(int sq)
	{
		return sq_file_pinned(sq, blockers);
	}
	bool sq_knight_pinned(int sq)
	{
		return sq_knight_pinned(sq, blockers);
	}
	bool sq_other_knight_pinned(int sq, int knight_sq)
	{
		return sq_other_knight_pinned(sq, knight_sq, blockers);
	}
	bool sq_is_pinned(int sq)
	{
		return sq_is_pinned(sq, blockers);
	}
	bool move_attack_sq(int move, int sq)
	{
		return move_attack_sq(move, sq, false);
	}
	int me_static_catch(int sq);
	int opp_static_catch(int sq);
	bool is_chase(int move);

	void gen_moves(Move_List& list)const;
	bool move_connect(int first, int second, bool is_cap = false)const;
	void gen_chase_legals(Move_List& list)const;
	void gen_legals(Move_List& list)const;
};


extern void init();


#endif
