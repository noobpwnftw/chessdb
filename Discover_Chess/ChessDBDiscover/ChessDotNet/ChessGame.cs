using System;
using System.Collections.Generic;
using System.Collections.ObjectModel;
using System.Linq;
using ChessDotNet.Pieces;
using System.Text;

namespace ChessDotNet
{
    public class ChessGame
    {
        bool _drawn = false;
        string _drawReason = null;
        Player _resigned = Player.None;

        public bool DrawClaimed
        {
            get
            {
                return _drawn;
            }
        }

        public string DrawReason
        {
            get
            {
                return _drawReason;
            }
        }

        public Player Resigned
        {
            get
            {
                return _resigned;
            }
        }

        protected virtual int[] AllowedFenPartsLength
        {
            get
            {
                return new int[1] { 4 };
            }
        }

        protected virtual bool UseTildesInFenGeneration
        {
            get
            {
                return false;
            }
        }

        public File InitialWhiteRookFileKingsideCastling { get; protected set; }
        public File InitialWhiteRookFileQueensideCastling { get; protected set; }
        public File InitialBlackRookFileKingsideCastling { get; protected set; }
        public File InitialBlackRookFileQueensideCastling { get; protected set; }
        public File InitialWhiteKingFile { get; protected set; }
        public File InitialBlackKingFile { get; protected set; }

        private Dictionary<char, Piece> fenMappings = new Dictionary<char, Piece>()
        {
            { 'K', new King(Player.White) },
            { 'k', new King(Player.Black) },
            { 'Q', new Queen(Player.White) },
            { 'q', new Queen(Player.Black) },
            { 'R', new Rook(Player.White) },
            { 'r', new Rook(Player.Black) },
            { 'B', new Bishop(Player.White) },
            { 'b', new Bishop(Player.Black) },
            { 'N', new Knight(Player.White) },
            { 'n', new Knight(Player.Black) },
            { 'P', new Pawn(Player.White) },
            { 'p', new Pawn(Player.Black) },
        };
        protected virtual Dictionary<char, Piece> FenMappings
        {
            get
            {
                return fenMappings;
            }
        }

        public virtual Piece MapPgnCharToPiece(char c, Player owner)
        {
            switch (c)
            {
                case 'K':
                    return owner == Player.White ? FenMappings['K'] : FenMappings['k'];
                case 'Q':
                    return owner == Player.White ? FenMappings['Q'] : FenMappings['q'];
                case 'R':
                    return owner == Player.White ? FenMappings['R'] : FenMappings['r'];
                case 'B':
                    return owner == Player.White ? FenMappings['B'] : FenMappings['b'];
                case 'N':
                    return owner == Player.White ? FenMappings['N'] : FenMappings['n'];
                case 'P':
                    return owner == Player.White ? FenMappings['P'] : FenMappings['p'];
                default:
                    if (!char.IsLower(c))
                    {
                        throw new PgnException("Unrecognized piece type: " + c.ToString());
                    }
                    return owner == Player.White ? FenMappings['P'] : FenMappings['p'];
            }
        }

        public virtual bool NeedsPgnMoveSpecialTreatment(string move, Player player) { return false; }
        public virtual bool HandleSpecialPgnMove(string move, Player player) { return false;  } 

        protected bool fiftyMoves = false;
        protected virtual bool FiftyMovesAndThisCanResultInDraw { get { return fiftyMoves; } }

        public virtual bool DrawCanBeClaimed
        {
            get
            {
                return FiftyMovesAndThisCanResultInDraw && !IsCheckmated(WhoseTurn) && !IsStalemated(WhoseTurn);
            }
        }

        public Player WhoseTurn
        {
            get;
            protected set;
        }

        protected int i_halfMoveClock = 0;
        public int HalfMoveClock
        {
            get
            {
                return i_halfMoveClock;
            }
        }

        protected int i_fullMoveNumber = 1;
        public int FullMoveNumber
        {
            get
            {
                return i_fullMoveNumber;
            }
        }

        public ReadOnlyCollection<Piece> PiecesOnBoard
        {
            get
            {
                return new ReadOnlyCollection<Piece>(Board.SelectMany(x => x).Where(x => x != null).ToList());
            }
        }

        public virtual int BoardWidth
        {
            get
            {
                return 8;
            }
        }

        public virtual int BoardHeight
        {
            get
            {
                return 8;
            }
        }

        protected Piece[][] Board;
        public Piece[][] GetBoard()
        {
            return CloneBoard(Board);
        }

        List<DetailedMove> _moves = new List<DetailedMove>();
        public ReadOnlyCollection<DetailedMove> Moves
        {
            get
            {
                return new ReadOnlyCollection<DetailedMove>(_moves);
            }
            protected set
            {
                _moves = value.ToList();
            }
        }

        public bool CanBlackCastleKingSide
        {
            get;
            protected set;
        }

        public bool CanBlackCastleQueenSide
        {
            get;
            protected set;
        }

