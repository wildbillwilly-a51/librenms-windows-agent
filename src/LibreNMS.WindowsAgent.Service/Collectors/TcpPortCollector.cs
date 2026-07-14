using System;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Net.NetworkInformation;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class TcpPortCollector : CollectorBase
    {
        public override string Name => "tcp_ports";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var watched = context.Config.Collectors.WatchedTcpPorts ?? new List<TcpPortWatchConfig>();
            var listeners = IPGlobalProperties.GetIPGlobalProperties().GetActiveTcpListeners();
            var lines = new List<string>
            {
                string.Join(" ", Kv("watched_count", watched.Count), Kv("active_listener_count", listeners.Length))
            };

            foreach (var watch in watched)
            {
                cancellationToken.ThrowIfCancellationRequested();

                var expectedAddress = string.IsNullOrWhiteSpace(watch.Address) ? null : watch.Address.Trim();
                var listening = listeners.Any(listener => Matches(listener, expectedAddress, watch.Port));
                lines.Add(string.Join(" ",
                    Kv("name", string.IsNullOrWhiteSpace(watch.Name) ? $"tcp_{watch.Port}" : watch.Name.Trim()),
                    Kv("address", expectedAddress ?? "*"),
                    Kv("port", watch.Port),
                    Kv("listening", listening ? 1 : 0)));
            }

            return Complete(new AgentSection("windows_agent_tcp_ports", lines));
        }

        private static bool Matches(IPEndPoint listener, string expectedAddress, int expectedPort)
        {
            if (listener.Port != expectedPort)
            {
                return false;
            }

            if (string.IsNullOrWhiteSpace(expectedAddress))
            {
                return true;
            }

            if (!IPAddress.TryParse(expectedAddress, out var expected))
            {
                return false;
            }

            return listener.Address.Equals(expected) ||
                IPAddress.Any.Equals(listener.Address) ||
                IPAddress.IPv6Any.Equals(listener.Address);
        }
    }
}
