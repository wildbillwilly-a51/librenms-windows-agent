using System;
using System.Diagnostics;
using System.Text;
using System.Threading;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal static class CommandRunner
    {
        public static CommandResult Run(string fileName, string arguments, TimeSpan timeout, CancellationToken cancellationToken)
        {
            try
            {
                using (var process = new Process())
                {
                    process.StartInfo.FileName = fileName;
                    process.StartInfo.Arguments = arguments ?? string.Empty;
                    process.StartInfo.UseShellExecute = false;
                    process.StartInfo.RedirectStandardOutput = true;
                    process.StartInfo.RedirectStandardError = true;
                    process.StartInfo.CreateNoWindow = true;

                    var output = new StringBuilder();
                    var error = new StringBuilder();
                    process.OutputDataReceived += (sender, args) => { if (args.Data != null) output.AppendLine(args.Data); };
                    process.ErrorDataReceived += (sender, args) => { if (args.Data != null) error.AppendLine(args.Data); };

                    var deadline = DateTimeOffset.UtcNow.Add(timeout);
                    process.Start();
                    process.BeginOutputReadLine();
                    process.BeginErrorReadLine();

                    while (!process.WaitForExit(250))
                    {
                        cancellationToken.ThrowIfCancellationRequested();
                        if (DateTimeOffset.UtcNow > deadline)
                        {
                            TryKill(process);
                            return CommandResult.Unsupported("timeout");
                        }
                    }

                    process.WaitForExit();

                    return new CommandResult
                    {
                        State = process.ExitCode == 0 ? "ok" : "unavailable",
                        ExitCode = process.ExitCode,
                        Output = output.ToString(),
                        Error = error.ToString(),
                    };
                }
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch (Exception ex)
            {
                return CommandResult.Unsupported(ex.GetType().Name);
            }
        }

        private static void TryKill(Process process)
        {
            try
            {
                if (!process.HasExited)
                {
                    process.Kill();
                }
            }
            catch
            {
            }
        }
    }

    internal sealed class CommandResult
    {
        public string State { get; set; } = string.Empty;
        public int ExitCode { get; set; }
        public string Output { get; set; } = string.Empty;
        public string Error { get; set; } = string.Empty;

        public static CommandResult Unsupported(string reason)
        {
            return new CommandResult { State = "unsupported", ExitCode = -1, Error = reason ?? string.Empty };
        }
    }
}
