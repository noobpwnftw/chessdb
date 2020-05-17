#ifndef CHESSLIB_UNMOVE_H_
#define CHESSLIB_UNMOVE_H_

#include "chess.h"

typedef unsigned int ChessUnmove;

typedef enum
{
    CHESS_UNMOVE_CAPTURED_NONE = 0,
    CHESS_UNMOVE_CAPTURED_PAWN = 1,
    CHESS_UNMOVE_CAPTURED_KNIGHT = 2,
    CHESS_UNMOVE_CAPTURED_BISHOP = 3,
    CHESS_UNMOVE_CAPTURED_ROOK = 4,
    CHESS_UNMOVE_CAPTURED_QUEEN = 5
} ChessUnmoveCaptured;

typedef enum
{
    CHESS_UNMOVE_EP_NONE = 0,
    CHESS_UNMOVE_EP_CAPTURE = 1,
    CHESS_UNMOVE_EP_AVAILABLE = 2
    /* values 2->9 correspond to the file if you subtract 2 */
} ChessUnmoveEp;

/* Constructor */
ChessUnmove chess_unmove_make(
    ChessSquare from, ChessSquare to, ChessUnmoveCaptured captured,
    ChessBoolean promotion, ChessUnmoveEp ep,
    ChessCastleState castle, int fifty);

/* Accessors */
ChessSquare chess_unmove_from(ChessUnmove);
ChessSquare chess_unmove_to(ChessUnmove);
ChessUnmoveCaptured chess_unmove_captured(ChessUnmove);
ChessBoolean chess_unmove_promotion(ChessUnmove);
ChessUnmoveEp chess_unmove_ep(ChessUnmove);
ChessCastleState chess_unmove_castle(ChessUnmove);
int chess_unmove_fifty(ChessUnmove);

#endif /* CHESSLIB_UNMOVE_H_ */
