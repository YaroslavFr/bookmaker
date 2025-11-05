<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;

class RefreshStatsCache extends Command
{
    protected $signature = 'stats:refresh {--year=} {--leagueid=*} {--base=}';
    protected $description = 'Refresh cached statistics data for leagues (last 120 days)';

    public function handle()
    {
        $preferredBase = rtrim(($this->option('base') ?: config('services.sstats.base_url', 'https://api.sstats.net')), '/');
        $baseCandidates = array_values(array_unique([
            $preferredBase,
            'https://sstats.net',
            'https://sstats.net/api',
            'https://api.sstats.net/api',
        ]));
        $apiKey = config('services.sstats.key');
        if (!$apiKey) {
            $this->error('SSTATS_API_KEY отсутствует. Установите ключ в .env (SSTATS_API_KEY) или services.sstats.key.');
            return self::FAILURE;
        }

        $headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'];
        $year = (int) ($this->option('year') ?? date('Y'));
        $optLeagues = (array) $this->option('leagueid');
        $idsToProcess = !empty($optLeagues) ? array_map('intval', $optLeagues) : [39,135,140,61,78,235];

        $this->info('Refreshing stats cache for year '.$year.'...');
        foreach ($idsToProcess as $leagueId) {
            try {
                $results = $this->fetchResults($headers, $baseCandidates, $year, $leagueId);
                $key = 'sstats:results_all:league:' . $leagueId . ':year:' . $year . ':days120';
                try {
                    Cache::put($key, $results, 24*60*60);
                } catch (\Throwable $e) {
                    // Fallback to file cache store when DB driver is not available
                    try { Cache::store('file')->put($key, $results, 24*60*60); }
                    catch (\Throwable $e2) { throw $e; }
                }
                $this->line("League {$leagueId}: cached ".count($results)." matches");
            } catch (\Throwable $e) {
                $this->warn("League {$leagueId}: failed to refresh — ".$e->getMessage());
            }
        }

        $this->info('Stats cache refresh complete.');
        return self::SUCCESS;
    }

