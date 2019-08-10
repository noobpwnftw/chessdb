using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Diagnostics;
using System.IO;
using System.Threading;
using System.Net;
using System.Configuration;
using System.Security.Cryptography;
using System.Reflection;

namespace ChessDBClient
{
    class EngineDriver
    {
        public static string StringToMD5Hash(string inputString)
        {
            MD5CryptoServiceProvider md5 = new MD5CryptoServiceProvider();
            byte[] encryptedBytes = md5.ComputeHash(Encoding.ASCII.GetBytes(inputString));
            StringBuilder sb = new StringBuilder();
            for (int i = 0; i < encryptedBytes.Length; i++)
            {
                sb.AppendFormat("{0:x2}", encryptedBytes[i]);
            }
            return sb.ToString();
        }

        public Process EngineProcess = new Process();
        public StreamWriter EngineStreamWriter;
        public StreamReader EngineStreamReader;
        public void StartEngine(int nThreadId)
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
            bool bAutoProcessorAffinity;
            if (!Boolean.TryParse(ConfigurationManager.AppSettings["AutoProcessorAffinity"], out bAutoProcessorAffinity))
            {
                bAutoProcessorAffinity = false;
            }
            if (bAutoProcessorAffinity)
            {
                EngineProcess.ProcessorAffinity = (System.IntPtr)(1 << (nThreadId * 2));
            }
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
        String TrimFromZero(String input)
        {
            if (input == null)
                return input;

            int index = input.IndexOf('\0');
            if (index < 0)
                return input;

            return input.Substring(0, index);
        }
        delegate string ReadLineDelegate();
        public void ProcessQueue(object ThreadId)
        {
            string strThreadId = string.Format("{0:D2}", int.Parse(ThreadId.ToString()));
            WaitForReady();
            bool bExitAfterEmptyQueue;
            if (!Boolean.TryParse(ConfigurationManager.AppSettings["ExitAfterEmptyQueue"], out bExitAfterEmptyQueue))
            {
                bExitAfterEmptyQueue = false;
            }
            bool bExitAfterResume;
            if (!Boolean.TryParse(ConfigurationManager.AppSettings["ExitAfterResume"], out bExitAfterResume))
            {
                bExitAfterResume = true;
            }
            while (!Program.bClosing)
            {
                try
                {
                    bool bResuming = false;
                    if (!File.Exists("last" + strThreadId + ".txt"))
                    {
                        Console.WriteLine("[" + strThreadId + "] 正在获取新队列...");
                        HttpWebRequest req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=getqueue&token=" + ConfigurationManager.AppSettings["AccessToken"]);
                        HttpWebResponse response = (HttpWebResponse)req.GetResponse();
                        if (response.StatusCode != HttpStatusCode.OK)
                            throw new Exception("获取队列失败。");
                        StreamReader myStreamReader = new StreamReader(response.GetResponseStream());
                        String result = TrimFromZero(myStreamReader.ReadToEnd());
                        myStreamReader.Close();
                        response.Close();
                        if (result.Length > 0)
                        {
                            if (result == "tokenerror")
                                throw new Exception("AccessToken错误。");
                            File.WriteAllText("last" + strThreadId + ".txt", result);
                        }
                        else if (bExitAfterEmptyQueue)
                        {
                            Program.bClosing = true;
                            return;
                        }
                    }
                    else
                    {
                        Console.WriteLine("[" + strThreadId + "] 正在恢复队列...");
                        if (bExitAfterResume)
                            bResuming = true;
                    }
                    if (File.Exists("last" + strThreadId + ".txt"))
                    {
                        String fenkey = File.ReadAllText("last" + strThreadId + ".txt");
                        StringReader sr = new StringReader(fenkey);
                        String fen = TrimFromZero(sr.ReadLine());
                        while (fen != null && fen.Length > 0)
                        {
                            Console.WriteLine("[" + strThreadId + "] 正在计算...");
                            String[] outdata = fen.Split(' ');
                            ChessDotNet.ChessGame board = new ChessDotNet.ChessGame(outdata[0] + ' ' + outdata[1] + ' ' + outdata[2] + ' ' + outdata[3]);
                            if (outdata.Length > 4)
                            {
                                board.MakeMove(outdata[5]);
                            }
                            EngineStreamWriter.WriteLine("position fen " + board.GetFen());
                            EngineStreamWriter.WriteLine(ConfigurationManager.AppSettings["GoCommand"]);
                            String outstr = EngineStreamReader.ReadLine();
                            bool hasBestMove = false;
                            int score = int.MinValue;
                            int nps = 0;
                            long nodes = 0;
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
                                        try
                                        {
                                            score = int.Parse(tmp[i + 2]);
                                            if (tmp[i + 1] != "cp")
                                            {
                                                if (score > 0)
                                                    score = 30000 - score;
                                                else
                                                    score = -30000 - score;
                                            }
                                        }
                                        catch { }
                                    }
                                    else if (tmp[i] == "nps")
                                    {
                                        try
                                        {
                                            nps = int.Parse(tmp[i + 1]) / 1000;
                                        }
                                        catch { }
                                    }
                                    else if (tmp[i] == "nodes")
                                    {
                                        try
                                        {
                                            nodes = int.Parse(tmp[i + 1]) / 1000;
                                        }
                                        catch { }
                                    }
                                }
                                if (hasBestMove)
                                    break;
                                ReadLineDelegate d = EngineStreamReader.ReadLine;
                                IAsyncResult result = d.BeginInvoke(null, null);
                                while (true)
                                {
                                    result.AsyncWaitHandle.WaitOne(300000);
                                    if (result.IsCompleted)
                                    {
                                        outstr = d.EndInvoke(result);
                                        break;
                                    }
                                    else
                                    {
                                        EngineStreamWriter.WriteLine("stop");
                                    }
                                }
                            }
                            WaitForReady();
                            if (hasBestMove && score != int.MinValue)
                            {
                                if (Math.Abs(score) > 30000)
                                {
                                    Console.WriteLine("[" + strThreadId + "] 清除Hash重新计算...");
                                    EngineStreamWriter.WriteLine("ucinewgame");
                                    WaitForReady();
                                    continue;
                                }
                                Console.WriteLine("[" + strThreadId + "] 正在提交结果...(NPS = " + nps.ToString() + "K)");
                                bool succeess = false;
                                while (!succeess)
                                {
                                    if (outdata.Length > 4)
                                    {
                                        try
                                        {
                                            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=store&board=" + outdata[0] + ' ' + outdata[1] + ' ' + outdata[2] + ' ' + outdata[3] + "&move=" + outdata[5] + "&score=" + score.ToString() + "&nodes=" + nodes.ToString() + "&token=" + StringToMD5Hash(ConfigurationManager.AppSettings["AccessToken"] + outdata[0] + ' ' + outdata[1] + ' ' + outdata[2] + ' ' + outdata[3] + outdata[5] + score.ToString()));
                                            HttpWebResponse response = (HttpWebResponse)req.GetResponse();
                                            if (response.StatusCode != HttpStatusCode.OK)
                                                throw new Exception("提交结果失败。");
                                            StreamReader myStreamReader = new StreamReader(response.GetResponseStream());
                                            String result = TrimFromZero(myStreamReader.ReadToEnd());
                                            myStreamReader.Close();
                                            response.Close();
                                            if (result == "tokenerror")
                                                throw new Exception("AccessToken错误。");

                                            board = new ChessDotNet.ChessGame(outdata[0] + ' ' + outdata[1] + ' ' + outdata[2] + ' ' + outdata[3]);
                                            board.MakeMove(outdata[5]);
                                            int tmpscore = -score;
                                            if (tmpscore < -10000)
                                                tmpscore--;
                                            else if (tmpscore > 10000)
                                                tmpscore++;
                                            req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=store&board=" + board.GetFen() + "&move=" + bestmove + "&score=" + tmpscore.ToString() + "&token=" + StringToMD5Hash(ConfigurationManager.AppSettings["AccessToken"] + board.GetFen() + bestmove + tmpscore.ToString()));
                                            response = (HttpWebResponse)req.GetResponse();
                                            if (response.StatusCode != HttpStatusCode.OK)
                                                throw new Exception("提交结果失败。");
                                            myStreamReader = new StreamReader(response.GetResponseStream());
                                            result = TrimFromZero(myStreamReader.ReadToEnd());
                                            myStreamReader.Close();
                                            response.Close();
                                            if (result == "tokenerror")
                                                throw new Exception("AccessToken错误。");
                                            succeess = true;
                                        }
                                        catch (Exception e)
                                        {
                                            Console.WriteLine(e.ToString());
                                            Thread.Sleep(1000);
                                        }
                                    }
                                    else
                                    {
                                        try
                                        {
                                            int tmpscore = -score;
                                            if (tmpscore < -10000)
                                                tmpscore--;
                                            else if (tmpscore > 10000)
                                                tmpscore++;
                                            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=store&board=" + outdata[0] + ' ' + outdata[1] + ' ' + outdata[2] + ' ' + outdata[3] + "&move=" + bestmove + "&score=" + tmpscore.ToString() + "&nodes=" + nodes.ToString() + "&token=" + StringToMD5Hash(ConfigurationManager.AppSettings["AccessToken"] + outdata[0] + ' ' + outdata[1] + ' ' + outdata[2] + ' ' + outdata[3] + bestmove + tmpscore.ToString()));
                                            HttpWebResponse response = (HttpWebResponse)req.GetResponse();
                                            if (response.StatusCode != HttpStatusCode.OK)
                                                throw new Exception("提交结果失败。");
                                            StreamReader myStreamReader = new StreamReader(response.GetResponseStream());
                                            String result = TrimFromZero(myStreamReader.ReadToEnd());
                                            myStreamReader.Close();
                                            response.Close();
                                            if (result == "tokenerror")
                                                throw new Exception("AccessToken错误。");
                                            succeess = true;
                                        }
                                        catch (Exception e)
                                        {
                                            Console.WriteLine(e.ToString());
                                            Thread.Sleep(1000);
                                        }
                                    }
                                }
                            }
                            fen = TrimFromZero(sr.ReadLine());
                        }
                        fenkey = StringToMD5Hash(fenkey);
                        try
                        {
                            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=ackqueue&key=" + fenkey + "&token=" + StringToMD5Hash(ConfigurationManager.AppSettings["AccessToken"] + fenkey));
                            HttpWebResponse response = (HttpWebResponse)req.GetResponse();
                            if (response.StatusCode != HttpStatusCode.OK)
                                throw new Exception("提交结果失败。");
                            StreamReader myStreamReader = new StreamReader(response.GetResponseStream());
                            String result = TrimFromZero(myStreamReader.ReadToEnd());
                            myStreamReader.Close();
                            response.Close();
                            if (result == "tokenerror")
                                throw new Exception("AccessToken错误。");
                        }
                        catch (Exception e)
                        {
                            Console.WriteLine(e.ToString());
                            Thread.Sleep(1000);
                        }
                        File.Delete("last" + strThreadId + ".txt");
                        if (bResuming)
                        {
                            Program.bClosing = true;
                            return;
                        }
                    }
                    else
                    {
                        Thread.Sleep(3000);
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
                driver.StartEngine(i);
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
