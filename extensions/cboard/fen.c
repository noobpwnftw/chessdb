#include <string.h>
#include <stdlib.h>
#include <ctype.h>
#include <stdio.h>

#include "fen.h"
#include "chess.h"
#include "position.h"
#include "generate.h"

const char* const CHESS_FEN_STARTING_POSITION = "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1";

ChessBoolean chess_fen_load(const char* s, ChessPosition* position)
{
    ChessPosition temp_position;
    ChessRank rank;
    ChessFile file;
    ChessPiece piece;
    char s_copy[CHESS_FEN_MAX_LENGTH];
    char *tokens[6], *token, *c;
    int t, m, skip;

    /* Clone the string, as strtok will clobber it */
    strncpy(s_copy, s, CHESS_FEN_MAX_LENGTH - 1);

    t = 0;
    token = strtok(s_copy, " _+");
    while (token && t < 6)
    {
        tokens[t++] = token;
        token = strtok(NULL, " _+");
    }

    /* Clear the position before filling it in */
    memset(&temp_position, 0, sizeof(ChessPosition));
    temp_position.ep = CHESS_FILE_INVALID;
    temp_position.move_num = 1;

    /* The first token is the board */
    rank = CHESS_RANK_8;
    token = strtok(tokens[0], "/");
    while (token && *token && rank >= CHESS_RANK_1)
    {
        m = 0;
        file = CHESS_FILE_A;
        while (token[m] && file <= CHESS_FILE_H)
        {
            piece = chess_piece_from_char(token[m]);
            if (piece != CHESS_PIECE_NONE)
            {
                temp_position.piece[chess_square_from_fr(file, rank)] = piece;
                file++;
            }
            else if (isdigit(token[m]))
            {
                skip = token[m] - '0';
                while (skip-- && file <= CHESS_FILE_H)
                {
                    temp_position.piece[chess_square_from_fr(file, rank)] = CHESS_PIECE_NONE;
                    file++;
                }
            }
            m++;
        }
        token = strtok(NULL, "/");
        rank--;
    }

    /* To move */
    if (t < 2 || !strcmp("w", tokens[1]))
        temp_position.to_move = CHESS_COLOR_WHITE;
    else if (!strcmp("b", tokens[1]))
        temp_position.to_move = CHESS_COLOR_BLACK;

    /* Castle availability */
    if (t > 2)
    {
        for (c = tokens[2]; *c; c++)
        {
            if (*c == 'K')
                temp_position.castle |= CHESS_CASTLE_STATE_WK;
            else if (*c == 'Q')
                temp_position.castle |= CHESS_CASTLE_STATE_WQ;
            else if (*c == 'k')
                temp_position.castle |= CHESS_CASTLE_STATE_BK;
            else if (*c == 'q')
                temp_position.castle |= CHESS_CASTLE_STATE_BQ;
        }
    }

    /* En passant */
    temp_position.ep = CHESS_FILE_INVALID;
    if (t > 3)
    {
        token = strchr("abcdefgh", tokens[3][0]);
        if (token && *token)
            temp_position.ep = *token - 'a';
    }

    /* Half moves */
    if (t > 4)
        temp_position.fifty = atoi(tokens[4]);

    /* Move num */
    if (t > 5)
        temp_position.move_num = atoi(tokens[5]);

    /* Validate the position before returning */
    if (chess_position_validate(&temp_position) == CHESS_FALSE)
        return CHESS_FALSE;

    chess_position_copy(&temp_position, position);
    return CHESS_TRUE;
}

int chess_fen_save(const ChessPosition* position, char* s)
{
    ChessFile file;
    ChessRank rank;
    ChessPiece piece;
    ChessCastleState castle = position->castle;
    ChessFile ep = position->ep;
    int run, n = 0;

    for (rank = CHESS_RANK_8; rank >= CHESS_RANK_1; rank--)
    {
        run = 0;
        for (file = CHESS_FILE_A; file <= CHESS_FILE_H; file++)
        {
            piece = position->piece[chess_square_from_fr(file, rank)];
            if (piece == CHESS_PIECE_NONE)
            {
                run++;
                continue;
            }

            if (run)
            {
                s[n++] = run + '0';
                run = 0;
            }
            s[n++] = chess_piece_to_char(piece);
        }

        if (run)
            s[n++] = run + '0';
        if (rank != CHESS_RANK_1)
            s[n++] = '/';
    }

    s[n++] = ' ';
    s[n++] = position->to_move == CHESS_COLOR_WHITE ? 'w' : 'b';
    s[n++] = ' ';
    if (castle != CHESS_CASTLE_STATE_NONE)
    {
        if (castle & CHESS_CASTLE_STATE_WK)
            s[n++] = 'K';
        if (castle & CHESS_CASTLE_STATE_WQ)
            s[n++] = 'Q';
        if (castle & CHESS_CASTLE_STATE_BK)
            s[n++] = 'k';
        if (castle & CHESS_CASTLE_STATE_BQ)
            s[n++] = 'q';
    }
    else
    {
        s[n++] = '-';
    }
    s[n++] = ' ';
    if (ep != CHESS_FILE_INVALID && chess_has_legal_ep(position))
    {
        s[n++] = chess_file_to_char(ep);
        s[n++] = (position->to_move == CHESS_COLOR_WHITE) ? '6' : '3';
    }
    else
    {
        s[n++] = '-';
    }
    //n += sprintf(s + n, " %d %d", position->fifty, position->move_num);
    s[n++] = '\0';
    return n;
}
