using System;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class HorizonPoolHealthInput
    {
        public bool Enabled { get; set; } = true;
        public bool InventoryComplete { get; set; } = true;
        public int MachinesTotal { get; set; }
        public int SpareTotal { get; set; }
        public int SpareReady { get; set; }
        public int SpareUnready { get; set; }
        public int WarningUnreadyPercent { get; set; } = 50;
        public int CriticalUnreadyPercent { get; set; } = 90;
        public int MinimumSpareSample { get; set; } = 2;
    }

    public sealed class HorizonPoolHealthResult
    {
        public string State { get; set; }
        public string Reason { get; set; }
        public decimal UnreadyPercent { get; set; }
    }

    public static class HorizonPoolHealth
    {
        public static HorizonPoolHealthResult Evaluate(HorizonPoolHealthInput input)
        {
            if (input == null) throw new ArgumentNullException(nameof(input));
            if (!input.Enabled) return Result("disabled", "pool_disabled", 0);
            if (!input.InventoryComplete) return Result("incomplete", "inventory_truncated_or_unavailable", 0);
            if (input.MachinesTotal == 0) return Result("incomplete", "no_machine_inventory", 0);
            if (input.SpareTotal == 0) return Result("warning", "no_unused_capacity", 0);

            var percent = Math.Round((Math.Max(0, input.SpareUnready) * 100m) / Math.Max(1, input.SpareTotal), 1);
            if (input.SpareReady == 0) return Result("critical", "no_ready_spares", percent);
            if (input.SpareUnready >= 2) return Result("warning", "multiple_unavailable_spares", percent);
            if (input.SpareUnready == 1) return Result("info", "one_unavailable_capacity_remains", percent);
            return Result("ok", "ready_capacity_available", percent);
        }

        private static HorizonPoolHealthResult Result(string state, string reason, decimal percent)
        {
            return new HorizonPoolHealthResult { State = state, Reason = reason, UnreadyPercent = percent };
        }
    }
}
