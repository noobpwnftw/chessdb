#include <assert.h>
#include <stdio.h>

#include "generate.h"

typedef enum {
    DIR_N = (1 << 0),
    DIR_NE = (1 << 1),
    DIR_E = (1 << 2),
    DIR_SE = (1 << 3),
    DIR_S = (1 << 4),
    DIR_SW = (1 << 5),
    DIR_W = (1 << 6),
    DIR_NW = (1 << 7)
} Dirs;

typedef enum {
    SLIDE_N = 8,
    SLIDE_NE = 9,
    SLIDE_E = 1,
    SLIDE_SE = -7,
    SLIDE_S = -8,
    SLIDE_SW = -9,
    SLIDE_W = -1,
    SLIDE_NW = 7
} Slides;

typedef enum {
    JUMP_NNE = 17,
    JUMP_ENE = 10,
    JUMP_ESE = -6,
    JUMP_SSE = -15,
    JUMP_SSW = -17,
    JUMP_WSW = -10,
    JUMP_WNW = 6,
    JUMP_NNW = 15
} Jumps;

static int slide_dirs[64];
static int jump_dirs[64];

static int dirs_array[] = { DIR_N, DIR_NE, DIR_E, DIR_SE, DIR_S, DIR_SW, DIR_W, DIR_NW };
static int slides_array[] = { SLIDE_N, SLIDE_NE, SLIDE_E, SLIDE_SE, SLIDE_S, SLIDE_SW, SLIDE_W, SLIDE_NW };
static int jumps_array[] = { JUMP_NNE, JUMP_ENE, JUMP_ESE, JUMP_SSE, JUMP_SSW, JUMP_WSW, JUMP_WNW, JUMP_NNW };

static int rook_dirs = DIR_N | DIR_E | DIR_S | DIR_W;
static int bishop_dirs = DIR_NE | DIR_SE | DIR_SW | DIR_NW;
static int queen_dirs = 0xff;

void chess_generate_init(void)
{
    static int initialized = 0;
    ChessSquare sq;
    int dirs, d;
    int slide, jump;
    int file, rank;
    int to_file, to_rank;

    if (initialized)
        return;
    initialized = 1;

    for (sq = CHESS_SQUARE_A1; sq <= CHESS_SQUARE_H8; sq++)
    {
        dirs = 0;
        file = chess_square_file(sq);
        rank = chess_square_rank(sq);
        for (d = 0; d < 8; d++)
        {
            slide = slides_array[d];
            to_file = file + chess_square_file(slide + 36) - 4;
            to_rank = rank + chess_square_rank(slide + 36) - 4;
            if (to_file >= CHESS_FILE_A && to_file <= CHESS_FILE_H
            &&  to_rank >= CHESS_RANK_1 && to_rank <= CHESS_RANK_8)
                dirs |= dirs_array[d];
        }

        slide_dirs[sq] = dirs;
    }

    for (sq = CHESS_SQUARE_A1; sq <= CHESS_SQUARE_H8; sq++)
    {
        dirs = 0;
        file = chess_square_file(sq);
        rank = chess_square_rank(sq);
        for (d = 0; d < 8; d++)
        {
            jump = jumps_array[d];
            to_file = file + chess_square_file(jump + 36) - 4;
            to_rank = rank + chess_square_rank(jump + 36) - 4;
            if (to_file >= CHESS_FILE_A && to_file <= CHESS_FILE_H
            &&  to_rank >= CHESS_RANK_1 && to_rank <= CHESS_RANK_8)
                dirs |= dirs_array[d];
        }

        jump_dirs[sq] = dirs;
    }
}

static ChessBoolean move_is_legal(const ChessPosition* position, ChessMove move)
{
    ChessPosition temp_position;
    chess_position_copy(position, &temp_position);
    chess_position_make_move(&temp_position,  move);
    temp_position.to_move = position->to_move;
    return !chess_position_is_check(&temp_position);
}

void chess_move_generator_init(ChessMoveGenerator* gen, const ChessPosition* position)
{
    gen->position = position;
    gen->sq = 0;
    gen->to = CHESS_SQUARE_INVALID;
    gen->d = 0;
    gen->promote = CHESS_MOVE_PROMOTE_NONE;
    gen->castle = -1;
    gen->is_ep = false;
}

