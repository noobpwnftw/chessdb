#include <assert.h>
#include <stdio.h>
#include <string.h>

#include "chess.h"

static const char piece_chars[] = "PpNnBbRrQqKk";
static const char rank_chars[] = "12345678";
static const char file_chars[] = "abcdefgh";

ChessColor chess_color_other(ChessColor color)
{
    assert(color == CHESS_COLOR_WHITE || color == CHESS_COLOR_BLACK);
    return (color == CHESS_COLOR_WHITE) ? CHESS_COLOR_BLACK : CHESS_COLOR_WHITE;
}

ChessColor chess_piece_color(ChessPiece piece)
{
    assert(piece >= CHESS_PIECE_WHITE_PAWN && piece <= CHESS_PIECE_BLACK_KING);
    return (piece % 2);
}

ChessPiece chess_piece_of_color(ChessPiece piece, ChessColor color)
{
    assert(piece >= CHESS_PIECE_WHITE_PAWN && piece <= CHESS_PIECE_BLACK_KING);
    return (piece & ~1) + color;
}

char chess_piece_to_char(ChessPiece piece)
{
    assert(piece >= CHESS_PIECE_WHITE_PAWN && piece <= CHESS_PIECE_BLACK_KING);
    return piece_chars[piece - CHESS_PIECE_WHITE_PAWN];
}

ChessPiece chess_piece_from_char(char c)
{
    char* s = strchr(piece_chars, c);
    return (s && *s) ? CHESS_PIECE_WHITE_PAWN + (s - piece_chars) : CHESS_PIECE_NONE;
}

ChessSquare chess_square_from_fr(ChessFile file, ChessRank rank)
{
    assert(file >= CHESS_FILE_A && file <= CHESS_FILE_H);
    assert(rank >= CHESS_RANK_1 && rank <= CHESS_RANK_8);
    return rank * 8 + file;
}

ChessFile chess_square_file(ChessSquare square)
{
    return (square % 8);
}

ChessRank chess_square_rank(ChessSquare square)
{
    return (square / 8);
}

ChessFile chess_file_from_char(char c)
{
    char *s = strchr(file_chars, c);
    return (s && *s) ? s - file_chars : CHESS_FILE_INVALID;
}

ChessRank chess_rank_from_char(char c)
{
    char *s = strchr(rank_chars, c);
    return (s && *s) ? s - rank_chars : CHESS_RANK_INVALID;
}

char chess_file_to_char(ChessFile file)
{
    assert(file >= CHESS_FILE_A && file <= CHESS_FILE_H);
    return file_chars[file];
}

char chess_rank_to_char(ChessRank rank)
{
    assert(rank >= CHESS_RANK_1 && rank <= CHESS_RANK_8);
    return rank_chars[rank];
}