        public bool CanWhiteCastleKingSide
        {
            get;
            protected set;
        }

        public bool CanWhiteCastleQueenSide
        {
            get;
            protected set;
        }

        protected virtual bool CastlingCanBeLegal
        {
            get
            {
                return true;
            }
        }

        protected static Piece[][] CloneBoard(Piece[][] originalBoard)
        {
            ChessUtilities.ThrowIfNull(originalBoard, "originalBoard");
            Piece[][] newBoard = new Piece[originalBoard.Length][];
            for (int i = 0; i < originalBoard.Length; i++)
            {
                newBoard[i] = new Piece[originalBoard[i].Length];
                Array.Copy(originalBoard[i], newBoard[i], originalBoard[i].Length);
            }
            return newBoard;
        }

        public ChessGame()
        {
            WhoseTurn = Player.White;
            _moves = new List<DetailedMove>();
            Board = new Piece[8][];
            Piece kw = FenMappings['K'];
            Piece kb = FenMappings['k'];
            Piece qw = FenMappings['Q'];
            Piece qb = FenMappings['q'];
            Piece rw = FenMappings['R'];
            Piece rb = FenMappings['r'];
            Piece nw = FenMappings['N'];
            Piece nb = FenMappings['n'];
            Piece bw = FenMappings['B'];
            Piece bb = FenMappings['b'];
            Piece pw = FenMappings['P'];
            Piece pb = FenMappings['p'];
            Piece o = null;
            Board = new Piece[8][]
            {
                new[] { rb, nb, bb, qb, kb, bb, nb, rb },
                new[] { pb, pb, pb, pb, pb, pb, pb, pb },
                new[] { o, o, o, o, o, o, o, o },
                new[] { o, o, o, o, o, o, o, o },
                new[] { o, o, o, o, o, o, o, o },
                new[] { o, o, o, o, o, o, o, o },
                new[] { pw, pw, pw, pw, pw, pw, pw, pw },
                new[] { rw, nw, bw, qw, kw, bw, nw, rw }
            };
            CanBlackCastleKingSide = CanBlackCastleQueenSide = CanWhiteCastleKingSide = CanWhiteCastleQueenSide = CastlingCanBeLegal;
            InitialBlackRookFileKingsideCastling = InitialWhiteRookFileKingsideCastling = File.H;
            InitialBlackRookFileQueensideCastling = InitialWhiteRookFileQueensideCastling = File.A;
            InitialBlackKingFile = InitialWhiteKingFile = File.E;
        }

        public ChessGame(IEnumerable<Move> moves, bool movesAreValidated) : this()
        {
            if (moves == null)
                throw new ArgumentNullException("moves");
            if (moves.Count() == 0)
                throw new ArgumentException("The Count of moves has to be greater than 0.");
            foreach (Move m in moves)
            {
                if (ApplyMove(m, movesAreValidated) == MoveType.Invalid)
                {
                    throw new ArgumentException("Invalid move passed to ChessGame constructor.");
                }
            }
        }

        public ChessGame(string fen)
        {
            GameCreationData data = FenStringToGameCreationData(fen);
            UseGameCreationData(data);
        }

        public ChessGame(GameCreationData data)
        {
            UseGameCreationData(data);
        }

        [Obsolete("This constructor is obsolete, use ChessGame(GameCreationData) instead.")]
        public ChessGame(Piece[][] board, Player whoseTurn)
        {
            Board = CloneBoard(board);
            _moves = new List<DetailedMove>();
            WhoseTurn = whoseTurn;
            Piece e1 = GetPieceAt(File.E, 1);
            Piece e8 = GetPieceAt(File.E, 8);
            Piece a1 = GetPieceAt(File.A, 1);
            Piece h1 = GetPieceAt(File.H, 1);
            Piece a8 = GetPieceAt(File.A, 8);
            Piece h8 = GetPieceAt(File.H, 8);
            CanBlackCastleKingSide = CanBlackCastleQueenSide = CanWhiteCastleKingSide = CanWhiteCastleQueenSide = CastlingCanBeLegal;
            InitialBlackRookFileKingsideCastling = InitialWhiteRookFileKingsideCastling = File.H;
            InitialBlackRookFileQueensideCastling = InitialWhiteRookFileQueensideCastling = File.A;
            InitialBlackKingFile = InitialWhiteKingFile = File.E;
            if (CastlingCanBeLegal)
            {
                if (!(e1 is King) || e1.Owner != Player.White)
                    CanWhiteCastleKingSide = CanWhiteCastleQueenSide = false;
                if (!(e8 is King) || e8.Owner != Player.Black)
                    CanBlackCastleKingSide = CanBlackCastleQueenSide = false;
                if (!(a1 is Rook) || a1.Owner != Player.White)
                    CanWhiteCastleQueenSide = false;
                if (!(h1 is Rook) || h1.Owner != Player.White)
                    CanWhiteCastleKingSide = false;
                if (!(a8 is Rook) || a8.Owner != Player.Black)
                    CanBlackCastleQueenSide = false;
                if (!(h8 is Rook) || h8.Owner != Player.Black)
                    CanBlackCastleKingSide = false;
            }
        }

