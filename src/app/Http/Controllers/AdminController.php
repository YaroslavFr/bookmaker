<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class AdminController extends Controller
{
    public function index()
    {
        $users = User::orderBy('id', 'desc')->limit(50)->get();
        return view('admin.index', ['users' => $users]);
    }

    public function create()
    {
        return view('admin.create_user');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'username' => ['required','string','min:3','max:50','alpha_dash','unique:users,username'],
            'name' => ['required','string','min:3','max:100'],
            'password' => ['required','string','min:6'],
            'role' => ['required','in:admin,moderator,user'],
        ]);
        $email = $request->string('email')->toString();
        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $email ?: ($data['username'].'@local'),
            'password' => $data['password'],
            'role' => $data['role'],
        ]);
        return redirect()->route('admin.index');
    }

    public function syncUpcoming(Request $request)
    {
        try {
            $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
            $apiKey = config('services.sstats.key');
            if (!$apiKey) { return redirect()->route('admin.index')->with('status', 'SSTATS_API_KEY отсутствует'); }
            $headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'];
            $limit = (int) ($request->integer('limit') ?: 100);
            $leagueId = (int) ($request->integer('leagueid') ?: 39);
            $year = (int) ($request->integer('year') ?: (int) date('Y'));

            $to = Carbon::now()->addDays(10)->format('Y-m-d');
            $paramsBase = ['Limit' => max(50, $limit*2), 'LeagueId' => $leagueId, 'Year' => $year];
            $params = $paramsBase + ['Ended' => 'false', 'Status' => 2, 'TO' => $to];
            $resp = Http::withHeaders($headers)->timeout(30)->get($base.'/games/list', $params);
            $json = $resp->ok() ? ($resp->json() ?? []) : [];
            if (!$resp->ok()) {
                $resp2 = Http::withHeaders($headers)->timeout(30)->get($base.'/games/list', $paramsBase);
                $json = $resp2->ok() ? ($resp2->json() ?? []) : [];
            }

            $games = [];
            if (isset($json['data']) || isset($json['Data'])) { $d = $json['data'] ?? $json['Data']; $games = $d['games'] ?? $d['items'] ?? (isset($d[0]) ? $d : []); }
            else { $games = $json['games'] ?? $json['Games'] ?? $json['items'] ?? $json['list'] ?? []; }

            $competition = null;
            foreach ((array) config('leagues.leagues') as $code => $info) { if ((int) ($info['id'] ?? 0) === $leagueId) { $competition = $code; break; } }

            $updated = 0;
            foreach ($games as $g) {
                if ($updated >= $limit) break;
                $gameId = data_get($g, 'id') ?? data_get($g, 'gameId') ?? data_get($g, 'game.id');
                $homeName = data_get($g, 'homeTeam.name') ?? data_get($g, 'home');
                $awayName = data_get($g, 'awayTeam.name') ?? data_get($g, 'away');
                $starts = $this->extractStartTime($g);
                if (!$gameId || !$homeName || !$awayName || !$starts) { continue; }
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

            return redirect()->route('admin.index')->with('status', "Обновлено матчей: {$updated}");
        } catch (\Throwable $e) {
            return redirect()->route('admin.index')->with('status', 'Ошибка API: '.$e->getMessage());
        }
    }

    private function parseOddsFromGame(array $g): array
    {
        $homeVals = []; $drawVals = []; $awayVals = [];
        $oddsList = $g['odds'] ?? null;
        if (is_array($oddsList)) {
            foreach ($oddsList as $mk) {
                $key = strtolower((string)($mk['key'] ?? ($mk['marketKey'] ?? '')));
                $name = strtolower((string)($mk['name'] ?? ($mk['marketName'] ?? '')));
                if ($key === '1x2' || str_contains($name, '1x2') || str_contains($name, 'full time') || str_contains($name, 'match odds') || str_contains($name, 'result')) {
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
        }
        $home = count($homeVals) ? $this->avg($homeVals) : null;
        $draw = count($drawVals) ? $this->avg($drawVals) : null;
        $away = count($awayVals) ? $this->avg($awayVals) : null;
        return [$home, $draw, $away];
    }

    private function fetchOddsForGame(string $base, array $headers, $gameId): array
    {
        try {
            $resp = Http::withHeaders($headers)->timeout(20)->get($base.'/odds/list', ['GameId' => $gameId]);
            $json = $resp->ok() ? ($resp->json() ?? []) : [];
            $oddsBlocks = [];
            if (isset($json['data']) || isset($json['Data'])) { $d = $json['data'] ?? $json['Data']; $oddsBlocks = is_array($d) ? ($d['markets'] ?? $d['odds'] ?? (isset($d[0]) ? $d : [])) : []; }
            elseif (is_array($json)) { $oddsBlocks = $json['markets'] ?? $json['odds'] ?? (isset($json[0]) ? $json : []); }
            return $this->parseOddsFromGame(['odds' => $oddsBlocks]);
        } catch (\Throwable $e) {
            return [null, null, null];
        }
    }

    private function extractStartTime(array $g): ?Carbon
    {
        $raw = data_get($g, 'commence') ?? data_get($g, 'commence_time') ?? data_get($g, 'date') ?? data_get($g, 'start');
        if (!$raw) return null;
        try { return Carbon::parse($raw)->utc()->second(0)->micro(0); } catch (\Throwable $e) { return null; }
    }

    private function avg(array $vals): float
    {
        $vals = array_values(array_filter(array_map('floatval', $vals), fn($v) => is_finite($v)));
        return count($vals) ? round(array_sum($vals) / count($vals), 2) : 0.0;
    }
}