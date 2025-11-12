<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bet extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id', 'bettor_name', 'amount_demo', 'selection', 'placed_odds', 'is_win', 'payout_demo', 'settled_at', 'coupon_id'
    ];

    protected $casts = [
        'amount_demo' => 'decimal:2',
        'placed_odds' => 'decimal:2',
        'payout_demo' => 'decimal:2',
        'settled_at' => 'datetime',
        'is_win' => 'boolean'
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }
}
