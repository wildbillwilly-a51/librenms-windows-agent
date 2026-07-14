using System;
using System.IO;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service
{
    internal sealed class FileAgentLogger : IAgentLogger
    {
        private readonly string _path;
        private readonly LogLevel _level;
        private readonly object _lock = new object();

        public FileAgentLogger(LoggingConfig config)
        {
            _path = ConfigLoader.ExpandPath(config?.Path);
            _level = ParseLevel(config?.Level);
        }

        public void Debug(string message)
        {
            Write(LogLevel.Debug, message);
        }

        public void Info(string message)
        {
            Write(LogLevel.Info, message);
        }

        public void Warn(string message)
        {
            Write(LogLevel.Warn, message);
        }

        public void Error(string message, Exception exception = null)
        {
            Write(LogLevel.Error, exception == null ? message : $"{message} {exception}");
        }

        private void Write(LogLevel level, string message)
        {
            if (level < _level)
            {
                return;
            }

            var line = $"{DateTimeOffset.UtcNow:O} [{level.ToString().ToUpperInvariant()}] {message}";

            lock (_lock)
            {
                var directory = Path.GetDirectoryName(_path);
                if (!string.IsNullOrWhiteSpace(directory))
                {
                    Directory.CreateDirectory(directory);
                }

                File.AppendAllText(_path, line + Environment.NewLine);
            }
        }

        private static LogLevel ParseLevel(string value)
        {
            if (Enum.TryParse(value ?? string.Empty, true, out LogLevel level))
            {
                return level;
            }

            return LogLevel.Info;
        }

        private enum LogLevel
        {
            Debug = 0,
            Info = 1,
            Warn = 2,
            Error = 3
        }
    }
}
