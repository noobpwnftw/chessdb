using System;
using System.Collections.Generic;
using System.Collections.ObjectModel;

namespace ChessDotNet.Pieces
{
    public class King : Piece
    {
        public override Player Owner
        {
            get;
            set;
        }

        public bool HasCastlingAbility
        {
            get;
            set;
        }

        public override bool IsPromotionResult
        {
            get;
            set;
        }

        public King() : this(Player.None) {}

        public King(Player owner) : this(owner, true) { }

        public King(Player owner, bool hasCastlingAbility)
        {
            Owner = owner;
            HasCastlingAbility = hasCastlingAbility;
            IsPromotionResult = false;
        }

        public override Piece AsPromotion()
        {
            King copy = new King(Owner, HasCastlingAbility);
            copy.IsPromotionResult = true;
            return copy;
        }

        public override Piece GetWithInvertedOwner()
        {
            return new King(ChessUtilities.GetOpponentOf(Owner));
        }

        public override char GetFenCharacter()
        {
            return Owner == Player.White ? 'K' : 'k';
        }

        public override bool IsValidMove(Move move, ChessGame game)
        {
            ChessUtilities.ThrowIfNull(move, "move");
            Position origin = move.OriginalPosition;
            Position destination = move.NewPosition;
            PositionDistance distance = new PositionDistance(origin, destination);
            if (((distance.DistanceX == 1 && distance.DistanceY == 1)
                || (distance.DistanceX == 0 && distance.DistanceY == 1)
                || (distance.DistanceX == 1 && distance.DistanceY == 0)) &&
                (game.GetPieceAt(destination) == null || game.GetPieceAt(destination).Owner == ChessUtilities.GetOpponentOf(move.Player)))
            {
                return true;
            }

            if (distance.DistanceX == 2)
            {
                if (move.Player == Player.White)
                {
                    if (origin.Rank != 1 || destination.Rank != 1) return false;
                    if (game.InitialWhiteKingFile == File.E && game.InitialWhiteRookFileKingsideCastling == File.H && destination.File == File.G)
                    {
                        return CanCastle(origin, new Position(File.H, 1), game);
                    }
                    if (game.InitialWhiteKingFile == File.E && game.InitialWhiteRookFileQueensideCastling == File.A && destination.File == File.C)
                    {
                        return CanCastle(origin, new Position(File.A, 1), game);
                    }
                }
                else
                {
                    if (origin.Rank != 8 || destination.Rank != 8) return false;
                    if (game.InitialBlackKingFile == File.E && game.InitialBlackRookFileKingsideCastling == File.H && destination.File == File.G)
                    {
                        return CanCastle(origin, new Position(File.H, 8), game);
                    }
                    if (game.InitialBlackKingFile == File.E && game.InitialBlackRookFileQueensideCastling == File.A && destination.File == File.C)
                    {
                        return CanCastle(origin, new Position(File.A, 8), game);
                    }
                }
            }

            if (game.GetPieceAt(destination) is Rook)
            {
                return CanCastle(origin, destination, game);
            }
            else
            {
                return false;
            }
        }

