<?php

namespace App\Console\Commands;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log; // Import the Log facade


class SyncLeaguesUpcoming extends Command
{
    protected $signature = 'leagues:sync-upcoming {--limit=15} {--year=}';
    protected $description = 'Синхронизирует предстоящие матчи и коэффициенты по всем лигам из config(leagues)';

    public function handle()
    {
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        $apiKey = config('services.sstats.key');
        if (!$apiKey) {
            $this->error('SSTATS_API_KEY отсутствует');
            return self::FAILURE;
        }
        $headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'];
        $limit = (int) $this->option('limit');
        $yearOpt = $this->option('year');
        $year1 = $yearOpt ? (int) $yearOpt : 2024;
        $year2 = 2025;

        $leagues = (array) (config('leagues.leagues') ?? []);
        $updatedTotal = 0;
        foreach ($leagues as $code => $info) {
            $leagueId = (int) ($info['id'] ?? 0);
            // $this->info('Лига '.$code.' id='.$leagueId);
            if ($leagueId <= 0) { continue; }
            $updatedTotal += $this->syncLeague($base, $headers, $leagueId, (string)$code, $year1, $limit);
            $updatedTotal += $this->syncLeague($base, $headers, $leagueId, (string)$code, $year2, $limit);
        }

        Cache::put('leagues_sync_last_at', Carbon::now(), 3600);
        
        $this->info('Обновлено матчей: '.$updatedTotal);
        return self::SUCCESS;
    }

    private function syncLeague(string $base, array $headers, int $leagueId, string $competition, int $year, int $limit): int
    {
        try {
            $to = Carbon::now()->addDays(10)->format('Y-m-d');
            $params = [
                'LeagueId' => $leagueId,
                'Year' => $year,
                'Limit' => $limit,
                'Ended' => 'false',
                'Status' => 2,
                'TO' => $to,
            ];
            $resp = Http::withHeaders($headers)->timeout(30)->get($base.'/games/list', $params);
            $json = $resp->ok() ? ($resp->json() ?? []) : [];
            $games = [];
            if (isset($json['data']) || isset($json['Data'])) {
                $data = $json['data'] ?? $json['Data'];
                $games = is_array($data) ? ($data['games'] ?? (isset($data[0]) ? $data : [])) : [];
            } elseif (is_array($json)) {
                $games = $json['games'] ?? $json['Games'] ?? (isset($json[0]) ? $json : []);
            }
            $this->line('Выборка '.$competition.' '.$year.': игр='.count($games));

            $updated = 0;
            foreach ($games as $g) {
                $gameId = data_get($g, 'id') ?? data_get($g, 'gameId') ?? data_get($g, 'game.id');
                $homeName = data_get($g, 'homeTeam.name') ?? data_get($g, 'home');
                $awayName = data_get($g, 'awayTeam.name') ?? data_get($g, 'away');
                $startsRaw = data_get($g, 'commence') ?? data_get($g, 'commence_time') ?? data_get($g, 'date') ?? data_get($g, 'start');
                if (!$gameId || !$homeName || !$awayName || !$startsRaw) { continue; }
                $starts = Carbon::parse($startsRaw)->utc()->second(0)->micro(0);
                if ($starts->isPast()) { continue; }
                if ($starts->gt(Carbon::now()->addDays(10))) { continue; }
                
                [$homeOdds, $drawOdds, $awayOdds] = $this->parseOddsFromGame($g);
                if ($homeOdds === null && $drawOdds === null && $awayOdds === null) {
                    [$homeOdds, $drawOdds, $awayOdds] = $this->fetchOddsForGame($base, $headers, $gameId);
                }
                if ($homeOdds === null || $drawOdds === null || $awayOdds === null) { continue; }
                if (($homeOdds <= 0) || ($drawOdds <= 0) || ($awayOdds <= 0)) { continue; }
                
                Event::updateOrCreate(
                    ['external_id' => (string) $gameId],
                    [
                        'competition' => $competition,
                        'title' => trim($homeName.' vs '.$awayName),
                        'home_team' => (string) $homeName,
                        'away_team' => (string) $awayName,
                        'status' => 'scheduled',
                        'starts_at' => $starts,
                        'home_odds' => $homeOdds,
                        'draw_odds' => $drawOdds,
                        'away_odds' => $awayOdds,
                    ]
                );
                $updated++;
            }
            $this->info('Обновлено '.$competition.' '.$year.': '.$updated);
            return $updated;
        } catch (\Throwable $e) {
            $this->warn('Лига '.$competition.' ошибка: '.$e->getMessage());
            return 0;
        }
    }

    private function parseOddsFromGame(array $g): array
    {
        $homeVals = []; $drawVals = []; $awayVals = [];
        $oddsList = $g['odds'] ?? null;
        if (is_array($oddsList)) {
            foreach ($oddsList as $key => $mk) {
                if($key > 0) continue;
                $key = strtolower((string)($mk['key'] ?? ($mk['marketKey'] ?? '')));
                    foreach (($mk['outcomes'] ?? ($mk['odds'] ?? [])) as $o) {
                        $sel = strtolower((string)($o['name'] ?? ($o['selectionName'] ?? '')));
                        $selMb = function_exists('mb_strtolower') ? mb_strtolower($sel) : $sel;
                        $val = $o['value'] ?? ($o['odd'] ?? ($o['rate'] ?? null));
                        if ($val === null) continue;
                        if (str_contains($sel, 'home') || $sel === '1' || str_contains($sel, 'team1') || str_contains($selMb, 'п1')) { $homeVals[] = (float)$val; }
                        elseif (str_contains($sel, 'draw') || str_contains($sel, 'x') || str_contains($selMb, 'нич')) { $drawVals[] = (float)$val; }
                        elseif (str_contains($sel, 'away') || $sel === '2' || str_contains($sel, 'team2') || str_contains($selMb, 'п2')) { $awayVals[] = (float)$val; }
                    }
                }
        }
        return [$this->avg($homeVals), $this->avg($drawVals), $this->avg($awayVals)];
    }

    private function fetchOddsForGame(string $base, array $headers, $gameId): array
    {
        try {
            $resp = Http::withHeaders($headers)->timeout(20)->get($base.'/odds/list', ['GameId' => $gameId]);
            $json = $resp->ok() ? ($resp->json() ?? []) : [];
            $oddsBlocks = [];
            if (isset($json['data'])) {
                $data = $json['data'];
                $oddsBlocks = is_array($data) ? ($data['markets'] ?? $data['odds'] ?? (isset($data[0]) ? $data : [])) : [];
            } elseif (is_array($json)) {
                $oddsBlocks = $json['markets'] ?? $json['odds'] ?? (isset($json[0]) ? $json : []);
            }
            return $this->parseOddsFromGame(['odds' => $oddsBlocks]);
        } catch (\Throwable $e) {
            return [null, null, null];
        }
    }

    private function avg(array $vals): float
    {
        $vals = array_values(array_filter(array_map('floatval', $vals), fn($v) => is_finite($v)));
        return count($vals) ? round(array_sum($vals) / count($vals), 2) : 0.0;
    }
}