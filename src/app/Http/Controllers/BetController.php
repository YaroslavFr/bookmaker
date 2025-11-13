<?php

namespace App\Http\Controllers;

use App\Models\Bet;
use App\Models\Event;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        $leagues = [];
        // Заголовки лиг по коду берём из общего конфига
        $leagueTitlesByCode = [];
        foreach (config('leagues.leagues') as $code => $info) {
            // Формируем человекочитаемый заголовок: используем "title" или сам код, если "title" отсутствует
            $leagueTitlesByCode[$code] = $info['title'] ?? $code;
        }

        $hasCompetition = Schema::hasColumn('events', 'competition');
        if ($hasCompetition) {
            // Получаем список доступных чемпионатов из БД для ближайших запланированных матчей
            $competitions = Event::query()
                ->where('status', 'scheduled')
                ->where('starts_at', '>', now())
                ->select('competition')
                ->distinct()
                ->pluck('competition')
                ->filter()
                ->values();

            $eventsByCompetition = [];
            foreach ($competitions as $comp) {
                $collection = Event::with('bets')
                    ->where('competition', $comp)
                    ->where('status', 'scheduled')
                    ->where('starts_at', '>', now())
                    ->orderByDesc('starts_at')
                    ->orderByDesc('id')
                    ->limit(12)
                    ->get();
                $collection = $prepareForView($collection);

                // Заголовок: человекочитаемое название или сырой код
                $leagues[] = [
                    'title' => (string) ($leagueTitlesByCode[(string)$comp] ?? (string)$comp),
                    'events' => $collection,
                ];

                // Ассоциативное кеширование лент по коду чемпионата
                $eventsByCompetition[(string) $comp] = $collection;
            
            }

        } else {
            // Фоллбэк до применения миграции: показываем все события одной лентой
            $all = Event::with('bets')
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get();
            $all = $prepareForView($all);
            $leagues[] = [
                'title' => 'События',
                'events' => $all,
            ];
        }

        if ((string) request()->query('debug') === 'competitions') {
            // Гарантируем, что панель включена для запроса
            try { Debugbar::enable(); } catch (\Throwable $e) { /* no-op */ }
            $titles = [];
            foreach ($competitions as $comp) {
                $titles[(string)$comp] = (string) ($leagueTitlesByCode[(string)$comp] ?? (string)$comp);
            }
        }

        // Сформируем карту соответствий event_id -> external_id для ленивой загрузки рынков из всех лиг
        foreach ($leagues as $lg) {
            foreach ($lg['events'] as $ev) {
                if (!empty($ev->external_id)) {
                    $gameIdsMap[$ev->id] = (string) $ev->external_id;
                }
            }
        }

        $coupons = Coupon::with(['bets.event'])->latest()->limit(50)->get();

        return view('home', [
            'leagues' => $leagues,
            'coupons' => $coupons,
            'marketsMap' => $marketsMap,
            'gameIdsMap' => $gameIdsMap,
        ]);
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
            // Разрешаем произвольные селекции для доп. рынков
            'items.*.selection' => ['required', 'string', 'max:100'],
            // Для доп. рынков принимаем кэф с клиента (необязательный)
            'items.*.odds' => ['nullable', 'numeric', 'min:0.01'],
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
            $sel = (string) ($item['selection'] ?? '');
            // Для основных исходов берём кэфы из события, для доп. рынков — из payload
            if (in_array($sel, ['home','draw','away'], true)) {
                $odds = match ($sel) {
                    'home' => $ev->home_odds,
                    'draw' => $ev->draw_odds,
                    'away' => $ev->away_odds,
                };
                $totalOdds *= ($odds ?? 1);
            } else {
                $placed = $item['odds'] ?? null;
                $totalOdds *= (is_numeric($placed) ? (float)$placed : 1);
            }
        }

        $coupon = null;
        if (\Illuminate\Support\Facades\Auth::check()) {
            $user = \Illuminate\Support\Facades\Auth::user();
            try {
                DB::transaction(function () use ($user, $bettorName, $data, $totalOdds, &$coupon) {
                    $u = User::where('id', $user->id)->lockForUpdate()->first();
                    $amount = (float) ($data['amount_demo'] ?? 0);
                    if ($amount <= 0) {
                        throw new \RuntimeException('Неверная сумма');
                    }
                    if ((float) ($u->balance ?? 0) < $amount) {
                        throw new \RuntimeException('Недостаточно средств');
                    }
                    $u->balance = (float) $u->balance - $amount;
                    $u->save();
                    $coupon = Coupon::create([
                        'bettor_name' => $bettorName,
                        'amount_demo' => $data['amount_demo'],
                        'total_odds' => $totalOdds,
                    ]);
                });
            } catch (\RuntimeException $e) {
                if ($request->wantsJson()) {
                    return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
                }
                return redirect()->back()->withErrors(['amount_demo' => $e->getMessage()]);
            }
        } else {
            $coupon = Coupon::create([
                'bettor_name' => $bettorName,
                'amount_demo' => $data['amount_demo'],
                'total_odds' => $totalOdds,
            ]);
        }

        // Create legs as Bet rows linked to the coupon
        foreach ($data['items'] as $item) {
            $sel = (string) ($item['selection'] ?? '');
            $placedOdds = null;
            if (in_array($sel, ['home','draw','away'], true)) {
                // Основной рынок 1x2: берём кэфы из события
                $ev = Event::find($item['event_id']);
                if ($ev) {
                    $placedOdds = match ($sel) {
                        'home' => $ev->home_odds,
                        'draw' => $ev->draw_odds,
                        'away' => $ev->away_odds,
                    };
                }
            } else {
                // Доп. рынок: используем переданный коэффициент
                $od = $item['odds'] ?? null;
                if (is_numeric($od)) { $placedOdds = (float) $od; }
            }
            Bet::create([
                'event_id' => $item['event_id'],
                'bettor_name' => $bettorName,
                'amount_demo' => $data['amount_demo'],
                'selection' => $sel,
                'placed_odds' => $placedOdds,
                'coupon_id' => $coupon->id,
            ]);
        }

        if ($request->wantsJson()) {
            $balance = null;
            if (\Illuminate\Support\Facades\Auth::check()) {
                $balance = (float) (\Illuminate\Support\Facades\Auth::user()->balance ?? 0);
            }
            return response()->json([
                'status' => 'ok',
                'coupon_id' => $coupon->id,
                'total_odds' => $totalOdds,
                'balance' => $balance,
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
