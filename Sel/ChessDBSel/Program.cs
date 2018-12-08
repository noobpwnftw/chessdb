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

namespace ChessDBSel
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
                        HttpWebRequest req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=getsel&token=" + ConfigurationManager.AppSettings["AccessToken"]);
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
                        StringReader sr = new StringReader(File.ReadAllText("last" + strThreadId + ".txt"));
                        String fen = TrimFromZero(sr.ReadLine());
                        while (fen != null && fen.Length > 0)
                        {
                            Console.WriteLine("[" + strThreadId + "] 正在筛选...");
                            EngineStreamWriter.WriteLine("position fen " + fen);
                            EngineStreamWriter.WriteLine(ConfigurationManager.AppSettings["GoCommand"]);
                            String outstr = EngineStreamReader.ReadLine();
                            while (outstr != null)
                            {
                                if(outstr.StartsWith("selmove"))
                                {
                                    var tmp = outstr.Split(' ');
                                    for (int i = 1; i < tmp.Length; i++)
                                    {
                                        bool succeess = false;
                                        while (!succeess)
                                        {
                                            try
                                            {
                                                HttpWebRequest req = (HttpWebRequest)WebRequest.Create(ConfigurationManager.AppSettings["CloudBookURL"] + "?action=store&board=" + fen + "&move=move:" + tmp[i]);
                                                HttpWebResponse response = (HttpWebResponse)req.GetResponse();
                                                if (response.StatusCode != HttpStatusCode.OK)
                                                    throw new Exception("提交结果失败。");
                                                StreamReader myStreamReader = new StreamReader(response.GetResponseStream());
                                                String result = TrimFromZero(myStreamReader.ReadToEnd());
                                                myStreamReader.Close();
                                                response.Close();
                                                if (result.Contains("ok"))
                                                {
                                                    Console.WriteLine("[" + strThreadId + "] 已添加着法[" + (i - 1) + "]...");
                                                }
                                                succeess = true;
                                            }
                                            catch (Exception e)
                                            {
                                                Console.WriteLine(e.ToString());
                                                Thread.Sleep(1000);
                                            }
                                        }
                                    }
                                    break;
                                }
                                outstr = EngineStreamReader.ReadLine();
                            }
                            WaitForReady();
                            fen = TrimFromZero(sr.ReadLine());
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
