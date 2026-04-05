<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyActiveBundle extends Model
{
    protected $table = 'v2_daily_active_bundle';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'first_active_at' => 'timestamp',
        'last_active_at' => 'timestamp',
        'activity_date' => 'date',
    ];
}
