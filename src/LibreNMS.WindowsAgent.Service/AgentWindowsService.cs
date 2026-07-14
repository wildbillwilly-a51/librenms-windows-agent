using System;
using System.ServiceProcess;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service
{
    internal sealed class AgentWindowsService : ServiceBase
    {
        private readonly AgentConfig _config;
        private readonly string _configPath;
        private CancellationTokenSource _cts;
        private Task _serverTask;
        private FileAgentLogger _logger;

        public AgentWindowsService(AgentConfig config, string configPath)
        {
            _config = config;
            _configPath = configPath;
            ServiceName = Program.ServiceName;
            CanStop = true;
            CanShutdown = true;
        }

        protected override void OnStart(string[] args)
        {
            _logger = new FileAgentLogger(_config.Logging);
            _cts = new CancellationTokenSource();
            var host = AgentHost.Create(_config, _configPath, _logger);
            _serverTask = Task.Run(() => host.RunAsync(_cts.Token));
            _logger.Info("Service started.");
        }

        protected override void OnStop()
        {
            StopServer();
        }

        protected override void OnShutdown()
        {
            StopServer();
        }

        private void StopServer()
        {
            try
            {
                _logger?.Info("Service stopping.");
                _cts?.Cancel();
                _serverTask?.Wait(TimeSpan.FromSeconds(10));
            }
            catch (Exception ex)
            {
                _logger?.Error("Service stop failed.", ex);
            }
            finally
            {
                _cts?.Dispose();
                _logger?.Info("Service stopped.");
            }
        }
    }
}
