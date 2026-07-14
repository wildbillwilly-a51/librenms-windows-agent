using System;
using System.Collections.Generic;
using System.Linq;

namespace LibreNMS.WindowsAgent.Core
{
    public static class LoggedOnUserParser
    {
        public static IReadOnlyList<LoggedOnUserSession> ParseQuser(string output)
        {
            var lines = (output ?? string.Empty)
                .Split(new[] { "\r\n", "\n" }, StringSplitOptions.None)
                .Where(line => !string.IsNullOrWhiteSpace(line))
                .ToList();
            if (lines.Count < 2)
            {
                return Array.Empty<LoggedOnUserSession>();
            }

            var header = lines[0];
            var sessionIndex = header.IndexOf("SESSIONNAME", StringComparison.OrdinalIgnoreCase);
            var idIndex = header.IndexOf(" ID ", StringComparison.OrdinalIgnoreCase);
            var stateIndex = header.IndexOf("STATE", StringComparison.OrdinalIgnoreCase);
            var idleIndex = header.IndexOf("IDLE TIME", StringComparison.OrdinalIgnoreCase);
            var logonIndex = header.IndexOf("LOGON TIME", StringComparison.OrdinalIgnoreCase);
            if (sessionIndex < 0 || idIndex < 0 || stateIndex < 0 || idleIndex < 0 || logonIndex < 0)
            {
                return Array.Empty<LoggedOnUserSession>();
            }

            var sessions = new List<LoggedOnUserSession>();
            foreach (var line in lines.Skip(1))
            {
                var padded = line.PadRight(logonIndex);
                var rawUser = Slice(padded, 0, sessionIndex).Trim();
                var current = rawUser.StartsWith(">", StringComparison.Ordinal);
                rawUser = rawUser.TrimStart('>').Trim();
                if (string.IsNullOrWhiteSpace(rawUser))
                {
                    continue;
                }

                SplitUser(rawUser, out var domain, out var user);
                sessions.Add(new LoggedOnUserSession
                {
                    Domain = domain,
                    User = user,
                    SessionName = Slice(padded, sessionIndex, idIndex).Trim(),
                    SessionId = Slice(padded, idIndex, stateIndex).Trim(),
                    State = Slice(padded, stateIndex, idleIndex).Trim(),
                    IdleTime = Slice(padded, idleIndex, logonIndex).Trim(),
                    LogonTime = padded.Length > logonIndex ? padded.Substring(logonIndex).Trim() : string.Empty,
                    Current = current,
                });
            }

            return sessions;
        }

        private static string Slice(string value, int start, int end)
        {
            if (start < 0 || end <= start || start >= value.Length)
            {
                return string.Empty;
            }

            return value.Substring(start, Math.Min(end, value.Length) - start);
        }

        private static void SplitUser(string rawUser, out string domain, out string user)
        {
            var parts = (rawUser ?? string.Empty).Split(new[] { '\\' }, 2);
            if (parts.Length == 2)
            {
                domain = parts[0];
                user = parts[1];
                return;
            }

            domain = string.Empty;
            user = rawUser ?? string.Empty;
        }
    }

    public sealed class LoggedOnUserSession
    {
        public string Domain { get; set; } = string.Empty;
        public string User { get; set; } = string.Empty;
        public string SessionName { get; set; } = string.Empty;
        public string SessionId { get; set; } = string.Empty;
        public string State { get; set; } = string.Empty;
        public string IdleTime { get; set; } = string.Empty;
        public string LogonTime { get; set; } = string.Empty;
        public bool Current { get; set; }
    }
}
