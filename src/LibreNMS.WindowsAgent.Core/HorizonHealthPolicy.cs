using System;
using System.Collections.Generic;
using System.Linq;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class HorizonClassification
    {
        public string State { get; set; } = "ok";
        public string ReasonCode { get; set; } = "none";
        public string Impact { get; set; } = "none";
        public string Component { get; set; } = "unknown";
        public bool Expected { get; set; }
    }

    public static class HorizonHealthPolicy
    {
        private static readonly string[] CoreConnectionServer = { "wsbroker", "connectionserver", "connection server" };
        private static readonly string[] CoreWeb = { "wswc", "webcomponent", "web component" };
        private static readonly string[] CoreMessageBus = { "wsmsgbus", "messagebus", "message bus" };
        private static readonly string[] CoreFramework = { "wsframework", "frameworkmanager", "framework manager" };
        private static readonly string[] ConditionalBlast = { "wsblast", "blastgateway", "blast gateway" };
        private static readonly string[] ConditionalPcoip = { "wspcoip", "pcoipgateway", "pcoip gateway" };
        private static readonly string[] ConditionalSecurity = { "wssecurity", "securitygateway", "security gateway" };
        private static readonly string[] OptionalCrl = { "wscrl", "crlprefetch", "crl prefetch", "crl_prefetch" };
        private static readonly string[] OptionalLog = { "wslog", "logcollector", "log collector" };
        private static readonly string[] OptionalScript = { "wsscript", "scripthost", "script host" };

        public static HorizonClassification ClassifyWindowsService(
            string name,
            string displayName,
            string state,
            string startMode,
            bool gatewayExpected,
            IDictionary<string, string> overrides = null)
        {
            var identity = Normalize(name + " " + displayName);
            var component = Component(identity);
            var expectation = Expectation(component);
            var overrideValue = FindOverride(overrides, name, component);
            if (!string.IsNullOrWhiteSpace(overrideValue))
            {
                expectation = NormalizeExpectation(overrideValue);
            }
            if (expectation == "conditional" && gatewayExpected)
            {
                expectation = "core";
            }

            var running = string.Equals(state, "Running", StringComparison.OrdinalIgnoreCase);
            var disabled = string.Equals(startMode, "Disabled", StringComparison.OrdinalIgnoreCase);
            if (running)
            {
                return Result("ok", "service_running", "none", component, expectation == "core");
            }
            if (expectation == "optional")
            {
                return Result("info", disabled ? "optional_service_disabled" : "optional_service_not_running", "observation", component, false);
            }
            if (expectation == "conditional")
            {
                return Result("info", disabled ? "unused_gateway_service_disabled" : "unused_gateway_service_not_running", "observation", component, false);
            }
            if (expectation == "core")
            {
                return Result("critical", disabled ? "required_service_disabled" : "required_service_not_running", "connection_server", component, true);
            }

            if (disabled || string.Equals(startMode, "Manual", StringComparison.OrdinalIgnoreCase))
            {
                return Result("info", "unclassified_service_not_expected", "observation", component, false);
            }

            return Result("warning", "unclassified_automatic_service_not_running", "connection_server", component, true);
        }

        public static HorizonClassification ClassifyCertificate(bool active, bool valid, int daysRemaining, int warningDays = 30)
        {
            if (!active)
            {
                return Result("info", "unused_certificate_inventory", "observation", "certificate", false);
            }
            if (!valid || daysRemaining < 0)
            {
                return Result("critical", "active_certificate_invalid_or_expired", "connection_server", "certificate", true);
            }
            if (daysRemaining <= Math.Max(1, warningDays))
            {
                return Result("warning", "active_certificate_expires_soon", "connection_server", "certificate", true);
            }
            return Result("ok", "active_certificate_valid", "none", "certificate", true);
        }

        public static HorizonClassification AggregateEnabledServers(IEnumerable<string> states)
        {
            var values = (states ?? Array.Empty<string>()).Select(NormalizeState).ToList();
            var healthy = values.Count(value => value == "ok" || value == "info");
            var unknown = values.Count(value => value == "incomplete");
            if (values.Count == 0)
            {
                return Result("incomplete", "no_enabled_connection_servers", "pod", "pod", true);
            }
            if (healthy <= 1)
            {
                return Result("critical", "connection_server_redundancy_lost", "pod", "pod", true);
            }
            if (values.Count - healthy - unknown > 0)
            {
                return Result("warning", "connection_server_redundancy_degraded", "pod", "pod", true);
            }
            if (unknown > 0)
            {
                return Result("incomplete", "connection_server_health_unknown", "pod", "pod", true);
            }
            return Result("ok", "connection_server_redundancy_healthy", "none", "pod", true);
        }

        public static int SeverityRank(string state)
        {
            switch (NormalizeState(state))
            {
                case "critical": return 50;
                case "warning": return 40;
                case "incomplete": return 30;
                case "info": return 20;
                case "disabled": return 10;
                default: return 0;
            }
        }

        private static HorizonClassification Result(string state, string reason, string impact, string component, bool expected)
        {
            return new HorizonClassification
            {
                State = state,
                ReasonCode = reason,
                Impact = impact,
                Component = component,
                Expected = expected
            };
        }

        private static string Component(string identity)
        {
            if (Matches(identity, OptionalCrl)) return "crl_prefetch";
            if (Matches(identity, OptionalLog)) return "log_collector";
            if (Matches(identity, OptionalScript)) return "script_host";
            if (Matches(identity, ConditionalBlast)) return "blast_gateway";
            if (Matches(identity, ConditionalPcoip)) return "pcoip_gateway";
            if (Matches(identity, ConditionalSecurity)) return "security_gateway";
            if (Matches(identity, CoreMessageBus)) return "message_bus";
            if (Matches(identity, CoreFramework)) return "framework_manager";
            if (Matches(identity, CoreWeb)) return "web_component";
            if (Matches(identity, CoreConnectionServer)) return "connection_server";
            return "unknown";
        }

        private static string Expectation(string component)
        {
            if (component == "crl_prefetch" || component == "log_collector" || component == "script_host") return "optional";
            if (component == "blast_gateway" || component == "pcoip_gateway" || component == "security_gateway") return "conditional";
            if (component == "connection_server" || component == "web_component" || component == "message_bus" || component == "framework_manager") return "core";
            return "unknown";
        }

        private static string FindOverride(IDictionary<string, string> overrides, string name, string component)
        {
            if (overrides == null) return string.Empty;
            if (!string.IsNullOrWhiteSpace(name) && overrides.TryGetValue(name, out var byName)) return byName;
            if (!string.IsNullOrWhiteSpace(component) && overrides.TryGetValue(component, out var byComponent)) return byComponent;
            return string.Empty;
        }

        private static string NormalizeExpectation(string value)
        {
            var normalized = (value ?? string.Empty).Trim().ToLowerInvariant();
            return normalized == "required" ? "core"
                : normalized == "unused" ? "optional"
                : normalized == "core" || normalized == "conditional" || normalized == "optional" ? normalized
                : "unknown";
        }

        private static bool Matches(string identity, IEnumerable<string> values)
        {
            return values.Any(value => identity.Contains(Normalize(value)));
        }

        private static string Normalize(string value)
        {
            return new string((value ?? string.Empty).ToLowerInvariant().Where(char.IsLetterOrDigit).ToArray());
        }

        private static string NormalizeState(string value)
        {
            var normalized = (value ?? string.Empty).Trim().ToLowerInvariant();
            return normalized == "partial" || normalized == "unknown" ? "incomplete" : normalized;
        }
    }
}