        protected virtual void UseGameCreationData(GameCreationData data)
        {
            Board = CloneBoard(data.Board);
            WhoseTurn = data.WhoseTurn;

            Piece[] eighthRank = Board[0];
            Piece[] firstRank = Board[7];

            CanBlackCastleKingSide = CanBlackCastleQueenSide = CanWhiteCastleKingSide = CanWhiteCastleQueenSide = CastlingCanBeLegal;
            InitialWhiteKingFile = (File)Array.IndexOf(firstRank, new King(Player.White));
            InitialBlackKingFile = (File)Array.IndexOf(eighthRank, new King(Player.Black));
            if (CastlingCanBeLegal)
            {
                CanBlackCastleKingSide = data.CanBlackCastleKingSide;
                CanBlackCastleQueenSide = data.CanBlackCastleQueenSide;
                CanWhiteCastleKingSide = data.CanWhiteCastleKingSide;
                CanWhiteCastleQueenSide = data.CanWhiteCastleQueenSide;
            }
            InitialBlackRookFileQueensideCastling = CanBlackCastleQueenSide ? (File)Array.IndexOf(eighthRank, new Rook(Player.Black)) : File.None;
            InitialBlackRookFileKingsideCastling = CanBlackCastleKingSide ? (File)Array.LastIndexOf(eighthRank, new Rook(Player.Black)) : File.None;
            InitialWhiteRookFileQueensideCastling = CanWhiteCastleQueenSide ? (File)Array.IndexOf(firstRank, new Rook(Player.White)) : File.None;
            InitialWhiteRookFileKingsideCastling = CanWhiteCastleKingSide ? (File)Array.LastIndexOf(firstRank, new Rook(Player.White)) : File.None;

            if (InitialBlackRookFileQueensideCastling == File.None) CanBlackCastleQueenSide = false;
            if (InitialBlackRookFileKingsideCastling == File.None) CanBlackCastleKingSide = false;
            if (InitialWhiteRookFileKingsideCastling == File.None) CanWhiteCastleKingSide = false;
            if (InitialWhiteRookFileQueensideCastling == File.None) CanWhiteCastleQueenSide = false;

            if (!data.Moves.Any() && data.EnPassant != null)
            {
                Position dest = new Position(data.EnPassant.File, data.WhoseTurn == Player.White ? 5 : 4);
                DetailedMove latestMove = new DetailedMove(new Move(new Position(data.EnPassant.File, data.WhoseTurn == Player.White ? 7 : 2),
                        dest,
                        ChessUtilities.GetOpponentOf(data.WhoseTurn)),
                    new Pawn(ChessUtilities.GetOpponentOf(data.WhoseTurn)),
                    false,
                    CastlingType.None,
                    dest.ToString().ToLowerInvariant()
                    );
                _moves.Add(latestMove);
            }
            else
            {
                _moves = data.Moves.ToList();
            }

            i_halfMoveClock = data.HalfMoveClock;
            i_fullMoveNumber = data.FullMoveNumber;

            _drawn = data.DrawClaimed;
            _drawReason = data.DrawReason;
            _resigned = data.Resigned;
        }

        public virtual string GetFen()
        {
            StringBuilder fenBuilder = new StringBuilder();
            Piece[][] board = GetBoard();
            for (int i = 0; i < board.Length; i++)
            {
                Piece[] row = board[i];
                int empty = 0;
                foreach (Piece piece in row)
                {
                    char pieceChar = piece == null ? '\0' : piece.GetFenCharacter();
                    if (pieceChar == '\0')
                    {
                        empty++;
                        continue;
                    }
                    if (empty != 0)
                    {
                        fenBuilder.Append(empty);
                        empty = 0;
                    }
                    fenBuilder.Append(pieceChar);
                    if (piece.IsPromotionResult && UseTildesInFenGeneration)
                    {
                        fenBuilder.Append('~');
                    }
                }
                if (empty != 0)
                {
                    fenBuilder.Append(empty);
                }
                if (i != board.Length - 1)
                {
                    fenBuilder.Append('/');
                }
            }

            fenBuilder.Append(' ');

            fenBuilder.Append(WhoseTurn == Player.White ? 'w' : 'b');

            fenBuilder.Append(' ');

            bool hasAnyCastlingOptions = false;


            if (CanWhiteCastleKingSide)
            {
                fenBuilder.Append('K');
                hasAnyCastlingOptions = true;
            }
            if (CanWhiteCastleQueenSide)
            {
                fenBuilder.Append('Q');
                hasAnyCastlingOptions = true;
            }


            if (CanBlackCastleKingSide)
            {
                fenBuilder.Append('k');
                hasAnyCastlingOptions = true;
            }
            if (CanBlackCastleQueenSide)
            {
                fenBuilder.Append('q');
                hasAnyCastlingOptions = true;
            }
            if (!hasAnyCastlingOptions)
            {
                fenBuilder.Append('-');
            }

            fenBuilder.Append(' ');

            DetailedMove last;
            if (Moves.Count > 0 && (last = Moves[Moves.Count - 1]).Piece is Pawn && Math.Abs(last.OriginalPosition.Rank - last.NewPosition.Rank) == 2
                && last.OriginalPosition.Rank == (last.Player == Player.White ? 2 : 7))
            {
                fenBuilder.Append(last.NewPosition.File.ToString().ToLowerInvariant());
                fenBuilder.Append(last.Player == Player.White ? 3 : 6);
            }
            else
            {
                fenBuilder.Append("-");
            }

            fenBuilder.Append(' ');

            fenBuilder.Append(i_halfMoveClock);

            fenBuilder.Append(' ');

            fenBuilder.Append(i_fullMoveNumber);

            return fenBuilder.ToString();
        }

