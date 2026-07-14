using System;
using System.Text.RegularExpressions;

namespace LibreNMS.WindowsAgent.Core
{
    public static class ServiceCommandLine
    {
        private static readonly Regex SensitiveKeyValue = new Regex(
            @"(?i)(?<key>(token|secret|password|passwd|pwd|key|apikey|api_key|clientsecret|client_secret|auth|session|signature|sig|code))(?<separator>\s*[:=]\s*)(?<value>[^&\s""']+)",
            RegexOptions.Compiled);

        public static string RedactPath(string commandLine)
        {
            var executable = ExtractExecutablePath(commandLine);
            return RedactSensitiveValues(executable);
        }

        public static string ExtractExecutablePath(string commandLine)
        {
            var value = (commandLine ?? string.Empty).Trim();
            if (value.Length == 0)
            {
                return string.Empty;
            }

            if (value[0] == '"')
            {
                var end = value.IndexOf('"', 1);
                return end > 1 ? value.Substring(1, end - 1) : value.Trim('"');
            }

            var exeIndex = value.IndexOf(".exe", StringComparison.OrdinalIgnoreCase);
            return exeIndex >= 0 ? value.Substring(0, exeIndex + 4) : value.Split(' ')[0];
        }

        public static string RedactSensitiveValues(string value)
        {
            var text = value ?? string.Empty;
            if (text.Length == 0)
            {
                return string.Empty;
            }

            var queryStart = text.IndexOf('?');
            if (queryStart >= 0)
            {
                text = text.Substring(0, queryStart) + "?<redacted>";
            }

            return SensitiveKeyValue.Replace(text, "${key}${separator}<redacted>");
        }
    }
}
