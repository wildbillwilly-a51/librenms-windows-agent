using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class RoleCollector : CollectorBase
    {
        public override string Name => "roles";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var mode = context.Config.Collectors.Roles?.Mode ?? "auto";
            if (IsDisabled(mode))
            {
                return Complete(new AgentSection("windows_agent_roles", new[] { "role=roles detected=0 confidence=none source=disabled" }));
            }

            var roles = RoleDetector.Detect(ServiceInventoryReader.Read(cancellationToken), ServerFeatureReader.ReadInstalled(cancellationToken));
            var lines = roles
                .OrderBy(role => role.Role)
                .Select(role => string.Join(" ",
                    Kv("role", role.Role),
                    Kv("detected", role.Detected ? 1 : 0),
                    Kv("confidence", role.Confidence),
                    Kv("source", role.Source),
                    Kv("health_issues", 0),
                    RoleEvidenceFields(role.Source, "inventory", role.Detected ? "Review role-specific section for health evidence." : "No action; role evidence was not detected.")))
                .ToList();

            return Complete(new AgentSection("windows_agent_roles", lines));
        }

        private static bool IsDisabled(string mode)
        {
            return string.Equals(mode, "disabled", System.StringComparison.OrdinalIgnoreCase) ||
                string.Equals(mode, "off", System.StringComparison.OrdinalIgnoreCase);
        }
    }
}
