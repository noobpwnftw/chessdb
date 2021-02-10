using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Diagnostics;
using System.IO;
using System.Threading;
using System.Net;
using System.Configuration;
using System.Reflection;

namespace ChessDBDiscover
{
    public class Board
    {
        private enum Piece
        {
            Blank,
            WhiteRook,
            WhiteKnight,
            WhiteBishop,
            WhiteAdvisor,
            WhiteKing,
            WhiteCannon,
            WhitePawn,
            BlackRook,
            BlackKnight,
            BlackBishop,
            BlackAdvisor,
            BlackKing,
            BlackCannon,
            BlackPawn
        }
        private Piece[] board = new Piece[90];
        private bool isblack = false;
        public void init()
        {
            init("rnbakabnr/9/1c5c1/p1p1p1p1p/9/9/P1P1P1P1P/1C5C1/9/RNBAKABNR w");
        }
        public void init(string fen)
        {
            bool hasturn = false;
            int index = 0;
            for (int i = 0; i < fen.Length; i++)
            {
                if (Char.IsDigit(fen[i]))
                {
                    for (int j = 0; j < fen[i] - '0'; j++)
                    {
                        board[index++] = Piece.Blank;
                    }
                }
                else if (!hasturn)
                {
                    switch (fen[i])
                    {
                        case 'R':
                            board[index++] = Piece.WhiteRook;
                            break;
                        case 'N':
                            board[index++] = Piece.WhiteKnight;
                            break;
                        case 'B':
                            board[index++] = Piece.WhiteBishop;
                            break;
                        case 'A':
                            board[index++] = Piece.WhiteAdvisor;
                            break;
                        case 'K':
                            board[index++] = Piece.WhiteKing;
                            break;
                        case 'C':
                            board[index++] = Piece.WhiteCannon;
                            break;
                        case 'P':
                            board[index++] = Piece.WhitePawn;
                            break;

                        case 'r':
                            board[index++] = Piece.BlackRook;
                            break;
                        case 'n':
                            board[index++] = Piece.BlackKnight;
                            break;
                        case 'b':
                            board[index++] = Piece.BlackBishop;
                            break;
                        case 'a':
                            board[index++] = Piece.BlackAdvisor;
                            break;
                        case 'k':
                            board[index++] = Piece.BlackKing;
                            break;
                        case 'c':
                            board[index++] = Piece.BlackCannon;
                            break;
                        case 'p':
                            board[index++] = Piece.BlackPawn;
                            break;
                        case '/':
                            break;
                        case ' ':
                            isblack = fen[i + 1] == 'b';
                            hasturn = true;
                            break;
                    }
                }
                else
                {
                    break;
                }
            }
        }
        public void makemove(string move)
        {
            int src = move[0] - 'a' + (9 - (move[1] - '0')) * 9;
            int dst = move[2] - 'a' + (9 - (move[3] - '0')) * 9;
            board[dst] = board[src];
            board[src] = Piece.Blank;
            isblack = !isblack;
        }
        public string getfen()
        {
            string fen = "";
            int blank = 0;
            for (int i = 0; i < board.Count(); i++)
            {
                if (i > 0 && i % 9 == 0)
                {
                    if (blank > 0)
                    {
                        fen += blank.ToString();
                        blank = 0;
                    }
                    fen += "/";
                }
                if (board[i] == Piece.Blank)
                    blank++;
                else
                {
                    if (blank > 0)
                    {
                        fen += blank.ToString();
                        blank = 0;
                    }
                    switch (board[i])
                    {
                        case Piece.WhiteRook:
                            fen += "R";
                            break;
                        case Piece.WhiteKnight:
                            fen += "N";
                            break;
                        case Piece.WhiteBishop:
                            fen += "B";
                            break;
                        case Piece.WhiteAdvisor:
                            fen += "A";
                            break;
                        case Piece.WhiteKing:
                            fen += "K";
                            break;
                        case Piece.WhiteCannon:
                            fen += "C";
                            break;
                        case Piece.WhitePawn:
                            fen += "P";
                            break;

                        case Piece.BlackRook:
                            fen += "r";
                            break;
                        case Piece.BlackKnight:
                            fen += "n";
                            break;
                        case Piece.BlackBishop:
                            fen += "b";
                            break;
                        case Piece.BlackAdvisor:
                            fen += "a";
                            break;
                        case Piece.BlackKing:
                            fen += "k";
                            break;
                        case Piece.BlackCannon:
                            fen += "c";
                            break;
                        case Piece.BlackPawn:
                            fen += "p";
                            break;
                    }
                }
            }
            if (blank > 0)
            {
                fen += blank.ToString();
                blank = 0;
            }
            if (isblack)
            {
                fen += " b";
            }
            else
            {
                fen += " w";
            }
            return fen;
        }
    }
    class EngineDriver
    {
        public Process EngineProcess = new Process();
        public StreamWriter EngineStreamWriter;
        public StreamReader EngineStreamReader;
        public void StartEngine()
        {
            EngineProcess.StartInfo.WorkingDirectory = Directory.GetCurrentDirectory() + @"/engine/";
            EngineProcess.StartInfo.FileName = EngineProcess.StartInfo.WorkingDirectory + ConfigurationManager.AppSettings["EngineFileName"];
            EngineProcess.StartInfo.UseShellExecute = false;
            EngineProcess.StartInfo.RedirectStandardInput = true;
            EngineProcess.StartInfo.RedirectStandardOutput = true;
            //EngineProcess.StartInfo.CreateNoWindow = true;
            EngineProcess.Start();
            EngineStreamWriter = EngineProcess.StandardInput;
            EngineStreamReader = EngineProcess.StandardOutput;
        }
        public void WaitForReady()
        {
            EngineStreamWriter.WriteLine("isready");
            while (true)
            {
                if (EngineStreamReader.ReadLine() == "readyok")
                    break;
            }
        }
        public void CheckProtocol()
        {
            EngineStreamWriter.WriteLine("uci");
            while (true)
            {
                if (EngineStreamReader.ReadLine() == "uciok")
                    break;
            }
        }
        String TrimFromZero(String input)
        {
            if (input == null)
                return input;

            int index = input.IndexOf('\0');
            if (index < 0)
                return input;

            return input.Substring(0, index);
        }
        public void ProcessQueue(object ThreadId)
        {
            string strThreadId = string.Format("{0:D2}", int.Parse(ThreadId.ToString()));
            int MinDepth;
            if (!int.TryParse(ConfigurationManager.AppSettings["MinDepth"], out MinDepth))
            {
                MinDepth = 5;
            }
            int MaxDepth;
            if (!int.TryParse(ConfigurationManager.AppSettings["MaxDepth"], out MaxDepth))
            {
                MaxDepth = 5;
            }
            Console.WriteLine("[" + strThreadId + "] 正在初始化引擎...");
            CheckProtocol();
            WaitForReady();
            Board board = new Board();
            while (!Program.bClosing)
            {
                board.init(ConfigurationManager.AppSettings["StartFEN"]);
                Console.WriteLine("[" + strThreadId + "] 正在获取初始局面...");
                int CurrentDepth = 0;
                while (CurrentDepth < MinDepth)
                {
                    try
                    {
                        HttpWebRequest req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=querylearn&board=" + board.getfen());
                        using (HttpWebResponse response = (HttpWebResponse)req.GetResponse())
                        {
                            if (response.StatusCode != HttpStatusCode.OK)
                                throw new Exception("获取局面失败。");
                            StreamReader myStreamReader = new StreamReader(response.GetResponseStream());
                            String result = TrimFromZero(myStreamReader.ReadToEnd());
                            myStreamReader.Close();
                            response.Close();
                            if (result.Length > 0)
                            {
                                if (result.Contains("move:"))
                                {
                                    board.makemove(result.Substring(5, 4));
                                    CurrentDepth++;
                                }
                                else
                                    break;
                            }
                        }
                    }
                    catch (Exception e)
                    {
                        Console.WriteLine(e.ToString());
                        Thread.Sleep(1000);
                    }
                }
                CurrentDepth = 0;
                try
                {
                    while (CurrentDepth < MaxDepth)
                    {
                        Console.WriteLine("[" + strThreadId + "] 正在计算...");
                        EngineStreamWriter.WriteLine(ConfigurationManager.AppSettings["FenCommand"] + " " + board.getfen());
                        EngineStreamWriter.WriteLine(ConfigurationManager.AppSettings["GoCommand"]);
                        String outstr = EngineStreamReader.ReadLine();
                        bool hasBestMove = false;
                        int score = int.MinValue;
                        int nps = 0;
                        String bestmove = null;
                        while (outstr != null)
                        {
                            var tmp = outstr.Split(' ');
                            for (int i = 0; i < tmp.Length; i++)
                            {
                                if (tmp[i] == "bestmove")
                                {
                                    hasBestMove = true;
                                    bestmove = tmp[i + 1];
                                    break;
                                }
                                else if (tmp[i] == "score")
                                {
                                    score = int.Parse(tmp[i + 1]);
                                }
                                else if (tmp[i] == "nps")
                                {
                                    nps = int.Parse(tmp[i + 1]) / 1000;
                                }
                            }
                            if (hasBestMove)
                                break;
                            outstr = EngineStreamReader.ReadLine();
                        }
                        WaitForReady();
                        if (hasBestMove && score != int.MinValue)
                        {
                            Console.WriteLine("[" + strThreadId + "] 正在提交结果...(NPS = " + nps.ToString() + "K)");
                            bool succeess = false;
                            while (!succeess)
                            {
                                try
                                {
                                    HttpWebRequest req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=store&board=" + board.getfen() + "&move=move:" + bestmove);
                                    using (HttpWebResponse response = (HttpWebResponse)req.GetResponse())
                                    {
                                        if (response.StatusCode != HttpStatusCode.OK)
                                            throw new Exception("提交结果失败。");
                                        response.Close();
                                    }
                                    board.makemove(bestmove);
                                    CurrentDepth++;
                                    succeess = true;
                                }
                                catch (Exception e)
                                {
                                    Console.WriteLine(e.ToString());
                                    Thread.Sleep(1000);
                                }
                            }
                            try
                            {
                                Console.WriteLine("[" + strThreadId + "] 正在获取局面...");
                                HttpWebRequest req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=querylearn&board=" + board.getfen());
                                using (HttpWebResponse response = (HttpWebResponse)req.GetResponse())
                                {
                                    if (response.StatusCode != HttpStatusCode.OK)
                                        throw new Exception("获取局面失败。");
                                    StreamReader myStreamReader = new StreamReader(response.GetResponseStream());
                                    String result = TrimFromZero(myStreamReader.ReadToEnd());
                                    myStreamReader.Close();
                                    response.Close();
                                    if (result.Length > 0)
                                    {
                                        if (result.Contains("move:"))
                                        {
                                            board.makemove(result.Substring(5, 4));
                                            CurrentDepth++;
                                        }
                                        else
                                            Console.WriteLine("[" + strThreadId + "] 正在学习...");
                                    }
                                }
                            }
                            catch (Exception e)
                            {
                                Console.WriteLine(e.ToString());
                                Thread.Sleep(1000);
                            }
                        }
                        else
                            break;
                    }
                }
                catch (Exception e)
                {
                    Console.WriteLine(e.ToString());
                    Thread.Sleep(1000);
                }
            }
        }
    }
    class Program
    {
        public static bool bClosing = false;

        static void Main(string[] args)
        {
            System.Console.TreatControlCAsInput = true;
            Directory.SetCurrentDirectory(Path.GetDirectoryName(Assembly.GetEntryAssembly().Location));
            int ThreadCount;
            if (!int.TryParse(ConfigurationManager.AppSettings["Threads"], out ThreadCount))
            {
                ThreadCount = 1;
            }
            List<Thread> workerThreads = new List<Thread>();
            for (int i = 0; i < ThreadCount; i++)
            {
                EngineDriver driver = new EngineDriver();
                driver.StartEngine();
                Thread thread = new Thread(driver.ProcessQueue);
                thread.Start(i);
                workerThreads.Add(thread);
            }
            ConsoleKeyInfo cki;
            do
            {
                cki = Console.ReadKey();
            }
            while (((cki.Modifiers & ConsoleModifiers.Control) == 0) || (cki.Key != ConsoleKey.C));
            Program.bClosing = true;
            Console.WriteLine("正在完成剩余计算...");
            foreach (Thread thread in workerThreads)
            {
                thread.Join();
            }
        }
    }
}
