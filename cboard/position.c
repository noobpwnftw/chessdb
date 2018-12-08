#include <assert.h>
#include <stdlib.h>
#include <memory.h>
#include <stdio.h>

#include "position.h"
#include "generate.h"
#include "fen.h"
#include "calloc.h"
#include "carray.h"

void chess_position_copy(const ChessPosition* from, ChessPosition* to)
{
    memcpy(to, from, sizeof(ChessPosition));
}

ChessBoolean chess_position_validate(ChessPosition* position)
{
    ChessSquare sq, other_king;
    ChessRank rank;
    ChessPiece pc;
    ChessPosition temp_position;

    chess_position_copy(position, &temp_position);
    temp_position.wking = CHESS_SQUARE_INVALID;
    temp_position.bking = CHESS_SQUARE_INVALID;

    for (sq = CHESS_SQUARE_A1; sq <= CHESS_SQUARE_H8; ++sq)
    {
        pc = position->piece[sq];
        if (pc == CHESS_PIECE_WHITE_KING)
        {
            if (temp_position.wking != CHESS_SQUARE_INVALID)
                return CHESS_FALSE; /* Too many white kings */

            temp_position.wking = sq;
        }
        else if (pc == CHESS_PIECE_BLACK_KING)
        {
            if (temp_position.bking != CHESS_SQUARE_INVALID)
                return CHESS_FALSE; /* Too many black kings */

            temp_position.bking = sq;
        }
        else if (pc == CHESS_PIECE_WHITE_PAWN || pc == CHESS_PIECE_BLACK_PAWN)
        {
            rank = chess_square_rank(sq);
            if (rank == CHESS_RANK_1 || rank == CHESS_RANK_8)
            {
                /* Pawn on first or last rank */
                return CHESS_FALSE;
            }
        }
    }

    if (temp_position.wking == CHESS_SQUARE_INVALID
        || temp_position.bking == CHESS_SQUARE_INVALID)
    {
        /* No white king or black king */
        return CHESS_FALSE;
    }

    /* Clear any impossible castling states */
    if (temp_position.piece[CHESS_SQUARE_E1] != CHESS_PIECE_WHITE_KING)
    {
        temp_position.castle &= ~CHESS_CASTLE_STATE_WKQ;
    }
    if (temp_position.piece[CHESS_SQUARE_H1] != CHESS_PIECE_WHITE_ROOK)
    {
        temp_position.castle &= ~CHESS_CASTLE_STATE_WK;
    }
    if (temp_position.piece[CHESS_SQUARE_A1] != CHESS_PIECE_WHITE_ROOK)
    {
        temp_position.castle &= ~CHESS_CASTLE_STATE_WQ;
    }
    if (temp_position.piece[CHESS_SQUARE_E8] != CHESS_PIECE_BLACK_KING)
    {
        temp_position.castle &= ~CHESS_CASTLE_STATE_BKQ;
    }
    if (temp_position.piece[CHESS_SQUARE_H8] != CHESS_PIECE_BLACK_ROOK)
    {
        temp_position.castle &= ~CHESS_CASTLE_STATE_BK;
    }
    if (temp_position.piece[CHESS_SQUARE_A8] != CHESS_PIECE_BLACK_ROOK)
    {
        temp_position.castle &= ~CHESS_CASTLE_STATE_BQ;
    }

    /* Clear en passant state if it's not valid */
    if (temp_position.ep != CHESS_FILE_INVALID)
    {
        /* Valid would mean that a pawn (possibly) just moved to its 4th rank */
        if ((temp_position.to_move == CHESS_COLOR_WHITE &&
            temp_position.piece[chess_square_from_fr(temp_position.ep, CHESS_RANK_5)] != CHESS_PIECE_BLACK_PAWN) ||
            (temp_position.to_move == CHESS_COLOR_BLACK &&
            temp_position.piece[chess_square_from_fr(temp_position.ep, CHESS_RANK_4)] != CHESS_PIECE_WHITE_PAWN))
        {
            temp_position.ep = CHESS_FILE_INVALID;
        }
    }

    other_king = (temp_position.to_move == CHESS_COLOR_WHITE)
        ? temp_position.bking : temp_position.wking;
    if (chess_generate_is_square_attacked(&temp_position, other_king, temp_position.to_move))
    {
        /* Opponent's king is en prise */
        return CHESS_FALSE;
    }

    /* All checks passed! */
    chess_position_copy(&temp_position, position);
    return CHESS_TRUE;
}

ChessBoolean chess_position_is_check(const ChessPosition* position)
{
    if (position->to_move == CHESS_COLOR_WHITE)
        return chess_generate_is_square_attacked(position, position->wking, CHESS_COLOR_BLACK);
    else
        return chess_generate_is_square_attacked(position, position->bking, CHESS_COLOR_WHITE);
}