static ChessMove gen_next(ChessMoveGenerator* gen)
{
    const ChessPosition* position = gen->position;
    ChessPiece piece;
    ChessColor color = position->to_move;
    ChessPiece target;
    int dirs, dir;
    int piece_dirs;

    ChessRank start_rank, end_rank;
    int slide;
    int capture_dirs;
    ChessFile ep_file;
    ChessSquare ep;
    ChessMove move;

    gen->is_ep = false;

    if (gen->promote != CHESS_MOVE_PROMOTE_NONE)
    {
gen_pawn_promotes:
        if (++gen->promote <= CHESS_MOVE_PROMOTE_QUEEN)
            return chess_move_make_promote(gen->sq, gen->to, gen->promote);
        gen->promote = CHESS_MOVE_PROMOTE_NONE;
    }

    for (; gen->sq <= CHESS_SQUARE_H8; gen->sq++)
    {
        piece = position->piece[gen->sq];
        if (piece == CHESS_PIECE_NONE)
            continue;

        if (color != chess_piece_color(piece))
            continue;

        switch (piece)
        {
            case CHESS_PIECE_WHITE_PAWN:
            case CHESS_PIECE_BLACK_PAWN:
                start_rank = (color == CHESS_COLOR_WHITE) ? CHESS_RANK_2 : CHESS_RANK_7;
                end_rank = (color == CHESS_COLOR_WHITE) ? CHESS_RANK_8 : CHESS_RANK_1;
                slide = (color == CHESS_COLOR_WHITE) ? SLIDE_N : SLIDE_S;
                capture_dirs = (color == CHESS_COLOR_WHITE) ? DIR_NE | DIR_NW : DIR_SE | DIR_SW;
                dirs = capture_dirs & slide_dirs[gen->sq];
                ep_file = position->ep;
                ep = (ep_file == CHESS_FILE_INVALID) ? CHESS_SQUARE_INVALID :
                     chess_square_from_fr(ep_file, (color == CHESS_COLOR_WHITE) ? CHESS_RANK_6 : CHESS_RANK_3);

                if (gen->d == 0)
                {
                    if (gen->to == CHESS_SQUARE_INVALID)
                    {
                        gen->to = gen->sq + slide;

                        if (position->piece[gen->to] == CHESS_PIECE_NONE)
                        {
                            if (chess_square_rank(gen->to) == end_rank)
                                goto gen_pawn_promotes;
                            else
                                return chess_move_make(gen->sq, gen->to);
                        }
                    }
                    else if (chess_square_rank(gen->sq) == start_rank && gen->to == gen->sq + slide)
                    {
                        gen->to += slide;
                        if (position->piece[gen->to] == CHESS_PIECE_NONE)
                            return chess_move_make(gen->sq, gen->to);
                    }
                    gen->to = CHESS_SQUARE_INVALID;
                    gen->d = 1;
                }

                while (gen->d < 8)
                {
                    if (dirs_array[gen->d] & dirs)
                    {
                        gen->to = gen->sq + slides_array[gen->d];
                        piece = position->piece[gen->to];
                        gen->d += 2;
                        if (piece != CHESS_PIECE_NONE && chess_piece_color(piece) != color)
                        {
                            if (chess_square_rank(gen->to) == end_rank)
                                goto gen_pawn_promotes;
                            else
                                return chess_move_make(gen->sq, gen->to);
                        }
                        else if (gen->to == ep)
                        {
                            gen->is_ep = true;
                            return chess_move_make(gen->sq, gen->to);
                        }
                    }
                    else
                    {
                        gen->d += 2;
                    }
                }
                gen->to = CHESS_SQUARE_INVALID;
                gen->d = 0;
                break;
            case CHESS_PIECE_WHITE_KNIGHT:
            case CHESS_PIECE_BLACK_KNIGHT:
                dirs = jump_dirs[gen->sq];
                for (; gen->d < 8; gen->d++)
                {
                    dir = dirs_array[gen->d];
                    if ((dir & dirs) == 0)
                        continue;

                    gen->to = gen->sq + jumps_array[gen->d];
                    target = position->piece[gen->to];
                    if (target == CHESS_PIECE_NONE || chess_piece_color(target) != color)
                    {
                        gen->d++;
                        return chess_move_make(gen->sq, gen->to);
                    }
                }
                gen->to = CHESS_SQUARE_INVALID;
                gen->d = 0;
                break;
            case CHESS_PIECE_WHITE_BISHOP:
            case CHESS_PIECE_BLACK_BISHOP:
                piece_dirs = bishop_dirs;
                goto gen_slide_moves;
            case CHESS_PIECE_WHITE_ROOK:
            case CHESS_PIECE_BLACK_ROOK:
                piece_dirs = rook_dirs;
                goto gen_slide_moves;
            case CHESS_PIECE_WHITE_QUEEN:
            case CHESS_PIECE_BLACK_QUEEN:
                piece_dirs = queen_dirs;
        gen_slide_moves:
                while (gen->d < 8)
                {
                    dir = dirs_array[gen->d] & piece_dirs;

                    if (gen->to == CHESS_SQUARE_INVALID)
                        gen->to = gen->sq;

                    do
                    {
                        dirs = slide_dirs[gen->to];
                        if ((dir & dirs) == 0)
                            break;

                        gen->to += slides_array[gen->d];
                        target = position->piece[gen->to];
                        if (target == CHESS_PIECE_NONE)
                            return chess_move_make(gen->sq, gen->to);
                        else if (chess_piece_color(target) != color)
                        {
                            move = chess_move_make(gen->sq, gen->to);
                            gen->d++;
                            gen->to = CHESS_SQUARE_INVALID;
                            return move;
                        }
                    } while (target == CHESS_PIECE_NONE);

                    gen->to = CHESS_SQUARE_INVALID;
                    gen->d++;
                }
                gen->to = CHESS_SQUARE_INVALID;
                gen->d = 0;
                break;
            case CHESS_PIECE_WHITE_KING:
            case CHESS_PIECE_BLACK_KING:
                dirs = slide_dirs[gen->sq];
                for (; gen->d < 8; gen->d++)
                {
                    dir = dirs_array[gen->d];
                    if ((dir & dirs) == 0)
                        continue;

                    gen->to = gen->sq + slides_array[gen->d];
                    target = position->piece[gen->to];
                    if (target == CHESS_PIECE_NONE || chess_piece_color(target) != color)
                    {
                        gen->d++;
                        return chess_move_make(gen->sq, gen->to);
                    }
                }
                gen->to = CHESS_SQUARE_INVALID;
                gen->d = 0;
                break;
            default:
                assert(0);
                break;
        }
    }

    if ((int)gen->castle == -1)
    {
        if (!chess_position_is_check(position))
            gen->castle = position->castle;
        else {
            gen->castle = CHESS_CASTLE_STATE_NONE;
        }
    }

    if (color == CHESS_COLOR_WHITE)
    {
        if ((gen->castle & CHESS_CASTLE_STATE_WK)
            && position->piece[CHESS_SQUARE_F1] == CHESS_PIECE_NONE
            && position->piece[CHESS_SQUARE_G1] == CHESS_PIECE_NONE
            && !chess_generate_is_square_attacked(position, CHESS_SQUARE_F1, CHESS_COLOR_BLACK)
            && !chess_generate_is_square_attacked(position, CHESS_SQUARE_G1, CHESS_COLOR_BLACK))
        {
            gen->castle &= ~CHESS_CASTLE_STATE_WK;
            return chess_move_make(CHESS_SQUARE_E1, CHESS_SQUARE_G1);
        }

        if ((gen->castle & CHESS_CASTLE_STATE_WQ)
            && position->piece[CHESS_SQUARE_B1] == CHESS_PIECE_NONE
            && position->piece[CHESS_SQUARE_C1] == CHESS_PIECE_NONE
            && position->piece[CHESS_SQUARE_D1] == CHESS_PIECE_NONE
            && !chess_generate_is_square_attacked(position, CHESS_SQUARE_D1, CHESS_COLOR_BLACK)
            && !chess_generate_is_square_attacked(position, CHESS_SQUARE_C1, CHESS_COLOR_BLACK))
        {
            gen->castle &= ~CHESS_CASTLE_STATE_WQ;
            return chess_move_make(CHESS_SQUARE_E1, CHESS_SQUARE_C1);
        }
    }
    else
    {
        if ((gen->castle & CHESS_CASTLE_STATE_BK)
            && position->piece[CHESS_SQUARE_F8] == CHESS_PIECE_NONE
            && position->piece[CHESS_SQUARE_G8] == CHESS_PIECE_NONE
            && !chess_generate_is_square_attacked(position, CHESS_SQUARE_F8, CHESS_COLOR_WHITE)
            && !chess_generate_is_square_attacked(position, CHESS_SQUARE_G8, CHESS_COLOR_WHITE))
        {
            gen->castle &= ~CHESS_CASTLE_STATE_BK;
            return chess_move_make(CHESS_SQUARE_E8, CHESS_SQUARE_G8);
        }

        if ((gen->castle & CHESS_CASTLE_STATE_BQ)
            && position->piece[CHESS_SQUARE_B8] == CHESS_PIECE_NONE
            && position->piece[CHESS_SQUARE_C8] == CHESS_PIECE_NONE
            && position->piece[CHESS_SQUARE_D8] == CHESS_PIECE_NONE
            && !chess_generate_is_square_attacked(position, CHESS_SQUARE_D8, CHESS_COLOR_WHITE)
            && !chess_generate_is_square_attacked(position, CHESS_SQUARE_C8, CHESS_COLOR_WHITE))
        {
            gen->castle &= ~CHESS_CASTLE_STATE_BQ;
            return chess_move_make(CHESS_SQUARE_E8, CHESS_SQUARE_C8);
        }
    }

    return 0;
}

