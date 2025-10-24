<?php

namespace App\Http\Controllers;

use App\Models\Bet;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BetController extends Controller
{
    public function index()
    {
        // Seed a few demo events if none exist
        if (Event::count() === 0) {
            Event::create([
                'title' => 'Arsenal vs Chelsea',
                'home_team' => 'Arsenal',
                'away_team' => 'Chelsea',
                'status' => 'scheduled',
                'starts_at' => now()->addDays(1),
                'home_odds' => 1.85,
                'draw_odds' => 3.60,
                'away_odds' => 4.20,
            ]);
            Event::create([
                'title' => 'Liverpool vs Manchester City',
                'home_team' => 'Liverpool',
                'away_team' => 'Manchester City',
                'status' => 'scheduled',
                'starts_at' => now()->addDays(2),
                'home_odds' => 2.40,
                'draw_odds' => 3.50,
                'away_odds' => 2.80,
            ]);
        }

        $events = Event::with('bets')->latest()->get();
        $bets = Bet::with('event')->latest()->limit(50)->get();

        return view('home', compact('events', 'bets'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'event_id' => ['required', 'exists:events,id'],
            'bettor_name' => ['required', 'string', 'max:100'],
            'amount_demo' => ['required', 'numeric', 'min:0.01'],
            'selection' => ['required', 'in:home,draw,away'],
        ]);

        Bet::create($data);

        return redirect()->route('home')->with('status', 'Ставка создана');
    }

    public function settle(Event $event, Request $request)
    {
        $payload = $request->validate([
            'result' => ['required', 'in:home,draw,away'],
        ]);

        $event->update([
            'status' => 'finished',
            'result' => $payload['result'],
            'ends_at' => now(),
        ]);

        // Payout using event odds: payout = amount_demo * selected_odds
        $event->bets()->each(function (Bet $bet) use ($event) {
            $win = $bet->selection === $event->result;
            $odds = match ($bet->selection) {
                'home' => $event->home_odds,
                'draw' => $event->draw_odds,
                'away' => $event->away_odds,
            };
            $bet->update([
                'is_win' => $win,
                'payout_demo' => $win ? ($bet->amount_demo * ($odds ?? 2)) : 0,
                'settled_at' => now(),
            ]);
        });

        return redirect()->route('home')->with('status', 'Событие рассчитано');
    }
}