ChessBoolean chess_position_move_is_legal(const ChessPosition* position, ChessMove move)
{
    ChessMoveGenerator generator;
    ChessMove legalMove;

    chess_move_generator_init(&generator, position);
    while ((legalMove = chess_move_generator_next(&generator)))
    {
        if (legalMove == move)
            return CHESS_TRUE;
    }
    return CHESS_FALSE;
}

ChessBoolean chess_position_move_is_capture(const ChessPosition* position, ChessMove move)
{
    ChessSquare to = chess_move_to(move);
    ChessRank ep_rank;
    if (position->piece[to] != CHESS_PIECE_NONE)
        return CHESS_TRUE;

    /* Special case is en passant */
    ep_rank = (position->to_move == CHESS_COLOR_WHITE) ? CHESS_RANK_6 : CHESS_RANK_3;
    return (position->ep != CHESS_FILE_INVALID && to == chess_square_from_fr(position->ep, ep_rank));
}

ChessResult chess_position_check_result(const ChessPosition* position)
{
    ChessMoveGenerator generator;

    chess_move_generator_init(&generator, position);
    if (chess_move_generator_next(&generator) > 0)
        return CHESS_RESULT_NONE;

    if (!chess_position_is_check(position))
        return CHESS_RESULT_DRAW; /* Stalemate */

    return (position->to_move == CHESS_COLOR_WHITE) ?
        CHESS_RESULT_BLACK_WINS : CHESS_RESULT_WHITE_WINS;
}

static ChessUnmoveCaptured capture_piece(ChessPiece piece)
{
    switch (piece)
    {
        case CHESS_PIECE_NONE:
            return CHESS_UNMOVE_CAPTURED_NONE;
        case CHESS_PIECE_WHITE_PAWN:
        case CHESS_PIECE_BLACK_PAWN:
            return CHESS_UNMOVE_CAPTURED_PAWN;
        case CHESS_PIECE_WHITE_KNIGHT:
        case CHESS_PIECE_BLACK_KNIGHT:
            return CHESS_UNMOVE_CAPTURED_KNIGHT;
        case CHESS_PIECE_WHITE_BISHOP:
        case CHESS_PIECE_BLACK_BISHOP:
            return CHESS_UNMOVE_CAPTURED_BISHOP;
        case CHESS_PIECE_WHITE_ROOK:
        case CHESS_PIECE_BLACK_ROOK:
            return CHESS_UNMOVE_CAPTURED_ROOK;
        case CHESS_PIECE_WHITE_QUEEN:
        case CHESS_PIECE_BLACK_QUEEN:
            return CHESS_UNMOVE_CAPTURED_QUEEN;
        default:
            assert(0);
            return 0;
    }
}

static ChessPiece promoted_piece(ChessMovePromote promote, ChessColor color)
{
    switch (promote)
    {
        case CHESS_MOVE_PROMOTE_KNIGHT:
            return chess_piece_of_color(CHESS_PIECE_WHITE_KNIGHT, color);
        case CHESS_MOVE_PROMOTE_BISHOP:
            return chess_piece_of_color(CHESS_PIECE_WHITE_BISHOP, color);
        case CHESS_MOVE_PROMOTE_ROOK:
            return chess_piece_of_color(CHESS_PIECE_WHITE_ROOK, color);
        case CHESS_MOVE_PROMOTE_QUEEN:
            return chess_piece_of_color(CHESS_PIECE_WHITE_QUEEN, color);
        default:
            assert(0);
            return 0;
    }
}

