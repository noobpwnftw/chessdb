#ifndef CHESSLIB_MOVE_H_
#define CHESSLIB_MOVE_H_

#include "chess.h"

typedef int ChessMove;

typedef enum
{
    CHESS_MOVE_PROMOTE_NONE = 0,
    CHESS_MOVE_PROMOTE_KNIGHT = 1,
    CHESS_MOVE_PROMOTE_BISHOP = 2,
    CHESS_MOVE_PROMOTE_ROOK = 3,
    CHESS_MOVE_PROMOTE_QUEEN = 4
} ChessMovePromote;

extern ChessMove CHESS_MOVE_NULL;

ChessMovePromote chess_move_promote_from_char(char);
char chess_move_promote_to_char(ChessMovePromote);

ChessSquare chess_move_from(ChessMove);
ChessSquare chess_move_to(ChessMove);
ChessMovePromote chess_move_promotes(ChessMove);

ChessMove chess_move_make(ChessSquare from, ChessSquare to);
ChessMove chess_move_make_promote(ChessSquare from, ChessSquare to, ChessMovePromote);

#endif /* CHESSLIB_MOVE_H_ */
