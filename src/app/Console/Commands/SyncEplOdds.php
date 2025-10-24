<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncEplOdds extends Command
{
    protected $signature = 'epl:sync-odds {--limit=10}';
    protected $description = 'Sync upcoming EPL matches and odds from open APIs';

    public function handle()
    {
        $this->info('Syncing EPL teams and odds...');

        $oddsApiKey = config('services.odds_api.key');
        if (!$oddsApiKey) {
            $this->warn('ODDS_API_KEY is not set. Set services.odds_api.key or ODDS_API_KEY in .env.');
        }

        $limit = (int) $this->option('limit');

        // Source 1: TheSportsDB teams (no key required for demo key=3)
        $teams = $this->fetchTeams();
        $this->info('Teams fetched: '.count($teams));

        // Source 2: The Odds API for upcoming EPL matches (requires key)
        $matches = [];
        if ($oddsApiKey) {
            $matches = $this->fetchOddsMatches($oddsApiKey, $limit);
            $this->info('Matches with odds fetched: '.count($matches));
        } else {
            $this->warn('Skipping odds fetch due to missing ODDS_API_KEY.');
        }

        // Persist events based on odds feed; if not available, create a few pairings from teams
        if (!empty($matches)) {
            foreach ($matches as $m) {
                $title = $m['home_team'].' vs '.$m['away_team'];
                $event = Event::updateOrCreate(
                    ['title' => $title, 'starts_at' => $m['commence_time']],
                    [
                        'home_team' => $m['home_team'],
                        'away_team' => $m['away_team'],
                        'status' => 'scheduled',
                        'home_odds' => $m['home_odds'],
                        'draw_odds' => $m['draw_odds'],
                        'away_odds' => $m['away_odds'],
                    ]
                );
                $this->line("Upserted: {$event->title} ({$event->home_odds}/{$event->draw_odds}/{$event->away_odds})");
            }
        } else {
            // Fallback: Pair first N teams
            $pairs = [];
            for ($i = 0; $i+1 < min(count($teams), $limit*2); $i+=2) {
                $pairs[] = [$teams[$i], $teams[$i+1]];
            }
            foreach ($pairs as [$home, $away]) {
                $title = $home.' vs '.$away;
                Event::firstOrCreate([
                    'title' => $title,
                ], [
                    'home_team' => $home,
                    'away_team' => $away,
                    'status' => 'scheduled',
                    'starts_at' => now()->addDays(rand(1,7)),
                    'home_odds' => 2.00,
                    'draw_odds' => 3.40,
                    'away_odds' => 3.60,
                ]);
                $this->line("Seeded fallback: {$title}");
            }
        }

        $this->info('EPL sync complete.');
        return self::SUCCESS;
    }

    private function fetchTeams(): array
    {
        try {
            $resp = Http::get('https://www.thesportsdb.com/api/v1/json/3/search_all_teams.php', [
                'l' => 'English Premier League',
            ]);
            if ($resp->failed()) return [];
            $data = $resp->json();
            $teams = collect($data['teams'] ?? [])
                ->pluck('strTeam')
                ->filter()
                ->map(fn($t) => Str::of($t)->trim()->toString())
                ->values()
                ->all();
            return $teams;
        } catch (\Throwable $e) {
            $this->error('Failed to fetch teams: '.$e->getMessage());
            return [];
        }
    }

    private function fetchOddsMatches(string $apiKey, int $limit): array
    {
        try {
            $resp = Http::get('https://api.the-odds-api.com/v4/sports/soccer_epl/odds', [
                'regions' => 'uk,eu',
                'markets' => 'h2h',
                'dateFormat' => 'iso',
                'oddsFormat' => 'decimal',
                'apiKey' => $apiKey,
            ]);
            if ($resp->failed()) {
                $this->error('Odds fetch failed: '.$resp->body());
                return [];
            }
            $events = $resp->json();
            $out = [];
            foreach (array_slice($events, 0, $limit) as $ev) {
                $home = $ev['home_team'] ?? null;
                $away = $ev['away_team'] ?? null;
                $commence = $ev['commence_time'] ?? null;
                // Aggregate odds across bookmakers (take average)
                $homeOdds = $drawOdds = $awayOdds = null;
                $h2hLists = collect($ev['bookmakers'] ?? [])
                    ->flatMap(fn($b) => collect($b['markets'] ?? [])->where('key', 'h2h')->pluck('outcomes'));
                if ($h2hLists->isNotEmpty()) {
                    $homeVals = [];$drawVals = [];$awayVals = [];
                    foreach ($h2hLists as $outcomes) {
                        foreach ($outcomes as $o) {
                            $name = $o['name']; $price = $o['price'];
                            if ($name === $home) $homeVals[] = $price;
                            elseif ($name === $away) $awayVals[] = $price;
                            elseif (strtolower($name) === 'draw') $drawVals[] = $price;
                        }
                    }
                    $homeOdds = !empty($homeVals) ? array_sum($homeVals)/count($homeVals) : null;
                    $drawOdds = !empty($drawVals) ? array_sum($drawVals)/count($drawVals) : null;
                    $awayOdds = !empty($awayVals) ? array_sum($awayVals)/count($awayVals) : null;
                }
                if ($home && $away) {
                    $out[] = [
                        'home_team' => $home,
                        'away_team' => $away,
                        'commence_time' => $commence ? \Carbon\Carbon::parse($commence) : now()->addDays(1),
                        'home_odds' => $homeOdds,
                        'draw_odds' => $drawOdds,
                        'away_odds' => $awayOdds,
                    ];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            $this->error('Failed to fetch odds: '.$e->getMessage());
            return [];
        }
    }
}