    private function fetchResults(array $headers, array $baseCandidates, int $year, int $leagueId): array
    {
        $res = [];
        $paramsBase = ['LeagueId' => $leagueId, 'Year' => $year, 'Limit' => 500];
        $attemptParams = [
            $paramsBase + ['Ended' => 'true'],
            $paramsBase,
            ['LeagueId' => $leagueId, 'Year' => $year, 'Limit' => 500, 'Status' => 2],
            ['leagueid' => $leagueId, 'year' => $year, 'limit' => 500, 'ended' => true],
        ];
        $json = [];
        $resp = null;
        $pathCandidates = ['/games/list', '/Games/list', '/Games/List', '/v1/games/list'];
        foreach ($baseCandidates as $base) {
            foreach ($pathCandidates as $path) {
                $mainUrl = rtrim($base, '/').$path;
                foreach (array_merge($attemptParams, [['leagueid'=>$leagueId,'year'=>$year,'limit'=>500,'status'=>2]]) as $p) {
                    try {
                        $resp = Http::withHeaders($headers)->timeout(30)->get($mainUrl, $p);
                        if ($resp->ok()) { $json = $resp->json() ?? []; break 3; }
                    } catch (\Throwable $e) {
                        // try next base/path/params
                        continue;
                    }
                }
            }
        }
        $games = [];
        if (is_array($json)) {
            if (isset($json[0])) {
                $games = $json;
            } elseif (isset($json['data'])) {
                $data = $json['data'];
                if (is_array($data)) {
                    $games = $data['games'] ?? $data['items'] ?? $data['list'] ?? $data['results'] ?? $data['records'] ?? (isset($data[0]) ? $data : []);
                }
            } else {
                $games = $json['games'] ?? $json['Games'] ?? $json['items'] ?? $json['list'] ?? $json['results'] ?? $json['Records'] ?? $json['value'] ?? $json['Value'] ?? [];
                if (!is_array($games)) { $games = []; }
            }
        }
        // Если пусто — попробуем без Ended/Status на первом доступном базовом URL
        if ((is_array($games) ? count($games) : 0) === 0) {
            foreach ($baseCandidates as $base) {
                $mainUrl = rtrim($base, '/').'/games/list';
                try {
                    $respAll = Http::withHeaders($headers)->timeout(30)->get($mainUrl, $paramsBase);
                    $jsonAll = $respAll->ok() ? ($respAll->json() ?? []) : [];
                    if (is_array($jsonAll)) {
                        if (isset($jsonAll[0])) {
                            $games = $jsonAll;
                        } elseif (isset($jsonAll['data'])) {
                            $data = $jsonAll['data'];
                            if (is_array($data)) {
                                $games = $data['games'] ?? $data['items'] ?? $data['list'] ?? $data['results'] ?? $data['records'] ?? (isset($data[0]) ? $data : []);
                            }
                        } else {
                            $games = $jsonAll['games'] ?? $jsonAll['Games'] ?? $jsonAll['items'] ?? $jsonAll['list'] ?? $jsonAll['results'] ?? $jsonAll['Records'] ?? $jsonAll['value'] ?? $jsonAll['Value'] ?? [];
                            if (!is_array($games)) { $games = []; }
                        }
                    }
                    if (is_array($games) && count($games) > 0) { break; }
                } catch (\Throwable $e) { continue; }
            }
        }
        $from = Carbon::now()->subDays(120)->startOfDay();
        $to = Carbon::now()->endOfDay();
        foreach ($games as $g) {
            $gi = is_array($g) && array_key_exists('game', $g) ? ($g['game'] ?? []) : $g;
            $dateStr = $gi['date'] ?? ($gi['startAt'] ?? ($gi['startTime'] ?? ($gi['utcDate'] ?? ($gi['start'] ?? ($gi['kickoff'] ?? null)))));
            $dt = null; if ($dateStr) { try { $dt = Carbon::parse($dateStr); } catch (\Throwable $e) { $dt = null; } }
            if ($dt && ($dt->lt($from) || $dt->gt($to))) { continue; }
            $homeScore = null; $awayScore = null;
            foreach (['homeResult','homeScore','homeGoals','score.home','home.score','homeTeam.goals','homeTeam.score'] as $p) { $v = data_get($gi, $p); if (is_numeric($v)) { $homeScore = (int)$v; break; } }
            foreach (['awayResult','awayScore','awayGoals','score.away','away.score','awayTeam.goals','awayTeam.score'] as $p) { $v = data_get($gi, $p); if (is_numeric($v)) { $awayScore = (int)$v; break; } }
            $status = $gi['statusName'] ?? ($gi['status'] ?? null);
            $isEnded = $status ? (stripos((string)$status, 'end') !== false) : null;
            if (($homeScore === null || $awayScore === null) && !$isEnded) { continue; }
            if ($homeScore === null || $awayScore === null) { continue; }
            $homeName = data_get($gi, 'homeTeam.name') ?? data_get($gi, 'home.name') ?? data_get($gi, 'homeTeamName') ?? data_get($gi, 'home');
            $awayName = data_get($gi, 'awayTeam.name') ?? data_get($gi, 'away.name') ?? data_get($gi, 'awayTeamName') ?? data_get($gi, 'away');
            $res[] = [
                'home_team' => $homeName,
                'away_team' => $awayName,
                'home_score' => $homeScore,
                'away_score' => $awayScore,
            ];
        }
        if (count($res) === 0 && is_array($games) && count($games) > 0) {
            foreach ($games as $g) {
                $gi = is_array($g) && array_key_exists('game', $g) ? ($g['game'] ?? []) : $g;
                $homeScore = null; $awayScore = null;
                foreach (['homeResult','homeScore','homeGoals','score.home','home.score','homeTeam.goals','homeTeam.score'] as $p) { $v = data_get($gi, $p); if (is_numeric($v)) { $homeScore = (int)$v; break; } }
                foreach (['awayResult','awayScore','awayGoals','score.away','away.score','awayTeam.goals','awayTeam.score'] as $p) { $v = data_get($gi, $p); if (is_numeric($v)) { $awayScore = (int)$v; break; } }
                if ($homeScore === null || $awayScore === null) { continue; }
                $homeName = data_get($gi, 'homeTeam.name') ?? data_get($gi, 'home.name') ?? data_get($gi, 'homeTeamName') ?? data_get($gi, 'home');
                $awayName = data_get($gi, 'awayTeam.name') ?? data_get($gi, 'away.name') ?? data_get($gi, 'awayTeamName') ?? data_get($gi, 'away');
                $res[] = [
                    'home_team' => $homeName,
                    'away_team' => $awayName,
                    'home_score' => $homeScore,
                    'away_score' => $awayScore,
                ];
            }
        }
        return $res;
    }
}