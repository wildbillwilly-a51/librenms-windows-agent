using System;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class HorizonHealthInput
    {
        public bool Detected { get; set; }
        public string NotDetectedState { get; set; } = "not_detected";
        public int ServicesNotRunning { get; set; }
        public int PortsMissing { get; set; }
        public int CertificatesExpired { get; set; }
        public int CertificatesExpiringCritical { get; set; }
    }

    public sealed class HorizonHealthResult
    {
        public string State { get; set; } = "ok";
        public int HealthIssues { get; set; }
    }

    public static class HorizonHealth
    {
        public static HorizonHealthResult Evaluate(HorizonHealthInput input)
        {
            if (input == null)
            {
                throw new ArgumentNullException(nameof(input));
            }

            if (!input.Detected)
            {
                return new HorizonHealthResult
                {
                    State = string.IsNullOrWhiteSpace(input.NotDetectedState) ? "not_detected" : input.NotDetectedState,
                    HealthIssues = 0
                };
            }

            var serviceIssues = Math.Max(0, input.ServicesNotRunning);
            var portIssues = Math.Max(0, input.PortsMissing);
            var expiredIssues = Math.Max(0, input.CertificatesExpired);
            var criticalCertificateIssues = Math.Max(0, input.CertificatesExpiringCritical);
            var issues = serviceIssues + portIssues + expiredIssues + criticalCertificateIssues;

            if (serviceIssues > 0 || portIssues > 0 || expiredIssues > 0)
            {
                return new HorizonHealthResult { State = "critical", HealthIssues = issues };
            }

            if (criticalCertificateIssues > 0)
            {
                return new HorizonHealthResult { State = "warning", HealthIssues = issues };
            }

            return new HorizonHealthResult { State = "ok", HealthIssues = 0 };
        }
    }
}
