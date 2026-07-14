using System;

namespace LibreNMS.WindowsAgent.Core
{
    public interface IAgentLogger
    {
        void Debug(string message);
        void Info(string message);
        void Warn(string message);
        void Error(string message, Exception exception = null);
    }

    public sealed class NullAgentLogger : IAgentLogger
    {
        public static readonly NullAgentLogger Instance = new NullAgentLogger();

        private NullAgentLogger()
        {
        }

        public void Debug(string message)
        {
        }

        public void Info(string message)
        {
        }

        public void Warn(string message)
        {
        }

        public void Error(string message, Exception exception = null)
        {
        }
    }
}
