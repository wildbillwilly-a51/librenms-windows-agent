using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class CheckmkRenderer
    {
        public string Render(IEnumerable<AgentSection> sections)
        {
            var builder = new StringBuilder();

            foreach (var section in sections ?? Enumerable.Empty<AgentSection>())
            {
                builder.Append("<<<");
                builder.Append(section.Name);
                builder.AppendLine(">>>");

                foreach (var line in section.Lines ?? Array.Empty<string>())
                {
                    builder.AppendLine(NormalizeLine(line));
                }
            }

            return builder.ToString();
        }

        public string RenderWithPayloadByteCount(IEnumerable<AgentSection> sections)
        {
            var materialized = (sections ?? Enumerable.Empty<AgentSection>()).ToArray();
            var output = Render(materialized);

            for (var index = 0; index < 3; index++)
            {
                var byteCount = Encoding.UTF8.GetByteCount(output);
                var updated = StampPayloadByteCount(materialized, byteCount);
                var updatedOutput = Render(updated);

                if (Encoding.UTF8.GetByteCount(updatedOutput) == byteCount)
                {
                    return updatedOutput;
                }

                materialized = updated;
                output = updatedOutput;
            }

            return output;
        }

        private static string NormalizeLine(string line)
        {
            if (line == null)
            {
                return string.Empty;
            }

            return line.Replace("\r", " ").Replace("\n", " ");
        }

        private static AgentSection[] StampPayloadByteCount(IReadOnlyList<AgentSection> sections, int payloadBytes)
        {
            var updated = new AgentSection[sections.Count];

            for (var index = 0; index < sections.Count; index++)
            {
                var section = sections[index];
                if (!IsAgentPerformanceSection(section.Name))
                {
                    updated[index] = section;
                    continue;
                }

                updated[index] = new AgentSection(section.Name, section.Lines.Select(line => StampPayloadByteCount(line, payloadBytes)));
            }

            return updated;
        }

        private static bool IsAgentPerformanceSection(string name)
        {
            return string.Equals(name, "windows_agent_performance", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(name, "windows_agent_performance", StringComparison.OrdinalIgnoreCase);
        }

        private static string StampPayloadByteCount(string line, int payloadBytes)
        {
            if (line == null || !line.StartsWith("type=summary ", StringComparison.Ordinal))
            {
                return line;
            }

            var value = "payload_bytes=" + payloadBytes;
            var start = line.IndexOf("payload_bytes=", StringComparison.Ordinal);
            if (start < 0)
            {
                return line + " " + value;
            }

            var end = line.IndexOf(' ', start);
            if (end < 0)
            {
                return line.Substring(0, start) + value;
            }

            return line.Substring(0, start) + value + line.Substring(end);
        }
    }
}
