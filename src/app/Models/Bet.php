<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bet extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id', 'bettor_name', 'amount_demo', 'selection', 'is_win', 'payout_demo', 'settled_at',
    ];

    protected $casts = [
        'amount_demo' => 'decimal:2',
        'payout_demo' => 'decimal:2',
        'settled_at' => 'datetime',
        'is_win' => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
