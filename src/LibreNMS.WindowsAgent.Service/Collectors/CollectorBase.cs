using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal abstract class CollectorBase : IAgentCollector
    {
        public abstract string Name { get; }

        public abstract Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken);

        protected static Task<IReadOnlyList<AgentSection>> Complete(params AgentSection[] sections)
        {
            return Task.FromResult((IReadOnlyList<AgentSection>)sections);
        }

        protected static string Kv(string key, object value)
        {
            if (value == null)
            {
                return key + "=" + Quote(string.Empty);
            }

            var text = value.ToString();
            if (NeedsQuote(text))
            {
                return key + "=" + Quote(text);
            }

            return key + "=" + text;
        }

        protected static string RoleEvidenceFields(string evidence, string healthScope, string nextAction)
        {
            return string.Join(" ",
                Kv("evidence", evidence ?? string.Empty),
                Kv("health_scope", string.IsNullOrWhiteSpace(healthScope) ? "inventory" : healthScope),
                Kv("next_action", nextAction ?? string.Empty));
        }

        protected static string Quote(string value)
        {
            return "\"" + (value ?? string.Empty).Replace("\\", "\\\\").Replace("\"", "\\\"") + "\"";
        }

        private static bool NeedsQuote(string value)
        {
            if (string.IsNullOrEmpty(value))
            {
                return true;
            }

            foreach (var ch in value)
            {
                if (char.IsWhiteSpace(ch) || ch == '"' || ch == '\\')
                {
                    return true;
                }
            }

            return false;
        }
    }
}
