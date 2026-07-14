using System.Collections.Generic;
using System.Threading;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal static class ServiceInventoryReader
    {
        public static List<ServiceInventoryRecord> Read(CancellationToken cancellationToken)
        {
            var services = new List<ServiceInventoryRecord>();
            foreach (var item in Wmi.Query("SELECT Name,DisplayName,State,StartMode,StartName,PathName FROM Win32_Service"))
            {
                cancellationToken.ThrowIfCancellationRequested();
                using (item)
                {
                    services.Add(new ServiceInventoryRecord
                    {
                        Name = Wmi.StringValue(item, "Name"),
                        DisplayName = Wmi.StringValue(item, "DisplayName"),
                        State = Wmi.StringValue(item, "State"),
                        StartMode = Wmi.StringValue(item, "StartMode"),
                        StartName = Wmi.StringValue(item, "StartName"),
                        PathName = Wmi.StringValue(item, "PathName"),
                    });
                }
            }

            return services;
        }
    }
}
