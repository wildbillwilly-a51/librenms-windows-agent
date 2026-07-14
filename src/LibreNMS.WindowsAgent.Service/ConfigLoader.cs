using System;
using System.Collections.Generic;
using System.IO;
using System.Web.Script.Serialization;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service
{
    internal static class ConfigLoader
    {
        public static AgentConfig Load(string path)
        {
            AgentConfig config;

            if (File.Exists(path))
            {
                var json = File.ReadAllText(path);
                config = new JavaScriptSerializer().Deserialize<AgentConfig>(json);
            }
            else
            {
                config = new AgentConfig();
            }

            Normalize(config);
            return config;
        }

        private static void Normalize(AgentConfig config)
        {
            if (config.Listener == null)
            {
                config.Listener = new ListenerConfig();
            }

            if (config.Collectors == null)
            {
                config.Collectors = new CollectorConfig();
            }

            if (config.Logging == null)
            {
                config.Logging = new LoggingConfig();
            }

            config.Listener.Address = string.IsNullOrWhiteSpace(config.Listener.Address) ? "0.0.0.0" : config.Listener.Address.Trim();
            if (config.Listener.Port <= 0 || config.Listener.Port > 65535)
            {
                throw new InvalidOperationException("listener.port must be between 1 and 65535.");
            }
            if (config.Listener.CacheRefreshSeconds <= 0)
            {
                config.Listener.CacheRefreshSeconds = 240;
            }
            if (config.Listener.InitialCacheWaitSeconds < 0)
            {
                config.Listener.InitialCacheWaitSeconds = 0;
            }
            if (config.Listener.InitialCacheWaitSeconds > 8)
            {
                config.Listener.InitialCacheWaitSeconds = 8;
            }

            config.Listener.AllowedClients = config.Listener.AllowedClients ?? new List<string>();
            config.Collectors.Enabled = config.Collectors.Enabled ?? new List<string>();
            config.Collectors.WatchedServices = config.Collectors.WatchedServices ?? new List<string>();
            config.Collectors.Services = config.Collectors.Services ?? new ServiceClassificationConfig();
            config.Collectors.Services.Mode = string.IsNullOrWhiteSpace(config.Collectors.Services.Mode) ? "classified" : config.Collectors.Services.Mode.Trim();
            config.Collectors.Services.Groups = config.Collectors.Services.Groups ?? new Dictionary<string, ServiceGroupConfig>(StringComparer.OrdinalIgnoreCase);
            config.Collectors.Services.Exclude = config.Collectors.Services.Exclude ?? new List<string>();
            config.Collectors.Roles = config.Collectors.Roles ?? new RoleDetectionConfig();
            config.Collectors.Roles.Mode = string.IsNullOrWhiteSpace(config.Collectors.Roles.Mode) ? "auto" : config.Collectors.Roles.Mode.Trim();
            config.Collectors.ActiveDirectory = config.Collectors.ActiveDirectory ?? new ActiveDirectoryConfig();
            config.Collectors.ActiveDirectory.Mode = string.IsNullOrWhiteSpace(config.Collectors.ActiveDirectory.Mode) ? "auto" : config.Collectors.ActiveDirectory.Mode.Trim();
            if (config.Collectors.ActiveDirectory.CommandTimeoutSeconds <= 0)
            {
                config.Collectors.ActiveDirectory.CommandTimeoutSeconds = 20;
            }
            if (config.Collectors.ActiveDirectory.SecurityEventSinceHours <= 0)
            {
                config.Collectors.ActiveDirectory.SecurityEventSinceHours = 24;
            }
            if (config.Collectors.ActiveDirectory.SecurityEventMaxEvents <= 0)
            {
                config.Collectors.ActiveDirectory.SecurityEventMaxEvents = 2000;
            }
            config.Collectors.LoggedOnUsers = config.Collectors.LoggedOnUsers ?? new LoggedOnUsersConfig();
            config.Collectors.LoggedOnUsers.Mode = string.IsNullOrWhiteSpace(config.Collectors.LoggedOnUsers.Mode) ? "auto" : config.Collectors.LoggedOnUsers.Mode.Trim();
            if (config.Collectors.LoggedOnUsers.CommandTimeoutSeconds <= 0)
            {
                config.Collectors.LoggedOnUsers.CommandTimeoutSeconds = 10;
            }
            config.Collectors.EventLogs = config.Collectors.EventLogs ?? new List<EventLogWatchConfig>();
            config.Collectors.WatchedProcesses = config.Collectors.WatchedProcesses ?? new List<ProcessWatchConfig>();
            config.Collectors.WatchedTcpPorts = config.Collectors.WatchedTcpPorts ?? new List<TcpPortWatchConfig>();
            config.Collectors.PerformanceDepth = config.Collectors.PerformanceDepth ?? new PerformanceDepthConfig();
            config.Collectors.PerformanceDepth.Mode = string.IsNullOrWhiteSpace(config.Collectors.PerformanceDepth.Mode) ? "auto" : config.Collectors.PerformanceDepth.Mode.Trim();
            if (config.Collectors.PerformanceDepth.TopProcesses <= 0)
            {
                config.Collectors.PerformanceDepth.TopProcesses = 5;
            }
            if (config.Collectors.PerformanceDepth.DiskLatencyWarningMs <= 0)
            {
                config.Collectors.PerformanceDepth.DiskLatencyWarningMs = 50;
            }
            if (config.Collectors.PerformanceDepth.DiskQueueWarning <= 0)
            {
                config.Collectors.PerformanceDepth.DiskQueueWarning = 2;
            }
            if (config.Collectors.PerformanceDepth.MemoryAvailableWarningMb <= 0)
            {
                config.Collectors.PerformanceDepth.MemoryAvailableWarningMb = 1024;
            }
            if (config.Collectors.PerformanceDepth.MemoryCommittedWarningPercent <= 0)
            {
                config.Collectors.PerformanceDepth.MemoryCommittedWarningPercent = 90;
            }
            if (config.Collectors.PerformanceDepth.PagingWarningPagesPerSec <= 0)
            {
                config.Collectors.PerformanceDepth.PagingWarningPagesPerSec = 50;
            }
            if (config.Collectors.PerformanceDepth.CpuQueueWarning <= 0)
            {
                config.Collectors.PerformanceDepth.CpuQueueWarning = 4;
            }
            config.Collectors.SqlServer = config.Collectors.SqlServer ?? new SqlServerConfig();
            config.Collectors.SqlServer.Mode = string.IsNullOrWhiteSpace(config.Collectors.SqlServer.Mode) ? "auto" : config.Collectors.SqlServer.Mode.Trim();
            config.Collectors.Iis = config.Collectors.Iis ?? new IisConfig();
            config.Collectors.Iis.Mode = string.IsNullOrWhiteSpace(config.Collectors.Iis.Mode) ? "auto" : config.Collectors.Iis.Mode.Trim();
            if (config.Collectors.Iis.CommandTimeoutSeconds <= 0)
            {
                config.Collectors.Iis.CommandTimeoutSeconds = 10;
            }
            config.Collectors.Horizon = config.Collectors.Horizon ?? new HorizonConfig();
            config.Collectors.Horizon.Mode = string.IsNullOrWhiteSpace(config.Collectors.Horizon.Mode) ? "auto" : config.Collectors.Horizon.Mode.Trim();
            config.Collectors.Horizon.Ports = config.Collectors.Horizon.Ports ?? new List<int>();
            if (config.Collectors.Horizon.Ports.Count == 0)
            {
                config.Collectors.Horizon.Ports.Add(443);
                config.Collectors.Horizon.Ports.Add(8443);
                config.Collectors.Horizon.Ports.Add(4172);
                config.Collectors.Horizon.Ports.Add(32111);
            }
            if (config.Collectors.Horizon.CertificateWarningDays <= 0)
            {
                config.Collectors.Horizon.CertificateWarningDays = 30;
            }
            if (config.Collectors.Horizon.CertificateCriticalDays <= 0)
            {
                config.Collectors.Horizon.CertificateCriticalDays = 7;
            }
            config.Collectors.FactoryTalk = config.Collectors.FactoryTalk ?? new FactoryTalkConfig();
            config.Collectors.FactoryTalk.Mode = string.IsNullOrWhiteSpace(config.Collectors.FactoryTalk.Mode) ? "auto" : config.Collectors.FactoryTalk.Mode.Trim();
            config.Collectors.FactoryTalk.Ports = config.Collectors.FactoryTalk.Ports ?? new List<int>();
            if (config.Collectors.FactoryTalk.Ports.Count == 0)
            {
                foreach (var port in new[] { 27000, 27001, 27002, 27003, 27004, 27005, 27006, 27007, 27008, 27009, 22350, 4244, 4245, 9111, 44818 })
                {
                    config.Collectors.FactoryTalk.Ports.Add(port);
                }
            }
            config.Collectors.TlsCertificates = config.Collectors.TlsCertificates ?? new TlsCertificateConfig();
            config.Collectors.TlsCertificates.Mode = string.IsNullOrWhiteSpace(config.Collectors.TlsCertificates.Mode) ? "auto" : config.Collectors.TlsCertificates.Mode.Trim();
            config.Collectors.TlsCertificates.Stores = config.Collectors.TlsCertificates.Stores ?? new List<string>();
            if (config.Collectors.TlsCertificates.Stores.Count == 0)
            {
                config.Collectors.TlsCertificates.Stores.Add("My");
                config.Collectors.TlsCertificates.Stores.Add("WebHosting");
            }
            if (config.Collectors.TlsCertificates.WarningDays <= 0)
            {
                config.Collectors.TlsCertificates.WarningDays = 30;
            }
            if (config.Collectors.TlsCertificates.CriticalDays <= 0)
            {
                config.Collectors.TlsCertificates.CriticalDays = 7;
            }
            if (config.Collectors.TlsCertificates.MinimumRsaKeySize <= 0)
            {
                config.Collectors.TlsCertificates.MinimumRsaKeySize = 2048;
            }
            config.Collectors.BackupStorage = config.Collectors.BackupStorage ?? new BackupStorageConfig();
            config.Collectors.BackupStorage.Mode = string.IsNullOrWhiteSpace(config.Collectors.BackupStorage.Mode) ? "auto" : config.Collectors.BackupStorage.Mode.Trim();
            config.Collectors.BackupStorage.ExpectedBackupMode = NormalizeBackupExpectedMode(config.Collectors.BackupStorage.ExpectedBackupMode);
            if (config.Collectors.BackupStorage.CommandTimeoutSeconds <= 0)
            {
                config.Collectors.BackupStorage.CommandTimeoutSeconds = 15;
            }
            if (config.Collectors.BackupStorage.DattoBackupWarningHours <= 0)
            {
                config.Collectors.BackupStorage.DattoBackupWarningHours = 24;
            }
            if (config.Collectors.BackupStorage.DattoBackupCriticalHours <= 0)
            {
                config.Collectors.BackupStorage.DattoBackupCriticalHours = 48;
            }
            if (config.Collectors.BackupStorage.DattoBackupEvidenceSinceHours <= 0)
            {
                config.Collectors.BackupStorage.DattoBackupEvidenceSinceHours = 48;
            }
            if (config.Collectors.BackupStorage.DattoBackupMaxLogBytes <= 0)
            {
                config.Collectors.BackupStorage.DattoBackupMaxLogBytes = 262144;
            }
            config.Collectors.BackupStorage.DattoBackupLogPaths = config.Collectors.BackupStorage.DattoBackupLogPaths ?? new List<string>();
            if (config.Collectors.BackupStorage.DattoBackupLogPaths.Count == 0)
            {
                config.Collectors.BackupStorage.DattoBackupLogPaths.Add(@"%ProgramFiles%\Datto\Datto Windows Agent");
                config.Collectors.BackupStorage.DattoBackupLogPaths.Add(@"%ProgramData%\Datto\Datto Windows Agent");
            }
            for (var i = 0; i < config.Collectors.BackupStorage.DattoBackupLogPaths.Count; i++)
            {
                config.Collectors.BackupStorage.DattoBackupLogPaths[i] = ExpandPath(config.Collectors.BackupStorage.DattoBackupLogPaths[i]);
            }

            foreach (var pair in config.Collectors.Services.Groups)
            {
                if (pair.Value == null)
                {
                    continue;
                }

                pair.Value.Include = pair.Value.Include ?? new List<string>();
                pair.Value.Patterns = pair.Value.Patterns ?? new List<string>();
            }

            if (config.Collectors.TimeoutSeconds <= 0)
            {
                config.Collectors.TimeoutSeconds = 10;
            }

            foreach (var eventLog in config.Collectors.EventLogs)
            {
                if (eventLog.SinceHours <= 0)
                {
                    eventLog.SinceHours = 24;
                }

                if (eventLog.MaxEvents <= 0)
                {
                    eventLog.MaxEvents = 5000;
                }

                if (eventLog.HighValueMaxSignatures <= 0)
                {
                    eventLog.HighValueMaxSignatures = 25;
                }

                if (eventLog.HighValueMaxSamplesPerSignature <= 0)
                {
                    eventLog.HighValueMaxSamplesPerSignature = 5;
                }

                if (eventLog.HighValueMaxMessageChars <= 0)
                {
                    eventLog.HighValueMaxMessageChars = 400;
                }

                eventLog.HighValueLevels = eventLog.HighValueLevels ?? new List<string>();
                if (eventLog.HighValueLevels.Count == 0)
                {
                    eventLog.HighValueLevels.Add("Critical");
                    eventLog.HighValueLevels.Add("Error");
                }

                eventLog.HighValueProviders = eventLog.HighValueProviders ?? new List<string>();
                eventLog.HighValueEventIds = eventLog.HighValueEventIds ?? new List<int>();
            }

            foreach (var tcpPort in config.Collectors.WatchedTcpPorts)
            {
                if (tcpPort.Port <= 0 || tcpPort.Port > 65535)
                {
                    throw new InvalidOperationException("collectors.watchedTcpPorts.port must be between 1 and 65535.");
                }
            }

            foreach (var port in config.Collectors.Horizon.Ports)
            {
                if (port <= 0 || port > 65535)
                {
                    throw new InvalidOperationException("collectors.horizon.ports values must be between 1 and 65535.");
                }
            }

            config.Logging.Level = string.IsNullOrWhiteSpace(config.Logging.Level) ? "info" : config.Logging.Level.Trim();
            config.Logging.Path = ExpandPath(config.Logging.Path);
        }

        public static string ExpandPath(string path)
        {
            if (string.IsNullOrWhiteSpace(path))
            {
                return Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                    "LibreNMS",
                    "LibreNMS Windows Agent",
                    "agent.log");
            }

            return Environment.ExpandEnvironmentVariables(path);
        }

        private static string NormalizeBackupExpectedMode(string mode)
        {
            var value = (mode ?? string.Empty).Trim();
            if (string.Equals(value, "local_agent", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(value, "agentless_vcenter", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(value, "none", StringComparison.OrdinalIgnoreCase))
            {
                return value.ToLowerInvariant();
            }

            return "auto";
        }
    }
}
