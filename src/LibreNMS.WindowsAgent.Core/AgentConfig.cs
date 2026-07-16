using System;
using System.Collections.Generic;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class AgentConfig
    {
        public ListenerConfig Listener { get; set; } = new ListenerConfig();
        public CollectorConfig Collectors { get; set; } = new CollectorConfig();
        public LoggingConfig Logging { get; set; } = new LoggingConfig();
    }

    public sealed class ListenerConfig
    {
        public string Address { get; set; } = "0.0.0.0";
        public int Port { get; set; } = 6556;
        public List<string> AllowedClients { get; set; } = new List<string>();
        public int CacheRefreshSeconds { get; set; } = 240;
        public int InitialCacheWaitSeconds { get; set; } = 2;
    }

    public sealed class CollectorConfig
    {
        public int TimeoutSeconds { get; set; } = 10;
        public List<string> Enabled { get; set; } = new List<string>
        {
            "agent",
            "os",
            "uptime",
            "cpu",
            "memory",
            "disks",
            "services",
            "roles",
            "active_directory",
            "logged_on_users",
            "pending_reboot",
            "windows_update",
            "event_logs",
            "processes",
            "tcp_ports",
            "performance_depth",
            "sql_server",
            "iis",
            "horizon",
            "factorytalk",
            "tls_certificates",
            "backup_storage"
        };

        public List<string> WatchedServices { get; set; } = new List<string>();
        public ServiceClassificationConfig Services { get; set; } = new ServiceClassificationConfig();
        public RoleDetectionConfig Roles { get; set; } = new RoleDetectionConfig();
        public ActiveDirectoryConfig ActiveDirectory { get; set; } = new ActiveDirectoryConfig();
        public LoggedOnUsersConfig LoggedOnUsers { get; set; } = new LoggedOnUsersConfig();
        public List<EventLogWatchConfig> EventLogs { get; set; } = new List<EventLogWatchConfig>
        {
            new EventLogWatchConfig { LogName = "System", SinceHours = 24 },
            new EventLogWatchConfig { LogName = "Application", SinceHours = 24 }
        };

        public List<ProcessWatchConfig> WatchedProcesses { get; set; } = new List<ProcessWatchConfig>();
        public List<TcpPortWatchConfig> WatchedTcpPorts { get; set; } = new List<TcpPortWatchConfig>();
        public PerformanceDepthConfig PerformanceDepth { get; set; } = new PerformanceDepthConfig();
        public SqlServerConfig SqlServer { get; set; } = new SqlServerConfig();
        public IisConfig Iis { get; set; } = new IisConfig();
        public HorizonConfig Horizon { get; set; } = new HorizonConfig();
        public FactoryTalkConfig FactoryTalk { get; set; } = new FactoryTalkConfig();
        public TlsCertificateConfig TlsCertificates { get; set; } = new TlsCertificateConfig();
        public BackupStorageConfig BackupStorage { get; set; } = new BackupStorageConfig();
    }

    public sealed class ServiceClassificationConfig
    {
        public string Mode { get; set; } = "classified";
        public Dictionary<string, ServiceGroupConfig> Groups { get; set; } = new Dictionary<string, ServiceGroupConfig>(StringComparer.OrdinalIgnoreCase);
        public List<string> Exclude { get; set; } = new List<string>();
        public bool IncludeUnknownVendorServices { get; set; } = true;
    }

    public sealed class ServiceGroupConfig
    {
        public List<string> Include { get; set; } = new List<string>();
        public List<string> Patterns { get; set; } = new List<string>();
    }

    public sealed class RoleDetectionConfig
    {
        public string Mode { get; set; } = "auto";
    }

    public sealed class ActiveDirectoryConfig
    {
        public string Mode { get; set; } = "auto";
        public bool IncludeReplicationTargets { get; set; } = true;
        public bool IncludeDfsr { get; set; } = true;
        public bool IncludeDcHealth { get; set; } = true;
        public bool IncludeDns { get; set; } = true;
        public bool IncludeTime { get; set; } = true;
        public bool IncludeSysvolNetlogon { get; set; } = true;
        public bool IncludeSecurityEvents { get; set; } = true;
        public int SecurityEventSinceHours { get; set; } = 24;
        public int SecurityEventMaxEvents { get; set; } = 2000;
        public int CommandTimeoutSeconds { get; set; } = 20;
    }

    public sealed class LoggedOnUsersConfig
    {
        public string Mode { get; set; } = "auto";
        public int CommandTimeoutSeconds { get; set; } = 10;
    }

    public sealed class EventLogWatchConfig
    {
        public string LogName { get; set; } = "System";
        public int SinceHours { get; set; } = 24;
        public int MaxEvents { get; set; } = 5000;
        public bool IncludeHighValueDetails { get; set; } = true;
        public int HighValueMaxSignatures { get; set; } = 25;
        public int HighValueMaxSamplesPerSignature { get; set; } = 5;
        public int HighValueMaxMessageChars { get; set; } = 400;
        public List<string> HighValueLevels { get; set; } = new List<string> { "Critical", "Error" };
        public List<string> HighValueProviders { get; set; } = new List<string>();
        public List<int> HighValueEventIds { get; set; } = new List<int>();
    }

    public sealed class ProcessWatchConfig
    {
        public string Name { get; set; } = string.Empty;
    }

    public sealed class TcpPortWatchConfig
    {
        public string Name { get; set; } = string.Empty;
        public string Address { get; set; } = string.Empty;
        public int Port { get; set; }
    }

    public sealed class PerformanceDepthConfig
    {
        public string Mode { get; set; } = "auto";
        public bool IncludeDisks { get; set; } = true;
        public bool IncludeNetwork { get; set; } = true;
        public bool IncludeTopProcesses { get; set; } = true;
        public int TopProcesses { get; set; } = 5;
        public int DiskLatencyWarningMs { get; set; } = 50;
        public int DiskQueueWarning { get; set; } = 2;
        public int MemoryAvailableWarningMb { get; set; } = 1024;
        public int MemoryCommittedWarningPercent { get; set; } = 90;
        public int PagingWarningPagesPerSec { get; set; } = 50;
        public int CpuQueueWarning { get; set; } = 4;
    }

    public sealed class SqlServerConfig
    {
        public string Mode { get; set; } = "auto";
    }

    public sealed class IisConfig
    {
        public string Mode { get; set; } = "auto";
        public bool IncludeSites { get; set; } = true;
        public bool IncludeAppPools { get; set; } = true;
        public bool IncludeBindings { get; set; } = true;
        public bool IncludeCertificates { get; set; } = true;
        public int CommandTimeoutSeconds { get; set; } = 10;
    }

    public sealed class HorizonConfig
    {
        public string Mode { get; set; } = "auto";
        public bool IncludeServices { get; set; } = true;
        public bool IncludeProcesses { get; set; } = true;
        public bool IncludePorts { get; set; } = true;
        public bool IncludeCertificates { get; set; } = true;
        public List<int> Ports { get; set; } = new List<int> { 443, 8443, 4172, 32111 };
        public int CertificateWarningDays { get; set; } = 30;
        public int CertificateCriticalDays { get; set; } = 7;
    }

    public sealed class FactoryTalkConfig
    {
        public string Mode { get; set; } = "auto";
        public bool IncludeProducts { get; set; } = true;
        public bool IncludeServices { get; set; } = true;
        public bool IncludeProcesses { get; set; } = true;
        public bool IncludeRuntimeMetrics { get; set; } = true;
        public bool IncludePorts { get; set; } = true;
        public string NativeCountersMode { get; set; } = "disabled";
        public int NativeCounterIntervalSeconds { get; set; } = 900;
        public int NativeCounterTimeoutSeconds { get; set; } = 30;
        public string NativeCounterExecutablePath { get; set; } = string.Empty;
        public List<int> Ports { get; set; } = new List<int> { 27000, 27001, 27002, 27003, 27004, 27005, 27006, 27007, 27008, 27009, 22350, 4244, 4245, 9111, 44818 };
    }

    public sealed class TlsCertificateConfig
    {
        public string Mode { get; set; } = "auto";
        public List<string> Stores { get; set; } = new List<string> { "My", "WebHosting" };
        public bool IncludeExpired { get; set; } = true;
        public bool ValidateChain { get; set; } = true;
        public bool CheckHttpSysBindings { get; set; } = true;
        public int MinimumRsaKeySize { get; set; } = 2048;
        public int WarningDays { get; set; } = 30;
        public int CriticalDays { get; set; } = 7;
    }

    public sealed class BackupStorageConfig
    {
        public string Mode { get; set; } = "auto";
        public bool IncludeVssWriters { get; set; } = true;
        public bool IncludeBackupServices { get; set; } = true;
        public bool IncludeDattoBackup { get; set; } = true;
        public string ExpectedBackupMode { get; set; } = "auto";
        public int DattoBackupWarningHours { get; set; } = 24;
        public int DattoBackupCriticalHours { get; set; } = 48;
        public int DattoBackupEvidenceSinceHours { get; set; } = 48;
        public int DattoBackupMaxLogBytes { get; set; } = 262144;
        public List<string> DattoBackupLogPaths { get; set; } = new List<string>
        {
            @"%ProgramFiles%\Datto\Datto Windows Agent",
            @"%ProgramData%\Datto\Datto Windows Agent"
        };
        public int CommandTimeoutSeconds { get; set; } = 15;
    }

    public sealed class LoggingConfig
    {
        public string Level { get; set; } = "info";
        public string Path { get; set; } = @"%ProgramData%\LibreNMS\Windows Agent\agent.log";
    }

    public sealed class AgentContext
    {
        public AgentContext(AgentConfig config, string configPath, DateTimeOffset nowUtc, string hostName)
        {
            Config = config ?? throw new ArgumentNullException(nameof(config));
            ConfigPath = configPath ?? string.Empty;
            NowUtc = nowUtc;
            HostName = hostName ?? string.Empty;
        }

        public AgentConfig Config { get; }
        public string ConfigPath { get; }
        public DateTimeOffset NowUtc { get; }
        public string HostName { get; }
    }
}
