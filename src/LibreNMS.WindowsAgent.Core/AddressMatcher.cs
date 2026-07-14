using System;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Net.Sockets;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class AddressMatcher
    {
        private readonly IReadOnlyList<string> _rules;

        public AddressMatcher(IEnumerable<string> rules)
        {
            _rules = (rules ?? Enumerable.Empty<string>())
                .Where(rule => !string.IsNullOrWhiteSpace(rule))
                .Select(rule => rule.Trim())
                .ToArray();
        }

        public bool IsAllowed(IPAddress address)
        {
            if (_rules.Count == 0)
            {
                return true;
            }

            if (address == null)
            {
                return false;
            }

            foreach (var rule in _rules)
            {
                if (rule == "*")
                {
                    return true;
                }

                if (MatchesRule(address, rule))
                {
                    return true;
                }
            }

            return false;
        }

        private static bool MatchesRule(IPAddress address, string rule)
        {
            if (rule.Contains("/"))
            {
                return MatchesCidr(address, rule);
            }

            if (IPAddress.TryParse(rule, out var parsed))
            {
                return parsed.Equals(address) || parsed.Equals(MapToIPv4IfPossible(address));
            }

            return false;
        }

        private static bool MatchesCidr(IPAddress address, string rule)
        {
            var parts = rule.Split('/');
            if (parts.Length != 2 || !IPAddress.TryParse(parts[0], out var network))
            {
                return false;
            }

            if (!int.TryParse(parts[1], out var prefixLength))
            {
                return false;
            }

            var candidate = NormalizeForFamily(address, network.AddressFamily);
            if (candidate == null)
            {
                return false;
            }

            var addressBytes = candidate.GetAddressBytes();
            var networkBytes = network.GetAddressBytes();
            var bitLength = addressBytes.Length * 8;

            if (prefixLength < 0 || prefixLength > bitLength)
            {
                return false;
            }

            var fullBytes = prefixLength / 8;
            var remainingBits = prefixLength % 8;

            for (var index = 0; index < fullBytes; index++)
            {
                if (addressBytes[index] != networkBytes[index])
                {
                    return false;
                }
            }

            if (remainingBits == 0)
            {
                return true;
            }

            var mask = (byte)(0xFF << (8 - remainingBits));
            return (addressBytes[fullBytes] & mask) == (networkBytes[fullBytes] & mask);
        }

        private static IPAddress NormalizeForFamily(IPAddress address, AddressFamily family)
        {
            if (address.AddressFamily == family)
            {
                return address;
            }

            if (family == AddressFamily.InterNetwork)
            {
                return MapToIPv4IfPossible(address);
            }

            return null;
        }

        private static IPAddress MapToIPv4IfPossible(IPAddress address)
        {
            if (address.AddressFamily == AddressFamily.InterNetworkV6 && address.IsIPv4MappedToIPv6)
            {
                return address.MapToIPv4();
            }

            return address;
        }
    }
}
