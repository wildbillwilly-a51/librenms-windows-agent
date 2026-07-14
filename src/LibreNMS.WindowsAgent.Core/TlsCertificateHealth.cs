namespace LibreNMS.WindowsAgent.Core
{
    public sealed class TlsCertificateHealthInput
    {
        public bool Bound { get; set; }
        public bool Expired { get; set; }
        public bool NotYetValid { get; set; }
        public bool InvalidChain { get; set; }
        public bool WeakKey { get; set; }
        public bool WeakSignature { get; set; }
        public bool MissingPrivateKeyForBinding { get; set; }
    }

    public sealed class TlsCertificateHealthResult
    {
        public string Health { get; set; } = "ok";
        public string HealthScope { get; set; } = "inventory";
        public bool Scored { get; set; }
        public bool Unhealthy { get; set; }
    }

    public static class TlsCertificateHealth
    {
        public static TlsCertificateHealthResult Evaluate(TlsCertificateHealthInput input)
        {
            input = input ?? new TlsCertificateHealthInput();
            var health = CalculateHealth(input);
            var scored = input.Bound;
            return new TlsCertificateHealthResult
            {
                Health = health,
                HealthScope = scored ? "scored" : "inventory",
                Scored = scored,
                Unhealthy = scored && health != "ok"
            };
        }

        private static string CalculateHealth(TlsCertificateHealthInput input)
        {
            if (input.Expired)
            {
                return "expired";
            }

            if (input.NotYetValid)
            {
                return "not_yet_valid";
            }

            if (input.MissingPrivateKeyForBinding)
            {
                return "missing_private_key";
            }

            if (input.InvalidChain)
            {
                return "invalid_chain";
            }

            if (input.WeakKey)
            {
                return "weak_key";
            }

            return input.WeakSignature ? "weak_signature" : "ok";
        }
    }
}
