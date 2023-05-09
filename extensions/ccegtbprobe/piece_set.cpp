#include "xiangqi.h"
#include "piece_set.h"
#include <unistd.h>
#include <fcntl.h>
#include <set>
#include <algorithm>
#include <errno.h>

using namespace std;

const U8* Piece_Set_ID[16] = {
	NULL, NULL, Rook_Set_ID[White], Knight_Set_ID[White], Cannon_Set_ID[White], NULL, NULL, Pawn_Set_ID[White],
	NULL, NULL, Rook_Set_ID[Black], Knight_Set_ID[Black], Cannon_Set_ID[Black], NULL, NULL, Pawn_Set_ID[Black],
};

S8 Piece_Sq_Index[1440];
Group_Info Group_Table[40];

void piece_index_init()
{
	int ix, data;
	memset(Piece_Sq_Index, 0, sizeof(Piece_Sq_Index));
	for (int piece = 1; piece < 16; ++piece)
	{
		if (piece == 8)
			continue;
		for (int sq = 0; sq < 90; ++sq)
		{
			ix = (sq << 4) + piece;
			switch (piece)
			{
			case WhitePawn:
				data = White_Pawn_Pos[sq];
				break;
			case BlackPawn:
				data = White_Pawn_Pos[sq_rank_mirror(sq)];
				break;
			case WhiteKing:
				data = White_King_Pos[sq];
				break;
			case BlackKing:
				data = White_King_Pos[sq_rank_mirror(sq)];
				break;
			case WhiteAdvisor:
				data = White_Advisor_Pos[sq];
				break;
			case BlackAdvisor:
				data = White_Advisor_Pos[sq_rank_mirror(sq)];
				break;
			case WhiteBishop:
				data = White_Bishop_Pos[sq];
				break;
			case BlackBishop:
				data = White_Bishop_Pos[sq_rank_mirror(sq)];
				break;
			default:
				data = sq;
			}
			Piece_Sq_Index[ix] = data;
		}
	}
}

static inline bool read_exact(int fd, void* buf, size_t n) {
    ssize_t r = read(fd, buf, n);
    return r == (ssize_t)n;
}

bool group_init()
{
	int fd = open("/home/apache/GROUP.dat", O_RDONLY);
	if (fd == -1)
	{
		return false;
	}
	for(int i = 0; i < 40; ++i)
	{
		if(!read_exact(fd, &Group_Table[i], 28)) return false;
		if (Group_Table[i].piece_cnt)
		{
			Group_Table[i].index_tb = new uint32[Group_Table[i].index_size];
			if(!read_exact(fd, Group_Table[i].index_tb, Group_Table[i].index_size * sizeof(uint32))) return false;
		}
	}
	close(fd);
	return true;
}
