using System;
using System.Net;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service
{
    internal sealed class AgentHost
    {
        private readonly AgentConfig _config;
        private readonly string _configPath;
        private readonly IAgentLogger _logger;
        private readonly CollectorRunner _runner;
        private readonly CheckmkRenderer _renderer;

        private AgentHost(AgentConfig config, string configPath, IAgentLogger logger)
        {
            _config = config;
            _configPath = configPath;
            _logger = logger ?? NullAgentLogger.Instance;
            _runner = new CollectorRunner(Program.CreateCollectors(), _logger);
            _renderer = new CheckmkRenderer();
        }

        public static AgentHost Create(AgentConfig config, string configPath, IAgentLogger logger)
        {
            return new AgentHost(config, configPath, logger);
        }

        public Task RunAsync(CancellationToken cancellationToken)
        {
            var server = new AgentServer(_config, CreateContext, _runner, _renderer, _logger);
            return server.RunAsync(cancellationToken);
        }

        public async Task<string> CollectOnceAsync(CancellationToken cancellationToken)
        {
            var sections = await _runner.CollectAsync(CreateContext(), cancellationToken).ConfigureAwait(false);
            return _renderer.RenderWithPayloadByteCount(sections);
        }

        private AgentContext CreateContext()
        {
            return new AgentContext(_config, _configPath, DateTimeOffset.UtcNow, Dns.GetHostName());
        }
    }
}
