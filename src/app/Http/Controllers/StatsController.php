<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;

class StatsController extends Controller
{
    public function index(Request $request)
    {
        try {
            // sstats.net config and params
            $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
            $apiKey = config('services.sstats.key');
            if (!$apiKey) {
                return view('stats', [
                    'error' => 'SSTATS_API_KEY отсутствует. Установите ключ в .env (SSTATS_API_KEY) или services.sstats.key.',
                    'leagues' => [],
                ]);
            }

            $headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'];
            $year = (int) ($request->query('year') ?? 2025);
            // Включаем кеш по умолчанию; можно отключить через ?nocache=true
            $nocache = filter_var($request->query('nocache', 'false'), FILTER_VALIDATE_BOOLEAN);

            // Лиги для отображения: EPL плюс запрошенные
            $leagueNames = [
                39 => 'Английская Премьер-лига',
                135 => 'Итальянская Серия А',
                140 => 'Испанская Ла Лига',
                61 => 'Французская Лига 1',
                78 => 'Бундеслига',
                235 => 'Российская Премьер-лига',
            ];
            $requestedId = $request->query('leagueid');
            $idsToProcess = $requestedId !== null ? [(int)$requestedId] : [39,135,140,61,78,235];

            // Загрузка матчей по лиге за последние 120 дней
            $fetch = function(int $leagueId) use ($headers, $base, $year) {
                $res = [];
                $paramsBase = ['LeagueId' => $leagueId, 'Year' => $year, 'Limit' => 500];
                $paramsMain = $paramsBase + ['Ended' => 'true'];
                $mainUrl = $base.'/games/list';
                $resp = Http::withHeaders($headers)->timeout(30)->get($mainUrl, $paramsMain);
                $json = $resp->ok() ? ($resp->json() ?? []) : [];
                if (!$resp->ok() && $resp->status() === 400 && is_string($resp->body()) && stripos($resp->body(), 'Ended') !== false) {
                    $resp2 = Http::withHeaders($headers)->timeout(30)->get($mainUrl, $paramsBase);
                    $json = $resp2->ok() ? ($resp2->json() ?? []) : [];
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
                if ($resp->ok() && (is_array($games) ? count($games) : 0) === 0) {
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
            };

            // Подсчёт статистики команды и агрегатов
            $compute = function(array $resultsAll) {
                $teamStats = [];
                foreach ($resultsAll as $m) {
                    $home = $m['home_team'] ?? null;
                    $away = $m['away_team'] ?? null;
                    $hs = is_numeric($m['home_score'] ?? null) ? (int) $m['home_score'] : null;
                    $as = is_numeric($m['away_score'] ?? null) ? (int) $m['away_score'] : null;
                    if (!$home || !$away || $hs === null || $as === null) { continue; }
                    if (!isset($teamStats[$home])) {
                        $teamStats[$home] = [
                            'matches' => 0,
                            'goals_for' => 0,
                            'goals_against' => 0,
                            'wins' => 0,
                            'losses' => 0,
                            'draws' => 0,
                            'home' => ['matches'=>0,'goals_for'=>0,'goals_against'=>0,'wins'=>0,'losses'=>0,'draws'=>0],
                            'away' => ['matches'=>0,'goals_for'=>0,'goals_against'=>0,'wins'=>0,'losses'=>0,'draws'=>0],
                        ];
                    }
                    if (!isset($teamStats[$away])) {
                        $teamStats[$away] = [
                            'matches' => 0,
                            'goals_for' => 0,
                            'goals_against' => 0,
                            'wins' => 0,
                            'losses' => 0,
                            'draws' => 0,
                            'home' => ['matches'=>0,'goals_for'=>0,'goals_against'=>0,'wins'=>0,'losses'=>0,'draws'=>0],
                            'away' => ['matches'=>0,'goals_for'=>0,'goals_against'=>0,'wins'=>0,'losses'=>0,'draws'=>0],
                        ];
                    }
                    $teamStats[$home]['matches']++;
                    $teamStats[$home]['goals_for'] += $hs;
                    $teamStats[$home]['goals_against'] += $as;
                    $teamStats[$home]['home']['matches']++;
                    $teamStats[$home]['home']['goals_for'] += $hs;
                    $teamStats[$home]['home']['goals_against'] += $as;
                    $teamStats[$away]['matches']++;
                    $teamStats[$away]['goals_for'] += $as;
                    $teamStats[$away]['goals_against'] += $hs;
                    $teamStats[$away]['away']['matches']++;
                    $teamStats[$away]['away']['goals_for'] += $as;
                    $teamStats[$away]['away']['goals_against'] += $hs;
                    if ($hs > $as) {
                        $teamStats[$home]['wins']++;
                        $teamStats[$home]['home']['wins']++;
                        $teamStats[$away]['losses']++;
                        $teamStats[$away]['away']['losses']++;
                    } elseif ($as > $hs) {
                        $teamStats[$away]['wins']++;
                        $teamStats[$away]['away']['wins']++;
                        $teamStats[$home]['losses']++;
                        $teamStats[$home]['home']['losses']++;
                    } else {
                        $teamStats[$home]['draws']++;
                        $teamStats[$home]['home']['draws']++;
                        $teamStats[$away]['draws']++;
                        $teamStats[$away]['away']['draws']++;
                    }
                }

                $mostScoringOverall = null;
                $mostScoringHome = null;
                $mostScoringAway = null;
                $mostConcedingHome = null;
                $mostConcedingAway = null;
                foreach ($teamStats as $name => $st) {
                    if (!$mostScoringOverall || $st['goals_for'] > $mostScoringOverall['goals']) {
                        $mostScoringOverall = ['team' => $name, 'goals' => $st['goals_for']];
                    }
                    if (!$mostScoringHome || $st['home']['goals_for'] > $mostScoringHome['goals']) {
                        $mostScoringHome = ['team' => $name, 'goals' => $st['home']['goals_for']];
                    }
                    if (!$mostScoringAway || $st['away']['goals_for'] > $mostScoringAway['goals']) {
                        $mostScoringAway = ['team' => $name, 'goals' => $st['away']['goals_for']];
                    }
                    if (!$mostConcedingHome || $st['home']['goals_against'] > $mostConcedingHome['goals']) {
                        $mostConcedingHome = ['team' => $name, 'goals' => $st['home']['goals_against']];
                    }
                    if (!$mostConcedingAway || $st['away']['goals_against'] > $mostConcedingAway['goals']) {
                        $mostConcedingAway = ['team' => $name, 'goals' => $st['away']['goals_against']];
                    }
                }

                $topScoring = collect($teamStats)->map(fn($st,$name)=>['team'=>$name,'goals'=>$st['goals_for']])->sortByDesc('goals')->values()->take(10)->all();
                $topConceding = collect($teamStats)->map(fn($st,$name)=>['team'=>$name,'goals'=>$st['goals_against']])->sortByDesc('goals')->values()->take(10)->all();
                $topWins = collect($teamStats)->map(fn($st,$name)=>['team'=>$name,'wins'=>$st['wins']])->sortByDesc('wins')->values()->take(10)->all();

                $aggregates = [
                    'most_scoring_overall' => $mostScoringOverall,
                    'most_scoring_home' => $mostScoringHome,
                    'most_scoring_away' => $mostScoringAway,
                    'most_conceding_home' => $mostConcedingHome,
                    'most_conceding_away' => $mostConcedingAway,
                    'top_scoring' => $topScoring,
                    'top_conceding' => $topConceding,
                    'top_wins' => $topWins,
                ];

                return [$teamStats, $aggregates];
            };

            $leagues = [];
            foreach ($idsToProcess as $lid) {
                $cacheKey = 'sstats:results_all:league:' . $lid . ':year:' . $year . ':days120';
                $resultsAll = $nocache ? $fetch($lid) : Cache::remember($cacheKey, 24*60*60, fn() => $fetch($lid));
                [$teamStats, $aggregates] = $compute($resultsAll);
                $leagues[] = [
                    'id' => $lid,
                    'name' => $leagueNames[$lid] ?? ('Лига '.$lid),
                    'aggregates' => $aggregates,
                    'teamStats' => $teamStats,
                ];
            }

            return view('stats', [
                'error' => null,
                'leagues' => $leagues,
            ]);
        } catch (\Throwable $e) {
            return view('stats', [
                'error' => 'Ошибка: '.$e->getMessage(),
                'leagues' => [],
            ]);
        }
    }
}