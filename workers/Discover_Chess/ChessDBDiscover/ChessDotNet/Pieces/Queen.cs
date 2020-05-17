using System;
using System.Linq;
using System.Collections.ObjectModel;

namespace ChessDotNet.Pieces
{
    public class Queen : Piece
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

        public Queen() : this(Player.None) {}

        public Queen(Player owner)
        {
            Owner = owner;
            IsPromotionResult = false;
        }

        public override Piece AsPromotion()
        {
            Queen copy = new Queen(Owner);
            copy.IsPromotionResult = true;
            return copy;
        }

        public override Piece GetWithInvertedOwner()
        {
            return new Queen(ChessUtilities.GetOpponentOf(Owner));
        }

        public override char GetFenCharacter()
        {
            return Owner == Player.White ? 'Q' : 'q';
        }

        public override bool IsValidMove(Move move, ChessGame game)
        {
            ChessUtilities.ThrowIfNull(move, "move");
            ChessUtilities.ThrowIfNull(game, "game");
            return new Bishop(Owner).IsValidMove(move, game) || new Rook(Owner).IsValidMove(move, game);
        }

        public override ReadOnlyCollection<Move> GetValidMoves(Position from, bool returnIfAny, ChessGame game, Func<Move, bool> gameMoveValidator)
        {
            ChessUtilities.ThrowIfNull(from, "from");
            ReadOnlyCollection<Move> horizontalVerticalMoves = new Rook(Owner).GetValidMoves(from, returnIfAny, game, gameMoveValidator);
            if (returnIfAny && horizontalVerticalMoves.Count > 0)
                return horizontalVerticalMoves;
            ReadOnlyCollection<Move> diagonalMoves = new Bishop(Owner).GetValidMoves(from, returnIfAny, game, gameMoveValidator);
            return new ReadOnlyCollection<Move>(horizontalVerticalMoves.Concat(diagonalMoves).ToList());
        }
    }
}
