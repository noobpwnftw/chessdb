#include <string.h>
#include <assert.h>

#include "chess.h"
#include "move.h"

static const char promote_chars[] = "nbrq";

ChessMove CHESS_MOVE_NULL = 0x80000000;

ChessMovePromote chess_move_promote_from_char(char c)
{
    char* s = strchr(promote_chars, c);
    return (s && *s) ? CHESS_MOVE_PROMOTE_KNIGHT + (s - promote_chars) : CHESS_MOVE_PROMOTE_NONE;
}

char chess_move_promote_to_char(ChessMovePromote promote)
{
    assert(promote >= CHESS_MOVE_PROMOTE_KNIGHT && promote <= CHESS_MOVE_PROMOTE_QUEEN);
    return promote_chars[promote - CHESS_MOVE_PROMOTE_KNIGHT];
}

ChessSquare chess_move_from(ChessMove move)
{
    return move & 077;
}

ChessSquare chess_move_to(ChessMove move)
{
    return (move >> 6) & 077;
}

ChessMovePromote chess_move_promotes(ChessMove move)
{
    return (move >> 12) & 017;
}

ChessMove chess_move_make(ChessSquare from, ChessSquare to)
{
    return from | (to << 6);
}

ChessMove chess_move_make_promote(ChessSquare from, ChessSquare to, ChessMovePromote promote)
{
    return from | (to << 6) | (promote << 12);
}
