<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'competition', 'starts_at', 'ends_at', 'status', 'result',
        'home_team', 'away_team', 'home_odds', 'draw_odds', 'away_odds',
        'external_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'home_odds' => 'decimal:2',
        'draw_odds' => 'decimal:2',
        'away_odds' => 'decimal:2',
    ];

    public function bets()
    {
        return $this->hasMany(Bet::class);
    }
}
