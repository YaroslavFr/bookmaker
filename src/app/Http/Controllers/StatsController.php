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
            $apiKey = config('services.api_sport.key');
            $base = rtrim(config('services.api_sport.base_url', 'https://api.api-sport.ru'), '/');
            if (!$apiKey) {
                return view('stats', [
                    'error' => 'API_SPORT_KEY отсутствует. Установите ключ в .env (API_SPORT_KEY) или services.api_sport.key.',
                    'aggregates' => null,
                    'teamStats' => [],
                ]);
            }

            $http = Http::withHeaders([
                'Authorization' => $apiKey,
            ])->acceptJson()
              ->timeout(20)
              ->connectTimeout(5)
              ->retry(2, 500);

            // EPL tournament by default (17)
            $tournamentId = (int) env('API_SPORT_TOURNAMENT_ID', 17);
            $tournamentResp = $http->get($base.'/v1/football/tournament/'.$tournamentId);
            $tournamentRaw = null; try { $tournamentRaw = $tournamentResp->json(); } catch (\Throwable $e) {}
            $categoryId = data_get($tournamentRaw, 'category.id');
            $seasonId = null;
            try {
                $seasons = data_get($tournamentRaw, 'seasons', []);
                if (is_array($seasons) && !empty($seasons)) {
                    $seasonId = collect($seasons)->max('id');
                }
            } catch (\Throwable $e) {}

            // Fetch all finished matches within last 120 days and aggregate
            $cacheKey = 'stats:results_all:football:'
                . $tournamentId . ':'
                . ($seasonId ?: 'none') . ':'
                . 'cat:' . ($categoryId ?: 'none') . ':'
                . 'days120';

            $resultsAll = Cache::remember($cacheKey, 12*60*60, function() use ($http, $base, $tournamentId, $seasonId, $categoryId) {
                $res = [];
                $allStart = Carbon::now()->subDays(120)->startOfDay();
                $allEnd = Carbon::now()->endOfDay();
                for ($d = $allStart->copy(); $d->lte($allEnd); $d->addDay()) {
                    $params = [
                        'date' => $d->toDateString(),
                        'status' => 'finished',
                        'tournament_id' => $tournamentId,
                    ];
                    if ($seasonId) { $params['season_id'] = $seasonId; }
                    if ($categoryId) { $params['category_id'] = $categoryId; }
                    $resp = $http->get($base.'/v1/football/matches', $params);
                    $body = null; try { $body = $resp->json(); } catch (\Throwable $e) {}
                    $matches = is_array($body) ? ($body['matches'] ?? []) : [];
                    foreach ($matches as $m) {
                        // Strict guard for tournament/category
                        $mTid = data_get($m, 'tournament.id');
                        $mTid2 = data_get($m, 'tournamentId');
                        $mCid = data_get($m, 'category.id');
                        $mCid2 = data_get($m, 'categoryId');
                        $tName = strtolower(trim((string) data_get($m, 'tournament.name')));
                        $okT = true;
                        if (!is_null($mTid) || !is_null($mTid2)) {
                            $okT = ((int)($mTid ?? $mTid2) === (int)$tournamentId);
                        }
                        $okC = true;
                        if ($categoryId && (!is_null($mCid) || !is_null($mCid2))) {
                            $okC = ((int)($mCid ?? $mCid2) === (int)$categoryId);
                        }
                        $okName = $tName ? str_contains($tName, 'premier league') : true;
                        if (!$okT || !$okC || !$okName) { continue; }

                        $homeScore = data_get($m, 'homeScore.current');
                        $awayScore = data_get($m, 'awayScore.current');
                        $homeScore = is_numeric($homeScore) ? (int) $homeScore : null;
                        $awayScore = is_numeric($awayScore) ? (int) $awayScore : null;
                        $res[] = [
                            'home_team' => data_get($m, 'homeTeam.name'),
                            'away_team' => data_get($m, 'awayTeam.name'),
                            'home_score' => $homeScore,
                            'away_score' => $awayScore,
                        ];
                    }
                }
                return $res;
            });

            // Build team stats
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
                // home updates
                $teamStats[$home]['matches']++;
                $teamStats[$home]['goals_for'] += $hs;
                $teamStats[$home]['goals_against'] += $as;
                $teamStats[$home]['home']['matches']++;
                $teamStats[$home]['home']['goals_for'] += $hs;
                $teamStats[$home]['home']['goals_against'] += $as;
                // away updates
                $teamStats[$away]['matches']++;
                $teamStats[$away]['goals_for'] += $as;
                $teamStats[$away]['goals_against'] += $hs;
                $teamStats[$away]['away']['matches']++;
                $teamStats[$away]['away']['goals_for'] += $as;
                $teamStats[$away]['away']['goals_against'] += $hs;
                // outcome
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

            // Aggregates
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

            // Leaderboards
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

            return view('stats', [
                'error' => null,
                'aggregates' => $aggregates,
                'teamStats' => $teamStats,
            ]);
        } catch (\Throwable $e) {
            return view('stats', [
                'error' => 'Ошибка: '.$e->getMessage(),
                'aggregates' => null,
                'teamStats' => [],
            ]);
        }
    }
}