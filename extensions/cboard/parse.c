#include <assert.h>
#include <string.h>
#include <ctype.h>

#include "chess.h"
#include "move.h"
#include "unmove.h"
#include "position.h"
#include "parse.h"
#include "generate.h"
#include "carray.h"

static ChessBoolean matches_move(const ChessPosition* position, ChessMove move,
    char piece, char from_file, char from_rank, char capture, char to_file, char to_rank, char promote)
{
    ChessSquare from = chess_move_from(move);
    ChessSquare to = chess_move_to(move);
    ChessPiece move_piece;
    ChessMovePromote move_promote;

    if (piece)
    {
        move_piece = position->piece[from];
        if (tolower(chess_piece_to_char(move_piece)) != piece)
            return CHESS_FALSE;
    }

    if (from_file && chess_file_to_char(chess_square_file(from)) != from_file)
        return CHESS_FALSE;

    if (from_rank && chess_rank_to_char(chess_square_rank(from)) != from_rank)
        return CHESS_FALSE;

    if (capture && !chess_position_move_is_capture(position, move))
        return CHESS_FALSE;

    if (to_file && chess_file_to_char(chess_square_file(to)) != to_file)
        return CHESS_FALSE;

    if (to_rank && chess_rank_to_char(chess_square_rank(to)) != to_rank)
        return CHESS_FALSE;

    if (promote)
    {
        move_promote = chess_move_promotes(move);
        if (move_promote == CHESS_MOVE_PROMOTE_NONE
        || tolower(chess_move_promote_to_char(move_promote)) != promote)
            return CHESS_FALSE;
    }

    return CHESS_TRUE;
}

ChessParseMoveResult chess_parse_move(const char* s, const ChessPosition* position, ChessMove* ret_move)
{
    char piece = '\0';
    char from_file = '\0', from_rank = '\0';
    char to_file = '\0', to_rank = '\0';
    char capture = '\0';
    char equals = '\0', promote = '\0';
    const char* c;
    ChessMoveGenerator generator;
    ChessMove move, piece_move;
    ChessPiece pc;
    ChessBoolean null_move = CHESS_FALSE;
    ChessBoolean pawn_move, pm, ambiguous;

    assert(s && *s);

    if ((c = strchr("NBRQKnrqk", *s)) && *c)
    {
        piece = tolower(*c);
        s++;
    }
    if ((c = strchr("abcdefgh", *s)) && *c)
    {
        from_file = *c;
        s++;
    }
    if ((c = strchr("12345678", *s)) && *c)
    {
        from_rank = *c;
        s++;
    }
    if (tolower(*s) == 'x')
    {
        capture = 'x';
        s++;
    }
    if ((c = strchr("abcdefgh", *s)) && *c)
    {
        to_file = *c;
        s++;
    }
    if ((c = strchr("12345678", *s)) && *c)
    {
        to_rank = *c;
        s++;
    }
    if (*s == '=')
    {
        equals = '=';
        s++;
    }
    if ((c = strchr("NBRQKnbrqk", *s)) && *c)
    {
        promote = tolower(*c);
        s++;
    }

    if (equals && !promote)
        return CHESS_PARSE_MOVE_ERROR; /* Extra equals sign */

    while ((c = strchr("+#!?", *s)) && *c)
        s++;

    if (*s)
        return CHESS_PARSE_MOVE_ERROR; /* Leftover characters */

    if (null_move)
    {
        *ret_move = CHESS_MOVE_NULL;
        return CHESS_PARSE_MOVE_OK;
    }

    if (!capture && !to_file && !to_rank)
    {
        to_file = from_file;
        from_file = 0;
        to_rank = from_rank;
        from_rank = 0;
    }

    chess_move_generator_init(&generator, position);
    move = 0;
    piece_move = 0;
    pawn_move = CHESS_FALSE;
    ambiguous = CHESS_FALSE;
    while ((move = chess_move_generator_next(&generator)))
    {
        if (matches_move(position, move, piece, from_file, from_rank, capture, to_file, to_rank, promote))
        {
            if (piece)
            {
                if (piece_move)
                    return CHESS_PARSE_MOVE_AMBIGUOUS;
                piece_move = move;
            }
            else
            {
                /* Need to prioritise pawn moves */
                pc = position->piece[chess_move_from(move)];
                pm = (pc == CHESS_PIECE_WHITE_PAWN || pc == CHESS_PIECE_BLACK_PAWN);
                if (!piece_move || (pm && !pawn_move))
                {
                    piece_move = move;
                    pawn_move = pm;
                    ambiguous = CHESS_FALSE;
                }
                else if (pm == pawn_move)
                {
                    if (pm)
                    {
                        /* Ambiguous pawn moves, no hope of correcting */
                        return CHESS_PARSE_MOVE_AMBIGUOUS;
                    }
                    else
                    {
                        /* Ambiguous piece moves, we may still find a pawn move */
                        ambiguous = CHESS_TRUE;
                    }
                }
            }
        }
    }

    if (piece_move == 0)
        return CHESS_PARSE_MOVE_ILLEGAL;

    if (ambiguous)
        return CHESS_PARSE_MOVE_AMBIGUOUS;

    *ret_move = piece_move;
    return CHESS_PARSE_MOVE_OK;
}
