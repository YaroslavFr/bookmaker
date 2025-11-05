<?php

namespace App\Console\Commands;

use App\Models\Bet;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class SyncEplResults extends Command
{
    protected $signature = 'epl:sync-results {--window=48 : Time window in hours for matching events} {--debug : Output API and matching debug info}';
    protected $description = 'Sync finished EPL match results from sstats.net and settle bets';

    public function handle()
    {
        $this->info('Syncing EPL finished results from sstats.net...');
        try {
            // Получаем завершённые матчи EPL из sstats с несколькими попытками
            $debug = (bool) $this->option('debug');
            $eventsApiRaw = $this->fetchFinishedGamesFromSstats($debug);
            $eventsApi = collect($eventsApiRaw);
            if ($debug) {
                $this->line('[DEBUG] API games count: '.count($eventsApiRaw));
                $sample = array_slice($eventsApiRaw, 0, 3);
                $this->line('[DEBUG] API sample (truncated): '. $this->truncateJson($sample, 1800));
            }
            $windowHours = (int)$this->option('window');

            $updated = 0;
            foreach ($eventsApi as $apiEv) {
                // Имена команд — пробуем несколько ключей
                $homeName = data_get($apiEv, 'homeTeam.name');
                $awayName = data_get($apiEv, 'awayTeam.name');
                $home = strtolower(trim(is_string($homeName) ? $homeName : ($apiEv['home'] ?? ($apiEv['Home'] ?? ''))));
                $away = strtolower(trim(is_string($awayName) ? $awayName : ($apiEv['away'] ?? ($apiEv['Away'] ?? ''))));

                // Счёт: пробуем извлечь из разных возможных ключей
                $homeScore = data_get($apiEv, 'score.home') ?? data_get($apiEv, 'scores.home') ?? ($apiEv['homeScore'] ?? ($apiEv['HomeScore'] ?? null));
                $awayScore = data_get($apiEv, 'score.away') ?? data_get($apiEv, 'scores.away') ?? ($apiEv['awayScore'] ?? ($apiEv['AwayScore'] ?? null));
                $homeScore = is_numeric($homeScore) ? (int)$homeScore : null;
                $awayScore = is_numeric($awayScore) ? (int)$awayScore : null;

                // Время окончания/проведения матча
                $ts = $apiEv['end'] ?? ($apiEv['datetime'] ?? ($apiEv['date'] ?? null));
                if (!$ts) { $ts = data_get($apiEv, 'game.time') ?? data_get($apiEv, 'game.timestamp') ?? null; }
                $apiTime = $ts ? Carbon::parse($ts) : null;

                if ($debug) {
                    $this->line('[DEBUG][API] home='.$home.' away='.$away.' hs='.$homeScore.' as='.$awayScore.' time='.($apiTime? $apiTime->toDateTimeString(): 'null'));
                }

                if (!$home || !$away || $homeScore === null || $awayScore === null || !$apiTime) continue;

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
                if ($debug) {
                    $this->line('[DEBUG][Match] candidates='.count($candidates).'; chosen='.(isset($ev)? ($ev->title.' (diff '.$ev->diffMin.'m)') : 'none'));
                }
                if (!$ev) continue;
                if ($ev->starts_at->isFuture()) {
                    if ($debug) { $this->line('[DEBUG][Skip] event is in future: '.$ev->title); }
                    continue;
                }
                $windowMinutes = $windowHours * 60;
                if ($ev->diffMin > $windowMinutes) {
                    if ($debug) { $this->line('[DEBUG][Skip] diff beyond window: '.$ev->diffMin.'m > '.$windowMinutes.'m'); }
                    continue;
                }

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

            $this->info('Results sync complete (sstats). Updated: '.$updated);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error (sstats): '.$e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Получение завершённых матчей EPL из sstats с устойчивостью:
     * 1) Year=текущий, Ended=true
     * 2) Year=текущий, без Ended
     * 3) Year=текущий-1, Ended=true
     * 4) Year=текущий-1, без Ended
     */
    private function fetchFinishedGamesFromSstats(bool $debug = false): array
    {
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        $apiKey = config('services.sstats.key');
        if (!$apiKey) return [];
        $headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'];
        $leagueId = 39; $year = (int) date('Y');
        $url = $base.'/games/list';

        $attempts = [
            ['Year' => $year, 'Ended' => 'true'],
            ['Year' => $year],
            ['Year' => $year-1, 'Ended' => 'true'],
            ['Year' => $year-1],
        ];

        foreach ($attempts as $paramsExtra) {
            try {
                $params = ['LeagueId' => $leagueId, 'Limit' => 300] + $paramsExtra;
                $resp = Http::withHeaders($headers)->timeout(30)->get($url, $params);
                $json = $resp->ok() ? ($resp->json() ?? []) : [];
                if ($debug) {
                    $this->line('[DEBUG][HTTP] GET '.$url.' '.json_encode($params).' status='.$resp->status());
                    $this->line('[DEBUG][HTTP] Body (truncated): '.$this->truncateJson($json, 2000));
                }
                if (!$resp->ok()) {
                    continue;
                }
                // Универсально извлекаем массив игр
                $games = [];
                if (is_array($json)) {
                    if (isset($json[0])) {
                        $games = $json;
                    } elseif (isset($json['data'])) {
                        $data = $json['data'];
                        if (is_array($data)) {
                            $games = $data['games'] ?? $data['items'] ?? $data['list'] ?? $data['results'] ?? (isset($data[0]) ? $data : []);
                        }
                    } else {
                        $games = $json['games'] ?? $json['items'] ?? $json['list'] ?? $json['results'] ?? (isset($json[0]) ? $json : []);
                        if (!is_array($games)) { $games = []; }
                    }
                }
                if (!empty($games)) {
                    return $games;
                }
            } catch (\Throwable $e) {
                // Пробуем следующий вариант
                continue;
            }
        }
        return [];
    }

    private function truncateJson($data, int $limit = 1200): string
    {
        try {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($encoded === false) { $encoded = '<<non-json>>'; }
            if (strlen($encoded) > $limit) {
                return substr($encoded, 0, $limit).'...('.(strlen($encoded)-$limit).' chars truncated)';
            }
            return $encoded;
        } catch (\Throwable $e) {
            return '<<debug stringify error: '.$e->getMessage().'>>';
        }
    }
}