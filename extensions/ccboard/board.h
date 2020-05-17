#ifndef _BOARD_H_
#define _BOARD_H_
#include <stdio.h>
#include "square.h"
#include "move.h"
#include "data.h"
#include "list.h"

struct Board
{
    sint32  square[SquareNb];
    sint32  piece[33];
    sint32  number[16];
    sint32  turn;
    sint32  rank[10];
    sint32  file[9];
	Move	lastmove;
	int		lastcpt;
    Board()
    {
        clear();
    }
    void    clear(void);
    bool    init(void);
    bool    init(const char *);
    int     char2type(char a);
    void    getfen(char *) const;
    void    gen(List *list);
	void	makemove(const Move &move);
	void	unmakemove();
	bool	incheck(int side) const;
};
#endif
