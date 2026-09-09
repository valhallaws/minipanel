<?php

namespace App\Models;

use Database\Factories\DnsSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DnsSetting extends Model
{
    /** @use HasFactory<DnsSettingFactory> */
    use HasFactory;

    protected $fillable = ['infrastructure_domain', 'nameservers'];

    protected function casts(): array
    {
        return ['nameservers' => 'array'];
    }

    public function hasCompleteConfiguration(): bool
    {
        return filled($this->infrastructure_domain)
            && count($this->nameservers ?? []) === 2
            && collect($this->nameservers)->every(fn (array $server) => filled($server['hostname'] ?? null) && filled($server['ip'] ?? null));
    }
}
