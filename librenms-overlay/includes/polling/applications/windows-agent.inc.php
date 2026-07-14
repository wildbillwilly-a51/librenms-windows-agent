<?php

use Illuminate\Support\Facades\Cache;

$cached_agent_data = Cache::driver('array')->get('agent_data');
if (empty($cached_agent_data['windows_agent'])) {
    echo 'Windows Agent: no unix-agent cache available';

    return;
}

$agent_data = $cached_agent_data;
include base_path('includes/polling/unix-agent/windows_agent.inc.php');
