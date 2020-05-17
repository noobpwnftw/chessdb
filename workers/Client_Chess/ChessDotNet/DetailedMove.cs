namespace ChessDotNet
{
    public class DetailedMove : Move
    {
        public Piece Piece
        {
            get;
            set;
        }

        public bool IsCapture
        {
            get;
            set;
        }

        public CastlingType Castling
        {
            get;
            set;
        }

        public string SAN
        {
            get;
            set;
        }

        protected DetailedMove() { }

        public DetailedMove(Position originalPosition, Position newPosition, Player player, char? promotion, Piece piece, bool isCapture, CastlingType castling, string san) : 
            base(originalPosition, newPosition, player, promotion)
        {
            Piece = piece;
            IsCapture = isCapture;
            Castling = castling;
            SAN = san;
        }

        public DetailedMove(Move move, Piece piece, bool isCapture, CastlingType castling, string san)
            : this(move.OriginalPosition, move.NewPosition, move.Player, move.Promotion, piece, isCapture, castling, san)
        {
        }
    }
}
