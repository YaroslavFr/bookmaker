<?php

namespace App\Http\Controllers;

use App\Models\Bet;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

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

    public function syncResults()
    {
        try {
            $resp = Http::get('https://www.thesportsdb.com/api/v1/json/3/eventspastleague.php', [
                'id' => 4328,
            ]);
            if ($resp->failed()) {
                return redirect()->route('home')->with('status', 'Не удалось получить результаты из API');
            }
            $data = $resp->json();
            $eventsApi = collect($data['events'] ?? []);
    
            $updated = 0;
            foreach ($eventsApi as $apiEv) {
                $home = strtolower(trim($apiEv['strHomeTeam'] ?? ''));
                $away = strtolower(trim($apiEv['strAwayTeam'] ?? ''));
                $homeScore = is_numeric($apiEv['intHomeScore'] ?? null) ? (int)$apiEv['intHomeScore'] : null;
                $awayScore = is_numeric($apiEv['intAwayScore'] ?? null) ? (int)$apiEv['intAwayScore'] : null;
                $ts = $apiEv['strTimestamp'] ?? ($apiEv['dateEvent'] ?? null);
                $apiTime = $ts ? Carbon::parse($ts) : null;
    
                // Требуем валидные счёты и только прошедшие матчи
                if (!$home || !$away || $homeScore === null || $awayScore === null || !$apiTime || $apiTime->isFuture()) continue;
    
                // Найдём локальное событие: те же команды, ближайшая дата к apiTime, в разумном окне (±48ч)
                $candidates = Event::query()
                    ->whereRaw('LOWER(home_team) = ?', [$home])
                    ->whereRaw('LOWER(away_team) = ?', [$away])
                    ->get();
    
                if ($candidates->isEmpty()) continue;
    
                $ev = $candidates
                    ->filter(fn($e) => $e->starts_at !== null)
                    ->map(function($e) use ($apiTime){
                        $diffMin = abs($e->starts_at->diffInMinutes($apiTime));
                        $e->diffMin = $diffMin;
                        return $e;
                    })
                    ->sortBy('diffMin')
                    ->first();
    
                if (!$ev) continue;
    
                // Избежать преждевременного расчёта: событие должно быть в прошлом и дата близка к API
                if ($ev->starts_at->isFuture()) continue;
                if ($ev->diffMin > 48*60) continue; // слишком далеко по времени — вероятно другой матч/сезон
    
                // Определяем результат
                $result = 'draw';
                if ($homeScore > $awayScore) $result = 'home';
                elseif ($awayScore > $homeScore) $result = 'away';
    
                // Обновляем только если отличается
                if ($ev->status !== 'finished' || $ev->result !== $result) {
                    $ev->status = 'finished';
                    $ev->result = $result;
                    $ev->ends_at = $apiTime ?: now();
                    $ev->save();
    
                    // Рассчитываем ставки
                    $ev->bets()->each(function(Bet $bet) use ($ev) {
                        $win = $bet->selection === $ev->result;
                        $odds = match ($bet->selection) {
                            'home' => $ev->home_odds,
                            'draw' => $ev->draw_odds,
                            'away' => $ev->away_odds,
                        };
                        $bet->is_win = $win;
                        $bet->payout_demo = $win ? ($bet->amount_demo * ($odds ?? 2)) : 0;
                        $bet->settled_at = now();
                        $bet->save();
                    });
                    $updated++;
                }
            }
    
            return redirect()->route('home')->with('status', "Синхронизировано результатов: {$updated}");
        } catch (\Throwable $e) {
            return redirect()->route('home')->with('status', 'Ошибка API: '.$e->getMessage());
        }
    }
}