ChessUnmove chess_position_make_move(ChessPosition* position, ChessMove move)
{
    ChessSquare from = chess_move_from(move);
    ChessSquare to = chess_move_to(move);
    ChessMovePromote promote = chess_move_promotes(move);
    ChessPiece piece;
    ChessColor color = position->to_move;
    ChessUnmoveEp ep;
    ChessUnmoveCaptured captured;
    ChessCastleState castle = position->castle;
    int fifty = position->fifty;

    /* Move the piece */
    if (move == CHESS_MOVE_NULL)
    {
        piece = CHESS_PIECE_NONE;
        captured = CHESS_UNMOVE_CAPTURED_NONE;
    }
    else
    {
        piece = position->piece[from];
        captured = capture_piece(position->piece[to]);

        position->piece[from] = CHESS_PIECE_NONE;
        if (promote == CHESS_MOVE_PROMOTE_NONE)
        {
            position->piece[to] = piece;
            if (piece == CHESS_PIECE_WHITE_KING)
                position->wking = to;
            else if (piece == CHESS_PIECE_BLACK_KING)
                position->bking = to;
        }
        else
        {
            position->piece[to] = promoted_piece(promote, color);
        }

        /* Handle castling */
        if (piece == CHESS_PIECE_WHITE_KING && from == CHESS_SQUARE_E1)
        {
            if (to == CHESS_SQUARE_G1)
            {
                position->piece[CHESS_SQUARE_F1] = CHESS_PIECE_WHITE_ROOK;
                position->piece[CHESS_SQUARE_H1] = CHESS_PIECE_NONE;
            }
            else if (to == CHESS_SQUARE_C1)
            {
                position->piece[CHESS_SQUARE_D1] = CHESS_PIECE_WHITE_ROOK;
                position->piece[CHESS_SQUARE_A1] = CHESS_PIECE_NONE;
            }
        }
        else if (piece == CHESS_PIECE_BLACK_KING && from == CHESS_SQUARE_E8)
        {
            if (to == CHESS_SQUARE_G8)
            {
                position->piece[CHESS_SQUARE_F8] = CHESS_PIECE_BLACK_ROOK;
                position->piece[CHESS_SQUARE_H8] = CHESS_PIECE_NONE;
            }
            else if (to == CHESS_SQUARE_C8)
            {
                position->piece[CHESS_SQUARE_D8] = CHESS_PIECE_BLACK_ROOK;
                position->piece[CHESS_SQUARE_A8] = CHESS_PIECE_NONE;
            }
        }

        /* Check if castling availability was lost */
        if (position->castle & (CHESS_CASTLE_STATE_WKQ))
        {
            if (from == CHESS_SQUARE_A1 || to == CHESS_SQUARE_A1)
                position->castle &= ~CHESS_CASTLE_STATE_WQ;

            if (from == CHESS_SQUARE_H1 || to == CHESS_SQUARE_H1)
                position->castle &= ~CHESS_CASTLE_STATE_WK;

            if (from == CHESS_SQUARE_E1 || to == CHESS_SQUARE_E1)
                position->castle &= ~(CHESS_CASTLE_STATE_WKQ);
        }
        if (position->castle & (CHESS_CASTLE_STATE_BKQ))
        {
            if (from == CHESS_SQUARE_A8 || to == CHESS_SQUARE_A8)
                position->castle &= ~CHESS_CASTLE_STATE_BQ;

            if (from == CHESS_SQUARE_H8 || to == CHESS_SQUARE_H8)
                position->castle &= ~CHESS_CASTLE_STATE_BK;

            if (from == CHESS_SQUARE_E8 || to == CHESS_SQUARE_E8)
                position->castle &= ~(CHESS_CASTLE_STATE_BKQ);
        }
    }

    /* Handle ep */
    if (position->ep == CHESS_FILE_INVALID)
    {
        ep = CHESS_UNMOVE_EP_NONE;
    }
    else
    {
        if (piece == CHESS_PIECE_WHITE_PAWN && to == chess_square_from_fr(position->ep, CHESS_RANK_6))
        {
            position->piece[chess_square_from_fr(position->ep, CHESS_RANK_5)] = CHESS_PIECE_NONE;
            ep = CHESS_UNMOVE_EP_CAPTURE;
        }
        else if (piece == CHESS_PIECE_BLACK_PAWN && to == chess_square_from_fr(position->ep, CHESS_RANK_3))
        {
            position->piece[chess_square_from_fr(position->ep, CHESS_RANK_4)] = CHESS_PIECE_NONE;
            ep = CHESS_UNMOVE_EP_CAPTURE;
        }
        else
        {
            ep = CHESS_UNMOVE_EP_AVAILABLE + position->ep;
        }
    }

    /* Update ep on a double pawn move */
    if (piece == CHESS_PIECE_WHITE_PAWN && to - from == 16)
        position->ep = chess_square_file(to);
    else if (piece == CHESS_PIECE_BLACK_PAWN && from - to == 16)
        position->ep = chess_square_file(to);
    else
        position->ep = CHESS_FILE_INVALID;

    /* Update fifty counter only if a reversible move was played */
    if (piece == CHESS_PIECE_WHITE_PAWN || piece == CHESS_PIECE_BLACK_PAWN
        || captured != CHESS_UNMOVE_CAPTURED_NONE || castle != position->castle)
        position->fifty = 0;
    else
        position->fifty++;

    /* Update move counter */
    position->to_move = chess_color_other(position->to_move);
    if (position->to_move == CHESS_COLOR_WHITE)
        position->move_num++;

    return chess_unmove_make(from, to, captured,
        promote != CHESS_MOVE_PROMOTE_NONE, ep, castle, fifty);
}

