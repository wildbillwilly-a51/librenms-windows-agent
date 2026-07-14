using System;

namespace LibreNMS.WindowsAgent.Core
{
    public interface ICollectorTimeoutOverride
    {
        TimeSpan GetTimeout(AgentContext context, TimeSpan defaultTimeout);
    }
}
