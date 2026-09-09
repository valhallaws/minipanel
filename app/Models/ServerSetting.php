<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerSetting extends Model
{
    protected $fillable = ['panel_domain', 'panel_path', 'panel_user', 'panel_php_version', 'timezone', 'public_ip', 'db_host', 'db_port', 'db_username', 'db_password', 'applied_at'];

    protected function casts(): array
    {
        return ['db_password' => 'encrypted', 'applied_at' => 'datetime'];
    }
}
