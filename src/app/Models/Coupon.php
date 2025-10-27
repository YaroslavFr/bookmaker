<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'bettor_name', 'amount_demo', 'total_odds', 'is_win', 'payout_demo', 'settled_at',
    ];

    protected $casts = [
        'amount_demo' => 'decimal:2',
        'total_odds' => 'decimal:2',
        'payout_demo' => 'decimal:2',
        'settled_at' => 'datetime',
        'is_win' => 'boolean',
    ];

    public function bets()
    {
        return $this->hasMany(Bet::class);
    }
}