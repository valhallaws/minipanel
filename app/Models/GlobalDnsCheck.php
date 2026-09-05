<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalDnsCheck extends Model
{
    protected $fillable = ['domain', 'record_type', 'expected_target', 'status', 'results', 'finished_at'];

    protected function casts(): array
    {
        return ['results' => 'array', 'finished_at' => 'datetime'];
    }
}
