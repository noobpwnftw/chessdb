#ifndef _CHESS_H_
#define _CHESS_H_

#include "utility.h"

enum{
	ColorNone = -1, White, Black, ColorNb
};
enum{
	King = 1, Rook, Knight, Cannon, Advisor, Bishop, Pawn
};
enum{
	WhiteOccupy, WhiteKing, WhiteRook, WhiteKnight, WhiteCannon, WhiteAdvisor, WhiteBishop, WhitePawn, 
	BlackOccupy, BlackKing, BlackRook, BlackKnight, BlackCannon, BlackAdvisor, BlackBishop, BlackPawn
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

const U8 Sq_Rank[90] = 
{
	Rank_0,Rank_0,Rank_0,Rank_0,Rank_0,Rank_0,Rank_0,Rank_0,Rank_0,
	Rank_1,Rank_1,Rank_1,Rank_1,Rank_1,Rank_1,Rank_1,Rank_1,Rank_1,
	Rank_2,Rank_2,Rank_2,Rank_2,Rank_2,Rank_2,Rank_2,Rank_2,Rank_2,
	Rank_3,Rank_3,Rank_3,Rank_3,Rank_3,Rank_3,Rank_3,Rank_3,Rank_3,
	Rank_4,Rank_4,Rank_4,Rank_4,Rank_4,Rank_4,Rank_4,Rank_4,Rank_4,
	Rank_5,Rank_5,Rank_5,Rank_5,Rank_5,Rank_5,Rank_5,Rank_5,Rank_5,
	Rank_6,Rank_6,Rank_6,Rank_6,Rank_6,Rank_6,Rank_6,Rank_6,Rank_6,
	Rank_7,Rank_7,Rank_7,Rank_7,Rank_7,Rank_7,Rank_7,Rank_7,Rank_7,
	Rank_8,Rank_8,Rank_8,Rank_8,Rank_8,Rank_8,Rank_8,Rank_8,Rank_8,
	Rank_9,Rank_9,Rank_9,Rank_9,Rank_9,Rank_9,Rank_9,Rank_9,Rank_9,
};
const U8 Sq_File[90] =
{
	File_A, File_B, File_C, File_D, File_E, File_F, File_G, File_H, File_I,
	File_A, File_B, File_C, File_D, File_E, File_F, File_G, File_H, File_I,
	File_A, File_B, File_C, File_D, File_E, File_F, File_G, File_H, File_I,
	File_A, File_B, File_C, File_D, File_E, File_F, File_G, File_H, File_I,
	File_A, File_B, File_C, File_D, File_E, File_F, File_G, File_H, File_I,
	File_A, File_B, File_C, File_D, File_E, File_F, File_G, File_H, File_I,
	File_A, File_B, File_C, File_D, File_E, File_F, File_G, File_H, File_I,
	File_A, File_B, File_C, File_D, File_E, File_F, File_G, File_H, File_I,
	File_A, File_B, File_C, File_D, File_E, File_F, File_G, File_H, File_I,
	File_A, File_B, File_C, File_D, File_E, File_F, File_G, File_H, File_I,
};
const U8 Sq_Color[90] = 
{
	White,White,White,White,White,White,White,White,White,
	White,White,White,White,White,White,White,White,White,
	White,White,White,White,White,White,White,White,White,
	White,White,White,White,White,White,White,White,White,
	White,White,White,White,White,White,White,White,White,
	Black,Black,Black,Black,Black,Black,Black,Black,Black,
	Black,Black,Black,Black,Black,Black,Black,Black,Black,
	Black,Black,Black,Black,Black,Black,Black,Black,Black,
	Black,Black,Black,Black,Black,Black,Black,Black,Black,
	Black,Black,Black,Black,Black,Black,Black,Black,Black,
};
const U8 Sq_File_Mirror[90] = {
	SQ_I0,SQ_H0,SQ_G0,SQ_F0,SQ_E0,SQ_D0,SQ_C0,SQ_B0,SQ_A0,
	SQ_I1,SQ_H1,SQ_G1,SQ_F1,SQ_E1,SQ_D1,SQ_C1,SQ_B1,SQ_A1,
	SQ_I2,SQ_H2,SQ_G2,SQ_F2,SQ_E2,SQ_D2,SQ_C2,SQ_B2,SQ_A2,
	SQ_I3,SQ_H3,SQ_G3,SQ_F3,SQ_E3,SQ_D3,SQ_C3,SQ_B3,SQ_A3,
	SQ_I4,SQ_H4,SQ_G4,SQ_F4,SQ_E4,SQ_D4,SQ_C4,SQ_B4,SQ_A4,
	SQ_I5,SQ_H5,SQ_G5,SQ_F5,SQ_E5,SQ_D5,SQ_C5,SQ_B5,SQ_A5,
	SQ_I6,SQ_H6,SQ_G6,SQ_F6,SQ_E6,SQ_D6,SQ_C6,SQ_B6,SQ_A6,
	SQ_I7,SQ_H7,SQ_G7,SQ_F7,SQ_E7,SQ_D7,SQ_C7,SQ_B7,SQ_A7,
	SQ_I8,SQ_H8,SQ_G8,SQ_F8,SQ_E8,SQ_D8,SQ_C8,SQ_B8,SQ_A8,
	SQ_I9,SQ_H9,SQ_G9,SQ_F9,SQ_E9,SQ_D9,SQ_C9,SQ_B9,SQ_A9,
};
const U8 Sq_Rank_Mirror[90] = {
	SQ_A9, SQ_B9, SQ_C9, SQ_D9, SQ_E9, SQ_F9, SQ_G9, SQ_H9, SQ_I9,
	SQ_A8, SQ_B8, SQ_C8, SQ_D8, SQ_E8, SQ_F8, SQ_G8, SQ_H8, SQ_I8,
	SQ_A7, SQ_B7, SQ_C7, SQ_D7, SQ_E7, SQ_F7, SQ_G7, SQ_H7, SQ_I7,
	SQ_A6, SQ_B6, SQ_C6, SQ_D6, SQ_E6, SQ_F6, SQ_G6, SQ_H6, SQ_I6,
	SQ_A5, SQ_B5, SQ_C5, SQ_D5, SQ_E5, SQ_F5, SQ_G5, SQ_H5, SQ_I5,
	SQ_A4, SQ_B4, SQ_C4, SQ_D4, SQ_E4, SQ_F4, SQ_G4, SQ_H4, SQ_I4,
	SQ_A3, SQ_B3, SQ_C3, SQ_D3, SQ_E3, SQ_F3, SQ_G3, SQ_H3, SQ_I3,
	SQ_A2, SQ_B2, SQ_C2, SQ_D2, SQ_E2, SQ_F2, SQ_G2, SQ_H2, SQ_I2,
	SQ_A1, SQ_B1, SQ_C1, SQ_D1, SQ_E1, SQ_F1, SQ_G1, SQ_H1, SQ_I1,
	SQ_A0, SQ_B0, SQ_C0, SQ_D0, SQ_E0, SQ_F0, SQ_G0, SQ_H0, SQ_I0,
};

const int Piece_Order_Value[16] = {
	0, 0, 4000, 600, 603, 11, 10, 80,
	0, 0, 4000, 600, 603, 11, 10, 80,
};
extern const S8 Piece_Sq_Index[1440];
INLINE int piece_index(int piece, int sq)
{
	return Piece_Sq_Index[(sq << 4) + piece];
}

INLINE bool color_is_ok(int color)
{
	return (color == White || color == Black);
}
INLINE int color_opp(int color)
{
	if (!color_is_ok(color))
	{
		color = color;
	}
	return (color ^ 1);
}
INLINE bool piece_is_ok(int piece)
{
	if ((piece >= WhiteKing && piece <= WhitePawn) || (piece >= BlackKing && piece <= BlackPawn))
		return true;
	else
		return false;
}

INLINE int piece_type(int piece)
{
	return (piece & 7);
}
INLINE int piece_color(int piece)
{
	return (piece ? (piece >> 3) : ColorNone);
}

INLINE int piece_make(int color, int type)
{
	return ((color << 3)+type);
}

INLINE bool sq_is_ok(int sq)
{
	return sq >=  SQ_A0 && sq < SQ_End;
}
INLINE bool file_is_ok(int file)
{
	return file >= File_A && file < File_End;
}
INLINE bool rank_is_ok(int rank)
{
	return rank >= Rank_0 && rank < Rank_End;
}
INLINE int sq_file(int sq)
{
	return Sq_File[sq];
}
INLINE int sq_rank(int sq)
{
	return Sq_Rank[sq];
}
INLINE int sq_make(int rank, int file)
{
	return (((rank)<<3)+(rank)+(file));
}
INLINE bool sq_equare_rank(int sq1, int sq2)
{
	return Sq_Rank[sq1] == Sq_Rank[sq2];
}
INLINE bool sq_equare_file(int sq1,int sq2)
{
	return Sq_File[sq1] == Sq_File[sq2];
}
INLINE int sq_color(int sq)
{
	return Sq_Color[sq];
}
INLINE int sq_file_mirror(int sq)
{
	return Sq_File_Mirror[sq];
}
INLINE int sq_rank_mirror(int sq)
{
	return Sq_Rank_Mirror[sq];
}

extern int piece_from_char(int c);
extern int piece_to_char(int p);
extern void square_to_string(int sq, char string[]);
extern int square_from_string(const char string[]);

#endif
