using System;
using System.Linq;

namespace ChessDotNet
{
    public static class ChessUtilities
    {
        public static void ThrowIfNull(object value, string parameterName)
        {
            if (value == null)
            {
                throw new ArgumentNullException(parameterName);
            }
        }

        public static Player GetOpponentOf(Player player)
        {
            if (player == Player.None)
                throw new ArgumentException("`player` cannot be Player.None.");
            return player == Player.White ? Player.Black : Player.White;
        }

        public static File[] FilesBetween(File file1, File file2, bool file1Inclusive, bool file2Inclusive)
        {
            if (file1 == file2)
            {
                if (file1Inclusive && file2Inclusive) { return new File[] { file1 }; }
                else { return new File[] { }; }
            }
            int min = Math.Min((int)file1, (int)file2);
            int max = Math.Max((int)file1, (int)file2);
            bool minInc;
            bool maxInc;
            if (min == (int)file1)
            {
                minInc = file1Inclusive;
                maxInc = file2Inclusive;
            }
            else
            {
                maxInc = file1Inclusive;
                minInc = file2Inclusive;
            }
            File[] files = new File[] { File.A, File.B, File.C, File.D, File.E, File.F, File.G, File.H };
            return files.Skip(min + (minInc ? 0 : 1)).Take(max - min + (maxInc ? 1 : 0) - (minInc ? 0 : 1)).ToArray();
        }

        private static string Chess960StartingArray(int n)
        {
            string[] fenParts = new string[8];

            int n2 = n / 4;
            int b1 = n % 4;
            fenParts[1 + b1 * 2] = "B";

            int n3 = n2 / 4;
            int b2 = n2 % 4;
            fenParts[b2 * 2] = "B";

            int n4 = n3 / 6;
            int q = n3 % 6;

            int free = 0;
            for (int i = 0; i < fenParts.Length; i++)
            {
                if (fenParts[i] == null)
                {
                    if (free == q)
                    {
                        fenParts[i] = "Q";
                        break;
                    }
                    free++;
                }
            }

            bool[] knightPositioning = new bool[][]
            {
                new bool[] { true, true, false, false, false },
                new bool[] { true, false, true, false, false },
                new bool[] { true, false, false, true, false },
                new bool[] { true, false, false, false, true },
                new bool[] { false, true, true, false, false },
                new bool[] { false, true, false, true, false },
                new bool[] { false, true, false, false, true },
                new bool[] { false, false, true, true, false },
                new bool[] { false, false, true, false, true },
                new bool[] { false, false, false, true, true }
            }[n4];
            int knightPosCounter = 0;
            for (int i = 0; i < fenParts.Length; i++)
            {
                if (fenParts[i] == null)
                {
                    if (knightPositioning[knightPosCounter])
                    {
                        fenParts[i] = "N";
                    }
                    knightPosCounter++;
                }
            }

            free = 0;
            for (int i = 0; i < fenParts.Length; i++)
            {
                if (fenParts[i] == null)
                {
                    switch (free)
                    {
                        case 0:
                            fenParts[i] = "R";
                            break;
                        case 1:
                            fenParts[i] = "K";
                            break;
                        case 2:
                            fenParts[i] = "R";
                            break;
                    }
                    free++;
                    if (free > 2)
                    {
                        break;
                    }
                }
            }

            return string.Join("", fenParts);
        }

        public static string FenForChess960Symmetrical(int n)
        {
            if (n < 0 || n > 959)
            {
                throw new ArgumentException("'n' must be greater than or equal to 0, and smaller than or equal to 959.");
            }

            string startingPos = Chess960StartingArray(n);
            return string.Format("{0}/pppppppp/8/8/8/8/PPPPPPPP/{1} w KQkq - 0 1", startingPos.ToLower(), startingPos);
        }

        public static string FenForChess960Asymmetrical(int nWhite, int nBlack)
        {
            if (nWhite < 0 || nWhite > 959 || nBlack < 0 || nBlack > 959)
            {
                throw new ArgumentException("'nWhite' and 'nBlack' must be greater than or equal to 0, and smaller than or equal to 959.");
            }

            string startingPosWhite = Chess960StartingArray(nWhite);
            string startingPosBlack = Chess960StartingArray(nBlack).ToLower();
            return string.Format("{0}/pppppppp/8/8/8/8/PPPPPPPP/{1} w KQkq - 0 1", startingPosBlack, startingPosWhite);
        }

        public static string FenForHorde960(int n)
        {
            if (n < 0 || n > 959)
            {
                throw new ArgumentException("'n' must be greater than or equal to 0, and smaller than or equal to 959.");
            }

            string startingPos = Chess960StartingArray(n);
            return string.Format("{0}/pppppppp/8/1PP2PP1/PPPPPPPP/PPPPPPPP/PPPPPPPP/PPPPPPPP w kq - 0 1", startingPos.ToLower());
        }

