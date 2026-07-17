using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.ServiceProcess;
using System.Threading;
using LibreNMS.WindowsAgent.Core;
using LibreNMS.WindowsAgent.Service.Collectors;

namespace LibreNMS.WindowsAgent.Service
{
    internal static class Program
    {
        public const string ServiceName = "LibreNMSWindowsAgent";
        public const string DisplayName = "LibreNMS Windows Agent";

        private static int Main(string[] args)
        {
            try
            {
                var options = CommandLineOptions.Parse(args);
                var configPath = options.ConfigPath ?? DefaultConfigPath();
                var config = ConfigLoader.Load(configPath);

                if (options.StoreHorizonCredential)
                {
                    return StoreHorizonCredential(config);
                }

                var logger = new FileAgentLogger(config.Logging);
                var host = AgentHost.Create(config, configPath, logger);

                if (options.ValidateConfig)
                {
                    Console.WriteLine("Configuration OK");
                    Console.WriteLine($"Config: {configPath}");
                    Console.WriteLine($"Listen: {config.Listener.Address}:{config.Listener.Port}");
                    Console.WriteLine($"Collectors: {string.Join(", ", config.Collectors.Enabled ?? new List<string>())}");
                    return 0;
                }

                if (!string.IsNullOrWhiteSpace(options.SupportBundlePath))
                {
                    SupportBundle.Create(options.SupportBundlePath, configPath, config, host);
                    Console.WriteLine($"Support bundle written to {options.SupportBundlePath}");
                    return 0;
                }

                if (options.Once)
                {
                    var output = host.CollectOnceAsync(CancellationToken.None).GetAwaiter().GetResult();
                    Console.Write(output);
                    return 0;
                }

                if (options.ConsoleMode || Environment.UserInteractive)
                {
                    Console.WriteLine($"{DisplayName} listening on {config.Listener.Address}:{config.Listener.Port}. Press Ctrl+C to stop.");
                    using (var cts = new CancellationTokenSource())
                    {
                        Console.CancelKeyPress += (sender, eventArgs) =>
                        {
                            eventArgs.Cancel = true;
                            cts.Cancel();
                        };

                        host.RunAsync(cts.Token).GetAwaiter().GetResult();
                    }

                    return 0;
                }

                ServiceBase.Run(new AgentWindowsService(config, configPath));
                return 0;
            }
            catch (Exception ex)
            {
                Console.Error.WriteLine(ex);
                return 1;
            }
        }

        private static int StoreHorizonCredential(AgentConfig config)
        {
            if (!Environment.UserInteractive || Console.IsInputRedirected)
            {
                throw new InvalidOperationException("Horizon credential provisioning requires an interactive console.");
            }

            Console.Write("Horizon API username: ");
            var username = (Console.ReadLine() ?? string.Empty).Trim();
            Console.Write("Horizon API domain: ");
            var domain = (Console.ReadLine() ?? string.Empty).Trim();
            Console.Write("Horizon API password: ");
            var inputValue = ReadPassword();
            Console.WriteLine();

            var path = HorizonApiCredentialStore.Write(
                config.Collectors.Horizon.Api.CredentialFile,
                HorizonApiCredential.Create(username, domain, inputValue));
            Console.WriteLine("Machine-protected Horizon API credential written to " + path);
            return 0;
        }

        private static string ReadPassword()
        {
            var characters = new List<char>();
            while (true)
            {
                var key = Console.ReadKey(intercept: true);
                if (key.Key == ConsoleKey.Enter)
                {
                    break;
                }
                if (key.Key == ConsoleKey.Backspace)
                {
                    if (characters.Count > 0) characters.RemoveAt(characters.Count - 1);
                    continue;
                }
                if (!char.IsControl(key.KeyChar)) characters.Add(key.KeyChar);
            }
            return new string(characters.ToArray());
        }

        public static string DefaultConfigPath()
        {
            return Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                "LibreNMS",
                "LibreNMS Windows Agent",
                "agent.json");
        }

        public static IReadOnlyList<IAgentCollector> CreateCollectors()
        {
            return new IAgentCollector[]
            {
                new AgentMetadataCollector(),
                new WindowsOsCollector(),
                new UptimeCollector(),
                new CpuCollector(),
                new MemoryCollector(),
                new DiskCollector(),
                new ServicesCollector(),
                new RoleCollector(),
                new ActiveDirectoryCollector(),
                new LoggedOnUsersCollector(),
                new PendingRebootCollector(),
                new WindowsUpdateCollector(),
                new EventLogCollector(),
                new ProcessCollector(),
                new TcpPortCollector(),
                new PerformanceDepthCollector(),
                new SqlServerCollector(),
                new IisCollector(),
                new HorizonCollector(),
                new HorizonApiCollector(),
                new FactoryTalkCollector(),
                new TlsCertificateCollector(),
                new BackupStorageCollector()
            };
        }
    }

    internal sealed class CommandLineOptions
    {
        public string ConfigPath { get; private set; }
        public bool ConsoleMode { get; private set; }
        public bool Once { get; private set; }
        public bool ValidateConfig { get; private set; }
        public string SupportBundlePath { get; private set; }
        public bool StoreHorizonCredential { get; private set; }

        public static CommandLineOptions Parse(string[] args)
        {
            var options = new CommandLineOptions();
            var queue = new Queue<string>(args ?? Array.Empty<string>());

            while (queue.Count > 0)
            {
                var arg = queue.Dequeue();
                switch (arg.ToLowerInvariant())
                {
                    case "--config":
                    case "-c":
                        options.ConfigPath = RequireValue(arg, queue);
                        break;
                    case "--console":
                        options.ConsoleMode = true;
                        break;
                    case "--once":
                        options.Once = true;
                        break;
                    case "--validate-config":
                        options.ValidateConfig = true;
                        break;
                    case "--support-bundle":
                        options.SupportBundlePath = RequireValue(arg, queue);
                        break;
                    case "--store-horizon-credential":
                        options.StoreHorizonCredential = true;
                        break;
                    case "--help":
                    case "-h":
                    case "/?":
                        PrintUsage();
                        Environment.Exit(0);
                        break;
                    default:
                        throw new ArgumentException($"Unknown argument '{arg}'.");
                }
            }

            return options;
        }

        private static string RequireValue(string arg, Queue<string> queue)
        {
            if (queue.Count == 0)
            {
                throw new ArgumentException($"Argument '{arg}' requires a value.");
            }

            return queue.Dequeue();
        }

        private static void PrintUsage()
        {
            Console.WriteLine("LibreNMS.WindowsAgent.Service.exe [options]");
            Console.WriteLine("  --config <path>           Use a specific agent.json path.");
            Console.WriteLine("  --console                 Run listener in the foreground.");
            Console.WriteLine("  --once                    Print one Checkmk-compatible payload and exit.");
            Console.WriteLine("  --validate-config         Validate config and exit.");
            Console.WriteLine("  --support-bundle <path>   Write a diagnostic zip and exit.");
            Console.WriteLine("  --store-horizon-credential  Store one machine-protected Horizon API credential.");
        }
    }
}
