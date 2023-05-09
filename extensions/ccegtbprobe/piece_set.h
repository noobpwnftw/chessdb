#ifndef _PIECE_SET_H_
#define _PIECE_SET_H_

#include <memory.h>
#include <cstdio>

enum {
	W_K = 1,  W_KA, W_KB, W_KAA, W_KAB, W_KBB, W_KAAB, W_KABB, W_KAABB, W_R, W_N, W_C, W_P, W_RR, W_NN, W_CC, W_PP, W_PPP, W_PPPP,
	B_K = 21, B_KA, B_KB, B_KAA, B_KAB, B_KBB, B_KAAB, B_KABB, B_KAABB, B_R, B_N, B_C, B_P, B_RR, B_NN, B_CC, B_PP, B_PPP, B_PPPP,
};
enum{
	WSet_Defend, WSet_Rook, WSet_Knight, WSet_Cannon, WSet_Pawn,
	BSet_Defend, BSet_Rook, BSet_Knight, BSet_Cannon, BSet_Pawn,
};
const U8 Defend_Set_ID[2][3][3] = {
	{W_K, W_KB, W_KBB,
	W_KA, W_KAB, W_KABB,
	W_KAA, W_KAAB, W_KAABB},
	{B_K, B_KB, B_KBB,
	B_KA, B_KAB, B_KABB,
	B_KAA, B_KAAB, B_KAABB},
};
const U8 Rook_Set_ID[2][3] = {{0, W_R, W_RR},{0, B_R, B_RR}};
const U8 Knight_Set_ID[2][3] = {{0, W_N, W_NN},{0, B_N, B_NN}};
const U8 Cannon_Set_ID[2][3] = {{0, W_C, W_CC},{0, B_C, B_CC}};
const U8 Pawn_Set_ID[2][4] = {{0, W_P, W_PP, W_PPP},{0, B_P, B_PP, B_PPP}};
extern const U8* Piece_Set_ID[16];

const int Piece_Order_Value[16] = {
	0, 0, 4000, 600, 603, 11, 10, 80,
	0, 0, 4000, 600, 603, 11, 10, 80,
};
extern S8 Piece_Sq_Index[1440];

inline int piece_index(int piece, int sq)
{
	return Piece_Sq_Index[(sq << 4) + piece];
}
inline int defend_set(int a_nb, int b_nb, int color)
{
	return Defend_Set_ID[color][a_nb][b_nb];
}
inline int piece_set(int piece, int number)
{
	return Piece_Set_ID[piece][number];
}
#pragma pack(push, 4)
struct Group_Info
{
	S8 info_id;
	S8 piece_cnt;
	S8 pieces[6];
	uint16 table_size;
	uint16 compress_size;
	uint16 weight[6];
	uint32 index_size;
	uint32 * index_tb;

	Group_Info()
	{
		memset(this, 0, sizeof(*this));
	}
	~Group_Info()
	{
		if (piece_cnt)
		{
			delete[] index_tb;
		}
	}

	uint32 get_list_pos(const S8* sq_list)const
	{
		int index = 0;
		for (int i = 0; i < piece_cnt; ++i)
		{
			index += weight[i] * piece_index(pieces[i], sq_list[i]);
		}
		return index_tb[index];
	}
};
#pragma pack(pop)

extern Group_Info Group_Table[40];
inline const Group_Info* get_set_info(int set_id)
{
	return &Group_Table[set_id];
}

extern void piece_index_init();
extern bool group_init();

#endif
