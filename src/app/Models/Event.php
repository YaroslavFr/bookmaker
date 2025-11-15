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
        'external_id', 'home_result', 'away_result', 'home_ht_result', 'away_ht_result', 'home_st2_result', 'away_st2_result', 'result_text',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'home_odds' => 'decimal:2',
        'draw_odds' => 'decimal:2',
        'away_odds' => 'decimal:2',
        'home_result' => 'integer',
        'away_result' => 'integer',
        'home_ht_result' => 'integer',
        'away_ht_result' => 'integer',
        'home_st2_result' => 'integer',
        'away_st2_result' => 'integer',
    ];

    public function bets()
    {
        return $this->hasMany(Bet::class);
    }
}