        protected virtual int[] ValidFenBoardRows { get { return new int[1] { 8 }; } }

        protected virtual GameCreationData FenStringToGameCreationData(string fen)
        {
            Dictionary<char, Piece> fenMappings = FenMappings;
            string[] parts = fen.Split(new char[] { ' ' }, StringSplitOptions.RemoveEmptyEntries);
            if (!AllowedFenPartsLength.Contains(parts.Length)) throw new ArgumentException("The FEN string has too much, or too few, parts.");
            Piece[][] board = new Piece[8][];
            string[] rows = parts[0].Split('/');
            if (!ValidFenBoardRows.Contains(rows.Length)) throw new ArgumentException("The board in the FEN string has an invalid number of rows.");
            GameCreationData data = new GameCreationData();

            data.Board = InterpretBoardOfFen(parts[0]);

            if (parts[1] == "w")
            {
                data.WhoseTurn = Player.White;
            }
            else if (parts[1] == "b")
            {
                data.WhoseTurn = Player.Black;
            }
            else
            {
                throw new ArgumentException("Expected `w` or `b` for the active player in the FEN string.");
            }

            if (parts[2].Contains("K")) data.CanWhiteCastleKingSide = true;
            else data.CanWhiteCastleKingSide = false;

            if (parts[2].Contains("Q")) data.CanWhiteCastleQueenSide = true;
            else data.CanWhiteCastleQueenSide = false;

            if (parts[2].Contains("k")) data.CanBlackCastleKingSide = true;
            else data.CanBlackCastleKingSide = false;

            if (parts[2].Contains("q")) data.CanBlackCastleQueenSide = true;
            else data.CanBlackCastleQueenSide = false;

            if (parts[3] == "-") data.EnPassant = null;
            else
            {
                Position ep = new Position(parts[3]);
                if ((data.WhoseTurn == Player.White && (ep.Rank != 6 || !(data.Board[3][(int)ep.File] is Pawn))) ||
                    (data.WhoseTurn == Player.Black && (ep.Rank != 3 || !(data.Board[4][(int)ep.File] is Pawn))))
                {
                    throw new ArgumentException("Invalid en passant field in FEN.");
                }
                data.EnPassant = ep;
            }

            return data;
        }

        protected virtual Piece[][] InterpretBoardOfFen(string board)
        {
            Piece[][] pieceArr = new Piece[8][];
            string[] rows = board.Split('/');
            for (int i = 0; i < 8; i++)
            {
                string row = rows[i];
                Piece[] currentRow = new Piece[8] { null, null, null, null, null, null, null, null };
                int j = 0;
                foreach (char c in row)
                {
                    if (char.IsDigit(c))
                    {
                        j += (int)char.GetNumericValue(c);
                        continue;
                    }
                    if (c == '~')
                    {
                        if (j == 0)
                        {
                            throw new ArgumentException("Error in FEN: misplaced '~'.");
                        }
                        if (currentRow[j - 1] == null)
                        {
                            throw new ArgumentException("Error in FEN: misplaced '~'.");
                        }
                        currentRow[j - 1] = currentRow[j - 1].AsPromotion();
                        continue;
                    }
                    if (!FenMappings.ContainsKey(c)) throw new ArgumentException("The FEN string contains an unknown piece.");
                    currentRow[j] = FenMappings[c];
                    j++;
                }
                if (j != 8)
                {
                    throw new ArgumentException("Not enough pieces provided for a row in the FEN string.");
                }
                pieceArr[i] = currentRow;
            }
            return pieceArr;
        }

        public Piece GetPieceAt(Position position)
        {
            ChessUtilities.ThrowIfNull(position, "position");
            return GetPieceAt(position.File, position.Rank);
        }

        public Piece GetPieceAt(File file, int rank)
        {
            return Board[8 - rank][(int)file];
        }

        protected virtual void SetPieceAt(File file, int rank, Piece piece)
        {
            Board[8 - rank][(int)file] = piece;
        }