        protected virtual bool CanCastle(Position origin, Position destination, ChessGame game)
        {
            if (!HasCastlingAbility) return false;
            if (Owner == Player.White)
            {
                if (origin.Rank != 1 || destination.Rank != 1) return false;

                if (destination.File == game.InitialWhiteRookFileKingsideCastling)
                {
                    if (!game.CanWhiteCastleKingSide) return false;
                }
                else if (destination.File == game.InitialWhiteRookFileQueensideCastling)
                {
                    if (!game.CanWhiteCastleQueenSide) return false;
                }
                else
                {
                    return false;
                }

                if (game.IsInCheck(Player.White))
                {
                    return false;
                }

                File[] betweenKingAndFinal = ChessUtilities.FilesBetween(origin.File, destination.File == game.InitialWhiteRookFileKingsideCastling ? File.G : File.C, false, true);
                foreach (File f in betweenKingAndFinal)
                {
                    if (f != destination.File && game.GetPieceAt(f, 1) != null)
                    {
                        return false;
                    }
                    if (game.WouldBeInCheckAfter(new Move(origin, new Position(f, 1), Player.White), Player.White))
                    {
                        return false;
                    }
                }

                File[] betweenRookAndFinal = ChessUtilities.FilesBetween(destination.File, destination.File == game.InitialWhiteRookFileKingsideCastling ? File.F : File.D, false, true);
                foreach (File f in betweenRookAndFinal)
                {
                    Piece p = game.GetPieceAt(f, 1);
                    if (f != destination.File && p != null && !(p is King))
                    {
                        return false;
                    }
                }
            }
            else
            {
                if (origin.Rank != 8 || destination.Rank != 8) return false;

                if (destination.File == game.InitialBlackRookFileKingsideCastling)
                {
                    if (!game.CanBlackCastleKingSide) return false;
                }
                else if (destination.File == game.InitialBlackRookFileQueensideCastling)
                {
                    if (!game.CanBlackCastleQueenSide) return false;
                }
                else
                {
                    return false;
                }

                if (game.IsInCheck(Player.Black))
                {
                    return false;
                }

                File[] betweenKingAndFinal = ChessUtilities.FilesBetween(origin.File, destination.File == game.InitialBlackRookFileKingsideCastling ? File.G : File.C, false, true);
                foreach (File f in betweenKingAndFinal)
                {
                    if (f != destination.File && game.GetPieceAt(f, 8) != null)
                    {
                        return false;
                    }
                    if (game.WouldBeInCheckAfter(new Move(origin, new Position(f, 8), Player.Black), Player.Black))
                    {
                        return false;
                    }
                }

                File[] betweenRookAndFinal = ChessUtilities.FilesBetween(destination.File, destination.File == game.InitialBlackRookFileKingsideCastling ? File.F : File.D, false, true);
                foreach (File f in betweenRookAndFinal)
                {
                    Piece p = game.GetPieceAt(f, 8);
                    if (f != destination.File && p != null && !(p is King))
                    {
                        return false;
                    }
                }
            }
            return true;
        }

        public override ReadOnlyCollection<Move> GetValidMoves(Position from, bool returnIfAny, ChessGame game, Func<Move, bool> gameMoveValidator)
        {
            ChessUtilities.ThrowIfNull(from, "from");
            List<Move> validMoves = new List<Move>();
            Piece piece = game.GetPieceAt(from);
            int l0 = game.BoardHeight;
            int l1 = game.BoardWidth;
            List<int[]> directions = new List<int[]>() { new int[] { 0, 1 }, new int[] { 1, 0 }, new int[] { 0, -1 }, new int[] { -1, 0 },
                        new int[] { 1, 1 }, new int[] { 1, -1 }, new int[] { -1, 1 }, new int[] { -1, -1 } };
            if (piece.Owner == Player.White && game.InitialWhiteKingFile == File.E && from.File == game.InitialWhiteKingFile && from.Rank == 1)
            {
                if (game.InitialWhiteRookFileKingsideCastling == File.H) directions.Add(new int[] { 2, 0 });
                if (game.InitialWhiteRookFileQueensideCastling == File.A) directions.Add(new int[] { -2, 0 });
            }
            if (piece.Owner == Player.Black && game.InitialBlackKingFile == File.E && from.File == game.InitialBlackKingFile && from.Rank == 8)
            {
                if (game.InitialBlackRookFileKingsideCastling == File.H) directions.Add(new int[] { 2, 0 });
                if (game.InitialBlackRookFileQueensideCastling == File.A) directions.Add(new int[] { -2, 0 });
            }
            if ((piece.Owner == Player.White ? game.InitialWhiteKingFile : game.InitialBlackKingFile) == from.File && from.Rank == (piece.Owner == Player.White ? 1 : 8))
            {
                if (piece.Owner == Player.White)
                {
                    int d1 = game.InitialWhiteRookFileKingsideCastling - from.File;
                    int d2 = game.InitialWhiteRookFileQueensideCastling - from.File;
                    if (Math.Abs(d1) != 1)
                    {
                        directions.Add(new int[] { d1, 0 });
                    }
                    if (Math.Abs(d2) != 1)
                    {
                        directions.Add(new int[] { d2, 0 });
                    }
                }
                else
                {
                    int d1 = game.InitialBlackRookFileKingsideCastling - from.File;
                    int d2 = game.InitialBlackRookFileQueensideCastling - from.File;
                    if (Math.Abs(d1) != 1)
                    {
                        directions.Add(new int[] { d1, 0 });
                    }
                    if (Math.Abs(d2) != 1)
                    {
                        directions.Add(new int[] { d2, 0 });
                    }
                }
            }
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
