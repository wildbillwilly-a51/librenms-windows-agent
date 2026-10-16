using System;
using System.Collections.Generic;
using System.Globalization;
using System.Security;
using System.Threading;
using Microsoft.Win32;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    /// <summary>
    /// Registry-sourced facts about an installed SQL Server instance. This is the
    /// authoritative local source for instance identity, product level and TCP
    /// endpoint configuration; none of it needs a SQL login.
    /// </summary>
    internal sealed class SqlInstanceRegistryInfo
    {
        public string Instance { get; set; } = string.Empty;
        public string RegistryKey { get; set; } = string.Empty;
        public string Edition { get; set; } = string.Empty;
        public string PatchLevel { get; set; } = string.Empty;
        public string ProductVersion { get; set; } = string.Empty;
        public string Collation { get; set; } = string.Empty;
        public string DataRoot { get; set; } = string.Empty;
        public string TcpPort { get; set; } = string.Empty;
        public string TcpDynamicPorts { get; set; } = string.Empty;
        public bool TcpEnabled { get; set; }
        public bool TcpKeyPresent { get; set; }
        public int LoginMode { get; set; }
    }

    internal static class SqlServerRegistryReader
    {
        private const string InstanceNamesPath = @"SOFTWARE\Microsoft\Microsoft SQL Server\Instance Names\SQL";
        private const string ProductRootPath = @"SOFTWARE\Microsoft\Microsoft SQL Server";

        /// <summary>
        /// Reads every installed database engine instance, keyed by instance name.
        /// Returns an empty map when SQL Server is not installed or the registry is
        /// unreadable; callers fall back to service evidence alone.
        /// </summary>
        public static Dictionary<string, SqlInstanceRegistryInfo> Read(CancellationToken cancellationToken)
        {
            var results = new Dictionary<string, SqlInstanceRegistryInfo>(StringComparer.OrdinalIgnoreCase);
            ReadView(results, RegistryView.Registry64, cancellationToken);
            if (results.Count == 0)
            {
                ReadView(results, RegistryView.Registry32, cancellationToken);
            }

            return results;
        }

        private static void ReadView(
            Dictionary<string, SqlInstanceRegistryInfo> results,
            RegistryView view,
            CancellationToken cancellationToken)
        {
            try
            {
                using (var baseKey = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view))
                using (var instanceNames = baseKey.OpenSubKey(InstanceNamesPath))
                {
                    if (instanceNames == null)
                    {
                        return;
                    }

                    foreach (var instance in instanceNames.GetValueNames())
                    {
                        cancellationToken.ThrowIfCancellationRequested();
                        if (string.IsNullOrWhiteSpace(instance) || results.ContainsKey(instance))
                        {
                            continue;
                        }

                        var keySuffix = instanceNames.GetValue(instance) as string;
                        if (string.IsNullOrWhiteSpace(keySuffix))
                        {
                            continue;
                        }

                        results[instance] = ReadInstance(baseKey, instance, keySuffix);
                    }
                }
            }
            catch (SecurityException)
            {
            }
            catch (UnauthorizedAccessException)
            {
            }
            catch (System.IO.IOException)
            {
            }
        }

        private static SqlInstanceRegistryInfo ReadInstance(RegistryKey baseKey, string instance, string keySuffix)
        {
            var info = new SqlInstanceRegistryInfo
            {
                Instance = instance,
                RegistryKey = keySuffix
            };

            var instanceRoot = ProductRootPath + "\\" + keySuffix;

            using (var setup = baseKey.OpenSubKey(instanceRoot + @"\Setup"))
            {
                if (setup != null)
                {
                    info.Edition = Text(setup, "Edition");
                    info.PatchLevel = Text(setup, "PatchLevel");
                    info.ProductVersion = Text(setup, "Version");
                    info.Collation = Text(setup, "Collation");
                    info.DataRoot = Text(setup, "SQLDataRoot");
                }
            }

            using (var server = baseKey.OpenSubKey(instanceRoot + @"\MSSQLServer"))
            {
                if (server != null)
                {
                    info.LoginMode = Number(server, "LoginMode");
                }
            }

            using (var tcp = baseKey.OpenSubKey(instanceRoot + @"\MSSQLServer\SuperSocketNetLib\Tcp"))
            {
                if (tcp != null)
                {
                    info.TcpKeyPresent = true;
                    info.TcpEnabled = Number(tcp, "Enabled") != 0;
                }
            }

            // IPAll carries the effective endpoint; SQL Server writes the live
            // dynamic port back here, which is how a named instance's real port
            // becomes readable without querying the engine.
            using (var ipAll = baseKey.OpenSubKey(instanceRoot + @"\MSSQLServer\SuperSocketNetLib\Tcp\IPAll"))
            {
                if (ipAll != null)
                {
                    info.TcpPort = Text(ipAll, "TcpPort");
                    info.TcpDynamicPorts = Text(ipAll, "TcpDynamicPorts");
                }
            }

            return info;
        }

        private static string Text(RegistryKey key, string name)
        {
            try
            {
                var value = key.GetValue(name);
                return value == null ? string.Empty : Convert.ToString(value, CultureInfo.InvariantCulture).Trim();
            }
            catch (SecurityException)
            {
                return string.Empty;
            }
            catch (UnauthorizedAccessException)
            {
                return string.Empty;
            }
        }

        private static int Number(RegistryKey key, string name)
        {
            try
            {
                var value = key.GetValue(name);
                if (value == null)
                {
                    return 0;
                }

                int parsed;
                return int.TryParse(
                    Convert.ToString(value, CultureInfo.InvariantCulture),
                    NumberStyles.Integer,
                    CultureInfo.InvariantCulture,
                    out parsed)
                    ? parsed
                    : 0;
            }
            catch (SecurityException)
            {
                return 0;
            }
            catch (UnauthorizedAccessException)
            {
                return 0;
            }
        }
    }
}
