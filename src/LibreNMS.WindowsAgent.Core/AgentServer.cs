using System;
using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class AgentServer
    {
        private readonly AgentConfig _config;
        private readonly Func<AgentContext> _contextFactory;
        private readonly CollectorRunner _collectorRunner;
        private readonly CheckmkRenderer _renderer;
        private readonly AddressMatcher _addressMatcher;
        private readonly IAgentLogger _logger;
        private readonly object _cacheLock = new object();
        private readonly SemaphoreSlim _refreshLock = new SemaphoreSlim(1, 1);
        private string _cachedOutput;
        private TcpListener _listener;

        public AgentServer(
            AgentConfig config,
            Func<AgentContext> contextFactory,
            CollectorRunner collectorRunner,
            CheckmkRenderer renderer,
            IAgentLogger logger)
        {
            _config = config ?? throw new ArgumentNullException(nameof(config));
            _contextFactory = contextFactory ?? throw new ArgumentNullException(nameof(contextFactory));
            _collectorRunner = collectorRunner ?? throw new ArgumentNullException(nameof(collectorRunner));
            _renderer = renderer ?? throw new ArgumentNullException(nameof(renderer));
            _logger = logger ?? NullAgentLogger.Instance;
            _addressMatcher = new AddressMatcher(_config.Listener.AllowedClients);
        }

        public async Task RunAsync(CancellationToken cancellationToken)
        {
            var endpoint = new IPEndPoint(ParseListenAddress(_config.Listener.Address), _config.Listener.Port);
            _listener = new TcpListener(endpoint);
            _listener.Start();
            _logger.Info($"Listening on {endpoint.Address}:{endpoint.Port}.");

            using (cancellationToken.Register(StopListener))
            {
                _ = Task.Run(() => RefreshLoopAsync(cancellationToken), cancellationToken);

                while (!cancellationToken.IsCancellationRequested)
                {
                    TcpClient client = null;

                    try
                    {
                        client = await _listener.AcceptTcpClientAsync().ConfigureAwait(false);
                    }
                    catch (ObjectDisposedException) when (cancellationToken.IsCancellationRequested)
                    {
                        break;
                    }
                    catch (SocketException) when (cancellationToken.IsCancellationRequested)
                    {
                        break;
                    }

                    _ = Task.Run(() => HandleClientAsync(client, cancellationToken), cancellationToken);
                }
            }
        }

        private async Task HandleClientAsync(TcpClient client, CancellationToken cancellationToken)
        {
            IPEndPoint remote = null;

            try
            {
                using (client)
                {
                    remote = (IPEndPoint)client.Client.RemoteEndPoint;
                    if (!_addressMatcher.IsAllowed(remote.Address))
                    {
                        _logger.Warn($"Rejected connection from {remote.Address}.");
                        return;
                    }

                    _logger.Debug($"Accepted connection from {remote.Address}.");
                    var started = Stopwatch.StartNew();
                    var output = await GetResponseOutputAsync(cancellationToken).ConfigureAwait(false);
                    var bytes = Encoding.UTF8.GetBytes(output);

                    using (var stream = client.GetStream())
                    {
                        await stream.WriteAsync(bytes, 0, bytes.Length, cancellationToken).ConfigureAwait(false);
                        await stream.FlushAsync(cancellationToken).ConfigureAwait(false);
                    }

                    started.Stop();
                    _logger.Info($"Served connection from {remote.Address} in {started.ElapsedMilliseconds}ms with {bytes.Length} bytes.");
                }
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                // Service shutdown should not be logged as a request failure.
            }
            catch (Exception ex)
            {
                var remoteAddress = remote?.Address.ToString() ?? "unknown";
                _logger.Error($"Failed to serve connection from {remoteAddress}.", ex);
            }
        }

        private async Task RefreshLoopAsync(CancellationToken cancellationToken)
        {
            var delay = TimeSpan.FromSeconds(Math.Max(5, _config.Listener.CacheRefreshSeconds));

            while (!cancellationToken.IsCancellationRequested)
            {
                await RefreshSnapshotAsync(cancellationToken).ConfigureAwait(false);

                try
                {
                    await Task.Delay(delay, cancellationToken).ConfigureAwait(false);
                }
                catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
                {
                    break;
                }
            }
        }

        private async Task<string> GetResponseOutputAsync(CancellationToken cancellationToken)
        {
            var output = GetCachedOutput();
            if (!string.IsNullOrEmpty(output))
            {
                return output;
            }

            var waitUntil = DateTimeOffset.UtcNow.AddSeconds(Math.Max(0, _config.Listener.InitialCacheWaitSeconds));
            while (DateTimeOffset.UtcNow < waitUntil && !cancellationToken.IsCancellationRequested)
            {
                await Task.Delay(100, cancellationToken).ConfigureAwait(false);
                output = GetCachedOutput();
                if (!string.IsNullOrEmpty(output))
                {
                    return output;
                }
            }

            return BuildCacheWarmingOutput();
        }

        private string GetCachedOutput()
        {
            lock (_cacheLock)
            {
                return _cachedOutput;
            }
        }

        private async Task RefreshSnapshotAsync(CancellationToken cancellationToken)
        {
            if (!_refreshLock.Wait(0))
            {
                return;
            }

            try
            {
                var started = Stopwatch.StartNew();
                var context = _contextFactory();
                var sections = await _collectorRunner.CollectAsync(context, cancellationToken).ConfigureAwait(false);
                var output = _renderer.RenderWithPayloadByteCount(sections);
                started.Stop();

                lock (_cacheLock)
                {
                    _cachedOutput = output;
                }

                _logger.Info($"Refreshed collector cache in {started.ElapsedMilliseconds}ms with {Encoding.UTF8.GetByteCount(output)} bytes.");
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                // Service shutdown should not be logged as a refresh failure.
            }
            catch (Exception ex)
            {
                _logger.Error("Failed to refresh collector cache.", ex);
            }
            finally
            {
                _refreshLock.Release();
            }
        }

        private string BuildCacheWarmingOutput()
        {
            var context = _contextFactory();
            var sections = new[]
            {
                new AgentSection("windows_agent", new[]
                {
                    $"name=windows-agent-librenms-windows-agent version=cache_warming protocol=checkmk_tcp host={context.HostName} utc={DateTimeOffset.UtcNow:O} config={Quote(context.ConfigPath)} process_64bit={(Environment.Is64BitProcess ? 1 : 0)} os_64bit={(Environment.Is64BitOperatingSystem ? 1 : 0)}"
                }),
                new AgentSection("windows_agent_performance", new[]
                {
                    "type=summary collect_duration_ms=0 collectors_run=0 collectors_failed=0 collectors_timed_out=0 section_count=3 line_count=3 payload_bytes=0 process_working_set_bytes=0 process_private_bytes=0 process_cpu_ms=0 process_cpu_percent=0 process_io_read_bytes=0 process_io_write_bytes=0 process_io_bytes=0 process_io_read_ops=0 process_io_write_ops=0"
                }),
                new AgentSection("local", new[]
                {
                    LocalCheck.Format(LocalCheckStatus.Unknown, "Agent Cache", "-", "Initial collector cache is still warming; retry after the first refresh completes.")
                })
            };

            return _renderer.RenderWithPayloadByteCount(sections);
        }

        private static string Quote(string value)
        {
            return "\"" + (value ?? string.Empty).Replace("\\", "\\\\").Replace("\"", "\\\"") + "\"";
        }

        private void StopListener()
        {
            try
            {
                _listener?.Stop();
            }
            catch
            {
                // Shutdown should not fail the hosting service.
            }
        }

        private static IPAddress ParseListenAddress(string address)
        {
            if (string.IsNullOrWhiteSpace(address) || address.Trim() == "*")
            {
                return IPAddress.Any;
            }

            if (!IPAddress.TryParse(address, out var parsed))
            {
                throw new InvalidOperationException($"Invalid listener address '{address}'.");
            }

            return parsed;
        }
    }
}
