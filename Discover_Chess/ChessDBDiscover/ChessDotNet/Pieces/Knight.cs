using System;
using System.Collections.Generic;
using System.Collections.ObjectModel;

namespace ChessDotNet.Pieces
{
    public class Knight : Piece
    {
        public override Player Owner
        {
            get;
            set;
        }

        public override bool IsPromotionResult
        {
            get;
            set;
        }

        public Knight() : this(Player.None) {}

        public Knight(Player owner)
        {
            Owner = owner;
            IsPromotionResult = false;
        }

        public override Piece AsPromotion()
        {
            Knight copy = new Knight(Owner);
            copy.IsPromotionResult = true;
            return copy;
        }

        public override Piece GetWithInvertedOwner()
        {
            return new Knight(ChessUtilities.GetOpponentOf(Owner));
        }

        public override char GetFenCharacter()
        {
            return Owner == Player.White ? 'N' : 'n';
        }

        public override bool IsValidMove(Move move, ChessGame game)
        {
            ChessUtilities.ThrowIfNull(move, "move");
            ChessUtilities.ThrowIfNull(game, "game");
            Position origin = move.OriginalPosition;
            Position destination = move.NewPosition;

            PositionDistance posDelta = new PositionDistance(origin, destination);
            if ((posDelta.DistanceX != 2 || posDelta.DistanceY != 1) && (posDelta.DistanceX != 1 || posDelta.DistanceY != 2))
                return false;
            return true;
        }

        public override ReadOnlyCollection<Move> GetValidMoves(Position from, bool returnIfAny, ChessGame game, Func<Move, bool> gameMoveValidator)
        {
            List<Move> validMoves = new List<Move>();
            Piece piece = game.GetPieceAt(from);
            int l0 = game.BoardHeight;
            int l1 = game.BoardWidth;
            int[][] directions = new int[][] { new int[] { 2, 1 }, new int[] { -2, -1 }, new int[] { 1, 2 }, new int[] { -1, -2 },
                        new int[] { 1, -2 }, new int[] { -1, 2 }, new int[] { 2, -1 }, new int[] { -2, 1 } };
            foreach (int[] dir in directions)
            {
                if ((int)from.File + dir[0] < 0 || (int)from.File + dir[0] >= l1
                    || from.Rank + dir[1] < 1 || from.Rank + dir[1] > l0)
                    continue;
                Move move = new Move(from, new Position(from.File + dir[0], from.Rank + dir[1]), piece.Owner);
                if (gameMoveValidator(move))
                {
                    validMoves.Add(move);
                    if (returnIfAny)
                        return new ReadOnlyCollection<Move>(validMoves);
                }
            }
            return new ReadOnlyCollection<Move>(validMoves);
        }
    }
}
