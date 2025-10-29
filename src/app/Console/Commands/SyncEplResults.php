<?php

namespace App\Console\Commands;

use App\Models\Bet;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class SyncEplResults extends Command
{
    protected $signature = 'epl:sync-results {--window=48 : Time window in hours for matching events}';
    protected $description = 'Sync finished EPL match results from TheSportsDB and settle bets';

    public function handle()
    {
        $this->info('Syncing EPL finished results...');
        try {
            $resp = Http::get('https://www.thesportsdb.com/api/json/3/eventspastleague.php', [
                'id' => 4328,
            ]);
            if ($resp->failed()) {
                $this->error('Failed to fetch results: '.$resp->body());
                return self::FAILURE;
            }
            $data = $resp->json();
            $eventsApi = collect($data['events'] ?? []);
            $windowHours = (int)$this->option('window');

            $updated = 0;
            foreach ($eventsApi as $apiEv) {
                $home = strtolower(trim($apiEv['strHomeTeam'] ?? ''));
                $away = strtolower(trim($apiEv['strAwayTeam'] ?? ''));
                $homeScore = is_numeric($apiEv['intHomeScore'] ?? null) ? (int)$apiEv['intHomeScore'] : null;
                $awayScore = is_numeric($apiEv['intAwayScore'] ?? null) ? (int)$apiEv['intAwayScore'] : null;
                $ts = $apiEv['strTimestamp'] ?? ($apiEv['dateEvent'] ?? null);
                $apiTime = $ts ? Carbon::parse($ts) : null;

                if (!$home || !$away || $homeScore === null || $awayScore === null || !$apiTime || $apiTime->isFuture()) continue;

                $candidates = Event::query()
                    ->whereRaw('LOWER(home_team) = ?', [$home])
                    ->whereRaw('LOWER(away_team) = ?', [$away])
                    ->get();
                if ($candidates->isEmpty()) continue;

                $ev = $candidates
                    ->filter(fn($e) => $e->starts_at !== null)
                    ->map(function($e) use ($apiTime){
                        $e->diffMin = abs($e->starts_at->diffInMinutes($apiTime));
                        return $e;
                    })
                    ->sortBy('diffMin')
                    ->first();
                if (!$ev) continue;
                if ($ev->starts_at->isFuture()) continue;
                if ($ev->diffMin > $windowHours*60) continue;

                $result = 'draw';
                if ($homeScore > $awayScore) $result = 'home';
                elseif ($awayScore > $homeScore) $result = 'away';

                if ($ev->status !== 'finished' || $ev->result !== $result) {
                    $ev->status = 'finished';
                    $ev->result = $result;
                    $ev->ends_at = $apiTime ?: now();
                    $ev->save();

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
                    $this->line("Updated: {$ev->title} => {$result}");
                }
            }

            $this->info('Results sync complete. Updated: '.$updated);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}