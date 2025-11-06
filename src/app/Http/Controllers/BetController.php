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
use Barryvdh\Debugbar\Facades\Debugbar;

class BetController extends Controller
{
    public function index()
    {
        // Рендер полностью развязан от обновлений: только чтение из БД.
        $marketsMap = [];
        $gameIdsMap = [];

        // Получаем отдельные ленты событий: EPL, UCL, ITA
        $hasCompetition = Schema::hasColumn('events', 'competition');
        Debugbar::startMeasure('Events_load', 'Load events for leagues');
        if ($hasCompetition) {
            $eventsEpl = Event::with('bets')
                ->where('competition', 'EPL')
                ->where('status', 'scheduled')
                ->where('starts_at', '>', now())
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->limit(60)
                ->get();
            $eventsUcl = Event::with('bets')
                ->where('competition', 'UCL')
                ->where('status', 'scheduled')
                ->where('starts_at', '>', now())
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->limit(60)
                ->get();
            $eventsIta = Event::with('bets')
                ->where('competition', 'ITA')
                ->where('status', 'scheduled')
                ->where('starts_at', '>', now())
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->limit(60)
                ->get();
        } else {
            // Фоллбэк до применения миграции: показываем все события 
            $eventsEpl = Event::with('bets')
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get();
            $eventsUcl = collect();
            $eventsIta = collect();
        }
        Debugbar::stopMeasure('Events_load');
        // Формируем человекочитаемые заголовки без канонизации: используем "сырые" названия.
        $prepareForView = function ($collection) {
            return $collection->map(function ($ev) {
                try {
                    $home = trim((string)($ev->home_team ?? ''));
                    $away = trim((string)($ev->away_team ?? ''));
                    $ev->title = ($home !== '' || $away !== '') ? trim($home.' vs '.$away) : ($ev->title ?? '');
                } catch (\Throwable $e) { /* no-op */ }
                return $ev;
            });
        };
        $eventsEpl = $prepareForView($eventsEpl);
        $eventsUcl = $prepareForView($eventsUcl);
        $eventsIta = $prepareForView($eventsIta);

        // Сформируем карту соответствий event_id -> external_id для ленивой загрузки рынков
        foreach ([$eventsEpl, $eventsUcl, $eventsIta] as $collection) {
            foreach ($collection as $ev) {
                if (!empty($ev->external_id)) {
                    $gameIdsMap[$ev->id] = (string)$ev->external_id;
                }
            }
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
            'marketsMap' => $marketsMap,
            'gameIdsMap' => $gameIdsMap,
        ]);
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

    // Обновление лиг и апсёрты удалены: index() не вызывает внешний API и не пишет в БД.

    // Приводим названия команд к каноничному виду, чтобы избежать дублей
    private function canonicalTeamName(?string $name): ?string
    {
        if ($name === null) return null;
        $n = trim($name);
        if ($n === '') return $n;
        // Уберём лишние пробелы
        $n = preg_replace('/\s+/', ' ', $n);
        $lower = mb_strtolower($n);
        // Приведём распространённые алиасы клубов к единообразным названиям
        $aliases = [
            'man utd' => 'Manchester United',
            'manchester utd' => 'Manchester United',
            'man united' => 'Manchester United',
            'man city' => 'Manchester City',
            'bayern munchen' => 'Bayern Munich',
            'psg' => 'Paris Saint-Germain',
            'barca' => 'Barcelona',
            'real mad' => 'Real Madrid',
            'inter milano' => 'Inter Milan',
            'ath bilbao' => 'Athletic Bilbao',
            'borussia m' => 'Borussia Monchengladbach',
            'cska moskva' => 'CSKA Moscow',
            'spartak m' => 'Spartak Moscow',
            'ogc nice' => 'Nice',
            'ol lyon' => 'Lyon',
            'as roma' => 'Roma',
            'ss lazio' => 'Lazio',
            // EPL консистентные короткие формы
            'leeds united' => 'Leeds',
            'newcastle united' => 'Newcastle',
            'brighton & hove albion' => 'Brighton',
            'brighton and hove albion' => 'Brighton',
            'wolverhampton wanderers' => 'Wolves',
            'west ham united' => 'West Ham',
            'afc bournemouth' => 'Bournemouth',
            'tottenham hotspur' => 'Tottenham',
            'nottm forest' => 'Nottingham Forest',
            'west bromwich albion' => 'West Brom',
            'queens park rangers' => 'QPR',
            'sheffield united' => 'Sheffield United',
            'sheffield wednesday' => 'Sheffield Wednesday',
            'bristol rovers' => 'Bristol Rovers',
            'bristol city' => 'Bristol City',
            'mk dons' => 'Milton Keynes Dons',
            'manchester u.' => 'Manchester United',
            'manchester c.' => 'Manchester City',
        ];
        if (isset($aliases[$lower])) return $aliases[$lower];
        // Нормализуем "FC"
        $n = preg_replace('/\bfc\b/i', 'FC', $n);
        // Уберём префиксы/суффиксы вида "AFC ", "CF", "C.F.", "Club"
        $n = preg_replace('/^AFC\s+/i', '', $n);
        $n = preg_replace('/\bC\.?F\.?\b/i', '', $n);
        $n = preg_replace('/\bClub\b/i', '', $n);
        // Унифицируем "&" и "and"
        $n = preg_replace('/\s*&\s*/', ' and ', $n);
        $n = preg_replace('/\s+and\s+/', ' and ', $n);
        // Дополнительная очистка двойных пробелов после замен
        $n = preg_replace('/\s+/', ' ', trim($n));
        return $n;
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
                $extId = data_get($apiEv, 'id') ?? data_get($apiEv, 'game.id') ?? data_get($apiEv, 'GameId') ?? data_get($apiEv, 'gameid') ?? null;
                $homeName = data_get($apiEv, 'homeTeam.name');
                $awayName = data_get($apiEv, 'awayTeam.name');
                $homeScore = is_numeric($apiEv['homeResult'] ?? null) ? (int)$apiEv['homeResult'] : null;
                $awayScore = is_numeric($apiEv['awayResult'] ?? null) ? (int)$apiEv['awayResult'] : null;
                $ts = $apiEv['date'] ?? null;
                $apiTime = $ts ? Carbon::parse($ts) : null;

                // Требуем валидные данные и только прошедшие матчи
                if (!$extId || !$homeName || !$awayName || $homeScore === null || $awayScore === null || !$apiTime || $apiTime->isFuture()) continue;

                // Ищем событие по external_id
                $ev = Event::query()->where('external_id', (string)$extId)->first();

                // Если локального события нет — создадим его как завершённое с результатом
                if (!$ev) {
                    $result = 'draw';
                    if ($homeScore > $awayScore) $result = 'home';
                    elseif ($awayScore > $homeScore) $result = 'away';

                    Event::create([
                        'external_id' => (string)$extId,
                        'title' => trim((string)$homeName.' vs '.(string)$awayName),
                        'home_team' => (string)$homeName,
                        'away_team' => (string)$awayName,
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

                // Определяем результат
                $result = 'draw';
                if ($homeScore > $awayScore) $result = 'home';
                elseif ($awayScore > $homeScore) $result = 'away';

                // Обновляем событие
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
