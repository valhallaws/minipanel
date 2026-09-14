<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerDatabaseUser extends Model
{
    protected $fillable = ['name', 'password', 'status'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }
}
