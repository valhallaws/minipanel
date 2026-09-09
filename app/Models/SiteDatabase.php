<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteDatabase extends Model
{
    protected $fillable = ['site_id', 'name', 'collation', 'status'];
}