        // https://github.com/ProgramFOX/Chess.NET/wiki/Algorithm-for-RacingKings1440-positions
        private static string[] RK1440WhiteStartingArray(int n)
        {
            string[][] setup = new string[][] { new string[4], new string[4] };

            int n2 = n / 4;
            int k = n % 4;

            switch (k)
            {
                case 0:
                    setup[0][3] = "K";
                    break;
                case 1:
                    setup[1][3] = "K";
                    break;
                case 2:
                    setup[1][2] = "K";
                    break;
                case 3:
                    setup[0][2] = "K";
                    break;
            }

            int n3 = n2 / 3;
            int b1 = n2 % 3;

            int[][] possibleB1Squares = k % 2 == 0 ? new int[][] { new int[] { 0, 1 }, new int[] { 0, 3 }, new int[] { 1, 2 }, new int[] { 1, 0 } }
                                                   : new int[][] { new int[] { 0, 0 }, new int[] { 0, 2 }, new int[] { 1, 3 }, new int[] { 1, 1 } };
            int counter = 0;
            for (int i = 0; i < possibleB1Squares.Length; i++)
            {
                int[] curr = possibleB1Squares[i];
                if (setup[curr[0]][curr[1]] == null)
                {
                    if (counter == b1)
                    {
                        setup[curr[0]][curr[1]] = "B";
                        break;
                    }
                    counter++;
                }
            }

            int n4 = n3 / 4;
            int b2 = n3 % 4;
            int[][] possibleB2Squares = k % 2 != 0 ? new int[][] { new int[] { 0, 1 }, new int[] { 0, 3 }, new int[] { 1, 2 }, new int[] { 1, 0 } }
                                                   : new int[][] { new int[] { 0, 0 }, new int[] { 0, 2 }, new int[] { 1, 3 }, new int[] { 1, 1 } };
            int[] chosenB2Square = possibleB2Squares[b2];
            setup[chosenB2Square[0]][chosenB2Square[1]] = "B";

            int n5 = n4 / 5;
            int q = n4 % 5;
            int[][] possibleQNRSquares = new int[][] { new int[] { 0, 0 }, new int[] { 0, 1 }, new int[] { 0, 2 }, new int[] { 0, 3 }, new int[] { 1, 3 }, new int[] { 1, 2 }, new int[] { 1, 1 }, new int[] { 1, 0 } };
            counter = 0;
            for (int i = 0; i < possibleQNRSquares.Length; i++)
            {
                int[] curr = possibleQNRSquares[i];
                if (setup[curr[0]][curr[1]] == null)
                {
                    if (counter == q)
                    {
                        setup[curr[0]][curr[1]] = "Q";
                        break;
                    }
                    counter++;
                }
            }

            string[] remainingConfiguration = new string[][]
            {
                new string[] { "N", "N", "R", "R" },
                new string[] { "N", "R", "N", "R" },
                new string[] { "N", "R", "R", "N" },
                new string[] { "R", "N", "N", "R" },
                new string[] { "R", "N", "R", "N" },
                new string[] { "R", "R", "N", "N" }
            }[n5];
            counter = 0;
            for (int i = 0; i < possibleQNRSquares.Length; i++)
            {
                int[] curr = possibleQNRSquares[i];
                if (setup[curr[0]][curr[1]] == null)
                {
                    setup[curr[0]][curr[1]] = remainingConfiguration[counter];
                    counter++;
                }
            }

            return setup.Select(x => string.Join("", x)).ToArray();
        }

        public static string FenForRacingKings1440Symmetrical(int n)
        {
            string[] whiteRows = RK1440WhiteStartingArray(n);
            string[] blackRows = new string[] { string.Concat(whiteRows[0].ToLowerInvariant().Reverse()), string.Concat(whiteRows[1].ToLowerInvariant().Reverse()) };
            return string.Format("8/8/8/8/8/8/{0}{1}/{2}{3} w - - 0 1", blackRows[0], whiteRows[0], blackRows[1], whiteRows[1]);
        }

        public static string FenForRacingKings1440Asymmetrical(int nWhite, int nBlack)
        {
            string[] whiteRows = RK1440WhiteStartingArray(nWhite);

            string[] whiteRowsForBlack = RK1440WhiteStartingArray(nBlack);
            string[] blackRows = new string[] { string.Concat(whiteRowsForBlack[0].ToLowerInvariant().Reverse()), string.Concat(whiteRowsForBlack[1].ToLowerInvariant().Reverse()) };
            return string.Format("8/8/8/8/8/8/{0}{1}/{2}{3} w - - 0 1", blackRows[0], whiteRows[0], blackRows[1], whiteRows[1]);
        }
    }
}
