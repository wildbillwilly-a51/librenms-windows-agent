using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal static class ServerFeatureReader
    {
        public static IReadOnlyList<string> ReadInstalled(CancellationToken cancellationToken)
        {
            var result = CommandRunner.Run(
                "powershell.exe",
                "-NoProfile -ExecutionPolicy Bypass -Command \"Import-Module ServerManager -ErrorAction Stop; Get-WindowsFeature | Where-Object Installed | ForEach-Object { $_.Name }\"",
                TimeSpan.FromSeconds(10),
                cancellationToken);

            if (result.State != "ok")
            {
                return Array.Empty<string>();
            }

            return (result.Output ?? string.Empty)
                .Split(new[] { "\r\n", "\n" }, StringSplitOptions.RemoveEmptyEntries)
                .Select(line => line.Trim())
                .Where(line => line.Length > 0)
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .OrderBy(line => line, StringComparer.OrdinalIgnoreCase)
                .ToList();
        }
    }
}
