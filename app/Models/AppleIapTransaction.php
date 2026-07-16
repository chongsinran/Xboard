<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppleIapTransaction extends Model
{
    protected $table = 'v2_apple_iap_transaction';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'apple_payload' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
