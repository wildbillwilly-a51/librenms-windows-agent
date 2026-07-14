using System;
using System.Collections.Generic;
using System.Diagnostics.Eventing.Reader;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class EventLogCollector : CollectorBase
    {
        public override string Name => "event_logs";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var watches = context.Config.Collectors.EventLogs ?? new List<EventLogWatchConfig>();
            var lines = new List<string>
            {
                string.Join(" ", Kv("configured_count", watches.Count))
            };
            var highValueGroups = new List<EventLogHighValueGroup>();
            var highValueEventsTotal = 0;
            var highValueSamplesTotal = 0;
            var highValueTruncated = false;
            var highValueMaxSignatures = 0;
            var highValueMaxSamples = 0;

            foreach (var watch in watches)
            {
                cancellationToken.ThrowIfCancellationRequested();

                var logName = string.IsNullOrWhiteSpace(watch.LogName) ? "System" : watch.LogName.Trim();
                var sinceHours = watch.SinceHours <= 0 ? 24 : watch.SinceHours;
                var maxEvents = watch.MaxEvents <= 0 ? 5000 : watch.MaxEvents;
                var cutoffUtc = context.NowUtc.UtcDateTime.AddHours(-sinceHours);
                highValueMaxSignatures = Math.Max(highValueMaxSignatures, watch.HighValueMaxSignatures <= 0 ? 25 : watch.HighValueMaxSignatures);
                highValueMaxSamples = Math.Max(highValueMaxSamples, watch.HighValueMaxSamplesPerSignature <= 0 ? 5 : watch.HighValueMaxSamplesPerSignature);

                try
                {
                    var summary = ReadSummary(logName, cutoffUtc, maxEvents, watch, cancellationToken);
                    lines.Add(string.Join(" ",
                        Kv("log", logName),
                        Kv("since_hours", sinceHours),
                        Kv("max_events", maxEvents),
                        Kv("scanned_events", summary.ScannedEvents),
                        Kv("critical_count", summary.CriticalCount),
                        Kv("error_count", summary.ErrorCount),
                        Kv("warning_count", summary.WarningCount),
                        Kv("latest_critical_or_error_utc", FormatUtc(summary.LatestCriticalOrErrorUtc))));

                    highValueGroups.AddRange(summary.HighValueGroups);
                    highValueEventsTotal += summary.HighValueEventsTotal;
                    highValueSamplesTotal += summary.HighValueSamplesTotal;
                    highValueTruncated = highValueTruncated || summary.HighValueTruncated;
                }
                catch (Exception ex) when (ex is EventLogException || ex is UnauthorizedAccessException || ex is InvalidOperationException)
                {
                    lines.Add(string.Join(" ",
                        Kv("log", logName),
                        Kv("since_hours", sinceHours),
                        Kv("max_events", maxEvents),
                        Kv("error", ex.GetType().Name),
                        Kv("message", ex.Message)));
                }
            }

            var highValueSummaryLines = new List<string>
            {
                string.Join(" ",
                    Kv("configured_logs", watches.Count),
                    Kv("signatures_total", highValueGroups.Count),
                    Kv("events_total", highValueEventsTotal),
                    Kv("samples_total", highValueSamplesTotal),
                    Kv("truncated", highValueTruncated ? 1 : 0),
                    Kv("max_signatures", highValueMaxSignatures),
                    Kv("max_samples_per_signature", highValueMaxSamples))
            };

            var highValueLines = highValueGroups
                .OrderBy(group => LevelSort(group.Level))
                .ThenByDescending(group => group.Count)
                .ThenByDescending(group => group.LastSeenUtc)
                .SelectMany(group => group.Samples.Select((sample, index) => string.Join(" ",
                    Kv("log", group.LogName),
                    Kv("provider", group.Provider),
                    Kv("event_id", group.EventId),
                    Kv("level", group.LevelName),
                    Kv("level_code", group.Level),
                    Kv("count", group.Count),
                    Kv("first_seen_utc", FormatUtc(group.FirstSeenUtc)),
                    Kv("last_seen_utc", FormatUtc(group.LastSeenUtc)),
                    Kv("sample_index", index + 1),
                    Kv("sample_time_utc", FormatUtc(sample.TimeUtc)),
                    Kv("message_excerpt", sample.MessageExcerpt))))
                .ToList();

            return Complete(
                new AgentSection("windows_agent_event_logs", lines),
                new AgentSection("windows_agent_event_log_high_value_summary", highValueSummaryLines),
                new AgentSection("windows_agent_event_log_high_value", highValueLines));
        }

        private static EventLogSummary ReadSummary(string logName, DateTime cutoffUtc, int maxEvents, EventLogWatchConfig watch, CancellationToken cancellationToken)
        {
            var cutoffMs = Math.Max(1, (long)(DateTime.UtcNow - cutoffUtc).TotalMilliseconds);
            var queryText = "*[System[TimeCreated[timediff(@SystemTime) <= " + cutoffMs + "]]]";
            var query = new EventLogQuery(logName, PathType.LogName, queryText)
            {
                ReverseDirection = true
            };

            var summary = new EventLogSummary();
            var highValueGroups = new Dictionary<string, EventLogHighValueGroup>(StringComparer.OrdinalIgnoreCase);
            var includeHighValueDetails = watch.IncludeHighValueDetails;
            var maxSignatures = watch.HighValueMaxSignatures <= 0 ? 25 : watch.HighValueMaxSignatures;
            var maxSamplesPerSignature = watch.HighValueMaxSamplesPerSignature <= 0 ? 5 : watch.HighValueMaxSamplesPerSignature;
            var maxMessageChars = watch.HighValueMaxMessageChars <= 0 ? 400 : watch.HighValueMaxMessageChars;
            using (var reader = new EventLogReader(query))
            {
                for (var i = 0; i < maxEvents; i++)
                {
                    cancellationToken.ThrowIfCancellationRequested();

                    using (var record = reader.ReadEvent())
                    {
                        if (record == null)
                        {
                            break;
                        }

                        summary.ScannedEvents++;

                        var level = record.Level ?? 0;
                        if (level == 1)
                        {
                            summary.CriticalCount++;
                            summary.TrackLatest(record.TimeCreated);
                        }
                        else if (level == 2)
                        {
                            summary.ErrorCount++;
                            summary.TrackLatest(record.TimeCreated);
                        }
                        else if (level == 3)
                        {
                            summary.WarningCount++;
                        }

                        if (includeHighValueDetails && IsHighValueRecord(record, watch))
                        {
                            summary.HighValueEventsTotal++;
                            var provider = string.IsNullOrWhiteSpace(record.ProviderName) ? "unknown" : record.ProviderName.Trim();
                            var key = logName + "|" + provider + "|" + record.Id + "|" + level;
                            EventLogHighValueGroup group;
                            if (!highValueGroups.TryGetValue(key, out group))
                            {
                                if (highValueGroups.Count >= maxSignatures)
                                {
                                    summary.HighValueTruncated = true;
                                    continue;
                                }

                                group = new EventLogHighValueGroup
                                {
                                    LogName = logName,
                                    Provider = provider,
                                    EventId = record.Id,
                                    Level = level,
                                    LevelName = LevelName(level)
                                };
                                highValueGroups[key] = group;
                            }

                            group.Count++;
                            group.TrackTime(record.TimeCreated);
                            if (group.Samples.Count < maxSamplesPerSignature)
                            {
                                group.Samples.Add(new EventLogSample
                                {
                                    TimeUtc = record.TimeCreated,
                                    MessageExcerpt = Truncate(SafeFormatDescription(record), maxMessageChars)
                                });
                                summary.HighValueSamplesTotal++;
                            }
                        }
                    }
                }
            }

            summary.HighValueGroups.AddRange(highValueGroups.Values);
            return summary;
        }

        private static string FormatUtc(DateTime? value)
        {
            return value.HasValue ? value.Value.ToUniversalTime().ToString("o") : string.Empty;
        }

        private static bool IsHighValueRecord(EventRecord record, EventLogWatchConfig watch)
        {
            var level = record.Level ?? 0;
            if (!LevelMatches(level, watch.HighValueLevels))
            {
                return false;
            }

            if (watch.HighValueEventIds != null && watch.HighValueEventIds.Count > 0 && !watch.HighValueEventIds.Contains(record.Id))
            {
                return false;
            }

            if (watch.HighValueProviders != null && watch.HighValueProviders.Count > 0)
            {
                var provider = record.ProviderName ?? string.Empty;
                return watch.HighValueProviders.Any(item => string.Equals(item, provider, StringComparison.OrdinalIgnoreCase));
            }

            return true;
        }

        private static bool LevelMatches(int level, IList<string> configuredLevels)
        {
            var levels = configuredLevels == null || configuredLevels.Count == 0
                ? new[] { "Critical", "Error" }
                : configuredLevels;

            foreach (var configuredLevel in levels)
            {
                if (string.Equals(configuredLevel, LevelName(level), StringComparison.OrdinalIgnoreCase))
                {
                    return true;
                }

                int numericLevel;
                if (int.TryParse(configuredLevel, out numericLevel) && numericLevel == level)
                {
                    return true;
                }
            }

            return false;
        }

        private static string LevelName(int level)
        {
            switch (level)
            {
                case 1:
                    return "Critical";
                case 2:
                    return "Error";
                case 3:
                    return "Warning";
                case 4:
                    return "Information";
                case 5:
                    return "Verbose";
                default:
                    return "Unknown";
            }
        }

        private static int LevelSort(int level)
        {
            if (level <= 0)
            {
                return 99;
            }

            return level;
        }

        private static string SafeFormatDescription(EventRecord record)
        {
            try
            {
                return NormalizeMessage(record.FormatDescription());
            }
            catch (Exception)
            {
                return string.Empty;
            }
        }

        private static string NormalizeMessage(string value)
        {
            if (string.IsNullOrWhiteSpace(value))
            {
                return string.Empty;
            }

            return value.Replace("\r", " ").Replace("\n", " ").Trim();
        }

        private static string Truncate(string value, int maxChars)
        {
            if (string.IsNullOrEmpty(value) || maxChars <= 0 || value.Length <= maxChars)
            {
                return value ?? string.Empty;
            }

            return value.Substring(0, maxChars);
        }

        private sealed class EventLogSummary
        {
            public int ScannedEvents { get; set; }
            public int CriticalCount { get; set; }
            public int ErrorCount { get; set; }
            public int WarningCount { get; set; }
            public DateTime? LatestCriticalOrErrorUtc { get; private set; }
            public List<EventLogHighValueGroup> HighValueGroups { get; } = new List<EventLogHighValueGroup>();
            public int HighValueEventsTotal { get; set; }
            public int HighValueSamplesTotal { get; set; }
            public bool HighValueTruncated { get; set; }

            public void TrackLatest(DateTime? value)
            {
                if (!value.HasValue)
                {
                    return;
                }

                var utc = value.Value.ToUniversalTime();
                if (!LatestCriticalOrErrorUtc.HasValue || utc > LatestCriticalOrErrorUtc.Value)
                {
                    LatestCriticalOrErrorUtc = utc;
                }
            }
        }

        private sealed class EventLogHighValueGroup
        {
            public string LogName { get; set; }
            public string Provider { get; set; }
            public int EventId { get; set; }
            public int Level { get; set; }
            public string LevelName { get; set; }
            public int Count { get; set; }
            public DateTime? FirstSeenUtc { get; private set; }
            public DateTime? LastSeenUtc { get; private set; }
            public List<EventLogSample> Samples { get; } = new List<EventLogSample>();

            public void TrackTime(DateTime? value)
            {
                if (!value.HasValue)
                {
                    return;
                }

                var utc = value.Value.ToUniversalTime();
                if (!FirstSeenUtc.HasValue || utc < FirstSeenUtc.Value)
                {
                    FirstSeenUtc = utc;
                }

                if (!LastSeenUtc.HasValue || utc > LastSeenUtc.Value)
                {
                    LastSeenUtc = utc;
                }
            }
        }

        private sealed class EventLogSample
        {
            public DateTime? TimeUtc { get; set; }
            public string MessageExcerpt { get; set; }
        }
    }
}
