<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SstatsController extends Controller
{
    public function index(Request $request)
    {
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        $key = config('services.sstats.key');
        $headers = $key ? ['X-API-KEY' => $key] : [];

        // Enforce EPL by default (league ID 39)
        $leagueId = (int) ($request->query('leagueid') ?? 39);
        $year = (int) ($request->query('year') ?? date('Y'));
        $gameId = $request->query('gameId');
        $ended = $request->query('ended');

        $leagues = null; $games = null; $game = null; $odds = null; $glicko = null; $profits = null; $error = null; $leagueName = 'English Premier League';

        try {
            // If specific game requested, show details view
            if ($gameId) {
                // Fetch game details
                $respGame = Http::withHeaders($headers)->timeout(30)->get($base.'/Games/'.$gameId);
                if ($respGame->ok()) {
                    $game = $respGame->json('data');
                } else {
                    $error = 'Game details failed: '.$respGame->status();
                }
                // Fetch odds (prematch)
                $respOdds = Http::withHeaders($headers)->timeout(30)->get($base.'/Odds/'.$gameId);
                if ($respOdds->ok()) {
                    $odds = $respOdds->json('data');
                }
                // Fetch Glicko analysis
                $respGlicko = Http::withHeaders($headers)->timeout(30)->get($base.'/Games/glicko/'.$gameId);
                if ($respGlicko->ok()) {
                    $glicko = $respGlicko->json('data');
                }
                // Fetch profits analysis
                $respProfits = Http::withHeaders($headers)->timeout(30)->get($base.'/Games/profits', ['gameId' => $gameId, 'limit' => 25, 'thisLeague' => true]);
                if ($respProfits->ok()) {
                    $profits = $respProfits->json('data');
                }
            } else {
                // Default EPL upcoming games only
                $params = ['leagueid' => $leagueId, 'year' => $year, 'limit' => 200, 'ended' => false];
                $respG = Http::withHeaders($headers)->timeout(30)->get($base.'/Games/list', $params);
                if ($respG->ok()) {
                    $games = $respG->json('data');
                    if (is_array($games)) {
                        $withOdds = [];
                        foreach ($games as $g) {
                            $gid = $g['id'] ?? null;
                            $gOdds = null;
                            if ($gid) {
                                try {
                                    $respOddsEach = Http::withHeaders($headers)->timeout(20)->get($base.'/Odds/'.$gid);
                                    if ($respOddsEach->ok()) {
                                        $gOdds = $respOddsEach->json('data');
                                    }
                                } catch (\Throwable $e) {}
                            }
                            $g['odds'] = $gOdds;
                            $withOdds[] = $g;
                        }
                        $games = $withOdds;
                    }
                    // Never show leagues list on EPL-only page
                    $leagues = null;
                } else {
                    $error = 'Games list failed: '.$respG->status();
                }
            }

            if ($leagueId && $year && !$gameId) {
                // Fetch games list for league & season
                $params = ['leagueid' => $leagueId, 'year' => $year, 'limit' => 200];
                if ($ended) { $params['ended'] = $ended; }
                $resp = Http::withHeaders($headers)->timeout(30)->get($base.'/Games/list', $params);
                if ($resp->ok()) {
                    $games = $resp->json('data');
                } else {
                    $error = 'Games list failed: '.$resp->status();
                }
            }

            if ($gameId) {
                // Fetch game details
                $respGame = Http::withHeaders($headers)->timeout(30)->get($base.'/Games/'.$gameId);
                if ($respGame->ok()) {
                    $game = $respGame->json('data');
                } else {
                    $error = 'Game details failed: '.$respGame->status();
                }
                // Fetch odds (prematch)
                $respOdds = Http::withHeaders($headers)->timeout(30)->get($base.'/Odds/'.$gameId);
                if ($respOdds->ok()) {
                    $odds = $respOdds->json('data');
                }
                // Fetch Glicko analysis
                $respGlicko = Http::withHeaders($headers)->timeout(30)->get($base.'/Games/glicko/'.$gameId);
                if ($respGlicko->ok()) {
                    $glicko = $respGlicko->json('data');
                }
                // Fetch profits analysis
                $respProfits = Http::withHeaders($headers)->timeout(30)->get($base.'/Games/profits', ['gameId' => $gameId, 'limit' => 25, 'thisLeague' => true]);
                if ($respProfits->ok()) {
                    $profits = $respProfits->json('data');
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('sstats', compact('leagues', 'games', 'game', 'odds', 'glicko', 'profits', 'error', 'leagueId', 'year', 'leagueName'));
    }
}