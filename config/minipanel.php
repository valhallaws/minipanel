<?php

return [
    // Keep false on local development. Only enable on the intended VPS after
    // installing and reviewing the root-owned MiniPanel agent.
    'execution_enabled' => env('MINIPANEL_EXECUTION_ENABLED', false),

    'panel_update_webhook_secret' => env('PANEL_UPDATE_WEBHOOK_SECRET'),

    'panel_update_branch' => env('PANEL_UPDATE_BRANCH', 'main'),

    'file_transfer_max_kb' => (int) env('MINIPANEL_FILE_TRANSFER_MAX_KB', 2097152),

    'download_path' => env('MINIPANEL_DOWNLOAD_PATH', '/var/lib/minipanel/downloads'),
];
