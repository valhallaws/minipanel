<?php

return [
    // Keep false on local development. Only enable on the intended VPS after
    // installing and reviewing the root-owned MiniPanel agent.
    'execution_enabled' => env('MINIPANEL_EXECUTION_ENABLED', false),

    'file_transfer_max_kb' => (int) env('MINIPANEL_FILE_TRANSFER_MAX_KB', 2097152),

    'download_path' => env('MINIPANEL_DOWNLOAD_PATH', '/var/lib/minipanel/downloads'),
];
