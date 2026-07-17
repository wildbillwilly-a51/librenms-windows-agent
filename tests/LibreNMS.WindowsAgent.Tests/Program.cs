using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Tests
{
    internal static class Program
    {
        private static int Main()
        {
            var tests = new (string Name, Action Test)[]
            {
                ("renderer emits checkmk sections", RendererEmitsCheckmkSections),
                ("renderer stamps performance payload bytes", RendererStampsPerformancePayloadBytes),
                ("renderer stamps public performance payload bytes", RendererStampsPublicPerformancePayloadBytes),
                ("local check quoting", LocalCheckQuoting),
                ("address matcher exact and cidr", AddressMatcherExactAndCidr),
                ("collector runner emits performance timing", CollectorRunnerEmitsPerformanceTiming),
                ("collector timeout returns local check", CollectorTimeoutReturnsLocalCheck),
                ("collector runner executes collectors concurrently", CollectorRunnerExecutesCollectorsConcurrently),
                ("service classifier legacy watched services", ServiceClassifierLegacyWatchedServices),
                ("service classifier explicit include beats pattern", ServiceClassifierExplicitIncludeBeatsPattern),
                ("service classifier explicit exclude", ServiceClassifierExplicitExclude),
                ("service classifier sql pattern", ServiceClassifierSqlPattern),
                ("service command line redaction", ServiceCommandLineRedaction),
                ("role detector domain controller", RoleDetectorDomainController),
                ("role detector netlogon alone is not domain controller", RoleDetectorNetlogonAloneIsNotDomainController),
                ("role detector netlogon adws is not domain controller", RoleDetectorNetlogonAdwsIsNotDomainController),
                ("role detector dfsr", RoleDetectorDfsr),
                ("role detector sql ignores msdtc alone", RoleDetectorSqlIgnoresMsdtcAlone),
                ("role detector iis", RoleDetectorIis),
                ("role detector iis feature", RoleDetectorIisFeature),
                ("role detector ad ds feature alone is neutral", RoleDetectorAdDsFeatureAloneIsNeutral),
                ("role detector factorytalk", RoleDetectorFactoryTalk),
                ("role detector backup storage", RoleDetectorBackupStorage),
                ("default config includes depth collectors", DefaultConfigIncludesDepthCollectors),
                ("new collector config defaults", NewCollectorConfigDefaults),
                ("horizon health not detected", HorizonHealthNotDetected),
                ("horizon health ok", HorizonHealthOk),
                ("horizon health service critical", HorizonHealthServiceCritical),
                ("horizon health certificate warning", HorizonHealthCertificateWarning),
                ("horizon health client only", HorizonHealthClientOnly),
                ("factorytalk health not detected", FactoryTalkHealthNotDetected),
                ("factorytalk health ok", FactoryTalkHealthOk),
                ("factorytalk health core service warning", FactoryTalkHealthCoreServiceWarning),
                ("factorytalk counter snapshot parses allowlist", FactoryTalkCounterSnapshotParsesAllowlist),
                ("factorytalk counter snapshot ignores sensitive and unknown values", FactoryTalkCounterSnapshotIgnoresSensitiveAndUnknownValues),
                ("factorytalk counter snapshot rejects dtd", FactoryTalkCounterSnapshotRejectsDtd),
                ("factorytalk counter snapshot rejects oversized input", FactoryTalkCounterSnapshotRejectsOversizedInput),
                ("ad dc health not applicable", ActiveDirectoryDcHealthNotApplicable),
                ("ad dc health ok", ActiveDirectoryDcHealthOk),
                ("ad dc health critical", ActiveDirectoryDcHealthCritical),
                ("ad dc health time warning", ActiveDirectoryDcHealthTimeWarning),
                ("windows performance health ok", WindowsPerformanceHealthOk),
                ("windows performance health pressure", WindowsPerformanceHealthPressure),
                ("datto absent in auto mode", DattoAbsentInAutoMode),
                ("datto expected backup modes", DattoExpectedBackupModes),
                ("datto running with unknown evidence", DattoRunningWithUnknownEvidence),
                ("datto backup service stopped", DattoBackupServiceStopped),
                ("datto provider stopped is acceptable", DattoProviderStoppedIsAcceptable),
                ("datto provider wrong start mode", DattoProviderWrongStartMode),
                ("datto last success staleness", DattoLastSuccessStaleness),
                ("datto recent failure evidence", DattoRecentFailureEvidence),
                ("datto vss writer failure", DattoVssWriterFailure),
                ("tls certificate health scope", TlsCertificateHealthScope),
                ("logged on user parser active and disconnected", LoggedOnUserParserActiveAndDisconnected)
            };

            var failed = 0;
            foreach (var test in tests)
            {
                try
                {
                    test.Test();
                    Console.WriteLine($"PASS {test.Name}");
                }
                catch (Exception ex)
                {
                    failed++;
                    Console.Error.WriteLine($"FAIL {test.Name}: {ex.Message}");
                }
            }

            return failed == 0 ? 0 : 1;
        }

        private static void RendererEmitsCheckmkSections()
        {
            var renderer = new CheckmkRenderer();
            var output = renderer.Render(new[]
            {
                new AgentSection("windows_agent", new[] { "version=0.1.0", "host=test" }),
                new AgentSection("local", new[] { "0 \"Windows Agent Test\" - OK" })
            });

            AssertContains(output, "<<<windows_agent>>>");
            AssertContains(output, "version=0.1.0");
            AssertContains(output, "<<<local>>>");
            AssertContains(output, "0 \"Windows Agent Test\" - OK");
        }

        private static void RendererStampsPerformancePayloadBytes()
        {
            var renderer = new CheckmkRenderer();
            var output = renderer.RenderWithPayloadByteCount(new[]
            {
                new AgentSection("windows_agent_performance", new[]
                {
                    "type=summary collect_duration_ms=1 collectors_run=1 collectors_failed=0 collectors_timed_out=0 section_count=1 line_count=1 payload_bytes=0 process_working_set_bytes=1 process_private_bytes=1"
                })
            });

            AssertContains(output, "payload_bytes=");
            AssertFalse(output.Contains("payload_bytes=0"), "payload byte count should be stamped after rendering");
        }

        private static void RendererStampsPublicPerformancePayloadBytes()
        {
            var renderer = new CheckmkRenderer();
            var output = renderer.RenderWithPayloadByteCount(new[]
            {
                new AgentSection("windows_agent_performance", new[]
                {
                    "type=summary collect_duration_ms=1 collectors_run=1 collectors_failed=0 collectors_timed_out=0 section_count=1 line_count=1 payload_bytes=0 process_working_set_bytes=1 process_private_bytes=1"
                })
            });

            AssertContains(output, "payload_bytes=");
            AssertFalse(output.Contains("payload_bytes=0"), "public payload byte count should be stamped after rendering");
        }

        private static void LocalCheckQuoting()
        {
            var line = LocalCheck.Format(LocalCheckStatus.Warning, "Windows Agent Pending Reboot", "pending=1", "Restart needed");
            AssertEqual("1 \"Windows Agent Pending Reboot\" pending=1 Restart needed", line);
        }

        private static void AddressMatcherExactAndCidr()
        {
            var matcher = new AddressMatcher(new[] { "127.0.0.1", "192.0.2.0/24" });
            AssertTrue(matcher.IsAllowed(IPAddress.Parse("127.0.0.1")), "loopback should match");
            AssertTrue(matcher.IsAllowed(IPAddress.Parse("192.0.2.39")), "cidr should match");
            AssertFalse(matcher.IsAllowed(IPAddress.Parse("198.51.100.20")), "different subnet should not match");
        }

        private static void CollectorRunnerEmitsPerformanceTiming()
        {
            var config = new AgentConfig();
            config.Collectors.Enabled = new List<string> { "fast" };
            var context = new AgentContext(config, "agent.json", DateTimeOffset.UtcNow, "test-host");
            var runner = new CollectorRunner(new[] { new FastCollector() }, NullAgentLogger.Instance);
            var sections = runner.CollectAsync(context, CancellationToken.None).GetAwaiter().GetResult();
            var output = new CheckmkRenderer().RenderWithPayloadByteCount(sections);

            AssertContains(output, "<<<windows_agent_performance>>>");
            AssertContains(output, "type=summary");
            AssertContains(output, "collectors_run=1");
            AssertContains(output, "collectors_failed=0");
            AssertContains(output, "collectors_timed_out=0");
            AssertContains(output, "payload_bytes=");
            AssertContains(output, "process_cpu_ms=");
            AssertContains(output, "process_cpu_percent=");
            AssertContains(output, "process_io_bytes=");
            AssertContains(output, "type=collector collector=fast");
            AssertContains(output, "state=ok");
        }

        private static void CollectorTimeoutReturnsLocalCheck()
        {
            var config = new AgentConfig();
            config.Collectors.TimeoutSeconds = 1;
            config.Collectors.Enabled = new List<string> { "slow" };
            var context = new AgentContext(config, "agent.json", DateTimeOffset.UtcNow, "test-host");
            var runner = new CollectorRunner(new[] { new SlowCollector() }, NullAgentLogger.Instance);
            var sections = runner.CollectAsync(context, CancellationToken.None).GetAwaiter().GetResult();
            var output = new CheckmkRenderer().Render(sections);

            AssertContains(output, "<<<windows_agent_errors>>>");
            AssertContains(output, "collector=slow");
            AssertContains(output, "<<<local>>>");
            AssertContains(output, "\"Windows Agent Collector slow\"");
            AssertContains(output, "collectors_timed_out=1");
            AssertContains(output, "type=collector collector=slow");
            AssertContains(output, "state=timeout");
        }

        private static void CollectorRunnerExecutesCollectorsConcurrently()
        {
            var config = new AgentConfig();
            config.Collectors.TimeoutSeconds = 1;
            config.Collectors.Enabled = new List<string> { "slow_a", "slow_b" };
            var context = new AgentContext(config, "agent.json", DateTimeOffset.UtcNow, "test-host");
            var runner = new CollectorRunner(
                new IAgentCollector[] { new NamedSlowCollector("slow_a"), new NamedSlowCollector("slow_b") },
                NullAgentLogger.Instance);
            var started = Stopwatch.StartNew();

            var sections = runner.CollectAsync(context, CancellationToken.None).GetAwaiter().GetResult();

            started.Stop();
            var output = new CheckmkRenderer().Render(sections);
            AssertTrue(started.Elapsed < TimeSpan.FromMilliseconds(1800), "two one-second collector timeouts should complete concurrently");
            AssertContains(output, "collectors_run=2");
            AssertContains(output, "collectors_timed_out=2");
            AssertContains(output, "type=collector collector=slow_a");
            AssertContains(output, "type=collector collector=slow_b");
        }

        private static void ServiceClassifierLegacyWatchedServices()
        {
            var result = ServiceClassifier.Classify(
                new[] { Service("EventLog", "Running", @"C:\Windows\System32\svchost.exe") },
                new ServiceClassificationConfig(),
                new[] { "EventLog" });

            AssertEqual(1, result.Included.Count);
            AssertEqual(ServiceClassifier.CoreWindows, result.Included[0].Group);
            AssertEqual("legacy_watchedServices", result.Included[0].Source);
        }

        private static void ServiceClassifierExplicitIncludeBeatsPattern()
        {
            var config = new ServiceClassificationConfig();
            config.Groups["web_app"] = new ServiceGroupConfig { Include = new List<string> { "MSSQLSERVER" } };
            var result = ServiceClassifier.Classify(
                new[] { Service("MSSQLSERVER", "Stopped", @"C:\Program Files\Microsoft SQL Server\MSSQL\Binn\sqlservr.exe") },
                config,
                Array.Empty<string>());

            AssertEqual(1, result.Included.Count);
            AssertEqual("web_app", result.Included[0].Group);
            AssertEqual("explicit", result.Included[0].Source);
            AssertEqual("Stopped", result.Included[0].State);
        }

        private static void ServiceClassifierExplicitExclude()
        {
            var config = new ServiceClassificationConfig { Exclude = new List<string> { "Spooler" } };
            var result = ServiceClassifier.Classify(
                new[] { Service("Spooler", "Running", @"C:\Windows\System32\spoolsv.exe") },
                config,
                new[] { "Spooler" });

            AssertEqual(0, result.Included.Count);
            AssertEqual(1, result.Excluded.Count);
            AssertEqual(ServiceClassifier.ExcludedLowValue, result.Excluded[0].Group);
        }

        private static void ServiceClassifierSqlPattern()
        {
            var result = ServiceClassifier.Classify(
                new[] { Service("MSSQL$APP", "Disabled", @"C:\Program Files\Microsoft SQL Server\MSSQL\Binn\sqlservr.exe") },
                new ServiceClassificationConfig(),
                Array.Empty<string>());

            AssertEqual(1, result.Included.Count);
            AssertEqual(ServiceClassifier.Database, result.Included[0].Group);
            AssertEqual("pattern", result.Included[0].Source);
            AssertEqual("Disabled", result.Included[0].StartMode);
        }

        private static ServiceInventoryRecord Service(string name, string state, string path)
        {
            return new ServiceInventoryRecord
            {
                Name = name,
                DisplayName = name,
                State = state,
                StartMode = state == "Disabled" ? "Disabled" : "Auto",
                StartName = "LocalSystem",
                PathName = path,
            };
        }

        private static void ServiceCommandLineRedaction()
        {
            var quoted = ServiceCommandLine.RedactPath("\"C:\\Program Files\\ScreenConnect Client\\ScreenConnect.ClientService.exe\" h=https://example.invalid&token=secret");
            var unquoted = ServiceCommandLine.RedactPath("C:\\Tools\\Agent\\agent.exe --token secret --password hunter2");
            var query = ServiceCommandLine.RedactSensitiveValues("https://example.invalid/connect?token=secret&client_secret=hidden");

            AssertEqual("C:\\Program Files\\ScreenConnect Client\\ScreenConnect.ClientService.exe", quoted);
            AssertEqual("C:\\Tools\\Agent\\agent.exe", unquoted);
            AssertContains(query, "?<redacted>");
            AssertFalse(query.Contains("secret"), "query secrets should not remain in redacted values");
            AssertFalse(query.Contains("hidden"), "client secrets should not remain in redacted values");
        }

        private static void RoleDetectorDomainController()
        {
            var roles = RoleDetector.Detect(new[]
            {
                Service("NTDS", "Stopped", @"C:\Windows\System32\lsass.exe"),
                Service("Netlogon", "Stopped", @"C:\Windows\System32\lsass.exe"),
                Service("Kdc", "Stopped", @"C:\Windows\System32\lsass.exe"),
                Service("ADWS", "Stopped", @"C:\Windows\ADWS\Microsoft.ActiveDirectory.WebServices.exe"),
            });

            var role = FindRole(roles, "domain_controller");
            AssertTrue(role.Detected, "domain controller should be detected from AD services");
            AssertEqual("high", role.Confidence);
        }

        private static void RoleDetectorDfsr()
        {
            var role = FindRole(RoleDetector.Detect(new[] { Service("DFSR", "Stopped", @"C:\Windows\System32\dfsr.exe") }), "dfsr");
            AssertTrue(role.Detected, "DFSR should be detected from DFSR service identity");
        }

        private static void RoleDetectorNetlogonAloneIsNotDomainController()
        {
            var role = FindRole(RoleDetector.Detect(new[] { Service("Netlogon", "Running", @"C:\Windows\System32\lsass.exe") }), "domain_controller");
            AssertFalse(role.Detected, "Netlogon alone should not imply domain controller role");
        }

        private static void RoleDetectorNetlogonAdwsIsNotDomainController()
        {
            var role = FindRole(RoleDetector.Detect(new[]
            {
                Service("Netlogon", "Running", @"C:\Windows\System32\lsass.exe"),
                Service("ADWS", "Running", @"C:\Windows\ADWS\Microsoft.ActiveDirectory.WebServices.exe"),
            }), "domain_controller");

            AssertFalse(role.Detected, "Netlogon plus ADWS should not imply domain controller role without NTDS or Kdc");
        }

        private static void RoleDetectorSqlIgnoresMsdtcAlone()
        {
            var role = FindRole(RoleDetector.Detect(new[] { Service("MSDTC", "Running", @"C:\Windows\System32\msdtc.exe") }), "sql_server");
            AssertFalse(role.Detected, "MSDTC alone should not imply SQL Server role");
        }

        private static void RoleDetectorIis()
        {
            var role = FindRole(RoleDetector.Detect(new[] { Service("W3SVC", "Stopped", @"C:\Windows\System32\svchost.exe") }), "iis_web");
            AssertTrue(role.Detected, "IIS should be detected from W3SVC service identity");
        }

        private static void RoleDetectorIisFeature()
        {
            var role = FindRole(RoleDetector.Detect(Array.Empty<ServiceInventoryRecord>(), new[] { "Web-Server" }), "iis_web");
            AssertTrue(role.Detected, "IIS should be detected from installed Web-Server feature");
            AssertEqual("high", role.Confidence);
            AssertContains(role.Source, "feature:Web-Server");
        }

        private static void RoleDetectorAdDsFeatureAloneIsNeutral()
        {
            var role = FindRole(RoleDetector.Detect(Array.Empty<ServiceInventoryRecord>(), new[] { "AD-Domain-Services" }), "domain_controller");
            AssertFalse(role.Detected, "AD DS feature alone should not imply local DC health role without DC service evidence");
            AssertContains(role.Source, "feature:AD-Domain-Services");
        }

        private static void RoleDetectorBackupStorage()
        {
            var role = FindRole(RoleDetector.Detect(new[] { Service("VeeamBackupSvc", "Running", @"C:\Program Files\Veeam\svc.exe") }), "backup_storage");
            AssertTrue(role.Detected, "backup/storage should be detected from known backup service identity");
        }

        private static void RoleDetectorFactoryTalk()
        {
            var role = FindRole(RoleDetector.Detect(new[] { Service("FTLinxService", "Running", @"C:\Program Files\Rockwell Software\FactoryTalk Linx\FTLinx.exe") }), "factorytalk");
            AssertTrue(role.Detected, "FactoryTalk should be detected from FactoryTalk Linx service identity");
        }

        private static void DefaultConfigIncludesDepthCollectors()
        {
            var config = new AgentConfig();

            AssertTrue(config.Collectors.Enabled.Contains("sql_server"), "sql_server should be enabled by default in auto mode");
            AssertTrue(config.Collectors.Enabled.Contains("iis"), "iis should be enabled by default in auto mode");
            AssertTrue(config.Collectors.Enabled.Contains("horizon"), "horizon should be enabled by default in auto mode");
            AssertTrue(config.Collectors.Enabled.Contains("factorytalk"), "factorytalk should be enabled by default in auto mode");
            AssertTrue(config.Collectors.Enabled.Contains("tls_certificates"), "tls_certificates should be enabled by default in auto mode");
            AssertTrue(config.Collectors.Enabled.Contains("backup_storage"), "backup_storage should be enabled by default in auto mode");
            AssertTrue(config.Collectors.Enabled.Contains("performance_depth"), "performance_depth should be enabled by default in auto mode");
        }

        private static void NewCollectorConfigDefaults()
        {
            var config = new AgentConfig();

            AssertEqual(240, config.Listener.CacheRefreshSeconds);
            AssertEqual(2, config.Listener.InitialCacheWaitSeconds);
            AssertEqual("auto", config.Collectors.SqlServer.Mode);
            AssertEqual("auto", config.Collectors.Iis.Mode);
            AssertTrue(config.Collectors.Iis.IncludeSites, "IIS sites should be included by default");
            AssertTrue(config.Collectors.Iis.IncludeAppPools, "IIS app pools should be included by default");
            AssertEqual("auto", config.Collectors.Horizon.Mode);
            AssertTrue(config.Collectors.Horizon.IncludeServices, "Horizon service visibility should be included by default");
            AssertTrue(config.Collectors.Horizon.IncludeProcesses, "Horizon process visibility should be included by default");
            AssertTrue(config.Collectors.Horizon.IncludePorts, "Horizon port visibility should be included by default");
            AssertTrue(config.Collectors.Horizon.IncludeCertificates, "Horizon certificate visibility should be included by default");
            AssertTrue(config.Collectors.Horizon.Ports.Contains(443), "Horizon HTTPS should be watched by default");
            AssertEqual(30, config.Collectors.Horizon.CertificateWarningDays);
            AssertEqual(7, config.Collectors.Horizon.CertificateCriticalDays);
            AssertEqual("auto", config.Collectors.FactoryTalk.Mode);
            AssertTrue(config.Collectors.FactoryTalk.IncludeProducts, "FactoryTalk product visibility should be included by default");
            AssertTrue(config.Collectors.FactoryTalk.IncludeServices, "FactoryTalk service visibility should be included by default");
            AssertTrue(config.Collectors.FactoryTalk.IncludeProcesses, "FactoryTalk process visibility should be included by default");
            AssertTrue(config.Collectors.FactoryTalk.IncludeRuntimeMetrics, "FactoryTalk runtime visibility should be included by default");
            AssertTrue(config.Collectors.FactoryTalk.IncludePorts, "FactoryTalk port visibility should be included by default");
            AssertEqual("local", config.Collectors.FactoryTalk.NativeCountersMode);
            AssertEqual(900, config.Collectors.FactoryTalk.NativeCounterIntervalSeconds);
            AssertEqual(30, config.Collectors.FactoryTalk.NativeCounterTimeoutSeconds);
            AssertTrue(config.Collectors.FactoryTalk.Ports.Contains(27000), "FactoryTalk Activation default port should be watched by default");
            AssertTrue(config.Collectors.FactoryTalk.Ports.Contains(4245), "FactoryTalk Linx default port should be watched by default");
            AssertTrue(config.Collectors.FactoryTalk.Ports.Contains(9111), "FactoryTalk Alarms and Events default port should be watched by default");
            AssertEqual("auto", config.Collectors.TlsCertificates.Mode);
            AssertTrue(config.Collectors.TlsCertificates.Stores.Contains("My"), "LocalMachine My store should be scanned by default");
            AssertTrue(config.Collectors.TlsCertificates.Stores.Contains("WebHosting"), "LocalMachine WebHosting store should be scanned by default");
            AssertTrue(config.Collectors.TlsCertificates.ValidateChain, "TLS chain validation should be enabled by default");
            AssertTrue(config.Collectors.TlsCertificates.CheckHttpSysBindings, "HTTP.SYS binding checks should be enabled by default");
            AssertEqual(2048, config.Collectors.TlsCertificates.MinimumRsaKeySize);
            AssertEqual(30, config.Collectors.TlsCertificates.WarningDays);
            AssertEqual(7, config.Collectors.TlsCertificates.CriticalDays);
            AssertEqual("auto", config.Collectors.BackupStorage.Mode);
            AssertTrue(config.Collectors.ActiveDirectory.IncludeDcHealth, "AD/DC health should be included by default");
            AssertTrue(config.Collectors.ActiveDirectory.IncludeDns, "AD/DC DNS checks should be included by default");
            AssertTrue(config.Collectors.ActiveDirectory.IncludeTime, "AD/DC time checks should be included by default");
            AssertTrue(config.Collectors.ActiveDirectory.IncludeSysvolNetlogon, "AD/DC SYSVOL/NETLOGON checks should be included by default");
            AssertTrue(config.Collectors.ActiveDirectory.IncludeSecurityEvents, "AD/DC bounded security event summaries should be included by default");
            AssertEqual(24, config.Collectors.ActiveDirectory.SecurityEventSinceHours);
            AssertEqual(2000, config.Collectors.ActiveDirectory.SecurityEventMaxEvents);
            AssertTrue(config.Collectors.BackupStorage.IncludeVssWriters, "VSS writers should be included by default");
            AssertTrue(config.Collectors.BackupStorage.IncludeBackupServices, "backup services should be included by default");
            AssertTrue(config.Collectors.BackupStorage.IncludeDattoBackup, "Datto backup health should be included by default");
            AssertEqual(24, config.Collectors.BackupStorage.DattoBackupWarningHours);
            AssertEqual(48, config.Collectors.BackupStorage.DattoBackupCriticalHours);
            AssertEqual(48, config.Collectors.BackupStorage.DattoBackupEvidenceSinceHours);
            AssertEqual(262144, config.Collectors.BackupStorage.DattoBackupMaxLogBytes);
            AssertTrue(config.Collectors.BackupStorage.DattoBackupLogPaths.Count >= 2, "Datto backup log paths should have defaults");
            AssertEqual("auto", config.Collectors.PerformanceDepth.Mode);
            AssertTrue(config.Collectors.PerformanceDepth.IncludeDisks, "performance disk depth should be included by default");
            AssertTrue(config.Collectors.PerformanceDepth.IncludeNetwork, "performance network depth should be included by default");
            AssertTrue(config.Collectors.PerformanceDepth.IncludeTopProcesses, "performance top process depth should be included by default");
            AssertEqual(5, config.Collectors.PerformanceDepth.TopProcesses);
            AssertEqual(50, config.Collectors.PerformanceDepth.DiskLatencyWarningMs);
            AssertEqual(2, config.Collectors.PerformanceDepth.DiskQueueWarning);
            AssertEqual(1024, config.Collectors.PerformanceDepth.MemoryAvailableWarningMb);
            AssertEqual(90, config.Collectors.PerformanceDepth.MemoryCommittedWarningPercent);
            AssertEqual(50, config.Collectors.PerformanceDepth.PagingWarningPagesPerSec);
            AssertEqual(4, config.Collectors.PerformanceDepth.CpuQueueWarning);
            AssertTrue(config.Collectors.EventLogs[0].IncludeHighValueDetails, "event log high-value details should be included by default");
            AssertEqual(25, config.Collectors.EventLogs[0].HighValueMaxSignatures);
            AssertEqual(5, config.Collectors.EventLogs[0].HighValueMaxSamplesPerSignature);
            AssertEqual(400, config.Collectors.EventLogs[0].HighValueMaxMessageChars);
            AssertTrue(config.Collectors.EventLogs[0].HighValueLevels.Contains("Critical"), "critical events should be sampled by default");
            AssertTrue(config.Collectors.EventLogs[0].HighValueLevels.Contains("Error"), "error events should be sampled by default");
        }

        private static void HorizonHealthNotDetected()
        {
            var result = HorizonHealth.Evaluate(new HorizonHealthInput { Detected = false });

            AssertEqual("not_detected", result.State);
            AssertEqual(0, result.HealthIssues);
        }

        private static void HorizonHealthOk()
        {
            var result = HorizonHealth.Evaluate(new HorizonHealthInput { Detected = true });

            AssertEqual("ok", result.State);
            AssertEqual(0, result.HealthIssues);
        }

        private static void HorizonHealthServiceCritical()
        {
            var result = HorizonHealth.Evaluate(new HorizonHealthInput
            {
                Detected = true,
                ServicesNotRunning = 1
            });

            AssertEqual("critical", result.State);
            AssertEqual(1, result.HealthIssues);
        }

        private static void HorizonHealthCertificateWarning()
        {
            var result = HorizonHealth.Evaluate(new HorizonHealthInput
            {
                Detected = true,
                CertificatesExpiringCritical = 1
            });

            AssertEqual("warning", result.State);
            AssertEqual(1, result.HealthIssues);
        }

        private static void HorizonHealthClientOnly()
        {
            var result = HorizonHealth.Evaluate(new HorizonHealthInput
            {
                Detected = false,
                NotDetectedState = "client_only"
            });

            AssertEqual("client_only", result.State);
            AssertEqual(0, result.HealthIssues);
        }

        private static void FactoryTalkHealthNotDetected()
        {
            var result = FactoryTalkHealth.Evaluate(new FactoryTalkHealthInput { Detected = false });

            AssertEqual("not_detected", result.State);
            AssertEqual(0, result.HealthIssues);
        }

        private static void FactoryTalkHealthOk()
        {
            var result = FactoryTalkHealth.Evaluate(new FactoryTalkHealthInput { Detected = true });

            AssertEqual("ok", result.State);
            AssertEqual(0, result.HealthIssues);
        }

        private static void FactoryTalkHealthCoreServiceWarning()
        {
            var result = FactoryTalkHealth.Evaluate(new FactoryTalkHealthInput
            {
                Detected = true,
                CoreServicesNotRunning = 1,
            });

            AssertEqual("warning", result.State);
            AssertEqual(1, result.HealthIssues);
        }

        private static void FactoryTalkCounterSnapshotParsesAllowlist()
        {
            const string xml = @"<CounterMonitorReport><Data><SystemDiagnostics><DiagItem dispname=""LOCALHOST""><DiagItem dispname=""FactoryTalk Linx""><DiagItem dispname=""Engine""><DiagItem dispname=""Transaction Manager""><Property dispname=""Size of transaction pool"" type=""3"" value=""40""/><Property dispname=""Number of transactions in use"" type=""3"" value=""5""/></DiagItem></DiagItem><DiagItem dispname=""Drivers""><DiagItem dispname=""Backplane""><DiagItem dispname=""Slot 3""><Property dispname=""Number of Packets Received"" type=""3"" value=""120""/><Property dispname=""Number of Packets Sent"" type=""3"" value=""90""/><Property dispname=""Number of Send Failures"" type=""3"" value=""2""/></DiagItem></DiagItem><DiagItem dispname=""AB_ETHIP-PlantNetwork""><Property dispname=""IP Address"" type=""8"" value=""192.0.2.10""/><DiagItem dispname=""Incoming TCP Connections""><Property dispname=""Number of connections active"" type=""3"" value=""3""/><Property dispname=""Number of connections accepted"" type=""3"" value=""100""/><Property dispname=""Number of connections closed"" type=""3"" value=""97""/></DiagItem><DiagItem dispname=""Outgoing TCP Connections""><Property dispname=""Number of connections active"" type=""3"" value=""4""/><Property dispname=""Number of connections attempted"" type=""3"" value=""80""/><Property dispname=""Number of connections closed"" type=""3"" value=""76""/></DiagItem></DiagItem></DiagItem></DiagItem><DiagItem dispname=""FactoryTalk Linx Instance02""><DiagItem dispname=""Engine""><DiagItem dispname=""Transaction Manager""><Property dispname=""Size of transaction pool"" value=""20""/><Property dispname=""Number of transactions in use"" value=""1""/></DiagItem></DiagItem></DiagItem><DiagItem dispname=""FactoryTalk Live Data""><DiagItem dispname=""RnaDaSvr [Example.exe(1234)]""><Property dispname=""total number of FactoryTalk data clients"" type=""3"" value=""7""/></DiagItem></DiagItem></DiagItem></SystemDiagnostics></Data></CounterMonitorReport>";
            var snapshot = ParseFactoryTalkSnapshot(xml);

            AssertEqual(2, snapshot.Connections.Count);
            AssertEqual("ethernet_ip", snapshot.Connections[0].Driver);
            AssertEqual(3, (int)snapshot.Connections[0].Active);
            AssertEqual(100, (int)snapshot.Connections[0].Accepted);
            AssertEqual(80, (int)snapshot.Connections[1].Attempted);
            AssertEqual(1, snapshot.BackplaneSlots.Count);
            AssertEqual(3, snapshot.BackplaneSlots[0].Slot);
            AssertEqual(120, (int)snapshot.BackplaneSlots[0].PacketsReceived);
            AssertEqual(2, snapshot.Transactions.Count);
            AssertEqual(2, snapshot.Transactions[1].Instance);
            AssertEqual(7, (int)snapshot.LiveDataClients);
            AssertEqual(1, snapshot.LiveDataSources);
        }

        private static void FactoryTalkCounterSnapshotIgnoresSensitiveAndUnknownValues()
        {
            const string xml = @"<CounterMonitorReport><Data><SystemDiagnostics><DiagItem dispname=""PRIVATE-HOST""><DiagItem dispname=""FactoryTalk Linx""><DiagItem dispname=""Drivers""><DiagItem dispname=""SECRET_DRIVER""><Property dispname=""IP Address"" value=""198.51.100.4""/><DiagItem dispname=""Incoming TCP Connections""><Property dispname=""Number of connections active"" value=""99""/></DiagItem></DiagItem></DiagItem><DiagItem dispname=""Shortcuts""><DiagItem dispname=""PRIVATE_CONTROLLER""><Property dispname=""arbitrary"" value=""123""/></DiagItem></DiagItem></DiagItem></DiagItem></SystemDiagnostics></Data></CounterMonitorReport>";
            var snapshot = ParseFactoryTalkSnapshot(xml);

            AssertEqual(0, snapshot.Connections.Count);
            AssertEqual(0, snapshot.BackplaneSlots.Count);
            AssertEqual(0, snapshot.Transactions.Count);
            AssertEqual(0, (int)snapshot.LiveDataClients);
        }

        private static void FactoryTalkCounterSnapshotRejectsDtd()
        {
            const string xml = "<!DOCTYPE x [<!ENTITY probe SYSTEM 'file:///not-read'>]><CounterMonitorReport><Data>&probe;</Data></CounterMonitorReport>";
            AssertThrows<Exception>(() => ParseFactoryTalkSnapshot(xml), "DTD-bearing snapshots must be rejected");
        }

        private static void FactoryTalkCounterSnapshotRejectsOversizedInput()
        {
            using (var stream = new MemoryStream(new byte[FactoryTalkCounterSnapshotParser.MaximumXmlBytes + 1]))
            {
                AssertThrows<InvalidDataException>(() => FactoryTalkCounterSnapshotParser.Parse(stream), "oversized snapshots must be rejected before parsing");
            }
        }

        private static FactoryTalkCounterSnapshot ParseFactoryTalkSnapshot(string xml)
        {
            using (var stream = new MemoryStream(Encoding.UTF8.GetBytes(xml)))
            {
                return FactoryTalkCounterSnapshotParser.Parse(stream);
            }
        }

        private static void ActiveDirectoryDcHealthNotApplicable()
        {
            var result = ActiveDirectoryDcHealth.Evaluate(new ActiveDirectoryDcHealthInput { IsDomainController = false });

            AssertEqual("not_applicable", result.State);
            AssertEqual(0, result.HealthIssues);
        }

        private static void ActiveDirectoryDcHealthOk()
        {
            var result = ActiveDirectoryDcHealth.Evaluate(new ActiveDirectoryDcHealthInput
            {
                IsDomainController = true,
                CoreServicesTotal = 5,
                CoreServicesNotRunning = 0,
                DnsServicePresent = true,
                DnsServiceRunning = true,
                TimeServicePresent = true,
                TimeServiceRunning = true,
                TimeState = "ok",
                SysvolSharePresent = true,
                NetlogonSharePresent = true,
            });

            AssertEqual("ok", result.State);
            AssertEqual(0, result.HealthIssues);
        }

        private static void ActiveDirectoryDcHealthCritical()
        {
            var result = ActiveDirectoryDcHealth.Evaluate(new ActiveDirectoryDcHealthInput
            {
                IsDomainController = true,
                CoreServicesTotal = 5,
                CoreServicesNotRunning = 1,
                DnsServicePresent = true,
                DnsServiceRunning = false,
                TimeServicePresent = true,
                TimeServiceRunning = true,
                TimeState = "ok",
                SysvolSharePresent = false,
                NetlogonSharePresent = true,
            });

            AssertEqual("critical", result.State);
            AssertEqual(1, result.DnsServiceIssue);
            AssertEqual(1, result.SharesMissing);
            AssertEqual(3, result.HealthIssues);
        }

        private static void ActiveDirectoryDcHealthTimeWarning()
        {
            var result = ActiveDirectoryDcHealth.Evaluate(new ActiveDirectoryDcHealthInput
            {
                IsDomainController = true,
                CoreServicesTotal = 5,
                CoreServicesNotRunning = 0,
                DnsServicePresent = true,
                DnsServiceRunning = true,
                TimeServicePresent = true,
                TimeServiceRunning = false,
                TimeState = "warning",
                SysvolSharePresent = true,
                NetlogonSharePresent = true,
            });

            AssertEqual("warning", result.State);
            AssertEqual(1, result.TimeIssues);
            AssertEqual(1, result.HealthIssues);
        }

        private static void WindowsPerformanceHealthOk()
        {
            var result = WindowsPerformanceHealth.Evaluate(new WindowsPerformanceHealthInput
            {
                CpuQueueLength = 0,
                MemoryAvailableMb = 4096,
                MemoryCommittedPercent = 50,
                PagesPerSec = 0,
                DiskReadLatencyMsMax = 5,
                DiskWriteLatencyMsMax = 7,
                DiskQueueLengthMax = 0,
                NetworkErrorsTotal = 0,
            });

            AssertEqual("ok", result.State);
            AssertEqual(0, result.PressureIssues);
        }

        private static void WindowsPerformanceHealthPressure()
        {
            var result = WindowsPerformanceHealth.Evaluate(new WindowsPerformanceHealthInput
            {
                CpuQueueLength = 5,
                MemoryAvailableMb = 512,
                MemoryCommittedPercent = 95,
                PagesPerSec = 70,
                DiskReadLatencyMsMax = 80,
                DiskWriteLatencyMsMax = 4,
                DiskQueueLengthMax = 3,
                NetworkErrorsTotal = 1,
            });

            AssertEqual("warning", result.State);
            AssertEqual(1, result.CpuPressure);
            AssertEqual(1, result.MemoryPressure);
            AssertEqual(1, result.PagingPressure);
            AssertEqual(1, result.DiskPressure);
            AssertEqual(1, result.NetworkIssue);
            AssertEqual(5, result.PressureIssues);
        }

        private static void DattoAbsentInAutoMode()
        {
            var result = DattoBackupHealth.Evaluate(new DattoBackupHealthInput { DattoDetected = false });

            AssertEqual("not_detected", result.State);
            AssertEqual(0, result.HealthIssues);
        }

        private static void DattoExpectedBackupModes()
        {
            var localAgentMissing = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                ExpectedMode = "local_agent",
                DattoDetected = false
            });
            var agentless = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                ExpectedMode = "agentless_vcenter",
                DattoDetected = false
            });
            var none = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                ExpectedMode = "none",
                DattoDetected = true,
                RecentCriticalFailures = 1
            });

            AssertEqual("missing", localAgentMissing.State);
            AssertEqual(1, localAgentMissing.HealthIssues);
            AssertEqual("agentless_vcenter", agentless.State);
            AssertEqual(0, agentless.HealthIssues);
            AssertEqual("not_expected", none.State);
            AssertEqual(0, none.HealthIssues);
        }

        private static void DattoRunningWithUnknownEvidence()
        {
            var result = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                DattoDetected = true,
                BackupServicePresent = true,
                BackupServiceRunning = true,
                ProcessCount = 1,
            });

            AssertEqual("ok", result.State);
            AssertEqual("unknown", result.EvidenceState);
            AssertEqual(-1, result.LastSuccessAgeHours);
        }

        private static void DattoBackupServiceStopped()
        {
            var result = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                DattoDetected = true,
                BackupServicePresent = true,
                BackupServiceRunning = false,
            });

            AssertEqual("critical", result.State);
            AssertEqual(1, result.HealthIssues);
        }

        private static void DattoProviderStoppedIsAcceptable()
        {
            var result = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                DattoDetected = true,
                BackupServicePresent = true,
                BackupServiceRunning = true,
                ProviderPresent = true,
                ProviderStartMode = "Auto",
            });

            AssertEqual("ok", result.State);
            AssertEqual(0, result.ProviderIssue);
        }

        private static void DattoProviderWrongStartMode()
        {
            var result = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                DattoDetected = true,
                BackupServicePresent = true,
                BackupServiceRunning = true,
                ProviderPresent = true,
                ProviderStartMode = "Manual",
            });

            AssertEqual("warning", result.State);
            AssertEqual(1, result.ProviderIssue);
        }

        private static void DattoLastSuccessStaleness()
        {
            var now = new DateTimeOffset(2026, 7, 3, 12, 0, 0, TimeSpan.Zero);
            var healthy = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                DattoDetected = true,
                BackupServicePresent = true,
                BackupServiceRunning = true,
                LastSuccessUtc = now.AddHours(-6),
                NowUtc = now,
            });
            var warning = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                DattoDetected = true,
                BackupServicePresent = true,
                BackupServiceRunning = true,
                LastSuccessUtc = now.AddHours(-30),
                NowUtc = now,
            });
            var critical = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                DattoDetected = true,
                BackupServicePresent = true,
                BackupServiceRunning = true,
                LastSuccessUtc = now.AddHours(-60),
                NowUtc = now,
            });

            AssertEqual("ok", healthy.State);
            AssertEqual(6, healthy.LastSuccessAgeHours);
            AssertEqual("warning", warning.State);
            AssertEqual(1, warning.StaleWarning);
            AssertEqual("critical", critical.State);
            AssertEqual(1, critical.StaleCritical);
        }

        private static void DattoRecentFailureEvidence()
        {
            var result = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                DattoDetected = true,
                BackupServicePresent = true,
                BackupServiceRunning = true,
                RecentErrors = 1,
                RecentCriticalFailures = 1,
            });

            AssertEqual("critical", result.State);
            AssertEqual(2, result.HealthIssues);
        }

        private static void DattoVssWriterFailure()
        {
            var result = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                DattoDetected = true,
                BackupServicePresent = true,
                BackupServiceRunning = true,
                VssWritersFailed = 1,
            });

            AssertEqual("critical", result.State);
            AssertEqual(1, result.HealthIssues);
        }

        private static void TlsCertificateHealthScope()
        {
            var unboundExpired = TlsCertificateHealth.Evaluate(new TlsCertificateHealthInput
            {
                Bound = false,
                Expired = true,
                InvalidChain = true
            });
            var boundExpired = TlsCertificateHealth.Evaluate(new TlsCertificateHealthInput
            {
                Bound = true,
                Expired = true,
                InvalidChain = true
            });

            AssertEqual("expired", unboundExpired.Health);
            AssertEqual("inventory", unboundExpired.HealthScope);
            AssertFalse(unboundExpired.Unhealthy, "unbound expired certificates should not be summary-scored");
            AssertEqual("expired", boundExpired.Health);
            AssertEqual("scored", boundExpired.HealthScope);
            AssertTrue(boundExpired.Unhealthy, "bound expired certificates should be summary-scored");
        }

        private static DetectedRole FindRole(IReadOnlyList<DetectedRole> roles, string name)
        {
            foreach (var role in roles)
            {
                if (string.Equals(role.Role, name, StringComparison.OrdinalIgnoreCase))
                {
                    return role;
                }
            }

            throw new InvalidOperationException($"Role was not returned: {name}");
        }

        private static void LoggedOnUserParserActiveAndDisconnected()
        {
            var output =
                " USERNAME              SESSIONNAME        ID  STATE   IDLE TIME  LOGON TIME\r\n" +
                QuserLine(">EXAMPLE\\operator", "console", "1", "Active", "none", "6/8/2026 4:10 AM") + "\r\n" +
                QuserLine("svc-admin", string.Empty, "2", "Disc", "1:23", "6/7/2026 9:22 PM") + "\r\n";

            var sessions = LoggedOnUserParser.ParseQuser(output);

            AssertEqual(2, sessions.Count);
            AssertEqual("EXAMPLE", sessions[0].Domain);
            AssertEqual("operator", sessions[0].User);
            AssertEqual("console", sessions[0].SessionName);
            AssertEqual("1", sessions[0].SessionId);
            AssertEqual("Active", sessions[0].State);
            AssertTrue(sessions[0].Current, "current marker should be parsed");
            AssertEqual("svc-admin", sessions[1].User);
            AssertEqual("Disc", sessions[1].State);
        }

        private static string QuserLine(string user, string sessionName, string id, string state, string idleTime, string logonTime)
        {
            return " " + (user ?? string.Empty).PadRight(22) +
                (sessionName ?? string.Empty).PadRight(19) +
                (id ?? string.Empty).PadRight(4) +
                (state ?? string.Empty).PadRight(8) +
                (idleTime ?? string.Empty).PadRight(11) +
                (logonTime ?? string.Empty);
        }

        private static void AssertContains(string haystack, string needle)
        {
            if (haystack == null || !haystack.Contains(needle))
            {
                throw new InvalidOperationException($"Expected to find '{needle}' in '{haystack}'.");
            }
        }

        private static void AssertEqual(string expected, string actual)
        {
            if (!string.Equals(expected, actual, StringComparison.Ordinal))
            {
                throw new InvalidOperationException($"Expected '{expected}', got '{actual}'.");
            }
        }

        private static void AssertEqual(int expected, int actual)
        {
            if (expected != actual)
            {
                throw new InvalidOperationException($"Expected '{expected}', got '{actual}'.");
            }
        }

        private static void AssertTrue(bool value, string message)
        {
            if (!value)
            {
                throw new InvalidOperationException(message);
            }
        }

        private static void AssertFalse(bool value, string message)
        {
            if (value)
            {
                throw new InvalidOperationException(message);
            }
        }

        private static void AssertThrows<TException>(Action action, string message) where TException : Exception
        {
            try
            {
                action();
            }
            catch (TException)
            {
                return;
            }

            throw new InvalidOperationException(message);
        }

        private sealed class SlowCollector : IAgentCollector
        {
            public string Name => "slow";

            public async Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
            {
                await Task.Delay(TimeSpan.FromSeconds(30), cancellationToken);
                return new[] { new AgentSection("slow", new[] { "done=1" }) };
            }
        }

        private sealed class NamedSlowCollector : IAgentCollector
        {
            private readonly string _name;

            public NamedSlowCollector(string name)
            {
                _name = name;
            }

            public string Name => _name;

            public async Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
            {
                await Task.Delay(TimeSpan.FromSeconds(30), cancellationToken);
                return new[] { new AgentSection(_name, new[] { "done=1" }) };
            }
        }

        private sealed class FastCollector : IAgentCollector
        {
            public string Name => "fast";

            public Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
            {
                return Task.FromResult((IReadOnlyList<AgentSection>)new[] { new AgentSection("fast", new[] { "done=1" }) });
            }
        }
    }
}
