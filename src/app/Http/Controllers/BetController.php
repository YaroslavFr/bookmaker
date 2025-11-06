<?php

namespace App\Http\Controllers;

use App\Models\Bet;
use App\Models\Event;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class BetController extends Controller
{
    public function index()
    {
        // Перед рендером обновим события и коэффициенты из sstats (минимум запросов, без кэша)
        $this->refreshUpcomingEventsForLeague(39, 'EPL'); // Чемпионат Англии
        $uclId = (int) (config('services.sstats.champions_league_id', 2));
        $this->refreshUpcomingEventsForLeague($uclId, 'UCL'); // Лига чемпионов
        $this->refreshUpcomingEventsForLeague(135, 'ITA'); // Серия А (Италия)

        // Получаем отдельные ленты событий: EPL, UCL, ITA
        $hasCompetition = Schema::hasColumn('events', 'competition');
        if ($hasCompetition) {
            $eventsEpl = Event::with('bets')
                ->where('competition', 'EPL')
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get();
            $eventsUcl = Event::with('bets')
                ->where('competition', 'UCL')
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get();
            $eventsIta = Event::with('bets')
                ->where('competition', 'ITA')
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get();
        } else {
            // Фоллбэк до применения миграции: показываем все события в секции EPL, а UCL — пусто.
            $eventsEpl = Event::with('bets')
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get();
            $eventsUcl = collect();
            $eventsIta = collect();
        }
        $coupons = Coupon::with(['bets.event'])->latest()->limit(50)->get();

        // Структурируем лиги для универсального рендера во вьюхе
        $leagues = [
            ['title' => 'Чемпионат Англии (EPL)', 'events' => $eventsEpl],
            ['title' => 'Лига чемпионов (UCL)', 'events' => $eventsUcl],
            ['title' => 'Серия А (ITA)', 'events' => $eventsIta],
        ];

        return view('home', [
            'leagues' => $leagues,
            'eventsEpl' => $eventsEpl,
            'eventsUcl' => $eventsUcl,
            'eventsIta' => $eventsIta,
            'coupons' => $coupons,
        ]);
    }

    /**
     * Обновляет таблицу events свежими предматчевыми коэффициентами (1x2) из sstats.
     * Делает один запрос к /games/list (EPL, предстоящие), извлекает inline-коэффициенты.
     */
    private function refreshUpcomingEventsForLeague(int $leagueId, string $competition): void
    {
        try {
            $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
            $apiKey = config('services.sstats.key');
            if (!$apiKey) return; // Без ключа не обновляем
            $headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'];
            $year = (int) date('Y');
            $limit = 20; // небольшое ограничение для минимизации нагрузки
            // Сначала удалим все незакреплённые будущие события (без ставок), чтобы избежать наложений
            Event::doesntHave('bets')->where('status', 'scheduled')->where('competition', $competition)->delete();

            $resp = Http::withHeaders($headers)->timeout(8)->get($base.'/games/list', [
                'LeagueId' => $leagueId,
                'Year' => $year,
                'Status' => 2, // upcoming
                'Limit' => $limit,
            ]);
            if (!$resp->ok()) return;
            $json = $resp->json() ?? [];
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
            if (empty($games)) return;

            $now = Carbon::now();
            foreach ($games as $g) {
                $homeRaw = data_get($g, 'homeTeam.name') ?? ($g['home'] ?? ($g['Home'] ?? null));
                $awayRaw = data_get($g, 'awayTeam.name') ?? ($g['away'] ?? ($g['Away'] ?? null));
                $home = $this->canonicalTeam($homeRaw);
                $away = $this->canonicalTeam($awayRaw);
                $title = (is_string($home) && is_string($away)) ? ($home.' vs '.$away) : (data_get($g, 'title') ?? 'Match');
                $commence = data_get($g, 'start') ?? data_get($g, 'datetime') ?? data_get($g, 'date') ?? data_get($g, 'startTime') ?? null;
                if (!$commence) continue;
                try { $dt = Carbon::parse($commence); } catch (\Throwable $e) { continue; }
                $statusName = data_get($g, 'statusName') ?? data_get($g, 'status');
                $isEnded = $statusName ? (stripos((string)$statusName, 'finish') !== false || stripos((string)$statusName, 'ended') !== false) : false;
                if ($isEnded || $dt->lte($now)) continue;

                // inline 1x2 odds extraction
                [$h,$d,$a] = $this->extractInlineOddsFromGame($g);
                if (!is_numeric($h) || !is_numeric($d) || !is_numeric($a)) {
                    continue; // без полного набора коэффициентов не создаём событие
                }

                // Апсерт по каноническим названиям и точному времени, чтобы избежать дублей от синонимов
                Event::updateOrCreate(
                    [
                        'home_team' => $home,
                        'away_team' => $away,
                        'starts_at' => $dt,
                    ],
                    [
                        'competition' => $competition,
                        'title' => $title,
                        'status' => 'scheduled',
                        'home_odds' => (float)$h,
                        'draw_odds' => (float)$d,
                        'away_odds' => (float)$a,
                    ]
                );
            }
        } catch (\Throwable $e) {
            // Молча игнорируем, чтобы не ломать главную страницу при сбоях внешнего API
            return;
        }
    }

    /**
     * Извлекает коэффициенты 1x2 из блока odds игры.
     */
    private function extractInlineOddsFromGame(array $game): array
    {
        $markets = data_get($game, 'odds');
        if (!is_array($markets)) return [null, null, null];
        $home = null; $draw = null; $away = null;
        foreach ($markets as $m) {
            $marketId = $m['marketId'] ?? $m['id'] ?? null;
            $name = strtolower((string)($m['marketName'] ?? $m['name'] ?? ''));
            if ($marketId === 1 || str_contains($name, '1x2') || str_contains($name, 'match odds') || str_contains($name, 'win-draw-win')) {
                $sels = $m['odds'] ?? $m['selections'] ?? [];
                foreach ($sels as $sel) {
                    $label = strtolower((string)($sel['name'] ?? $sel['label'] ?? ''));
                    $value = $sel['value'] ?? $sel['price'] ?? $sel['decimal'] ?? $sel['odds'] ?? null;
                    if (!is_numeric($value)) continue;
                    if (str_contains($label, 'home')) $home = (float)$value;
                    elseif (str_contains($label, 'draw')) $draw = (float)$value;
                    elseif (str_contains($label, 'away')) $away = (float)$value;
                }
                break;
            }
        }
        return [$home, $draw, $away];
    }

    /**
     * Нормализует названия команд к каноническому виду, чтобы избежать дублей.
     */
    private function canonicalTeam(?string $name): ?string
    {
        if (!$name) return $name;
        $n = strtolower(trim($name));
        $aliases = [
            'wolves' => 'Wolverhampton Wanderers',
            'wolverhampton' => 'Wolverhampton Wanderers',
            'wolverhampton wanderers' => 'Wolverhampton Wanderers',
            'brighton' => 'Brighton and Hove Albion',
            'brighton & hove albion' => 'Brighton and Hove Albion',
            'brighton and hove albion' => 'Brighton and Hove Albion',
            'newcastle' => 'Newcastle United',
            'newcastle utd' => 'Newcastle United',
            'newcastle united' => 'Newcastle United',
            'leeds' => 'Leeds United',
            'leeds utd' => 'Leeds United',
            'leeds united' => 'Leeds United',
            'man city' => 'Manchester City',
            'manchester city' => 'Manchester City',
            'man united' => 'Manchester United',
            'manchester utd' => 'Manchester United',
            'manchester united' => 'Manchester United',
            'nottingham forest' => 'Nottingham Forest',
            'chelsea' => 'Chelsea',
            'arsenal' => 'Arsenal',
            'everton' => 'Everton',
            'fulham' => 'Fulham',
            'west ham' => 'West Ham',
            'burnley' => 'Burnley',
            'tottenham' => 'Tottenham',
            'crystal palace' => 'Crystal Palace',
            'bournemouth' => 'Bournemouth',
            'aston villa' => 'Aston Villa',
            'liverpool' => 'Liverpool',
            'brentford' => 'Brentford',
        ];
        return $aliases[$n] ?? ucwords($n);
    }

    public function store(Request $request)
    {
        // Expect one coupon with many items (parlay)
        $data = $request->validate([
            // Если пользователь авторизован, имя игрока не требуется — используем его логин (username)
            'bettor_name' => [\Illuminate\Support\Facades\Auth::check() ? 'nullable' : 'required', 'string', 'max:100'],
            'amount_demo' => ['required', 'numeric', 'min:0.01'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.event_id' => ['required', 'exists:events,id'],
            'items.*.selection' => ['required', 'in:home,draw,away'],
        ]);

        // Определяем имя игрока: для авторизованного — username, иначе — из формы
        $bettorName = $data['bettor_name'] ?? null;
        if (\Illuminate\Support\Facades\Auth::check()) {
            $user = \Illuminate\Support\Facades\Auth::user();
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
                // 1) Дата близка к локальной (<= 24ч);
                // 2) Локальное событие ещё scheduled, но API говорит, что матч уже прошёл — обновляем по API времени.
                $inWindow = ($ev->starts_at !== null) ? ($ev->diffMin <= 24*60) : false;
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
    // Note: Resolver removed in favor of direct tournament fetch by ID for deterministic EPL output.
}
