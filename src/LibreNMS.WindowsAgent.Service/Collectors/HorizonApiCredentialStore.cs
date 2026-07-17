using System;
using System.IO;
using System.Security.AccessControl;
using System.Security.Cryptography;
using System.Security.Principal;
using System.Text;
using System.Web.Script.Serialization;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class HorizonApiCredential
    {
        public string Username { get; set; } = string.Empty;
        public string Domain { get; set; } = string.Empty;
        public string Password { get; set; } = string.Empty;

        public static HorizonApiCredential Create(string username, string domain, string value)
        {
            return new HorizonApiCredential { Username = username, Domain = domain, Password = value };
        }
    }

    internal static class HorizonApiCredentialStore
    {
        private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("LibreNMS.WindowsAgent.HorizonApi.v1");

        public static HorizonApiCredential Read(string path)
        {
            var resolved = ResolvePath(path);
            var protectedBytes = File.ReadAllBytes(resolved);
            var clearBytes = ProtectedData.Unprotect(protectedBytes, Entropy, DataProtectionScope.LocalMachine);
            try
            {
                var json = Encoding.UTF8.GetString(clearBytes);
                var credential = new JavaScriptSerializer().Deserialize<HorizonApiCredential>(json);
                if (credential == null || string.IsNullOrWhiteSpace(credential.Username) || string.IsNullOrEmpty(credential.Password))
                {
                    throw new InvalidDataException("The Horizon API credential file is incomplete.");
                }
                return credential;
            }
            finally
            {
                Array.Clear(clearBytes, 0, clearBytes.Length);
            }
        }

        public static string Write(string path, HorizonApiCredential credential)
        {
            if (credential == null || string.IsNullOrWhiteSpace(credential.Username) || string.IsNullOrEmpty(credential.Password))
            {
                throw new ArgumentException("A username and password are required.", nameof(credential));
            }

            var resolved = ResolvePath(path);
            var directory = Path.GetDirectoryName(resolved);
            if (!string.IsNullOrWhiteSpace(directory))
            {
                Directory.CreateDirectory(directory);
            }

            var json = new JavaScriptSerializer().Serialize(credential);
            var clearBytes = Encoding.UTF8.GetBytes(json);
            try
            {
                var protectedBytes = ProtectedData.Protect(clearBytes, Entropy, DataProtectionScope.LocalMachine);
                using (var stream = new FileStream(
                    resolved,
                    FileMode.Create,
                    FileSystemRights.Write,
                    FileShare.None,
                    4096,
                    FileOptions.WriteThrough,
                    RestrictedAcl()))
                {
                    stream.Write(protectedBytes, 0, protectedBytes.Length);
                    stream.Flush();
                }
            }
            finally
            {
                Array.Clear(clearBytes, 0, clearBytes.Length);
            }

            return resolved;
        }

        public static string ResolvePath(string path)
        {
            return Environment.ExpandEnvironmentVariables(path ?? string.Empty);
        }

        private static FileSecurity RestrictedAcl()
        {
            var security = new FileSecurity();
            security.SetAccessRuleProtection(true, false);
            security.AddAccessRule(new FileSystemAccessRule(
                new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null),
                FileSystemRights.FullControl,
                AccessControlType.Allow));
            security.AddAccessRule(new FileSystemAccessRule(
                new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid, null),
                FileSystemRights.FullControl,
                AccessControlType.Allow));
            return security;
        }
    }
}
