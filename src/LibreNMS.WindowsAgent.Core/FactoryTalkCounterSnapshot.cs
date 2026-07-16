using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Text.RegularExpressions;
using System.Xml;
using System.Xml.Linq;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class FactoryTalkCounterSnapshot
    {
        public IList<FactoryTalkLinxConnectionCounter> Connections { get; } = new List<FactoryTalkLinxConnectionCounter>();
        public IList<FactoryTalkLinxBackplaneCounter> BackplaneSlots { get; } = new List<FactoryTalkLinxBackplaneCounter>();
        public IList<FactoryTalkLinxTransactionCounter> Transactions { get; } = new List<FactoryTalkLinxTransactionCounter>();
        public long LiveDataClients { get; set; }
        public int LiveDataSources { get; set; }
    }

    public sealed class FactoryTalkLinxConnectionCounter
    {
        public int Instance { get; set; }
        public string Driver { get; set; } = string.Empty;
        public string Direction { get; set; } = string.Empty;
        public long Active { get; set; }
        public long Accepted { get; set; }
        public long Attempted { get; set; }
        public long Closed { get; set; }
    }

    public sealed class FactoryTalkLinxBackplaneCounter
    {
        public int Instance { get; set; }
        public int Slot { get; set; }
        public long PacketsReceived { get; set; }
        public long PacketsSent { get; set; }
        public long SendFailures { get; set; }
    }

    public sealed class FactoryTalkLinxTransactionCounter
    {
        public int Instance { get; set; }
        public long InUse { get; set; }
        public long PoolSize { get; set; }
    }

    public static class FactoryTalkCounterSnapshotParser
    {
        public const long MaximumXmlBytes = 2 * 1024 * 1024;
        public const int MaximumElements = 10000;
        private static readonly Regex InstancePattern = new Regex(@"^FactoryTalk Linx(?: Instance(?<number>\d+))?$", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
        private static readonly Regex SlotPattern = new Regex(@"^Slot\s+(?<number>\d+)$", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);

        public static FactoryTalkCounterSnapshot Parse(Stream stream)
        {
            if (stream == null)
            {
                throw new ArgumentNullException(nameof(stream));
            }

            if (stream.CanSeek && stream.Length > MaximumXmlBytes)
            {
                throw new InvalidDataException("Counter Monitor snapshot exceeds the maximum permitted size.");
            }

            var settings = new XmlReaderSettings
            {
                DtdProcessing = DtdProcessing.Prohibit,
                XmlResolver = null,
                MaxCharactersInDocument = MaximumXmlBytes,
                IgnoreComments = true,
                IgnoreProcessingInstructions = true,
            };

            XDocument document;
            using (var reader = XmlReader.Create(stream, settings))
            {
                document = XDocument.Load(reader, LoadOptions.None);
            }

            if (document.Root == null || !string.Equals(document.Root.Name.LocalName, "CounterMonitorReport", StringComparison.Ordinal))
            {
                throw new InvalidDataException("Unexpected Counter Monitor snapshot root element.");
            }

            var elements = document.Descendants().Take(MaximumElements + 1).ToList();
            if (elements.Count > MaximumElements)
            {
                throw new InvalidDataException("Counter Monitor snapshot contains too many elements.");
            }

            var snapshot = new FactoryTalkCounterSnapshot();
            foreach (var item in elements.Where(element => string.Equals(element.Name.LocalName, "DiagItem", StringComparison.Ordinal)))
            {
                ParseDiagnosticItem(item, snapshot);
            }

            return snapshot;
        }

        private static void ParseDiagnosticItem(XElement item, FactoryTalkCounterSnapshot snapshot)
        {
            var path = item.AncestorsAndSelf()
                .Where(element => string.Equals(element.Name.LocalName, "DiagItem", StringComparison.Ordinal))
                .Reverse()
                .Select(element => Attribute(element, "dispname"))
                .Where(value => !string.IsNullOrWhiteSpace(value))
                .ToList();
            var properties = item.Elements()
                .Where(element => string.Equals(element.Name.LocalName, "Property", StringComparison.Ordinal))
                .GroupBy(element => Attribute(element, "dispname"), StringComparer.OrdinalIgnoreCase)
                .Where(group => !string.IsNullOrWhiteSpace(group.Key))
                .ToDictionary(group => group.Key, group => NonNegativeLong(Attribute(group.First(), "value")), StringComparer.OrdinalIgnoreCase);

            if (properties.Count == 0)
            {
                return;
            }

            var instance = LinxInstance(path);
            if (instance > 0 && path.Any(part => string.Equals(part, "Transaction Manager", StringComparison.OrdinalIgnoreCase)))
            {
                snapshot.Transactions.Add(new FactoryTalkLinxTransactionCounter
                {
                    Instance = instance,
                    PoolSize = Property(properties, "Size of transaction pool"),
                    InUse = Property(properties, "Number of transactions in use"),
                });
                return;
            }

            var driversIndex = path.FindIndex(part => string.Equals(part, "Drivers", StringComparison.OrdinalIgnoreCase));
            if (instance > 0 && driversIndex >= 0 && driversIndex + 1 < path.Count)
            {
                var driver = NormalizeDriver(path[driversIndex + 1]);
                if (driver == "backplane" && driversIndex + 2 < path.Count)
                {
                    var slotMatch = SlotPattern.Match(path[driversIndex + 2]);
                    if (slotMatch.Success && int.TryParse(slotMatch.Groups["number"].Value, NumberStyles.None, CultureInfo.InvariantCulture, out var slot) && slot >= 0 && slot <= 255)
                    {
                        snapshot.BackplaneSlots.Add(new FactoryTalkLinxBackplaneCounter
                        {
                            Instance = instance,
                            Slot = slot,
                            PacketsReceived = Property(properties, "Number of Packets Received"),
                            PacketsSent = Property(properties, "Number of Packets Sent"),
                            SendFailures = Property(properties, "Number of Send Failures"),
                        });
                    }
                    return;
                }

                if (driver != string.Empty && driversIndex + 2 < path.Count)
                {
                    var direction = ConnectionDirection(path[driversIndex + 2]);
                    if (direction != string.Empty)
                    {
                        snapshot.Connections.Add(new FactoryTalkLinxConnectionCounter
                        {
                            Instance = instance,
                            Driver = driver,
                            Direction = direction,
                            Active = Property(properties, "Number of connections active"),
                            Accepted = Property(properties, "Number of connections accepted"),
                            Attempted = Property(properties, "Number of connections attempted"),
                            Closed = Property(properties, "Number of connections closed"),
                        });
                    }
                    return;
                }
            }

            if (path.Any(part => string.Equals(part, "FactoryTalk Live Data", StringComparison.OrdinalIgnoreCase)) &&
                properties.TryGetValue("total number of FactoryTalk data clients", out var clients))
            {
                snapshot.LiveDataClients += clients;
                snapshot.LiveDataSources++;
            }
        }

        private static int LinxInstance(IList<string> path)
        {
            foreach (var part in path)
            {
                var match = InstancePattern.Match(part);
                if (!match.Success)
                {
                    continue;
                }

                if (!match.Groups["number"].Success)
                {
                    return 1;
                }

                if (int.TryParse(match.Groups["number"].Value, NumberStyles.None, CultureInfo.InvariantCulture, out var instance) && instance >= 1 && instance <= 255)
                {
                    return instance;
                }
            }
            return 0;
        }

        private static string NormalizeDriver(string value)
        {
            if (string.Equals(value, "Backplane", StringComparison.OrdinalIgnoreCase)) return "backplane";
            if (string.Equals(value, "Ethernet", StringComparison.OrdinalIgnoreCase)) return "ethernet";
            if (value.StartsWith("AB_ETHIP", StringComparison.OrdinalIgnoreCase)) return "ethernet_ip";
            if (value.StartsWith("AB_ETH", StringComparison.OrdinalIgnoreCase)) return "ethernet";
            return string.Empty;
        }

        private static string ConnectionDirection(string value)
        {
            if (string.Equals(value, "Incoming TCP Connections", StringComparison.OrdinalIgnoreCase)) return "incoming";
            if (string.Equals(value, "Outgoing TCP Connections", StringComparison.OrdinalIgnoreCase)) return "outgoing";
            return string.Empty;
        }

        private static string Attribute(XElement element, string name)
        {
            return element.Attributes().FirstOrDefault(attribute => string.Equals(attribute.Name.LocalName, name, StringComparison.OrdinalIgnoreCase))?.Value ?? string.Empty;
        }

        private static long Property(IDictionary<string, long> properties, string name)
        {
            return properties.TryGetValue(name, out var value) ? value : 0;
        }

        private static long NonNegativeLong(string value)
        {
            if (!long.TryParse(value, NumberStyles.Integer, CultureInfo.InvariantCulture, out var result))
            {
                return 0;
            }
            return Math.Max(0, result);
        }
    }
}
