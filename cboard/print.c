#include <assert.h>
#include <stdio.h>
#include <ctype.h>
#include <string.h>

#include "print.h"
#include "carray.h"
#include "generate.h"

int chess_print_move(ChessMove move, char* s)
{
    ChessSquare from = chess_move_from(move);
    ChessSquare to = chess_move_to(move);
    ChessMovePromote promote = chess_move_promotes(move);

    int n = 0;
    s[n++] = chess_file_to_char(chess_square_file(from));
    s[n++] = chess_rank_to_char(chess_square_rank(from));
    s[n++] = chess_file_to_char(chess_square_file(to));
    s[n++] = chess_rank_to_char(chess_square_rank(to));
    if (promote != CHESS_MOVE_PROMOTE_NONE)
    {
        static const char chars[] = " qrbn";
        s[n++] = chars[promote];
    }
    s[n] = '\0';
    return n;
}

int chess_print_move_san(ChessMove move, const ChessPosition* position, char* s)
{
    ChessSquare from = chess_move_from(move);
    ChessSquare to = chess_move_to(move);
    ChessMovePromote promote = chess_move_promotes(move);
    ChessPiece piece;
    ChessMoveGenerator generator;
    ChessMove move2;
    ChessBoolean capture;
    ChessBoolean piece_ambiguous = CHESS_FALSE;
    ChessBoolean file_ambiguous = CHESS_FALSE, rank_ambiguous = CHESS_FALSE;
    ChessSquare sq;
    ChessFile file;
    ChessRank rank;
    ChessPosition temp_position;
    size_t n = 0;

    /* Check for null move */
    if (move == CHESS_MOVE_NULL)
    {
        strcpy(s, "--");
        return 2;
    }

    piece = position->piece[from];
    assert(piece != CHESS_PIECE_NONE);

    /* Handle castling */
    if ((piece == CHESS_PIECE_WHITE_KING || piece == CHESS_PIECE_BLACK_KING)
        && chess_square_file(from) == CHESS_FILE_E)
    {
        if (chess_square_file(to) == CHESS_FILE_G)
        {
            strcpy(s, "O-O");
            return 3;
        }
        if (chess_square_file(to) == CHESS_FILE_C)
        {
            strcpy(s, "O-O-O");
            return 5;
        }
    }

    /* Always include to square and capture */
    capture = chess_position_move_is_capture(position, move);

    if (piece == CHESS_PIECE_WHITE_PAWN || piece == CHESS_PIECE_BLACK_PAWN)
    {
        /* Pawn moves are always unambiguous */
        if (capture)
            s[n++] = chess_file_to_char(chess_square_file(from));
    }
    else
    {
        s[n++] = toupper(chess_piece_to_char(piece));

        /* Need to examine legal moves to determine ambiguity */
        chess_move_generator_init(&generator, position);

        file = chess_square_file(from);
        rank = chess_square_rank(from);

        while ((move2 = chess_move_generator_next(&generator)))
        {
            if (chess_move_to(move2) != to)
                continue;

            sq = chess_move_from(move2);
            if (sq == from)
                continue; /* same move */
            if (position->piece[sq] != piece)
                continue; /* different piece */

            piece_ambiguous = CHESS_TRUE;
            if (chess_square_file(sq) == file)
                file_ambiguous = CHESS_TRUE;
            if (chess_square_rank(sq) == rank)
                rank_ambiguous = CHESS_TRUE;
        }

        if (piece_ambiguous)
        {
            if (!file_ambiguous)
            {
                s[n++] = chess_file_to_char(file);
            }
            else if (!rank_ambiguous)
            {
                s[n++] = chess_rank_to_char(rank);
            }
            else
            {
                s[n++] = chess_file_to_char(file);
                s[n++] = chess_rank_to_char(rank);
            }
        }
    }

    if (capture)
        s[n++] = 'x';

    s[n++] = chess_file_to_char(chess_square_file(to));
    s[n++] = chess_rank_to_char(chess_square_rank(to));

    if (promote != CHESS_MOVE_PROMOTE_NONE)
    {
        static char chars[] = " QRBN";
        s[n++] = '=';
        s[n++] = chars[promote];
    }

    /* Add check or mate symbol */
    chess_position_copy(position, &temp_position);
    chess_position_make_move(&temp_position, move);
    if (chess_position_is_check(&temp_position))
    {
        if (chess_position_check_result(&temp_position) == CHESS_RESULT_NONE)
            s[n++] = '+';
        else
            s[n++] = '#';
    }

    s[n] = '\0';
    return n;
}

int chess_print_position(const ChessPosition* position, char* s)
{
    int n = 0;
    ChessColor to_move;
    ChessCastleState castle;
    ChessFile ep;
    int move_num;
    int rank, file;

    for (rank = CHESS_RANK_8; rank >= CHESS_RANK_1; --rank)
        for (file = CHESS_FILE_A; file <= CHESS_FILE_H; ++file)
        {
            ChessPiece piece = position->piece[chess_square_from_fr((ChessFile)file, (ChessRank)rank)];
            char c;
            if (piece == CHESS_PIECE_NONE)
            {
                c = (file + rank) % 2 == 0 ? '+' : '-';
            }
            else
            {
                c = chess_piece_to_char(piece);
            }

            s[n++] = c;

            if (file == CHESS_FILE_H)
                s[n++] = '\n';
        }

    to_move = position->to_move;
    castle = position->castle;
    ep = position->ep;
    move_num = position->move_num;
    n += sprintf(s + n, "%d. %s to move %c%c%c%c %c (%d)\n",
                 move_num,
                 to_move == CHESS_COLOR_WHITE ? "White" : "Black",
                 castle & CHESS_CASTLE_STATE_WK ? 'K' : '-',
                 castle & CHESS_CASTLE_STATE_WQ ? 'Q' : '-',
                 castle & CHESS_CASTLE_STATE_BK ? 'k' : '-',
                 castle & CHESS_CASTLE_STATE_BQ ? 'q' : '-',
                 ep != CHESS_FILE_INVALID ? chess_file_to_char(ep) : '-',
                 position->fifty);

    return n;
}

int chess_print_result(ChessResult result, char* s)
{
    assert(result >= CHESS_RESULT_WHITE_WINS && result <= CHESS_RESULT_IN_PROGRESS);
    switch (result)
    {
        case CHESS_RESULT_WHITE_WINS:
            strcpy(s, "1-0");
            break;
        case CHESS_RESULT_BLACK_WINS:
            strcpy(s, "0-1");
            break;
        case CHESS_RESULT_DRAW:
            strcpy(s, "1/2-1/2");
            break;
        case CHESS_RESULT_IN_PROGRESS:
            strcpy(s, "*");
            break;
        default:
            assert(CHESS_FALSE);
            break;
    }
    return strlen(s);
}
