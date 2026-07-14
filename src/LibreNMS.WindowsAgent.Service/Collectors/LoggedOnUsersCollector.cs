using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class LoggedOnUsersCollector : CollectorBase, ICollectorTimeoutOverride
    {
        public override string Name => "logged_on_users";

        public TimeSpan GetTimeout(AgentContext context, TimeSpan defaultTimeout)
        {
            var commandTimeout = Math.Max(1, context.Config.Collectors.LoggedOnUsers?.CommandTimeoutSeconds ?? 10);
            return TimeSpan.FromSeconds(Math.Max(defaultTimeout.TotalSeconds, commandTimeout + 5));
        }

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var config = context.Config.Collectors.LoggedOnUsers ?? new LoggedOnUsersConfig();
            if (IsDisabled(config.Mode))
            {
                return Complete(new AgentSection("windows_agent_logged_on_users", new[] { string.Join(" ", Kv("state", "disabled"), Kv("source", "config")) }));
            }

            var timeout = TimeSpan.FromSeconds(Math.Max(1, config.CommandTimeoutSeconds));
            var result = CommandRunner.Run("quser.exe", string.Empty, timeout, cancellationToken);
            var source = "quser";
            if (result.State != "ok")
            {
                result = CommandRunner.Run("query.exe", "user", timeout, cancellationToken);
                source = "query_user";
            }

            if (result.State != "ok")
            {
                if (IsNoUsers(result))
                {
                    return Complete(new AgentSection("windows_agent_logged_on_users", new[]
                    {
                        string.Join(" ", Kv("state", "none"), Kv("source", source))
                    }));
                }

                return Complete(new AgentSection("windows_agent_logged_on_users", new[]
                {
                    string.Join(" ",
                        Kv("state", result.State),
                        Kv("source", source),
                        Kv("reason", FirstNonEmpty(result.Error, result.Output)))
                }));
            }

            var sessions = LoggedOnUserParser.ParseQuser(result.Output);
            if (sessions.Count == 0)
            {
                return Complete(new AgentSection("windows_agent_logged_on_users", new[]
                {
                    string.Join(" ", Kv("state", "none"), Kv("source", source))
                }));
            }

            var lines = sessions.Select(session => string.Join(" ",
                Kv("state", string.IsNullOrWhiteSpace(session.State) ? "unknown" : session.State),
                Kv("domain", session.Domain),
                Kv("user", session.User),
                Kv("session_name", session.SessionName),
                Kv("session_id", session.SessionId),
                Kv("idle_time", session.IdleTime),
                Kv("logon_time", session.LogonTime),
                Kv("current", session.Current ? 1 : 0),
                Kv("source", source))).ToList();

            return Complete(new AgentSection("windows_agent_logged_on_users", lines));
        }

        private static string FirstNonEmpty(params string[] values)
        {
            foreach (var value in values ?? Array.Empty<string>())
            {
                if (!string.IsNullOrWhiteSpace(value))
                {
                    return value.Trim();
                }
            }

            return string.Empty;
        }

        private static bool IsNoUsers(CommandResult result)
        {
            var text = (FirstNonEmpty(result?.Output, result?.Error) ?? string.Empty);
            return text.IndexOf("No User exists", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        private static bool IsDisabled(string mode)
        {
            return string.Equals(mode, "disabled", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(mode, "off", StringComparison.OrdinalIgnoreCase);
        }
    }
}
