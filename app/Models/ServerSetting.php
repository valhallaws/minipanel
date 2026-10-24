<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerSetting extends Model
{
    protected $fillable = ['panel_domain', 'panel_path', 'panel_user', 'panel_php_version', 'timezone', 'public_ip', 'db_host', 'db_port', 'db_username', 'db_password', 'applied_at', 'telegram_enabled', 'telegram_bot_token', 'telegram_chat_id'];

    protected $hidden = ['db_password', 'telegram_bot_token'];

    protected function casts(): array
    {
        return [
            'db_password' => 'encrypted',
            'applied_at' => 'datetime',
            'telegram_enabled' => 'boolean',
            'telegram_bot_token' => 'encrypted',
        ];
    }
}
