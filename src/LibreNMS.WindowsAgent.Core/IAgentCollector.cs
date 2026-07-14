using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;

namespace LibreNMS.WindowsAgent.Core
{
    public interface IAgentCollector
    {
        string Name { get; }
        Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken);
    }
}
