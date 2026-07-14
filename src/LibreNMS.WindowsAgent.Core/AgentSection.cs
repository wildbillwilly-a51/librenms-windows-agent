using System;
using System.Collections.Generic;
using System.Linq;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class AgentSection
    {
        public AgentSection(string name, IEnumerable<string> lines)
        {
            if (string.IsNullOrWhiteSpace(name))
            {
                throw new ArgumentException("Section name is required.", nameof(name));
            }

            Name = name.Trim();
            Lines = (lines ?? Enumerable.Empty<string>()).Select(line => line ?? string.Empty).ToArray();
        }

        public string Name { get; }
        public IReadOnlyList<string> Lines { get; }
    }
}
