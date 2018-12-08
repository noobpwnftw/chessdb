using System;
using System.Collections.Generic;
using System.Collections.ObjectModel;

namespace ChessDotNet.Pieces
{
    public class Bishop : Piece
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

        public Bishop() : this(Player.None) {}

        public Bishop(Player owner)
        {
            Owner = owner;
            IsPromotionResult = false;
        }

        public override Piece AsPromotion()
        {
            Bishop copy = new Bishop(Owner);
            copy.IsPromotionResult = true;
            return copy;
        }

        public override Piece GetWithInvertedOwner()
        {
            return new Bishop(ChessUtilities.GetOpponentOf(Owner));
        }

        public override char GetFenCharacter()
        {
            return Owner == Player.White ? 'B' : 'b';
        }

        public override bool IsValidMove(Move move, ChessGame game)
        {
            ChessUtilities.ThrowIfNull(move, "move");
            ChessUtilities.ThrowIfNull(game, "game");
            Position origin = move.OriginalPosition;
            Position destination = move.NewPosition;

            PositionDistance posDelta = new PositionDistance(origin, destination);
            if (posDelta.DistanceX != posDelta.DistanceY)
                return false;
            bool increasingRank = destination.Rank > origin.Rank;
            bool increasingFile = (int)destination.File > (int)origin.File;
            for (int f = (int)origin.File + (increasingFile ? 1 : -1), r = origin.Rank + (increasingRank ? 1 : -1);
                 increasingFile ? f < (int)destination.File : f > (int)destination.File;
                 f += increasingFile ? 1 : -1, r += increasingRank ? 1 : -1)
            {
                if (game.GetPieceAt((File)f, r) != null)
                {
                    return false;
                }
            }
            return true;
        }

        public override ReadOnlyCollection<Move> GetValidMoves(Position from, bool returnIfAny, ChessGame game, Func<Move, bool> gameMoveValidator)
        {
            List<Move> validMoves = new List<Move>();
            Piece piece = game.GetPieceAt(from);
            int l0 = game.BoardHeight;
            int l1 = game.BoardWidth;
            for (int i = -7; i < 8; i++)
            {
                if (i == 0)
                    continue;
                if (from.Rank + i > 0 && from.Rank + i <= l0
                    && (int)from.File + i > -1 && (int)from.File + i < l1)
                {
                    Move move = new Move(from, new Position(from.File + i, from.Rank + i), piece.Owner);
                    if (gameMoveValidator(move))
                    {
                        validMoves.Add(move);
                        if (returnIfAny)
                            return new ReadOnlyCollection<Move>(validMoves);
                    }
                }
                if (from.Rank - i > 0 && from.Rank - i <= l0
                    && (int)from.File + i > -1 && (int)from.File + i < l1)
                {
                    Move move = new Move(from, new Position(from.File + i, from.Rank - i), piece.Owner);
                    if (gameMoveValidator(move))
                    {
                        validMoves.Add(move);
                        if (returnIfAny)
                            return new ReadOnlyCollection<Move>(validMoves);
                    }
                }
            }
            return new ReadOnlyCollection<Move>(validMoves);
        }
    }
}
