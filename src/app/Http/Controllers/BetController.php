<?php

namespace App\Http\Controllers;

use App\Models\Bet;
use App\Models\Event;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class BetController extends Controller
{
    public function index()
    {
        // Use explicit ordering by starts_at to avoid relying on created_at
        $events = Event::with('bets')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();
        $coupons = Coupon::with(['bets.event'])->latest()->limit(50)->get();

        return view('home', compact('events', 'coupons'));
    }

    public function store(Request $request)
    {
        // Expect one coupon with many items (parlay)
        $data = $request->validate([
            // Если пользователь авторизован, имя игрока не требуется — используем его логин (username)
            'bettor_name' => [auth()->check() ? 'nullable' : 'required', 'string', 'max:100'],
            'amount_demo' => ['required', 'numeric', 'min:0.01'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.event_id' => ['required', 'exists:events,id'],
            'items.*.selection' => ['required', 'in:home,draw,away'],
        ]);

        // Определяем имя игрока: для авторизованного — username, иначе — из формы
        $bettorName = $data['bettor_name'] ?? null;
        if (auth()->check()) {
            $user = auth()->user();
            $bettorName = trim((string) ($user->username ?? $user->email ?? '')) ?: 'User';
        }

        // Calculate total odds by multiplying selected odds of each event
        $totalOdds = 1.0;
        foreach ($data['items'] as $item) {
            $ev = Event::find($item['event_id']);
            if (!$ev) continue;
            $odds = match ($item['selection']) {
                'home' => $ev->home_odds,
                'draw' => $ev->draw_odds,
                'away' => $ev->away_odds,
            };
            $totalOdds *= ($odds ?? 1);
        }

        $coupon = Coupon::create([
            'bettor_name' => $bettorName,
            'amount_demo' => $data['amount_demo'],
            'total_odds' => $totalOdds,
        ]);

        // Create legs as Bet rows linked to the coupon
        foreach ($data['items'] as $item) {
            Bet::create([
                'event_id' => $item['event_id'],
                'bettor_name' => $bettorName,
                'amount_demo' => $data['amount_demo'],
                'selection' => $item['selection'],
                'coupon_id' => $coupon->id,
            ]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'coupon_id' => $coupon->id,
                'total_odds' => $totalOdds,
            ]);
        }

        return redirect()->route('home')->with('status', 'Купон создан');
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

        // Optional: settle coupons when all legs finished (simple approach)
        // Find affected coupons and mark win only if all legs win
        $affectedCouponIds = $event->bets()->pluck('coupon_id')->filter()->unique();
        foreach ($affectedCouponIds as $cid) {
            $coupon = Coupon::with('bets.event')->find($cid);
            if (!$coupon) continue;
            $allSettled = $coupon->bets->every(fn($b) => $b->settled_at !== null);
            if ($allSettled) {
                $allWin = $coupon->bets->every(fn($b) => $b->is_win === true);
                $coupon->is_win = $allWin;
                $coupon->payout_demo = $allWin ? ($coupon->amount_demo * ($coupon->total_odds ?? 1)) : 0;
                $coupon->settled_at = now();
                $coupon->save();
            }
        }

        return redirect()->route('home')->with('status', 'Событие рассчитано');
    }

    public function syncResults()
    {
        try {
            // Use sstats.net as the single source of truth for ended games
            $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
            $key = config('services.sstats.key');
            $headers = $key ? ['X-API-KEY' => $key] : [];
            if (!$key) {
                return redirect()->route('home')->with('status', 'SSTATS_API_KEY отсутствует');
            }

            // EPL by default
            $leagueId = 39; $year = (int) date('Y');
            $resp = Http::withHeaders($headers)->timeout(30)->get($base.'/Games/list', [
                'leagueid' => $leagueId,
                'year' => $year,
                'limit' => 500,
                'ended' => true,
            ]);
            if ($resp->failed()) {
                return redirect()->route('home')->with('status', 'Не удалось получить результаты из sstats');
            }
            $eventsApi = collect($resp->json('data') ?? []);

            $updated = 0;
            foreach ($eventsApi as $apiEv) {
                $homeName = data_get($apiEv, 'homeTeam.name');
                $awayName = data_get($apiEv, 'awayTeam.name');
                $home = strtolower(trim((string) $homeName));
                $away = strtolower(trim((string) $awayName));
                $homeScore = is_numeric($apiEv['homeResult'] ?? null) ? (int)$apiEv['homeResult'] : null;
                $awayScore = is_numeric($apiEv['awayResult'] ?? null) ? (int)$apiEv['awayResult'] : null;
                $ts = $apiEv['date'] ?? null;
                $apiTime = $ts ? Carbon::parse($ts) : null;

                // Требуем валидные счёты и только прошедшие матчи
                if (!$home || !$away || $homeScore === null || $awayScore === null || !$apiTime || $apiTime->isFuture()) continue;

                // Найдём локальное событие: те же команды, ближайшая дата к apiTime, в разумном окне (<= 72ч)
                $candidates = Event::query()
                    ->whereRaw('LOWER(home_team) = ?', [$home])
                    ->whereRaw('LOWER(away_team) = ?', [$away])
                    ->get();

                // Если локального события нет — создадим его как завершённое с результатом
                if ($candidates->isEmpty()) {
                    $result = 'draw';
                    if ($homeScore > $awayScore) $result = 'home';
                    elseif ($awayScore > $homeScore) $result = 'away';

                    $title = trim(($homeName ?? '').' vs '.($awayName ?? ''));
                    Event::create([
                        'title' => $title ?: ($home.' vs '.$away),
                        'home_team' => $homeName ?? $home,
                        'away_team' => $awayName ?? $away,
                        'status' => 'finished',
                        'result' => $result,
                        'starts_at' => $apiTime,
                        'ends_at' => $apiTime,
                        'home_odds' => null,
                        'draw_odds' => null,
                        'away_odds' => null,
                    ]);
                    $updated++;
                    continue;
                }

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

                // Допускаем два сценария обновления:
                // 1) Дата близка к локальной (<= 72ч);
                // 2) Локальное событие ещё scheduled, но API говорит, что матч уже прошёл — обновляем по API времени.
                $inWindow = ($ev->starts_at !== null) ? ($ev->diffMin <= 72*60) : false;
                $allowByApiTime = ($ev->status === 'scheduled') && $apiTime && $apiTime->isPast();
                if (!$inWindow && !$allowByApiTime) continue;

                // Определяем результат
                $result = 'draw';
                if ($homeScore > $awayScore) $result = 'home';
                elseif ($awayScore > $homeScore) $result = 'away';

                // Обновляем
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

    /**
     * Debug: EPL fixtures (upcoming week) and results (previous week) via API‑Sport.ru.
     */
    public function debugResults()
    {
        try {
            // Read API‑SPORT.ru config (OpenAPI: https://api.api-sport.ru, header Authorization)
            $apiKey = config('services.api_sport.key');
            $base = rtrim(config('services.api_sport.base_url', 'https://api.api-sport.ru'), '/');
            if (!$apiKey) {
                return view('debug-results', [
                    'error' => 'API_SPORT_KEY отсутствует. Установите ключ в .env (API_SPORT_KEY) или services.api_sport.key.',
                    'apiSportFixturesWeek' => [],
                    'apiSportTournamentRaw' => null,
                ]);
            }

            $http = Http::withHeaders([
                'Authorization' => $apiKey,
            ])->acceptJson()
              ->timeout(20)
              ->connectTimeout(5)
              ->retry(2, 500);

            // Direct tournament fetch by ID (default EPL: 17). Allows exact raw tournament output.
            $tournamentId = (int) env('API_SPORT_TOURNAMENT_ID', 17);
            $tournamentResp = $http->get($base.'/v2football/tournament/'.$tournamentId);
            $tournamentRaw = null; try { $tournamentRaw = $tournamentResp->json(); } catch (\Throwable $e) {}
            $categoryId = data_get($tournamentRaw, 'category.id');
            // Try to detect current season id from tournament data
            $seasonId = null;
            try {
                $seasons = data_get($tournamentRaw, 'seasons', []);
                if (is_array($seasons) && !empty($seasons)) {
                    // Pick the season with the largest id as a heuristic for current
                    $seasonId = collect($seasons)->max('id');
                }
            } catch (\Throwable $e) {}

            $now = Carbon::now()->startOfDay();
            $end = Carbon::now()->addDays(6)->startOfDay();

            $fixturesWeek = [];
            $fixturesStatusLast = null;
            for ($d = $now->copy(); $d->lte($end); $d->addDay()) {
                $params = [
                    'date' => $d->toDateString(),
                    'status' => 'notstarted',
                    'tournament_id' => $tournamentId,
                ];
                if ($categoryId) { $params['category_id'] = $categoryId; }
                $resp = $http->get($base.'/v2/football/matches', $params);
                $fixturesStatusLast = $resp->status();
                $body = null; try { $body = $resp->json(); } catch (\Throwable $e) {}
                $matches = is_array($body) ? ($body['matches'] ?? []) : [];
                foreach ($matches as $m) {
                    // Strict guard: keep only requested tournament/category
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
                    // Robust start time extraction: prefer startTimestamp (ms or sec), then startTime/dateEvent
                    $dateIso = null;
                    $startTs = data_get($m, 'startTimestamp');
                    if (is_numeric($startTs)) {
                        try {
                            $ts = (int) $startTs;
                            $seconds = ($ts >= 1000000000000) ? (int) floor($ts / 1000) : $ts; // ms vs sec
                            $dateIso = Carbon::createFromTimestampUTC($seconds)->toIso8601String();
                        } catch (\Throwable $e) { $dateIso = null; }
                    }
                    if (!$dateIso) {
                        $startStr = data_get($m, 'startTime');
                        if (is_string($startStr) && strlen($startStr) > 0) {
                            try { $dateIso = Carbon::parse($startStr)->toIso8601String(); } catch (\Throwable $e) {}
                        }
                    }
                    if (!$dateIso) {
                        $dateStr = data_get($m, 'dateEvent');
                        if (is_string($dateStr) && strlen($dateStr) > 0) {
                            try { $dateIso = Carbon::parse($dateStr)->toIso8601String(); } catch (\Throwable $e) {}
                        }
                    }
                    $fixturesWeek[] = [
                        'home_team' => data_get($m, 'homeTeam.name'),
                        'away_team' => data_get($m, 'awayTeam.name'),
                        'commence_time' => $dateIso,
                    ];
                }
            }

            // Past week results (finished matches)
            $resultsWeek = [];
            $pastStart = Carbon::now()->subDays(6)->startOfDay();
            $pastEnd = Carbon::now()->subDay()->endOfDay();
            for ($d = $pastStart->copy(); $d->lte($pastEnd); $d->addDay()) {
                $params = [
                    'date' => $d->toDateString(),
                    'status' => 'finished', // API-Sport status for completed matches
                    'tournament_id' => $tournamentId,
                ];
                if ($categoryId) { $params['category_id'] = $categoryId; }
                $resp = $http->get($base.'/v2/football/matches', $params);
                $body = null; try { $body = $resp->json(); } catch (\Throwable $e) {}
                $matches = is_array($body) ? ($body['matches'] ?? []) : [];
                foreach ($matches as $m) {
                    // Strict guard: keep only requested tournament/category
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
                    $endTs = $m['startTimestamp'] ?? null; // use startTimestamp for display if finish timestamp not provided
                    $dateIso = null;
                    if (is_numeric($endTs)) {
                        try {
                            $seconds = (int) floor(((int) $endTs) / 1000);
                            $dateIso = Carbon::createFromTimestampUTC($seconds)->toIso8601String();
                        } catch (\Throwable $e) { $dateIso = null; }
                    }
                    if (!$dateIso) {
                        $dateIso = isset($m['dateEvent']) ? (Carbon::parse($m['dateEvent'])->startOfDay()->toIso8601String()) : null;
                    }
                    
                    // Extract scores from correct API structure: homeScore.current / awayScore.current
                    $homeScore = data_get($m, 'homeScore.current');
                    $awayScore = data_get($m, 'awayScore.current');
                    
                    // Ensure scores are numeric
                    $homeScore = is_numeric($homeScore) ? (int) $homeScore : null;
                    $awayScore = is_numeric($awayScore) ? (int) $awayScore : null;
                    
                    $resultsWeek[] = [
                        'home_team' => data_get($m, 'homeTeam.name'),
                        'away_team' => data_get($m, 'awayTeam.name'),
                        'finished_at' => $dateIso,
                        'home_score' => $homeScore,
                        'away_score' => $awayScore,
                    ];
                }
            }

            // All previous results (cached; chunked by day to avoid huge payloads)
            $cacheKey = 'api_sport:results_all:football:'
                . $tournamentId . ':'
                . ($seasonId ?: 'none') . ':'
                . 'cat:' . ($categoryId ?: 'none') . ':'
                . 'days120';
            $resultsAll = Cache::remember($cacheKey, 12*60*60, function() use ($http, $base, $tournamentId, $seasonId, $categoryId) {
                $results = [];
                // Limit to last 120 days to keep payloads and decode memory reasonable
                $allStart = Carbon::now()->subDays(120)->startOfDay();
                $allEnd = Carbon::now()->endOfDay();
                for ($d = $allStart->copy(); $d->lte($allEnd); $d->addDay()) {
                    $paramsAll = [
                        'date' => $d->toDateString(),
                        'status' => 'finished',
                        'tournament_id' => $tournamentId,
                    ];
                    if ($seasonId) { $paramsAll['season_id'] = $seasonId; }
                    if ($categoryId) { $paramsAll['category_id'] = $categoryId; }
                    $respAll = $http->get($base.'/v2/football/matches', $paramsAll);
                    $bodyAll = null; try { $bodyAll = $respAll->json(); } catch (\Throwable $e) {}
                    $matchesAll = is_array($bodyAll) ? ($bodyAll['matches'] ?? []) : [];
                    foreach ($matchesAll as $m) {
                        // Strict guard: keep only requested tournament/category
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
                        $endTs = $m['startTimestamp'] ?? null;
                        $dateIso = null;
                        if (is_numeric($endTs)) {
                            try {
                                $seconds = (int) floor(((int) $endTs) / 1000);
                                $dateIso = Carbon::createFromTimestampUTC($seconds)->toIso8601String();
                            } catch (\Throwable $e) { $dateIso = null; }
                        }
                        if (!$dateIso) {
                            $dateIso = isset($m['dateEvent']) ? (Carbon::parse($m['dateEvent'])->startOfDay()->toIso8601String()) : null;
                        }

                        $homeScore = data_get($m, 'homeScore.current');
                        $awayScore = data_get($m, 'awayScore.current');
                        $homeScore = is_numeric($homeScore) ? (int) $homeScore : null;
                        $awayScore = is_numeric($awayScore) ? (int) $awayScore : null;

                        $results[] = [
                            'home_team' => data_get($m, 'homeTeam.name'),
                            'away_team' => data_get($m, 'awayTeam.name'),
                            'finished_at' => $dateIso,
                            'home_score' => $homeScore,
                            'away_score' => $awayScore,
                        ];
                    }
                }
                return $results;
            });

            // Aggregate season stats from resultsAll
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
                // Home team updates
                $teamStats[$home]['matches']++;
                $teamStats[$home]['goals_for'] += $hs;
                $teamStats[$home]['goals_against'] += $as;
                $teamStats[$home]['home']['matches']++;
                $teamStats[$home]['home']['goals_for'] += $hs;
                $teamStats[$home]['home']['goals_against'] += $as;
                // Away team updates
                $teamStats[$away]['matches']++;
                $teamStats[$away]['goals_for'] += $as;
                $teamStats[$away]['goals_against'] += $hs;
                $teamStats[$away]['away']['matches']++;
                $teamStats[$away]['away']['goals_for'] += $as;
                $teamStats[$away]['away']['goals_against'] += $hs;
                // Outcome
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

            // Compute most scoring/most conceding teams
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

            // Compute simple fair odds for upcoming fixtures based on home/away performance
            $fixturesWeekWithOdds = [];
            foreach ($fixturesWeek as $m) {
                $home = $m['home_team'] ?? null;
                $away = $m['away_team'] ?? null;
                $hSt = $home && isset($teamStats[$home]) ? $teamStats[$home] : null;
                $aSt = $away && isset($teamStats[$away]) ? $teamStats[$away] : null;
                $pHome = 0.33; $pAway = 0.33; $pDraw = 0.34;
                if ($hSt && $aSt) {
                    $hMatches = max(1, $hSt['home']['matches']);
                    $aMatches = max(1, $aSt['away']['matches']);
                    $homeWinRate = $hSt['home']['wins'] / $hMatches;
                    $homeLossRate = $hSt['home']['losses'] / $hMatches;
                    $homeDrawRate = $hSt['home']['draws'] / $hMatches;
                    $awayWinRate = $aSt['away']['wins'] / $aMatches;
                    $awayLossRate = $aSt['away']['losses'] / $aMatches;
                    $awayDrawRate = $aSt['away']['draws'] / $aMatches;
                    $pHome = max(0.01, ($homeWinRate + $awayLossRate) / 2);
                    $pAway = max(0.01, ($awayWinRate + $homeLossRate) / 2);
                    $pDraw = max(0.01, ($homeDrawRate + $awayDrawRate) / 2);
                    $sum = $pHome + $pAway + $pDraw;
                    if ($sum > 0) {
                        $pHome /= $sum; $pAway /= $sum; $pDraw /= $sum;
                    } else {
                        $pHome = 0.33; $pAway = 0.33; $pDraw = 0.34;
                    }
                }
                $homeOdds = round(1 / $pHome, 2);
                $drawOdds = round(1 / $pDraw, 2);
                $awayOdds = round(1 / $pAway, 2);
                $fixturesWeekWithOdds[] = array_merge($m, [
                    'home_odds' => $homeOdds,
                    'draw_odds' => $drawOdds,
                    'away_odds' => $awayOdds,
                ]);
            }

            // Try to link upcoming fixtures to existing Event records to enable betting from debug page
            $eventsUpcoming = Event::where('status', 'scheduled')->get();
            $eventIndex = [];
            foreach ($eventsUpcoming as $ev) {
                $homeKey = is_string($ev->home_team) ? mb_strtolower(trim($ev->home_team)) : '';
                $awayKey = is_string($ev->away_team) ? mb_strtolower(trim($ev->away_team)) : '';
                $dateKey = $ev->starts_at ? $ev->starts_at->toDateString() : '';
                if ($homeKey && $awayKey && $dateKey) {
                    $eventIndex[$homeKey.'|'.$awayKey.'|'.$dateKey] = $ev->id;
                }
            }
            foreach ($fixturesWeekWithOdds as &$m) {
                try {
                    $homeKey = is_string($m['home_team'] ?? null) ? mb_strtolower(trim($m['home_team'])) : '';
                    $awayKey = is_string($m['away_team'] ?? null) ? mb_strtolower(trim($m['away_team'])) : '';
                    $dateKey = isset($m['commence_time']) && $m['commence_time'] ? Carbon::parse($m['commence_time'])->toDateString() : '';
                    $idxKey = $homeKey.'|'.$awayKey.'|'.$dateKey;
                    if ($homeKey && $awayKey && $dateKey && isset($eventIndex[$idxKey])) {
                        $m['event_id'] = $eventIndex[$idxKey];
                    } else {
                        // If no existing Event found, upsert a new scheduled event for betting
                        $homeName = $m['home_team'] ?? null;
                        $awayName = $m['away_team'] ?? null;
                        $startsAt = isset($m['commence_time']) && $m['commence_time'] ? Carbon::parse($m['commence_time']) : null;
                        if ($homeName && $awayName && $startsAt) {
                            $existing = Event::where('home_team', $homeName)
                                ->where('away_team', $awayName)
                                ->whereDate('starts_at', $startsAt->toDateString())
                                ->first();
                            if (!$existing) {
                                $existing = Event::create([
                                    'title' => $homeName.' vs '.$awayName,
                                    'home_team' => $homeName,
                                    'away_team' => $awayName,
                                    'starts_at' => $startsAt,
                                    'status' => 'scheduled',
                                    'home_odds' => $m['home_odds'] ?? null,
                                    'draw_odds' => $m['draw_odds'] ?? null,
                                    'away_odds' => $m['away_odds'] ?? null,
                                ]);
                            }
                            $m['event_id'] = $existing->id;
                        }
                    }
                } catch (\Throwable $e) {}
            }
            unset($m);

            $aggregates = [
                'most_scoring_overall' => $mostScoringOverall,
                'most_scoring_home' => $mostScoringHome,
                'most_scoring_away' => $mostScoringAway,
                'most_conceding_home' => $mostConcedingHome,
                'most_conceding_away' => $mostConcedingAway,
                'team_stats' => $teamStats,
            ];

            // Load latest coupons (bet history)
            $coupons = Coupon::with(['bets.event'])->latest()->limit(50)->get();

            return view('debug-results', [
                'error' => null,
                'apiSportFixturesWeek' => $fixturesWeekWithOdds,
                'apiSportResultsWeek' => $resultsWeek,
                'apiSportResultsAll' => $resultsAll,
                'apiSportTournamentRaw' => $tournamentRaw,
                'apiSportAggregates' => $aggregates,
                'coupons' => $coupons,
            ]);
        } catch (\Throwable $e) {
            return view('debug-results', [
                'error' => 'Ошибка: '.$e->getMessage(),
                'apiSportFixturesWeek' => [],
                'apiSportResultsWeek' => [],
                'apiSportResultsAll' => [],
                'apiSportTournamentRaw' => null,
            ]);
        }
    }

    // Note: Resolver removed in favor of direct tournament fetch by ID for deterministic EPL output.
}