        public bool IsValidMove(Move move)
        {
            ChessUtilities.ThrowIfNull(move, "move");
            return IsValidMove(move, true, true);
        }

        protected bool IsValidMove(Move move, bool validateCheck)
        {
            ChessUtilities.ThrowIfNull(move, "move");
            return IsValidMove(move, validateCheck, true);
        }

        protected virtual bool IsValidMove(Move move, bool validateCheck, bool careAboutWhoseTurnItIs)
        {
            ChessUtilities.ThrowIfNull(move, "move");
            if (move.OriginalPosition.Equals(move.NewPosition))
                return false;
            Piece piece = GetPieceAt(move.OriginalPosition.File, move.OriginalPosition.Rank);
            if (careAboutWhoseTurnItIs && move.Player != WhoseTurn) return false;
            if (piece == null || piece.Owner != move.Player) return false;
            Piece pieceAtDestination = GetPieceAt(move.NewPosition);
            bool isCastle = pieceAtDestination is Rook && piece is King && pieceAtDestination.Owner == piece.Owner;
            if (pieceAtDestination != null && pieceAtDestination.Owner == move.Player && !isCastle)
            {
                return false;
            }
            if (!piece.IsValidMove(move, this))
            {
                return false;
            }
            if (validateCheck)
            {
                if (!isCastle && WouldBeInCheckAfter(move, move.Player))
                {
                    return false;
                }
            }

            return true;
        }

        protected virtual CastlingType ApplyCastle(Move move)
        {
            CastlingType castle;
            int rank = move.Player == Player.White ? 1 : 8;
            File rookFile = move.NewPosition.File;
            if (move.Player == Player.White)
            {
                if (rookFile == File.C && GetPieceAt(File.C, rank) == null && InitialWhiteKingFile == File.E)
                {
                    rookFile = File.A;
                }
                else if (rookFile == File.G && GetPieceAt(File.G, rank) == null && InitialWhiteKingFile == File.E)
                {
                    rookFile = File.H;
                }
            }
            else
            {
                if (rookFile == File.C && GetPieceAt(File.C, rank) == null && InitialBlackKingFile == File.E)
                {
                    rookFile = File.A;
                }
                else if (rookFile == File.G && GetPieceAt(File.G, rank) == null && InitialBlackKingFile == File.E)
                {
                    rookFile = File.H;
                }
            }

            File newRookFile;
            File newKingFile;
            if (rookFile == (move.Player == Player.White ? InitialWhiteRookFileQueensideCastling : InitialBlackRookFileQueensideCastling))
            {
                castle = CastlingType.QueenSide;
                newRookFile = File.D;
                newKingFile = File.C;
            }
            else
            {
                castle = CastlingType.KingSide;
                newRookFile = File.F;
                newKingFile = File.G;
            }
            SetPieceAt(rookFile, rank, null);
            SetPieceAt(move.OriginalPosition.File, rank, null);
            SetPieceAt(newRookFile, rank, new Rook(move.Player));
            SetPieceAt(newKingFile, rank, new King(move.Player));
            return castle;
        }

        public virtual MoveType MakeMove(Move move, bool alreadyValidated)
        {
            Piece captured;
            return MakeMove(move, alreadyValidated, out captured);
        }
        public virtual MoveType MakeMove(string move)
        {

            Position origin = new Position(move.Substring(0, 2));
            Position destination = new Position(move.Substring(2, 2));
            char? promotion = null;
            if (move.Length > 4)
                promotion = move.Substring(4, 1).ToCharArray()[0];
            Move m = new Move(origin, destination, WhoseTurn, promotion);
            return MakeMove(m, true);
        }
        public virtual MoveType MakeMove(Move move, bool alreadyValidated, out Piece captured)
        {
            Piece movingPiece = GetPieceAt(move.OriginalPosition);
            List<Position> ambiguities = GetAmbiguities(move, movingPiece);
            CastlingType castle;
            MoveType mt = ApplyMove(move, alreadyValidated, out captured, out castle);
            if (mt == MoveType.Invalid)
            {
                return mt;
            }
            AddDetailedMove(new DetailedMove(move, movingPiece, captured != null, castle, null)); // needed so caches work well in GetSanForMove
            _moves[_moves.Count - 1].SAN = GetSanForMove(move, movingPiece, captured != null, castle, ambiguities);
            return mt;
        }

        protected MoveType ApplyMove(Move move, bool alreadyValidated)
        {
            Piece captured;
            CastlingType castleType;
            return ApplyMove(move, alreadyValidated, out captured, out castleType);
        }

