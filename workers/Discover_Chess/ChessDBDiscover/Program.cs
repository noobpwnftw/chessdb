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
            while (!Program.bClosing)
            {
                ChessDotNet.ChessGame board = new ChessDotNet.ChessGame(ConfigurationManager.AppSettings["StartFEN"]);
                Console.WriteLine("[" + strThreadId + "] 正在获取初始局面...");
                int CurrentDepth = 0;
                while (CurrentDepth < MinDepth)
                {
                    try
                    {
                        HttpWebRequest req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=querylearn&board=" + board.GetFen());
                        using (HttpWebResponse response = (HttpWebResponse)req.GetResponse())
                        {
                            if (response.StatusCode != HttpStatusCode.OK)
                            {
                                response.Close();
                                throw new Exception("获取局面失败。");
                            }
                            StreamReader myStreamReader = new StreamReader(response.GetResponseStream());
                            String result = TrimFromZero(myStreamReader.ReadToEnd());
                            myStreamReader.Close();
                            response.Close();
                            if (result.Length > 0)
                            {
                                if (result.Contains("move:"))
                                {
                                    board.MakeMove(result.Substring(5));
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
                        EngineStreamWriter.WriteLine(ConfigurationManager.AppSettings["FenCommand"] + " " + board.GetFen());
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
                                    score = int.Parse(tmp[i + 2]);
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
                        if (hasBestMove)
                        {
                            Console.WriteLine("[" + strThreadId + "] 正在提交结果...(NPS = " + nps.ToString() + "K)");
                            bool succeess = false;
                            while (!succeess)
                            {
                                try
                                {
                                    HttpWebRequest req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=store&board=" + board.GetFen() + "&move=move:" + bestmove);
                                    using (HttpWebResponse response = (HttpWebResponse)req.GetResponse())
                                    {
                                        if (response.StatusCode != HttpStatusCode.OK)
                                        {
                                            response.Close();
                                            throw new Exception("提交结果失败。");
                                        }
                                        response.Close();
                                    }
                                    board.MakeMove(bestmove);
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
                                HttpWebRequest req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=querylearn&board=" + board.GetFen());
                                using (HttpWebResponse response = (HttpWebResponse)req.GetResponse())
                                {
                                    if (response.StatusCode != HttpStatusCode.OK)
                                    {
                                        response.Close();
                                        throw new Exception("获取局面失败。");
                                    }
                                    StreamReader myStreamReader = new StreamReader(response.GetResponseStream());
                                    String result = TrimFromZero(myStreamReader.ReadToEnd());
                                    myStreamReader.Close();
                                    response.Close();
                                    if (result.Length > 0)
                                    {
                                        if (result.Contains("move:"))
                                        {
                                            board.MakeMove(result.Substring(5));
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
