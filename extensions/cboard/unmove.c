#include <assert.h>

#include "unmove.h"

ChessUnmove chess_unmove_make(
    ChessSquare from, ChessSquare to, ChessUnmoveCaptured captured,
    ChessBoolean promotion, ChessUnmoveEp ep,
    ChessCastleState castle, int fifty)
{
    /* When capturing ep, captured piece must be NONE */
    assert(ep != CHESS_UNMOVE_EP_CAPTURE || captured == CHESS_UNMOVE_CAPTURED_NONE);
    assert(ep >= CHESS_UNMOVE_EP_NONE);
    assert((int)ep <= CHESS_UNMOVE_EP_AVAILABLE + CHESS_FILE_H);

    return from             /* 6 bits */
        | (to << 6)         /* 6 bits */
        | (captured << 12)  /* 3 bits */
        | (promotion << 15) /* 1 bit */
        | (ep << 16)        /* 4 bits */
        | (castle << 20)    /* 4 bits */
        | (fifty << 24);    /* remaining 8 bits */
}

ChessSquare chess_unmove_from(ChessUnmove unmove)
{
    return unmove & 077;
}

ChessSquare chess_unmove_to(ChessUnmove unmove)
{
    return (unmove >> 6) & 077;
}

ChessUnmoveCaptured chess_unmove_captured(ChessUnmove unmove)
{
    return (unmove >> 12) & 07;
}

ChessBoolean chess_unmove_promotion(ChessUnmove unmove)
{
    return (unmove >> 15) & 1;
}

ChessUnmoveEp chess_unmove_ep(ChessUnmove unmove)
{
    return (unmove >> 16) & 0xf;
}

ChessCastleState chess_unmove_castle(ChessUnmove unmove)
{
    return (unmove >> 20) & 0xf;
}

int chess_unmove_fifty(ChessUnmove unmove)
{
    return (unmove >> 24);
}