        protected virtual MoveType ApplyMove(Move move, bool alreadyValidated, out Piece captured, out CastlingType castleType)
        {
            ChessUtilities.ThrowIfNull(move, "move");
            captured = null;
            if (!alreadyValidated && !IsValidMove(move))
            {
                castleType = CastlingType.None;
                return MoveType.Invalid;
            }
            MoveType type = MoveType.Move;
            Piece movingPiece = GetPieceAt(move.OriginalPosition.File, move.OriginalPosition.Rank);
            Piece capturedPiece = GetPieceAt(move.NewPosition.File, move.NewPosition.Rank);
            captured = capturedPiece;
            Piece newPiece = movingPiece;
            bool isCapture = capturedPiece != null;
            CastlingType castle = CastlingType.None;
            if (movingPiece is Pawn)
            {
                i_halfMoveClock = 0;
                PositionDistance pd = new PositionDistance(move.OriginalPosition, move.NewPosition);
                if (pd.DistanceX == 1 && pd.DistanceY == 1 && GetPieceAt(move.NewPosition) == null)
                { // en passant
                    isCapture = true;
                    captured = GetPieceAt(move.NewPosition.File, move.OriginalPosition.Rank);
                    SetPieceAt(move.NewPosition.File, move.OriginalPosition.Rank, null);
                }
                if (move.NewPosition.Rank == (move.Player == Player.White ? 8 : 1))
                {
                    newPiece = MapPgnCharToPiece(move.Promotion.Value, move.Player).AsPromotion();
                    type |= MoveType.Promotion;
                }
            }
            else if (movingPiece is King)
            {
                if (movingPiece.Owner == Player.White)
                    CanWhiteCastleKingSide = CanWhiteCastleQueenSide = false;
                else
                    CanBlackCastleKingSide = CanBlackCastleQueenSide = false;

                if (CastlingCanBeLegal &&
                    ((GetPieceAt(move.NewPosition) is Rook && GetPieceAt(move.NewPosition).Owner == move.Player) ||
                        ((move.NewPosition.File == File.C || move.NewPosition.File == File.G) &&
                        (move.Player == Player.White ? InitialWhiteKingFile : InitialBlackKingFile) == File.E
                        && move.OriginalPosition.File == File.E)))
                {
                    castle = ApplyCastle(move);
                    type |= MoveType.Castling;
                    isCapture = false;
                }
            }
            else if (movingPiece is Rook)
            {
                if (move.Player == Player.White)
                {
                    if (move.OriginalPosition.File == File.A && move.OriginalPosition.Rank == 1)
                        CanWhiteCastleQueenSide = false;
                    else if (move.OriginalPosition.File == File.H && move.OriginalPosition.Rank == 1)
                        CanWhiteCastleKingSide = false;
                }
                else
                {
                    if (move.OriginalPosition.File == File.A && move.OriginalPosition.Rank == 8)
                        CanBlackCastleQueenSide = false;
                    else if (move.OriginalPosition.File == File.H && move.OriginalPosition.Rank == 8)
                        CanBlackCastleKingSide = false;
                }
            }
            if (isCapture)
            {
                type |= MoveType.Capture;
                i_halfMoveClock = 0;
                if (move.NewPosition.File == File.A && move.NewPosition.Rank == 1)
                    CanWhiteCastleQueenSide = false;
                else if (move.NewPosition.File == File.H && move.NewPosition.Rank == 1)
                    CanWhiteCastleKingSide = false;
                else if (move.NewPosition.File == File.A && move.NewPosition.Rank == 8)
                    CanBlackCastleQueenSide = false;
                else if (move.NewPosition.File == File.H && move.NewPosition.Rank == 8)
                    CanBlackCastleKingSide = false;
            }
            if (!isCapture && !(movingPiece is Pawn))
            {
                i_halfMoveClock++;
                if (i_halfMoveClock >= 100)
                {
                    fiftyMoves = true;
                }
                else
                {
                    fiftyMoves = false;
                }
            }
            if (move.Player == Player.Black)
            {
                i_fullMoveNumber++;
            }
            if (castle == CastlingType.None)
            {
                SetPieceAt(move.NewPosition.File, move.NewPosition.Rank, newPiece);
                SetPieceAt(move.OriginalPosition.File, move.OriginalPosition.Rank, null);
            }
            WhoseTurn = ChessUtilities.GetOpponentOf(move.Player);
            castleType = castle;
            return type;
        }

        protected virtual List<Position> GetAmbiguities(Move move, Piece movingPiece)
        {
            if (movingPiece == null)
            {
                return new List<Position>();
            }
            List<Position> ambiguities = new List<Position>();
            foreach (File f in Enum.GetValues(typeof(File)))
            {
                if (f == File.None) continue;
                for (int r = 1; r <= 8; r++)
                {
                    Position pos = new Position(f, r);
                    if (!move.OriginalPosition.Equals(pos))
                    {
                        Piece p = GetPieceAt(f, r);
                        if (p != null && p.Equals(movingPiece) && p.IsValidMove(new Move(pos, move.NewPosition, move.Player), this))
                        {
                            ambiguities.Add(pos);
                        }
                    }
                }
            }
            return ambiguities;
        }

