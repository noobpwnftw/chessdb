using System;
using System.Collections.ObjectModel;

namespace ChessDotNet
{
    public abstract class Piece
    {
        public abstract Player Owner
        {
            get;
            set;
        }

        public abstract bool IsPromotionResult
        {
            get;
            set;
        }

        public abstract Piece GetWithInvertedOwner();

        public abstract Piece AsPromotion();

        public override bool Equals(object obj)
        {
            if (ReferenceEquals(this, obj))
                return true;
            if (obj == null || GetType() != obj.GetType())
                return false;
            Piece piece1 = this;
            Piece piece2 = (Piece)obj;
            return piece1.Owner == piece2.Owner;
        }

        public override int GetHashCode()
        {
            return new { Piece = GetFenCharacter(), Owner }.GetHashCode();
        }

        public static bool operator ==(Piece piece1, Piece piece2)
        {
            if (ReferenceEquals(piece1, piece2))
                return true;
            if ((object)piece1 == null || (object)piece2 == null)
                return false;
            return piece1.Equals(piece2);
        }

        public static bool operator !=(Piece piece1, Piece piece2)
        {
            if (ReferenceEquals(piece1, piece2))
                return false;
            if ((object)piece1 == null || (object)piece2 == null)
                return true;
            return !piece1.Equals(piece2);
        }

        public abstract char GetFenCharacter();
        public abstract bool IsValidMove(Move move, ChessGame game);
        public abstract ReadOnlyCollection<Move> GetValidMoves(Position from, bool returnIfAny, ChessGame game, Func<Move, bool> gameMoveValidator);
    }
}
