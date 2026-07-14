using System;
using System.IO;
using System.IO.Compression;
using System.Text;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service
{
    internal static class SupportBundle
    {
        public static void Create(string bundlePath, string configPath, AgentConfig config, AgentHost host)
        {
            var fullPath = Path.GetFullPath(bundlePath);
            var directory = Path.GetDirectoryName(fullPath);
            if (!string.IsNullOrWhiteSpace(directory))
            {
                Directory.CreateDirectory(directory);
            }

            if (File.Exists(fullPath))
            {
                File.Delete(fullPath);
            }

            using (var archive = ZipFile.Open(fullPath, ZipArchiveMode.Create))
            {
                AddText(archive, "summary.txt", BuildSummary(configPath, config));

                if (File.Exists(configPath))
                {
                    AddText(archive, "agent.json", File.ReadAllText(configPath));
                }

                AddText(archive, "sample-output.txt", host.CollectOnceAsync(default).GetAwaiter().GetResult());
            }
        }

        private static string BuildSummary(string configPath, AgentConfig config)
        {
            var builder = new StringBuilder();
            builder.AppendLine($"created_utc={DateTimeOffset.UtcNow:O}");
            builder.AppendLine($"machine={Environment.MachineName}");
            builder.AppendLine($"os_version={Environment.OSVersion}");
            builder.AppendLine($"is_64bit_os={Environment.Is64BitOperatingSystem}");
            builder.AppendLine($"is_64bit_process={Environment.Is64BitProcess}");
            builder.AppendLine($"config_path={configPath}");
            builder.AppendLine($"listener={config.Listener.Address}:{config.Listener.Port}");
            return builder.ToString();
        }

        private static void AddText(ZipArchive archive, string name, string content)
        {
            var entry = archive.CreateEntry(name, CompressionLevel.Optimal);
            using (var stream = entry.Open())
            using (var writer = new StreamWriter(stream, new UTF8Encoding(false)))
            {
                writer.Write(content ?? string.Empty);
            }
        }
    }
}
