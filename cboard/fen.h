#ifndef CHESSLIB_FEN_H_
#define CHESSLIB_FEN_H_

#include "chess.h"
#include "position.h"

/* Longest possible FEN string is about 102 characters */
#define CHESS_FEN_MAX_LENGTH 128

extern const char* const CHESS_FEN_STARTING_POSITION;

ChessBoolean chess_fen_load(const char* s, ChessPosition*);
int chess_fen_save(const ChessPosition*, char* s);

#endif /* CHESSLIB_FEN_H_ */