ChessMove chess_move_generator_next(ChessMoveGenerator* generator)
{
    ChessMove move = 0;
    while ((move = gen_next(generator)) && !move_is_legal(generator->position, move))
        ;
    return move;
}

bool chess_has_legal_ep(const ChessPosition* position)
{
    ChessMoveGenerator generator;
    ChessMove move;

    chess_move_generator_init(&generator, position);

    while ((move = chess_move_generator_next(&generator)))
    {
        if (generator.is_ep)
            return true;
    }
    return false;
}

void chess_generate_moves(const ChessPosition* position, ChessArray* moves)
{
    ChessMoveGenerator generator;
    ChessMove move;

    chess_move_generator_init(&generator, position);

    while ((move = chess_move_generator_next(&generator)))
    {
        chess_array_push(moves, &move);
    }
}

ChessBoolean chess_generate_is_square_attacked(const ChessPosition* position, ChessSquare sq, ChessColor color)
{
    ChessSquare from;
    ChessPiece piece;
    int dirs, dir, d, dist;

    /* Check for knight attacks */
    dirs = jump_dirs[sq];
    for (d = 0; d < 8; d++)
    {
        dir = dirs_array[d];
        if ((dir & dirs) == 0)
            continue;

        from = sq + jumps_array[d];
        piece = position->piece[from];
        if (position->piece[from] == chess_piece_of_color(CHESS_PIECE_WHITE_KNIGHT, color))
            return CHESS_TRUE;
    }

    for (d = 0; d < 8; d++)
    {
        dir = dirs_array[d];
        from = sq;
        dist = 1;

        do
        {
            dirs = slide_dirs[from];
            if ((dir & dirs) == 0)
                break;

            from += slides_array[d];
            piece = position->piece[from];
            if (piece != CHESS_PIECE_NONE && chess_piece_color(piece) == color)
            {
                switch (piece)
                {
                    case CHESS_PIECE_WHITE_QUEEN:
                    case CHESS_PIECE_BLACK_QUEEN:
                        return CHESS_TRUE;
                    case CHESS_PIECE_WHITE_BISHOP:
                    case CHESS_PIECE_BLACK_BISHOP:
                        if (dir & bishop_dirs)
                            return CHESS_TRUE;
                        break;
                    case CHESS_PIECE_WHITE_ROOK:
                    case CHESS_PIECE_BLACK_ROOK:
                        if (dir & rook_dirs)
                            return CHESS_TRUE;
                        break;
                    case CHESS_PIECE_WHITE_KING:
                    case CHESS_PIECE_BLACK_KING:
                        if (dist == 1)
                            return CHESS_TRUE;
                        break;
                    case CHESS_PIECE_WHITE_PAWN:
                        if (dist == 1 && dir & (DIR_SE | DIR_SW))
                            return CHESS_TRUE;
                        break;
                    case CHESS_PIECE_BLACK_PAWN:
                        if (dist == 1 && dir & (DIR_NE | DIR_NW))
                            return CHESS_TRUE;
                        break;
                    case CHESS_PIECE_WHITE_KNIGHT:
                    case CHESS_PIECE_BLACK_KNIGHT:
                        break;
                    default:
                        assert(CHESS_FALSE);
                        break;
                }
            }
            dist++;
        } while (piece == CHESS_PIECE_NONE);
    }

    return CHESS_FALSE;
}
