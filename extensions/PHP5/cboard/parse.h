#ifndef CHESSLIB_PARSE_H_
#define CHESSLIB_PARSE_H_

#include "move.h"
#include "position.h"

typedef enum {
    CHESS_PARSE_MOVE_OK = 0,
    CHESS_PARSE_MOVE_ERROR,
    CHESS_PARSE_MOVE_ILLEGAL,
    CHESS_PARSE_MOVE_AMBIGUOUS
} ChessParseMoveResult;

ChessParseMoveResult chess_parse_move(const char* s, const ChessPosition*, ChessMove*);

#endif /* CHESSLIB_PARSE_H_ */
