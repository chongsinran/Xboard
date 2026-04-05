<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDevice extends Model
{
    protected $table = 'v2_user_device';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'first_seen_at' => 'timestamp',
        'last_seen_at' => 'timestamp',
        'is_registration_device' => 'boolean',
    ];
}