        protected virtual string GetSanForMove(Move move, Piece movingPiece, bool isCapture, CastlingType castle, List<Position> ambiguities)
        {
            if (castle == CastlingType.KingSide) return "O-O";
            if (castle == CastlingType.QueenSide) return "O-O-O";

            bool needsUnambigFile = false;
            bool needsUnambigRank = false;
            if (ambiguities.Count > 0)
            {
                foreach (Position amb in ambiguities)
                {
                    if (amb.Rank == move.OriginalPosition.Rank)
                    {
                        needsUnambigFile = true;
                    }
                    if (amb.File == move.OriginalPosition.File)
                    {
                        needsUnambigRank = true;
                    }
                }
                if (!needsUnambigFile && !needsUnambigRank)
                {
                    needsUnambigFile = true;
                }
            }

            StringBuilder sanBuilder = new StringBuilder();

            if (!(movingPiece is Pawn))
            {
                sanBuilder.Append(char.ToUpperInvariant(movingPiece.GetFenCharacter()));
            }
            else if (isCapture)
            {
                sanBuilder.Append(move.OriginalPosition.File.ToString().ToLowerInvariant());
                needsUnambigFile = false;
                needsUnambigRank = false;
            }
            if (needsUnambigFile)
            {
                sanBuilder.Append(move.OriginalPosition.File.ToString().ToLowerInvariant());
            }
            if (needsUnambigRank)
            {
                sanBuilder.Append(move.OriginalPosition.Rank.ToString());
            }
            if (isCapture)
            {
                sanBuilder.Append("x");
            }
            sanBuilder.Append(move.NewPosition.ToString().ToLowerInvariant());
            if (move.Promotion.HasValue)
            {
                sanBuilder.Append("=");
                sanBuilder.Append(move.Promotion.Value);
            }
            if (IsWinner(ChessUtilities.GetOpponentOf(WhoseTurn)))
            {
                sanBuilder.Append("#");
            }
            else if (IsInCheck(WhoseTurn))
            {
                sanBuilder.Append("+");
            }
            return sanBuilder.ToString();
        }

        public virtual string GetPGN()
        {
            StringBuilder pgnBuilder = new StringBuilder();
            int counter = 1;
            foreach (DetailedMove dm in _moves)
            {
                counter++;
                if (counter % 2 == 0)
                {
                    pgnBuilder.Append(counter / 2);
                    pgnBuilder.Append(". ");
                }
                pgnBuilder.Append(dm.SAN);
                pgnBuilder.Append(" ");
            }
            if (IsDraw())
            {
                pgnBuilder.Append("1/2-1/2");
            }
            else if (IsWinner(Player.White))
            {
                pgnBuilder.Append("1-0");
            }
            else if (IsWinner(Player.Black))
            {
                pgnBuilder.Append("0-1");
            }
            else
            {
                pgnBuilder.Append("*");
            }
            return pgnBuilder.ToString();
        }

        protected virtual void AddDetailedMove(DetailedMove dm)
        {
            _moves.Add(dm);
        }

        public ReadOnlyCollection<Move> GetValidMoves(Position from)
        {
            ChessUtilities.ThrowIfNull(from, "from");
            return GetValidMoves(from, false);
        }

        protected virtual ReadOnlyCollection<Move> GetValidMoves(Position from, bool returnIfAny)
        {
            ChessUtilities.ThrowIfNull(from, "from");
            Piece piece = GetPieceAt(from);
            if (piece == null || piece.Owner != WhoseTurn) return new ReadOnlyCollection<Move>(new List<Move>());
            return piece.GetValidMoves(from, returnIfAny, this, IsValidMove);
        }

        public ReadOnlyCollection<Move> GetValidMoves(Player player)
        {
            return GetValidMoves(player, false);
        }

        protected virtual ReadOnlyCollection<Move> GetValidMoves(Player player, bool returnIfAny)
        {
            if (player != WhoseTurn) return new ReadOnlyCollection<Move>(new List<Move>());
            List<Move> validMoves = new List<Move>();
            for (int r = 1; r <= Board.Length; r++)
            {
                for (int f = 0; f < Board[8 - r].Length; f++)
                {
                    Piece p = GetPieceAt((File)f, r);
                    if (p != null && p.Owner == player)
                    {
                        validMoves.AddRange(GetValidMoves(new Position((File)f, r), returnIfAny));
                        if (returnIfAny && validMoves.Count > 0)
                        {
                            return new ReadOnlyCollection<Move>(validMoves);
                        }
                    }
                }
            }
            return new ReadOnlyCollection<Move>(validMoves);
        }

        public virtual bool HasAnyValidMoves(Position from)
        {
            ChessUtilities.ThrowIfNull(from, "from");
            ReadOnlyCollection<Move> validMoves = GetValidMoves(from, true);
            return validMoves.Count > 0;
        }

        public virtual bool HasAnyValidMoves(Player player)
        {
            ReadOnlyCollection<Move> validMoves = GetValidMoves(player, true);
            return validMoves.Count > 0;
        }

