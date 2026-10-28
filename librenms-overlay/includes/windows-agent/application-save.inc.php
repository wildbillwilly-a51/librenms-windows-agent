<?php

namespace WindowsAgentOverlay;

use App\Models\Application;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/horizon-projection.inc.php';
require_once __DIR__ . '/sql-evaluation.inc.php';

final class ApplicationSave
{
    public static function save(int $appId, array $incoming, string $response, array $fields): void
    {
        // Match the central publisher's lock order. No collection or RRD I/O here.
        DB::transaction(static function () use ($appId, $incoming, $response, $fields): void {
            $locked = Application::query()->whereKey($appId)->lockForUpdate()->first();
            if (! $locked) return; // Concurrent app deletion must not recreate it.

            $latest = is_array($locked->data) ? $locked->data : [];
            $merged = array_replace($latest, $incoming);
            $latestCentral = ($latest['horizon_central_meta']['source'] ?? '') === 'central';
            $copiedCentral = ($incoming['horizon_central_meta']['source'] ?? '') === 'central';
            if ($latestCentral || $copiedCentral) {
                // The early poll snapshot is never authoritative for central-owned keys.
                foreach (HorizonProjection::DATA_KEYS as $key) {
                    $merged[$key] = is_array($latest[$key] ?? null) ? $latest[$key] : [];
                }
                foreach (array_keys($fields) as $name) {
                    if (HorizonProjection::ownsMetric($name)) unset($fields[$name]);
                }
                // Include central-only health/vendor fields; core deletes omitted metrics.
                $fields = array_merge($fields, HorizonProjection::pollerMetrics($merged, $latestCentral));
            }
            // Bound repeated cached observations by their first accepted expiry.
            $view = SqlEvaluation::evaluate(
                $merged['sql_server_summary'] ?? [], $merged['sql_server_instances'] ?? [],
                $merged['sql_server_components'] ?? [], $merged['sql_server_performance'] ?? [],
                $merged['collector_timings'] ?? [], (string) ($merged['last_agent_utc'] ?? ''),
                SqlEvaluation::now(),
                is_array($latest['sql_server_qualification'] ?? null) ? $latest['sql_server_qualification'] : []
            );
            $merged['sql_server_qualification'] = SqlEvaluation::mapping($view);
            $fields = array_merge($fields, SqlEvaluation::metrics($view));
            $locked->data = $merged;
            update_application($locked, $response, $fields, $response);
        });
    }
}
