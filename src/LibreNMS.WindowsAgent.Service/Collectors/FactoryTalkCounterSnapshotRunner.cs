using System;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Security.Cryptography.X509Certificates;
using System.Threading;
using System.Xml;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class FactoryTalkNativeCounterResult
    {
        public string Mode { get; set; } = "disabled";
        public string State { get; set; } = "disabled";
        public bool Available { get; set; }
        public bool SignatureValid { get; set; }
        public string Version { get; set; } = string.Empty;
        public DateTimeOffset? SnapshotUtc { get; set; }
        public long SnapshotDurationMs { get; set; }
        public long SnapshotAgeSeconds { get; set; } = -1;
        public string LastError { get; set; } = "none";
        public FactoryTalkCounterSnapshot Snapshot { get; set; }
    }

    internal static class FactoryTalkCounterSnapshotRunner
    {
        private const string MutexName = @"Global\LibreNMSWindowsAgentFactoryTalkCounterSnapshot";
        private static readonly object Gate = new object();
        private static DateTimeOffset? _lastAttemptUtc;
        private static FactoryTalkNativeCounterResult _lastResult;
        private static FactoryTalkNativeCounterResult _lastSuccess;

        public static FactoryTalkNativeCounterResult Collect(FactoryTalkConfig config, DateTimeOffset nowUtc, CancellationToken cancellationToken)
        {
            config = config ?? new FactoryTalkConfig();
            if (!string.Equals(config.NativeCountersMode, "local", StringComparison.OrdinalIgnoreCase))
            {
                return new FactoryTalkNativeCounterResult();
            }

            lock (Gate)
            {
                var interval = TimeSpan.FromSeconds(Math.Max(300, config.NativeCounterIntervalSeconds));
                if (_lastAttemptUtc.HasValue && nowUtc - _lastAttemptUtc.Value < interval && _lastResult != null)
                {
                    return WithAge(_lastResult, nowUtc);
                }

                _lastAttemptUtc = nowUtc;
                try
                {
                    _lastResult = RunSnapshot(config, nowUtc, cancellationToken);
                    if (_lastResult.Snapshot != null && string.Equals(_lastResult.State, "ok", StringComparison.OrdinalIgnoreCase))
                    {
                        _lastSuccess = _lastResult;
                    }
                    return WithAge(_lastResult, nowUtc);
                }
                catch (OperationCanceledException)
                {
                    throw;
                }
                catch
                {
                    _lastResult = Failure("failed", "unexpected_error", nowUtc, false, false, string.Empty);
                    return WithAge(_lastResult, nowUtc);
                }
            }
        }

        private static FactoryTalkNativeCounterResult RunSnapshot(FactoryTalkConfig config, DateTimeOffset nowUtc, CancellationToken cancellationToken)
        {
            var executable = FindExecutable(config.NativeCounterExecutablePath);
            if (string.IsNullOrEmpty(executable))
            {
                return Failure("unavailable", "executable_not_found", nowUtc, false, false, string.Empty);
            }

            var version = SafeFileVersion(executable);
            if (!AuthenticodeVerifier.IsTrustedRockwellFile(executable))
            {
                return Failure("unavailable", "signature_invalid", nowUtc, true, false, version);
            }

            var existingProcesses = Process.GetProcessesByName("FTCounterMonitor");
            try
            {
                if (existingProcesses.Any(process => !SafeHasExited(process)))
                {
                    return Failure("skipped", "counter_monitor_running", nowUtc, true, true, version);
                }
            }
            finally
            {
                foreach (var existingProcess in existingProcesses)
                {
                    existingProcess.Dispose();
                }
            }

            using (var mutex = new Mutex(false, MutexName))
            {
                var acquired = false;
                try
                {
                    try
                    {
                        acquired = mutex.WaitOne(0);
                    }
                    catch (AbandonedMutexException)
                    {
                        acquired = true;
                    }

                    if (!acquired)
                    {
                        return Failure("skipped", "snapshot_in_progress", nowUtc, true, true, version);
                    }

                    return LaunchAndParse(executable, version, config.NativeCounterTimeoutSeconds, nowUtc, cancellationToken);
                }
                catch (UnauthorizedAccessException)
                {
                    return Failure("unavailable", "mutex_access_denied", nowUtc, true, true, version);
                }
                finally
                {
                    if (acquired)
                    {
                        mutex.ReleaseMutex();
                    }
                }
            }
        }

        private static FactoryTalkNativeCounterResult LaunchAndParse(string executable, string version, int timeoutSeconds, DateTimeOffset nowUtc, CancellationToken cancellationToken)
        {
            var tempDirectory = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                "LibreNMS",
                "Windows Agent",
                "temp");
            Directory.CreateDirectory(tempDirectory);
            var snapshotPath = Path.Combine(tempDirectory, "factorytalk-counter-" + Guid.NewGuid().ToString("N") + ".xml");
            Process process = null;
            var started = Stopwatch.StartNew();
            try
            {
                var startInfo = new ProcessStartInfo
                {
                    FileName = executable,
                    Arguments = "/snapshot /wks:localhost \"" + snapshotPath.Replace("\"", string.Empty) + "\"",
                    UseShellExecute = false,
                    CreateNoWindow = true,
                    WorkingDirectory = Path.GetDirectoryName(executable),
                };
                process = Process.Start(startInfo);
                if (process == null)
                {
                    return Failure("failed", "process_start_failed", nowUtc, true, true, version);
                }

                var timeout = TimeSpan.FromSeconds(Math.Max(5, Math.Min(60, timeoutSeconds)));
                while (!process.WaitForExit(200))
                {
                    if (cancellationToken.IsCancellationRequested)
                    {
                        KillOwnedProcess(process);
                        cancellationToken.ThrowIfCancellationRequested();
                    }
                    if (started.Elapsed >= timeout)
                    {
                        KillOwnedProcess(process);
                        return Failure("failed", "timeout", nowUtc, true, true, version);
                    }
                }

                if (process.ExitCode != 0)
                {
                    return Failure("failed", "nonzero_exit", nowUtc, true, true, version);
                }
                if (!File.Exists(snapshotPath))
                {
                    return Failure("failed", "snapshot_missing", nowUtc, true, true, version);
                }
                var fileInfo = new FileInfo(snapshotPath);
                if (fileInfo.Length <= 0 || fileInfo.Length > FactoryTalkCounterSnapshotParser.MaximumXmlBytes)
                {
                    return Failure("failed", "snapshot_size_invalid", nowUtc, true, true, version);
                }

                FactoryTalkCounterSnapshot snapshot;
                using (var stream = new FileStream(snapshotPath, FileMode.Open, FileAccess.Read, FileShare.Read, 4096, FileOptions.SequentialScan))
                {
                    snapshot = FactoryTalkCounterSnapshotParser.Parse(stream);
                }

                started.Stop();
                return new FactoryTalkNativeCounterResult
                {
                    Mode = "local",
                    State = "ok",
                    Available = true,
                    SignatureValid = true,
                    Version = version,
                    SnapshotUtc = nowUtc,
                    SnapshotDurationMs = Math.Max(0, (long)Math.Round(started.Elapsed.TotalMilliseconds)),
                    SnapshotAgeSeconds = 0,
                    LastError = "none",
                    Snapshot = snapshot,
                };
            }
            catch (InvalidDataException)
            {
                return Failure("failed", "snapshot_invalid", nowUtc, true, true, version);
            }
            catch (XmlException)
            {
                return Failure("failed", "snapshot_xml_invalid", nowUtc, true, true, version);
            }
            catch (UnauthorizedAccessException)
            {
                return Failure("failed", "access_denied", nowUtc, true, true, version);
            }
            catch (System.ComponentModel.Win32Exception)
            {
                return Failure("failed", "process_error", nowUtc, true, true, version);
            }
            catch (IOException)
            {
                return Failure("failed", "io_error", nowUtc, true, true, version);
            }
            finally
            {
                if (process != null)
                {
                    process.Dispose();
                }
                try
                {
                    if (File.Exists(snapshotPath))
                    {
                        File.Delete(snapshotPath);
                    }
                }
                catch
                {
                    // Never expose snapshot contents or turn cleanup failure into collector failure.
                }
            }
        }

        private static FactoryTalkNativeCounterResult Failure(string state, string error, DateTimeOffset nowUtc, bool available, bool signatureValid, string version)
        {
            if (_lastSuccess != null && _lastSuccess.Snapshot != null)
            {
                return new FactoryTalkNativeCounterResult
                {
                    Mode = "local",
                    State = "stale",
                    Available = available,
                    SignatureValid = signatureValid,
                    Version = string.IsNullOrEmpty(version) ? _lastSuccess.Version : version,
                    SnapshotUtc = _lastSuccess.SnapshotUtc,
                    SnapshotDurationMs = _lastSuccess.SnapshotDurationMs,
                    SnapshotAgeSeconds = _lastSuccess.SnapshotUtc.HasValue ? Math.Max(0, (long)(nowUtc - _lastSuccess.SnapshotUtc.Value).TotalSeconds) : -1,
                    LastError = error,
                    Snapshot = _lastSuccess.Snapshot,
                };
            }

            return new FactoryTalkNativeCounterResult
            {
                Mode = "local",
                State = state,
                Available = available,
                SignatureValid = signatureValid,
                Version = version,
                LastError = error,
            };
        }

        private static FactoryTalkNativeCounterResult WithAge(FactoryTalkNativeCounterResult result, DateTimeOffset nowUtc)
        {
            return new FactoryTalkNativeCounterResult
            {
                Mode = result.Mode,
                State = result.State,
                Available = result.Available,
                SignatureValid = result.SignatureValid,
                Version = result.Version,
                SnapshotUtc = result.SnapshotUtc,
                SnapshotDurationMs = result.SnapshotDurationMs,
                SnapshotAgeSeconds = result.SnapshotUtc.HasValue ? Math.Max(0, (long)(nowUtc - result.SnapshotUtc.Value).TotalSeconds) : -1,
                LastError = result.LastError,
                Snapshot = result.Snapshot,
            };
        }

        private static string FindExecutable(string configuredPath)
        {
            var candidates = new[]
            {
                Environment.ExpandEnvironmentVariables(configuredPath ?? string.Empty),
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), "Common Files", "Rockwell", "FTCounterMonitor.exe"),
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "Common Files", "Rockwell", "FTCounterMonitor.exe"),
            };
            foreach (var candidate in candidates.Where(path => !string.IsNullOrWhiteSpace(path)))
            {
                try
                {
                    var fullPath = Path.GetFullPath(candidate);
                    if (string.Equals(Path.GetFileName(fullPath), "FTCounterMonitor.exe", StringComparison.OrdinalIgnoreCase) && File.Exists(fullPath))
                    {
                        return fullPath;
                    }
                }
                catch (Exception ex) when (ex is ArgumentException || ex is NotSupportedException || ex is PathTooLongException)
                {
                }
            }
            return string.Empty;
        }

        private static string SafeFileVersion(string path)
        {
            try
            {
                return FileVersionInfo.GetVersionInfo(path).FileVersion ?? string.Empty;
            }
            catch
            {
                return string.Empty;
            }
        }

        private static void KillOwnedProcess(Process process)
        {
            try
            {
                if (process != null && !process.HasExited)
                {
                    process.Kill();
                    process.WaitForExit(5000);
                }
            }
            catch
            {
            }
        }

        private static bool SafeHasExited(Process process)
        {
            try
            {
                return process == null || process.HasExited;
            }
            catch
            {
                return false;
            }
        }
    }

    internal static class AuthenticodeVerifier
    {
        private static readonly Guid ActionGenericVerifyV2 = new Guid("00AAC56B-CD44-11d0-8CC2-00C04FC295EE");

        public static bool IsTrustedRockwellFile(string path)
        {
            if (!VerifyTrust(path))
            {
                return false;
            }

            try
            {
                using (var certificate = new X509Certificate2(X509Certificate.CreateFromSignedFile(path)))
                {
                    return certificate.Subject.IndexOf("Rockwell Automation", StringComparison.OrdinalIgnoreCase) >= 0;
                }
            }
            catch
            {
                return false;
            }
        }

        private static bool VerifyTrust(string path)
        {
            return VerifyTrustStatus(path) == 0;
        }

        private static int VerifyTrustStatus(string path)
        {
            var fileInfo = new WinTrustFileInfo(path);
            var fileInfoPointer = Marshal.AllocHGlobal(Marshal.SizeOf(fileInfo));
            var actionPointer = Marshal.AllocHGlobal(Marshal.SizeOf(ActionGenericVerifyV2));
            IntPtr trustDataPointer = IntPtr.Zero;
            try
            {
                Marshal.StructureToPtr(fileInfo, fileInfoPointer, false);
                var trustData = new WinTrustData(fileInfoPointer);
                Marshal.StructureToPtr(ActionGenericVerifyV2, actionPointer, false);
                trustDataPointer = Marshal.AllocHGlobal(Marshal.SizeOf(trustData));
                Marshal.StructureToPtr(trustData, trustDataPointer, false);
                return WinVerifyTrust(IntPtr.Zero, actionPointer, trustDataPointer);
            }
            finally
            {
                if (trustDataPointer != IntPtr.Zero)
                {
                    Marshal.DestroyStructure(trustDataPointer, typeof(WinTrustData));
                    Marshal.FreeHGlobal(trustDataPointer);
                }
                Marshal.DestroyStructure(actionPointer, typeof(Guid));
                Marshal.FreeHGlobal(actionPointer);
                Marshal.DestroyStructure(fileInfoPointer, typeof(WinTrustFileInfo));
                Marshal.FreeHGlobal(fileInfoPointer);
            }
        }

        [DllImport("wintrust.dll", ExactSpelling = true, SetLastError = false, CharSet = CharSet.Unicode)]
        private static extern int WinVerifyTrust(IntPtr hwnd, IntPtr actionId, IntPtr trustData);

        [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
        private struct WinTrustFileInfo
        {
            public uint StructSize;
            [MarshalAs(UnmanagedType.LPWStr)] public string FilePath;
            public IntPtr FileHandle;
            public IntPtr KnownSubject;

            public WinTrustFileInfo(string filePath)
            {
                StructSize = (uint)Marshal.SizeOf(typeof(WinTrustFileInfo));
                FilePath = filePath;
                FileHandle = IntPtr.Zero;
                KnownSubject = IntPtr.Zero;
            }
        }

        [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
        private struct WinTrustData
        {
            public uint StructSize;
            public IntPtr PolicyCallbackData;
            public IntPtr SipClientData;
            public uint UiChoice;
            public uint RevocationChecks;
            public uint UnionChoice;
            public IntPtr FileInfo;
            public uint StateAction;
            public IntPtr StateData;
            public IntPtr UrlReference;
            public uint ProviderFlags;
            public uint UiContext;

            public WinTrustData(IntPtr fileInfo)
            {
                StructSize = (uint)Marshal.SizeOf(typeof(WinTrustData));
                PolicyCallbackData = IntPtr.Zero;
                SipClientData = IntPtr.Zero;
                UiChoice = 2;
                RevocationChecks = 0;
                UnionChoice = 1;
                FileInfo = fileInfo;
                StateAction = 0;
                StateData = IntPtr.Zero;
                UrlReference = IntPtr.Zero;
                ProviderFlags = 0x00000010 | 0x00001000;
                UiContext = 0;
            }
        }
    }
}