        public virtual bool IsInCheck(Player player)
        {
            if (player == Player.None)
            {
                throw new ArgumentException("IsInCheck: Player.None is an invalid argument.");
            }

            Position kingPos = new Position(File.None, -1);

            for (int r = 1; r <= Board.Length; r++)
            {
                for (int f = 0; f < Board[8 - r].Length; f++)
                {
                    Piece curr = GetPieceAt((File)f, r);
                    if (curr is King && curr.Owner == player)
                    {
                        kingPos = new Position((File)f, r);
                        break;
                    }
                }
                if (kingPos != new Position(File.None, -1))
                {
                    break;
                }
            }

            if (kingPos.File == File.None)
                return false;

            for (int r = 1; r <= Board.Length; r++)
            {
                for (int f = 0; f < Board[8 - r].Length; f++)
                {
                    Piece curr = GetPieceAt((File)f, r);
                    if (curr == null) continue;
                    Player p = curr.Owner;
                    Move move = new Move(new Position((File)f, r), kingPos, p);
                    List<Move> moves = new List<Move>();
                    if (curr is Pawn && ((move.NewPosition.Rank == 8 && move.Player == Player.White) || (move.NewPosition.Rank == 1 && move.Player == Player.Black)))
                    {
                        moves.Add(new Move(move.OriginalPosition, move.NewPosition, move.Player, 'Q'));
                        moves.Add(new Move(move.OriginalPosition, move.NewPosition, move.Player, 'R'));
                        moves.Add(new Move(move.OriginalPosition, move.NewPosition, move.Player, 'B'));
                        moves.Add(new Move(move.OriginalPosition, move.NewPosition, move.Player, 'N'));
                        moves.Add(new Move(move.OriginalPosition, move.NewPosition, move.Player, 'K'));
                    }
                    else
                    {
                        moves.Add(move);
                    }
                    foreach (Move m in moves)
                    {
                        if (IsValidMove(m, false, false))
                        {
                            return true;
                        }
                    }
                }
            }
            return false;
        }

        public virtual bool IsCheckmated(Player player)
        {
            return IsInCheck(player) && !HasAnyValidMoves(player);
        }

        public virtual bool IsStalemated(Player player)
        {
            return WhoseTurn == player && !IsInCheck(player) && !HasAnyValidMoves(player);
        }

        public virtual bool IsWinner(Player player)
        {
            return IsCheckmated(ChessUtilities.GetOpponentOf(player));
        }

        public virtual bool IsDraw()
        {
            return DrawClaimed || IsStalemated(Player.White) || IsStalemated(Player.Black);
        }

        public virtual bool WouldBeInCheckAfter(Move move, Player player)
        {
            ChessUtilities.ThrowIfNull(move, "move");
            GameCreationData gcd = new GameCreationData();
            gcd.Board = Board;
            gcd.CanWhiteCastleKingSide = CanWhiteCastleKingSide;
            gcd.CanWhiteCastleQueenSide = CanWhiteCastleQueenSide;
            gcd.CanBlackCastleKingSide = CanBlackCastleKingSide;
            gcd.CanBlackCastleQueenSide = CanBlackCastleQueenSide;
            gcd.EnPassant = null;
            if (_moves.Count > 0)
            {
                DetailedMove last = _moves.Last();
                if (last.Piece is Pawn && new PositionDistance(last.OriginalPosition, last.NewPosition).DistanceY == 2)
                {
                    gcd.EnPassant = new Position(last.NewPosition.File, last.Player == Player.White ? 3 : 6);
                }
            }
            gcd.HalfMoveClock = i_halfMoveClock;
            gcd.FullMoveNumber = i_fullMoveNumber;
            ChessGame copy = new ChessGame(gcd);
            Piece p = copy.GetPieceAt(move.OriginalPosition);
            copy.SetPieceAt(move.OriginalPosition.File, move.OriginalPosition.Rank, null);
            copy.SetPieceAt(move.NewPosition.File, move.NewPosition.Rank, p);
            return copy.IsInCheck(player);
        }

        public void ClaimDraw(string reason)
        {
            _drawn = true;
            _drawReason = reason;
        }

        public void Resign(Player player)
        {
            _resigned = player;
        }

        public GameCreationData GetGameCreationData()
        {
            return new GameCreationData
            {
                Board = Board,
                CanBlackCastleKingSide = CanBlackCastleKingSide,
                CanBlackCastleQueenSide = CanBlackCastleQueenSide,
                CanWhiteCastleKingSide = CanWhiteCastleKingSide,
                CanWhiteCastleQueenSide = CanWhiteCastleQueenSide,
                Moves = Moves.Select(x => x).ToArray(),
                FullMoveNumber = i_fullMoveNumber,
                HalfMoveClock = i_halfMoveClock,
                WhoseTurn = WhoseTurn,
                DrawClaimed = DrawClaimed,
                DrawReason = DrawReason,
                Resigned = Resigned
            };
        }
    }
}