static ChessPiece captured_piece(ChessUnmoveCaptured captured, ChessColor color)
{
    switch (captured)
    {
        case CHESS_UNMOVE_CAPTURED_NONE:
            return CHESS_PIECE_NONE;
        case CHESS_UNMOVE_CAPTURED_PAWN:
            return chess_piece_of_color(CHESS_PIECE_WHITE_PAWN, color);
        case CHESS_UNMOVE_CAPTURED_KNIGHT:
            return chess_piece_of_color(CHESS_PIECE_WHITE_KNIGHT, color);
        case CHESS_UNMOVE_CAPTURED_BISHOP:
            return chess_piece_of_color(CHESS_PIECE_WHITE_BISHOP, color);
        case CHESS_UNMOVE_CAPTURED_ROOK:
            return chess_piece_of_color(CHESS_PIECE_WHITE_ROOK, color);
        case CHESS_UNMOVE_CAPTURED_QUEEN:
            return chess_piece_of_color(CHESS_PIECE_WHITE_QUEEN, color);
        default:
            assert(0);
            return 0;
    }
}

void chess_position_undo_move(ChessPosition* position, ChessUnmove unmove)
{
    ChessSquare from = chess_unmove_from(unmove);
    ChessSquare to = chess_unmove_to(unmove);
    ChessUnmoveCaptured captured = chess_unmove_captured(unmove);
    ChessUnmoveEp ep = chess_unmove_ep(unmove);

    ChessPiece piece;
    ChessColor other = position->to_move;
    ChessColor color = chess_color_other(other);
    ChessFile file;

    if (from == 0 && to == 0)
    {
        /* Null move */
        piece = CHESS_PIECE_NONE;
    }
    else
    {
        if (chess_unmove_promotion(unmove))
            piece = chess_piece_of_color(CHESS_PIECE_WHITE_PAWN, color);
        else
            piece = position->piece[to];
        assert(color == chess_piece_color(piece));

        /* Unmove the piece */
        position->piece[from] = piece;
        position->piece[to] = captured_piece(captured, other);

        /* Handle castling */
        if (piece == CHESS_PIECE_WHITE_KING && from == CHESS_SQUARE_E1)
        {
            if (to == CHESS_SQUARE_G1)
            {
                position->piece[CHESS_SQUARE_F1] = CHESS_PIECE_NONE;
                position->piece[CHESS_SQUARE_H1] = CHESS_PIECE_WHITE_ROOK;
            }
            else if (to == CHESS_SQUARE_C1)
            {
                position->piece[CHESS_SQUARE_D1] = CHESS_PIECE_NONE;
                position->piece[CHESS_SQUARE_A1] = CHESS_PIECE_WHITE_ROOK;
            }
        }
        else if (piece == CHESS_PIECE_BLACK_KING && from == CHESS_SQUARE_E8)
        {
            if (to == CHESS_SQUARE_G8)
            {
                position->piece[CHESS_SQUARE_F8] = CHESS_PIECE_NONE;
                position->piece[CHESS_SQUARE_H8] = CHESS_PIECE_BLACK_ROOK;
            }
            else if (to == CHESS_SQUARE_C8)
            {
                position->piece[CHESS_SQUARE_D8] = CHESS_PIECE_NONE;
                position->piece[CHESS_SQUARE_A8] = CHESS_PIECE_BLACK_ROOK;
            }
        }
        position->castle = chess_unmove_castle(unmove);
    }

    /* Handle ep */
    if (ep == CHESS_UNMOVE_EP_NONE)
    {
        position->ep = CHESS_FILE_INVALID;
    }
    else if (ep == CHESS_UNMOVE_EP_CAPTURE)
    {
        assert(piece == CHESS_PIECE_WHITE_PAWN || piece == CHESS_PIECE_BLACK_PAWN);

        /* Restore the captured pawn */
        file = chess_square_file(to);
        if (color == CHESS_COLOR_WHITE)
            position->piece[chess_square_from_fr(file, CHESS_RANK_5)] = CHESS_PIECE_BLACK_PAWN;
        else
            position->piece[chess_square_from_fr(file, CHESS_RANK_4)] = CHESS_PIECE_WHITE_PAWN;
        position->ep = file;
    }
    else
    {
        position->ep = ep - CHESS_UNMOVE_EP_AVAILABLE;
    }

    /* Update king positions */
    if (piece == CHESS_PIECE_WHITE_KING)
        position->wking = from;
    else if (piece == CHESS_PIECE_BLACK_KING)
        position->bking = from;

    /* Update move counters */
    position->fifty = chess_unmove_fifty(unmove);

    position->to_move = color;
    if (position->to_move == CHESS_COLOR_BLACK)
        position->move_num--;
}
