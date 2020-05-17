#ifndef CHESSLIB_POSITION_H_
#define CHESSLIB_POSITION_H_

#include "chess.h"
#include "move.h"
#include "unmove.h"

typedef struct
{
    /* Variables that store the current state of the board. */
    ChessPiece piece[64];
    ChessColor to_move;
    ChessCastleState castle;
    ChessFile ep;
    int fifty;
    int move_num;
    /* The remaining members are private and should not be used. */
    ChessSquare wking, bking;
} ChessPosition;

void chess_position_copy(const ChessPosition* from, ChessPosition* to);

/* Validates the given position by checking some simple invariants, and if
 * valid, sets up any extra internal state. This method MUST be called after
 * setting up a new position. If position is invalid, returns CHESS_FALSE.
 *
 * The following invariants are checked:
 *  1. Both sides have one king each.
 *  2. There are no pawns on the first or last rank.
 *  3. The opponent's king can not immediately be captured.
 *
 * In addition, any castle or en-passant states are cleared if they are
 * impossible (e.g. if the king is not on its starting square).
 */
ChessBoolean chess_position_validate(ChessPosition*);

ChessBoolean chess_position_is_check(const ChessPosition*);
ChessBoolean chess_position_move_is_legal(const ChessPosition*, ChessMove);
ChessBoolean chess_position_move_is_capture(const ChessPosition*, ChessMove);
ChessResult chess_position_check_result(const ChessPosition*);

ChessUnmove chess_position_make_move(ChessPosition*, ChessMove);
void chess_position_undo_move(ChessPosition*, ChessUnmove);

#endif /* CHESSLIB_POSITION_H_ */
