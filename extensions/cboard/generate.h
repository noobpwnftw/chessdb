#ifndef CHESSLIB_GENERATE_H_
#define CHESSLIB_GENERATE_H_

#include <stdbool.h>
#include "chess.h"
#include "position.h"
#include "carray.h"

typedef struct
{
    const ChessPosition* position;
    ChessSquare sq;
    ChessSquare to;
    int d;
    ChessMovePromote promote;
    ChessCastleState castle;
    bool is_ep;
} ChessMoveGenerator;

void chess_generate_init(void);

void chess_move_generator_init(ChessMoveGenerator*, const ChessPosition*);
ChessMove chess_move_generator_next(ChessMoveGenerator*);

void chess_generate_moves(const ChessPosition*, ChessArray*);
ChessBoolean chess_generate_is_square_attacked(const ChessPosition*, ChessSquare, ChessColor);
ChessBoolean chess_generate_check_impossible(const ChessPosition*, ChessSquare, ChessColor);
bool chess_has_legal_ep(const ChessPosition* position);

#endif /* CHESSLIB_GENERATE_H_ */
