using System;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class FactoryTalkHealthInput
    {
        public bool Detected { get; set; }
        public string NotDetectedState { get; set; } = "not_detected";
        public int CoreServicesNotRunning { get; set; }
        public int PortsMissing { get; set; }
    }

    public sealed class FactoryTalkHealthResult
    {
        public string State { get; set; } = "ok";
        public int HealthIssues { get; set; }
    }

    public static class FactoryTalkHealth
    {
        public static FactoryTalkHealthResult Evaluate(FactoryTalkHealthInput input)
        {
            if (input == null)
            {
                throw new ArgumentNullException(nameof(input));
            }

            if (!input.Detected)
            {
                return new FactoryTalkHealthResult
                {
                    State = string.IsNullOrWhiteSpace(input.NotDetectedState) ? "not_detected" : input.NotDetectedState,
                    HealthIssues = 0
                };
            }

            var issues = Math.Max(0, input.CoreServicesNotRunning) + Math.Max(0, input.PortsMissing);
            return issues > 0
                ? new FactoryTalkHealthResult { State = "warning", HealthIssues = issues }
                : new FactoryTalkHealthResult { State = "ok", HealthIssues = 0 };
        }
    }
}
