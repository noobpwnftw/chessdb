using System;
using System.Globalization;

namespace ChessDotNet
{
    public enum File
    {
        A = 0,
        B = 1,
        C = 2,
        D = 3,
        E = 4,
        F = 5,
        G = 6,
        H = 7,
        None = -1
    }

    public class Position
    {
        File _file;
        public File File
        {
            get
            {
                return _file;
            }
            set
            {
                _file = value;
            }
        }

        int _rank;
        public int Rank
        {
            get
            {
                return _rank;
            }
            set
            {
                _rank = value;
            }
        }
        public Position() {}

        public Position(File file, int rank)
        {
            _file = file;
            _rank = rank;
        }

        public Position(string position)
        {
            if (position == null)
            {
                throw new ArgumentNullException("position");
            }
            if (position.Length != 2)
            {
                throw new ArgumentException("Length of `pos` is not 2.");
            }

            position = position.ToUpperInvariant();
            char file = position[0];
            char rank = position[1];
            switch (file)
            {
                case 'A':
                    _file = File.A;
                    break;
                case 'B':
                    _file = File.B;
                    break;
                case 'C':
                    _file = File.C;
                    break;
                case 'D':
                    _file = File.D;
                    break;
                case 'E':
                    _file = File.E;
                    break;
                case 'F':
                    _file = File.F;
                    break;
                case 'G':
                    _file = File.G;
                    break;
                case 'H':
                    _file = File.H;
                    break;
                default:
                    throw new ArgumentException("First char of `pos` not in range A-F.");
            }

            if (int.TryParse(rank.ToString(), out _rank))
            {
                if (_rank < 1 || _rank > 8)
                {
                    throw new ArgumentException("Second char of `pos` not in range 1-8.");
                }
            }
            else
            {
                throw new ArgumentException("Second char of `pos` not in range 1-8.");
            }
        }

        public override bool Equals(object obj)
        {
            if (ReferenceEquals(this, obj))
                return true;
            if (obj == null || GetType() != obj.GetType())
                return false;
            Position pos2 = (Position)obj;
            return File == pos2.File && Rank == pos2.Rank;
        }

        public override int GetHashCode()
        {
            return new { File, Rank }.GetHashCode();
        }

        public static bool operator ==(Position position1, Position position2)
        {
            if (ReferenceEquals(position1, position2))
                return true;
            if ((object)position1 == null || (object)position2 == null)
                return false;
            return position1.Equals(position2);
        }

        public static bool operator !=(Position position1, Position position2)
        {
            if (ReferenceEquals(position1, position2))
                return false;
            if ((object)position1 == null || (object)position2 == null)
                return true;
            return !position1.Equals(position2);
        }

        public override string ToString()
        {
            return File.ToString() + Rank.ToString(CultureInfo.InvariantCulture);
        }
    }
}
