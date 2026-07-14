# Windows Agent Alert Rules

These alert rules are optional. Install the LibreNMS overlay first, confirm the
`windows-agent` application metrics are present, then create equivalent
LibreNMS alert rules in the target instance.

Recommended initial rules:

| Rule | Severity | Purpose |
| --- | --- | --- |
| `application_metrics.metric = "agent_up" and application_metrics.value != 1` | critical | Agent is missing or unreachable. |
| `application_metrics.metric = "pending_reboot" and application_metrics.value = 1` | warning | Windows reports a pending reboot. |
| `application_metrics.metric = "watched_services_not_running" and application_metrics.value > 0` | warning | One or more watched Windows services are not running. |
| `application_metrics.metric = "windows_update_reboot_required" and application_metrics.value = 1` | warning | Windows Update reports a required reboot. |

Keep these rules opt-in for production. Some sites may already have separate
reboot or service-health policy, and the first rollout should avoid duplicate
notifications.
