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
                ->where('starts_at', '>', Carbon::now())
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
                    ->where('starts_at', '>', Carbon::now())
                    ->orderBy('starts_at')
                    ->orderBy('id')
                    ->limit(44)
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
            'items.*.market' => ['nullable', 'string', 'max:100'],
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
        $newBalance = null;
        if (\Illuminate\Support\Facades\Auth::check()) {
            $user = \Illuminate\Support\Facades\Auth::user();
            try {
                DB::transaction(function () use ($user, $bettorName, $data, $totalOdds, &$coupon, &$newBalance) {
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
                    $newBalance = (float) $u->balance;
                    $coupon = Coupon::create([
                        'bettor_name' => $bettorName,
                        'amount_demo' => $data['amount_demo'],
                        'total_odds' => $totalOdds,
                    ]);
                });
                if ($newBalance !== null) { $user->balance = $newBalance; }
            } catch (\RuntimeException $e) {
                $expectsJson = $request->expectsJson() || str_contains((string) $request->header('Accept'), 'application/json') || strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest';
                if ($expectsJson) {
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
                'market' => $item['market'] ?? null,
            ]);
        }

        $expectsJson = $request->expectsJson() || str_contains((string) $request->header('Accept'), 'application/json') || strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest';
        if ($expectsJson) {
            $balance = null;
            if (\Illuminate\Support\Facades\Auth::check()) {
                $balance = $newBalance !== null ? (float) $newBalance : (float) (\Illuminate\Support\Facades\Auth::user()->balance ?? 0);
            }
            $full = Coupon::with(['bets.event'])->find($coupon->id);
            $couponPayload = null;
            if ($full) {
                $bets = [];
                foreach (($full->bets ?? []) as $b) {
                    $bets[] = [
                        'event' => [
                            'home_team' => $b->event->home_team ?? null,
                            'away_team' => $b->event->away_team ?? null,
                            'title' => $b->event->title ?? null,
                            'starts_at' => $b->event->starts_at ? $b->event->starts_at->toIso8601String() : null,
                        ],
                        'selection' => (string) $b->selection,
                        'placed_odds' => $b->placed_odds !== null ? (float) $b->placed_odds : null,
                        'market' => $b->market ?? null,
                    ];
                }
                $evTimes = collect($full->bets ?? [])
                    ->filter(function($b){ return $b && $b->event && $b->event->starts_at; })
                    ->map(function($b){ return $b->event->starts_at; });
                $latestStart = $evTimes->max();
                $settlementAt = $latestStart ? $latestStart->copy()->addMinutes(120)->setTimezone('Europe/Moscow') : null;
                $couponPayload = [
                    'id' => $full->id,
                    'bettor_name' => (string) $full->bettor_name,
                    'amount_demo' => $full->amount_demo !== null ? (float) $full->amount_demo : null,
                    'total_odds' => $full->total_odds !== null ? (float) $full->total_odds : null,
                    'is_win' => $full->is_win,
                    'created_at' => $full->created_at ? $full->created_at->toIso8601String() : null,
                    'settlement_at' => $settlementAt ? $settlementAt->toIso8601String() : null,
                    'settlement_at_display' => $settlementAt ? $settlementAt->format('Y-m-d H:i') : null,
                    'bets' => $bets,
                ];
            }
            return response()->json([
                'status' => 'ok',
                'coupon_id' => $coupon->id,
                'total_odds' => $totalOdds,
                'balance' => $balance,
                'coupon' => $couponPayload,
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
    
    public function autoSettleDue()
    {
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        $key = config('services.sstats.key');
        $headers = $key ? ['X-API-KEY' => $key] : [];
        $dueEvents = Event::query()
            ->where('status', '=', 'finished')
            ->limit(200)
            ->get();
        foreach ($dueEvents as $ev) {
            $extId = (string) ($ev->external_id ?? '');
            $resp = $extId ? Http::withHeaders($headers)->timeout(20)->get($base.'/Games/list', ['id' => $extId]) : null;
            $payload = $resp && !$resp->failed() ? ($resp->json('data.0') ?? $resp->json('data') ?? []) : [];
            $homeScore = is_numeric(data_get($payload, 'homeResult')) ? (int) data_get($payload, 'homeResult') : null;
            $awayScore = is_numeric(data_get($payload, 'awayResult')) ? (int) data_get($payload, 'awayResult') : null;
            $homeSt1 = is_numeric(data_get($payload, 'homeResultSt1')) ? (int) data_get($payload, 'homeResultSt1') : null;
            $awaySt1 = is_numeric(data_get($payload, 'awayResultSt1')) ? (int) data_get($payload, 'awayResultSt1') : null;
            $homeSt2 = is_numeric(data_get($payload, 'homeResultSt2')) ? (int) data_get($payload, 'homeResultSt2') : null;
            $awaySt2 = is_numeric(data_get($payload, 'awayResultSt2')) ? (int) data_get($payload, 'awayResultSt2') : null;
            if ($homeSt2 === null && $awaySt2 === null && $homeScore !== null && $awayScore !== null && $homeSt1 !== null && $awaySt1 !== null) {
                $homeSt2 = max(0, $homeScore - $homeSt1);
                $awaySt2 = max(0, $awayScore - $awaySt1);
            }
            if ($homeScore === null || $awayScore === null) { $homeScore = 0; $awayScore = 0; }
            $ev->home_result = $homeScore; $ev->away_result = $awayScore;
            $ev->home_ht_result = $homeSt1; $ev->away_ht_result = $awaySt1;
            $ev->home_st2_result = $homeSt2; $ev->away_st2_result = $awaySt2;
            $ev->result = $homeScore === $awayScore ? 'draw' : ($homeScore > $awayScore ? 'home' : 'away');
            $ev->result_text = 'HT(' . ($homeSt1 ?? 0) . ':' . ($awaySt1 ?? 0) . ') 2T(' . ($homeSt2 ?? 0) . ':' . ($awaySt2 ?? 0) . ') FT ' . ($homeScore ?? 0) . ':' . ($awayScore ?? 0);
            $ev->save();
            $ev->bets()->whereNull('settled_at')->each(function (Bet $bet) use ($homeScore, $awayScore, $homeSt1, $awaySt1, $homeSt2, $awaySt2, $ev) {
                $market = trim((string) ($bet->market ?? ''));
                $selection = trim(strtolower((string) $bet->selection));
                $amount = (float) ($bet->amount_demo ?? 0);
                $odds = (float) ($bet->placed_odds ?? 0);
                $win = false; $payout = 0.0; $settled = false;
                if ($market === '' || in_array($selection, ['home','draw','away'], true)) {
                    $win = ($selection === 'home' && $homeScore > $awayScore) || ($selection === 'away' && $awayScore > $homeScore) || ($selection === 'draw' && $homeScore === $awayScore);
                    $settled = true;
                } elseif (stripos($market, '2 тайм') !== false) {
                    if ($homeSt2 !== null && $awaySt2 !== null) {
                        $win = ($selection === 'home' && $homeSt2 > $awaySt2) || ($selection === 'away' && $awaySt2 > $homeSt2) || ($selection === 'draw' && $homeSt2 === $awaySt2);
                        $settled = true;
                    }
                } elseif (stripos($market, 'тоталы 1 тайм') !== false) {
                    if ($homeSt1 !== null && $awaySt1 !== null) {
                        $total = $homeSt1 + $awaySt1;
                        if (preg_match('/^(over|under)\s*([0-9]+(?:\.[0-9]+)?)$/i', $bet->selection, $m)) {
                            $type = strtolower($m[1]); $line = (float) $m[2];
                            if ($type === 'over') {
                                if ($total > $line) { $win = true; }
                                elseif ($total == $line) { $payout = $amount; }
                                else { $win = false; }
                            } else {
                                if ($total < $line) { $win = true; }
                                elseif ($total == $line) { $payout = $amount; }
                                else { $win = false; }
                            }
                            $settled = true;
                        }
                    }
                } elseif (stripos($market, 'тоталы 2 тайм') !== false) {
                    if ($homeSt2 !== null && $awaySt2 !== null) {
                        $total = $homeSt2 + $awaySt2;
                        if (preg_match('/^(over|under)\s*([0-9]+(?:\.[0-9]+)?)$/i', $bet->selection, $m)) {
                            $type = strtolower($m[1]); $line = (float) $m[2];
                            if ($type === 'over') {
                                if ($total > $line) { $win = true; }
                                elseif ($total == $line) { $payout = $amount; }
                                else { $win = false; }
                            } else {
                                if ($total < $line) { $win = true; }
                                elseif ($total == $line) { $payout = $amount; }
                                else { $win = false; }
                            }
                            $settled = true;
                        }
                    }
                } elseif (stripos($market, 'точный счет') !== false || stripos($market, 'exact score') !== false) {
                    if ($homeScore !== null && $awayScore !== null && preg_match('/^(\d+)\s*[:\-]\s*(\d+)$/', (string) $bet->selection, $m)) {
                        $h = (int) $m[1];
                        $a = (int) $m[2];
                        $win = ($h === $homeScore && $a === $awayScore);
                        $settled = true;
                    }
                } elseif (preg_match('/^\d+\s*[:\-]\s*\d+$/', (string) $bet->selection)) {
                    if ($homeScore !== null && $awayScore !== null) {
                        if (preg_match('/^(\d+)\s*[:\-]\s*(\d+)$/', (string) $bet->selection, $m)) {
                            $h = (int) $m[1];
                            $a = (int) $m[2];
                            $win = ($h === $homeScore && $a === $awayScore);
                            $settled = true;
                        }
                    }
                } elseif (stripos($market, 'тоталы') !== false) {
                    $total = $homeScore + $awayScore;
                    Debugbar::addMessage($total, 'total');
                    if (preg_match('/^(over|under)\s*([0-9]+(?:\.[0-9]+)?)$/i', $bet->selection, $m)) {
                        $type = strtolower($m[1]); $line = (float) $m[2];
                        if (fmod($line, 0.5) === 0.25) {
                            $lower = $line - 0.25; $upper = $line + 0.25;
                            $winLower = $type === 'over' ? ($total > $lower) : ($total < $lower);
                            $winUpper = $type === 'over' ? ($total > $upper) : ($total < $upper);
                            $win = $winLower && $winUpper;
                            if (!$win && ($winLower || $winUpper)) { $payout = $amount * $odds * 0.5; }
                            $settled = true;
                        } else {
                            if ($type === 'over') {
                                if ($total > $line) { $win = true; $settled = true; }
                                elseif ($total == $line) { $payout = $amount; $settled = true; }
                                else { $win = false; $settled = true; }
                            } else {
                                if ($total < $line) { $win = true; $settled = true; }
                                elseif ($total == $line) { $payout = $amount; $settled = true; }
                                else { $win = false; $settled = true; }
                            }
                        }
                    }
                } elseif (stripos($market, 'обе забьют') !== false) {
                    $yes = strpos($bet->selection, 'Yes') !== false || strpos($bet->selection, 'Да') !== false;
                    $no = strpos($bet->selection, 'No') !== false || strpos($bet->selection, 'Нет') !== false;
                    if ($yes || $no) { $win = $yes ? ($homeScore > 0 && $awayScore > 0) : !($homeScore > 0 && $awayScore > 0); $settled = true; }
                } elseif (preg_match('/^1\s*забьет/i', $market)) {
                    $yes = strpos($bet->selection, 'Yes') !== false || strpos($bet->selection, 'Да') !== false;
                    $no = strpos($bet->selection, 'No') !== false || strpos($bet->selection, 'Нет') !== false;
                    if ($yes || $no) { $win = $yes ? ($homeScore > 0) : ($homeScore === 0); $settled = true; }
                } elseif (preg_match('/^2\s*забьет/i', $market)) {
                    $yes = strpos($bet->selection, 'Yes') !== false || strpos($bet->selection, 'Да') !== false;
                    $no = strpos($bet->selection, 'No') !== false || strpos($bet->selection, 'Нет') !== false;
                    if ($yes || $no) { $win = $yes ? ($awayScore > 0) : ($awayScore === 0); $settled = true; }
                } elseif (stripos($market, 'азиатский гандикап') !== false || stripos($market, 'фора') !== false) {
                    if (preg_match('/^(home|away)\s*([+-]?\d+(?:\.\d+)?)$/i', $bet->selection, $m)) {
                        $team = strtolower($m[1]); $line = (float) $m[2];
                        $adjHome = $homeScore + ($team === 'home' ? $line : 0);
                        $adjAway = $awayScore + ($team === 'away' ? $line : 0);
                        if (fmod($line, 0.5) === 0.25) {
                            $l1 = $line - 0.25; $l2 = $line + 0.25;
                            $adjHome1 = $homeScore + ($team === 'home' ? $l1 : 0);
                            $adjAway1 = $awayScore + ($team === 'away' ? $l1 : 0);
                            $adjHome2 = $homeScore + ($team === 'home' ? $l2 : 0);
                            $adjAway2 = $awayScore + ($team === 'away' ? $l2 : 0);
                            $win1 = $adjHome1 > $adjAway1; $win2 = $adjHome2 > $adjAway2;
                            $win = $win1 && $win2;
                            if (!$win && ($win1 || $win2)) { $payout = $amount * $odds * 0.5; }
                            $settled = true;
                        } else {
                            if ($adjHome > $adjAway) { $win = true; $settled = true; }
                            elseif ($adjHome == $adjAway) { $payout = $amount; $settled = true; }
                            else { $win = false; $settled = true; }
                        }
                    }
                } elseif (stripos($market, '1 тайм / 2 тайм') !== false) {
                    if ($homeSt1 !== null && $awaySt1 !== null) {
                        $fh = $homeSt1 === $awaySt1 ? 'draw' : ($homeSt1 > $awaySt1 ? 'home' : 'away');
                        $ft = $homeScore === $awayScore ? 'draw' : ($homeScore > $awayScore ? 'home' : 'away');
                        $parts = explode('/', str_replace(' ', '', strtolower($bet->selection)));
                        if (count($parts) === 2) { $win = ($parts[0] === $fh && $parts[1] === $ft); $settled = true; }
                    }
                }
                if ($settled) {
                    $bet->is_win = ($payout === $amount && !$win) ? null : $win;
                    if ($win) { $payout = $payout > 0 ? $payout : ($amount * ($odds ?: 1)); }
                    $bet->payout_demo = $payout;
                    $bet->settled_at = now();
                    $bet->save();
                }
            });
            $affectedCouponIds = $ev->bets()->pluck('coupon_id')->filter()->unique();
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
        }
        return redirect()->route('home')->with('status', 'Авторасчёт завершён');
    }

    public function processDueScheduled100()
    {
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        $key = config('services.sstats.key');
        $headers = $key ? ['X-API-KEY' => $key] : [];
        $now = Carbon::now()->subMinutes(100);
        $events = Event::query()
            ->where('status', 'scheduled')
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', $now)
            ->limit(20)
            ->get();
        Debugbar::addMessage($events->toArray(), 'events');
        foreach ($events as $ev) {
            $extId = (string) ($ev->external_id ?? '');
            if (!$extId) { continue; }
            $resp = Http::withHeaders($headers)->timeout(20)->get($base.'/Games/list', ['id' => $extId]);
            if ($resp->failed()) { continue; }
            $payload = $resp->json('data.0') ?? $resp->json('data') ?? [];
            $homeScore = is_numeric(data_get($payload, 'homeResult')) ? (int) data_get($payload, 'homeResult') : null;
            $awayScore = is_numeric(data_get($payload, 'awayResult')) ? (int) data_get($payload, 'awayResult') : null;
            $homeSt1 = is_numeric(data_get($payload, 'homeHTResult')) ? (int) data_get($payload, 'homeHTResult') : (is_numeric(data_get($payload, 'homeResultSt1')) ? (int) data_get($payload, 'homeResultSt1') : null);
            $awaySt1 = is_numeric(data_get($payload, 'awayHTResult')) ? (int) data_get($payload, 'awayHTResult') : (is_numeric(data_get($payload, 'awayResultSt1')) ? (int) data_get($payload, 'awayResultSt1') : null);
            $homeSt2 = is_numeric(data_get($payload, 'homeResultSt2')) ? (int) data_get($payload, 'homeResultSt2') : null;
            $awaySt2 = is_numeric(data_get($payload, 'awayResultSt2')) ? (int) data_get($payload, 'awayResultSt2') : null;
            if ($homeSt2 === null && $awaySt2 === null && $homeScore !== null && $awayScore !== null && $homeSt1 !== null && $awaySt1 !== null) {
                $homeSt2 = max(0, $homeScore - $homeSt1);
                $awaySt2 = max(0, $awayScore - $awaySt1);
            }
            if ($homeScore === null || $awayScore === null) { continue; }
            $ev->home_result = $homeScore;
            $ev->away_result = $awayScore;
            $ev->home_ht_result = $homeSt1;
            $ev->away_ht_result = $awaySt1;
            $ev->home_st2_result = $homeSt2;
            $ev->away_st2_result = $awaySt2;
            $ev->result = $homeScore === $awayScore ? 'draw' : ($homeScore > $awayScore ? 'home' : 'away');
            $ev->result_text = 'HT(' . ($homeSt1 ?? 0) . ':' . ($awaySt1 ?? 0) . ') 2T(' . ($homeSt2 ?? 0) . ':' . ($awaySt2 ?? 0) . ') FT ' . ($homeScore ?? 0) . ':' . ($awayScore ?? 0);
            $ev->status = 'finished';
            $ev->ends_at = Carbon::now();
            $ev->save();
        }
        // return redirect()->route('home')->with('status', 'Обработка завершена');
    }

    public function settleUnsettledBets(Request $request)
    {
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        $key = config('services.sstats.key');
        $headers = $key ? ['X-API-KEY' => $key] : [];
        $query = Bet::with('event')->whereNull('settled_at');
        $eventId = (int) $request->query('event_id', 0);
        $betId = (int) $request->query('bet_id', 0);
        if ($betId > 0) { $query->where('id', $betId); }
        if ($eventId > 0) { $query->where('event_id', $eventId); }
        $bets = $query->orderBy('id')->limit($betId > 0 || $eventId > 0 ? 100 : 10)->get();
        $byEvent = $bets->groupBy('event_id');
        
        $changed = [];
        foreach ($byEvent as $eventId => $items) {
            $ev = $items->first()->event;
            Debugbar::addMessage($items->toArray(), 'items');
            Debugbar::addMessage($ev->toArray(), 'ev');
            if (!$ev) { continue; }
            $homeScore = is_numeric($ev->home_result) ? (int) $ev->home_result : null;
            $awayScore = is_numeric($ev->away_result) ? (int) $ev->away_result : null;
            $homeSt1 = is_numeric($ev->home_ht_result) ? (int) $ev->home_ht_result : null;
            $awaySt1 = is_numeric($ev->away_ht_result) ? (int) $ev->away_ht_result : null;
            $homeSt2 = is_numeric($ev->home_st2_result) ? (int) $ev->home_st2_result : null;
            $awaySt2 = is_numeric($ev->away_st2_result) ? (int) $ev->away_st2_result : null;
            if ($homeScore === null || $awayScore === null) {
                $payload = [];
                if ($ev->external_id) {
                    $resp = Http::withHeaders($headers)->timeout(20)->get($base.'/Games/list', ['id' => $ev->external_id]);
                    if ($resp && !$resp->failed()) {
                        $payload = $resp->json('data.0') ?? $resp->json('data') ?? [];
                    }
                }
                if (empty($payload)) {
                    try {
                        $path = base_path('result_test.json');
                        if (is_file($path)) {
                            $data = json_decode(file_get_contents($path), true);
                            $rows = data_get($data, 'data', []);
                            foreach ((array)$rows as $row) {
                                $h = trim((string) data_get($row, 'homeTeam.name'));
                                $a = trim((string) data_get($row, 'awayTeam.name'));
                                $title = trim((string) ($ev->home_team.' vs '.$ev->away_team));
                                if (($h && $a) && (mb_strtolower($h) === mb_strtolower((string)$ev->home_team)) && (mb_strtolower($a) === mb_strtolower((string)$ev->away_team))) { $payload = $row; break; }
                                $rowTitle = trim((string) (data_get($row, 'homeTeam.name').' vs '.data_get($row, 'awayTeam.name')));
                                if (mb_strtolower($rowTitle) === mb_strtolower($title)) { $payload = $row; break; }
                            }
                        }
                    } catch (\Throwable $e) {}
                }
                if (!empty($payload)) {
                    $homeScore = is_numeric(data_get($payload, 'homeResult')) ? (int) data_get($payload, 'homeResult') : null;
                    $awayScore = is_numeric(data_get($payload, 'awayResult')) ? (int) data_get($payload, 'awayResult') : null;
                    $homeSt1 = is_numeric(data_get($payload, 'homeHTResult')) ? (int) data_get($payload, 'homeHTResult') : (is_numeric(data_get($payload, 'homeResultSt1')) ? (int) data_get($payload, 'homeResultSt1') : $homeSt1);
                    $awaySt1 = is_numeric(data_get($payload, 'awayHTResult')) ? (int) data_get($payload, 'awayHTResult') : (is_numeric(data_get($payload, 'awayResultSt1')) ? (int) data_get($payload, 'awayResultSt1') : $awaySt1);
                    $homeSt2 = is_numeric(data_get($payload, 'homeResultSt2')) ? (int) data_get($payload, 'homeResultSt2') : $homeSt2;
                    $awaySt2 = is_numeric(data_get($payload, 'awayResultSt2')) ? (int) data_get($payload, 'awayResultSt2') : $awaySt2;
                }
                if ($homeSt2 === null && $awaySt2 === null && $homeScore !== null && $awayScore !== null && $homeSt1 !== null && $awaySt1 !== null) {
                    $homeSt2 = max(0, $homeScore - $homeSt1);
                    $awaySt2 = max(0, $awayScore - $awaySt1);
                }
                if ($homeScore !== null && $awayScore !== null) {
                    $ev->home_result = $homeScore; $ev->away_result = $awayScore;
                    $ev->home_ht_result = $homeSt1; $ev->away_ht_result = $awaySt1;
                    $ev->home_st2_result = $homeSt2; $ev->away_st2_result = $awaySt2;
                    $ev->result = $homeScore === $awayScore ? 'draw' : ($homeScore > $awayScore ? 'home' : 'away');
                    $ev->result_text = 'HT(' . ($homeSt1 ?? 0) . ':' . ($awaySt1 ?? 0) . ') 2T(' . ($homeSt2 ?? 0) . ':' . ($awaySt2 ?? 0) . ') FT ' . ($homeScore ?? 0) . ':' . ($awayScore ?? 0);
                    if ($ev->status !== 'finished') { $ev->status = 'finished'; $ev->ends_at = Carbon::now(); }
                    $ev->save();
                }
            }
            if ($homeScore === null || $awayScore === null) { continue; }
            
            foreach ($items as $bet) {
                $market = trim((string) ($bet->market ?? ''));
                $selection = trim(strtolower((string) $bet->selection));
                $amount = (float) ($bet->amount_demo ?? 0);
                $odds = (float) ($bet->placed_odds ?? 0);
                $win = false; $payout = 0.0; $settled = false;
                Debugbar::addMessage($market, 'market');
                if ($market === '' || in_array($selection, ['home','draw','away'], true)) {
                    $win = ($selection === 'home' && $homeScore > $awayScore) || ($selection === 'away' && $awayScore > $homeScore) || ($selection === 'draw' && $homeScore === $awayScore);
                    $settled = true;
                } elseif (stripos($market, '2 тайм') !== false) {
                    if ($homeSt2 !== null && $awaySt2 !== null) {
                        $win = ($selection === 'home' && $homeSt2 > $awaySt2) || ($selection === 'away' && $awaySt2 > $homeSt2) || ($selection === 'draw' && $homeSt2 === $awaySt2);
                        $settled = true;
                    }
                } elseif (stripos($market, 'тоталы 1 тайм') !== false) {
                    if ($homeSt1 !== null && $awaySt1 !== null) {
                        $total = $homeSt1 + $awaySt1;
                        if (preg_match('/^(over|under)\s*([0-9]+(?:\.[0-9]+)?)$/i', $bet->selection, $m)) {
                            $type = strtolower($m[1]); $line = (float) $m[2];
                            if ($type === 'over') {
                                if ($total > $line) { $win = true; }
                                elseif ($total == $line) { $payout = $amount; }
                                else { $win = false; }
                            } else {
                                if ($total < $line) { $win = true; }
                                elseif ($total == $line) { $payout = $amount; }
                                else { $win = false; }
                            }
                            $settled = true;
                        }
                    }
                } elseif (stripos($market, 'тоталы 2 тайм') !== false) {
                    if ($homeSt2 !== null && $awaySt2 !== null) {
                        $total = $homeSt2 + $awaySt2;
                        if (preg_match('/^(over|under)\s*([0-9]+(?:\.[0-9]+)?)$/i', $bet->selection, $m)) {
                            $type = strtolower($m[1]); $line = (float) $m[2];
                            if ($type === 'over') {
                                if ($total > $line) { $win = true; }
                                elseif ($total == $line) { $payout = $amount; }
                                else { $win = false; }
                            } else {
                                if ($total < $line) { $win = true; }
                                elseif ($total == $line) { $payout = $amount; }
                                else { $win = false; }
                            }
                            $settled = true;
                        }
                    }
                } elseif (stripos($market, 'точный счет') !== false || stripos($market, 'exact score') !== false) {
                    if ($homeScore !== null && $awayScore !== null && preg_match('/^(\d+)\s*[:\-]\s*(\d+)$/', (string) $bet->selection, $m)) {
                        $h = (int) $m[1];
                        $a = (int) $m[2];
                        $win = ($h === $homeScore && $a === $awayScore);
                        $settled = true;
                    }
                } elseif (stripos($market, 'тоталы') !== false) {
                    $total = $homeScore + $awayScore;
                    Debugbar::addMessage($total, 'total');
                    if (preg_match('/^(over|under)\s*([0-9]+(?:\.[0-9]+)?)$/i', $bet->selection, $m)) {
                        $type = strtolower($m[1]); $line = (float) $m[2];
                        if (fmod($line, 0.5) === 0.25) {
                            $lower = $line - 0.25; $upper = $line + 0.25;
                            $winLower = $type === 'over' ? ($total > $lower) : ($total < $lower);
                            $winUpper = $type === 'over' ? ($total > $upper) : ($total < $upper);
                            $win = $winLower && $winUpper;
                            if (!$win && ($winLower || $winUpper)) { $payout = $amount * $odds * 0.5; }
                            $settled = true;
                        } else {
                            if ($type === 'over') {
                                if ($total > $line) { $win = true; $settled = true; }
                                elseif ($total == $line) { $payout = $amount; $settled = true; }
                                else { $win = false; $settled = true; }
                            } else {
                                if ($total < $line) { $win = true; $settled = true; }
                                elseif ($total == $line) { $payout = $amount; $settled = true; }
                                else { $win = false; $settled = true; }
                            }
                        }
                    }
                } elseif (stripos($market, 'обе забьют') !== false) {
                    $yes = strpos($bet->selection, 'Yes') !== false || strpos($bet->selection, 'Да') !== false;
                    $no = strpos($bet->selection, 'No') !== false || strpos($bet->selection, 'Нет') !== false;
                    if ($yes || $no) { $win = $yes ? ($homeScore > 0 && $awayScore > 0) : !($homeScore > 0 && $awayScore > 0); $settled = true; }
                } elseif (preg_match('/^1\s*забьет/i', $market)) {
                    $yes = strpos($bet->selection, 'Yes') !== false || strpos($bet->selection, 'Да') !== false;
                    $no = strpos($bet->selection, 'No') !== false || strpos($bet->selection, 'Нет') !== false;
                    if ($yes || $no) { $win = $yes ? ($homeScore > 0) : ($homeScore === 0); $settled = true; }
                } elseif (preg_match('/^2\s*забьет/i', $market)) {
                    $yes = strpos($bet->selection, 'Yes') !== false || strpos($bet->selection, 'Да') !== false;
                    $no = strpos($bet->selection, 'No') !== false || strpos($bet->selection, 'Нет') !== false;
                    if ($yes || $no) { $win = $yes ? ($awayScore > 0) : ($awayScore === 0); $settled = true; }
                } elseif (stripos($market, 'азиатский гандикап') !== false || stripos($market, 'фора') !== false) {
                    if (preg_match('/^(home|away)\s*([+-]?\d+(?:\.\d+)?)$/i', $bet->selection, $m)) {
                        $team = strtolower($m[1]); $line = (float) $m[2];
                        $adjHome = $homeScore + ($team === 'home' ? $line : 0);
                        $adjAway = $awayScore + ($team === 'away' ? $line : 0);
                        if (fmod($line, 0.5) === 0.25) {
                            $l1 = $line - 0.25; $l2 = $line + 0.25;
                            $adjHome1 = $homeScore + ($team === 'home' ? $l1 : 0);
                            $adjAway1 = $awayScore + ($team === 'away' ? $l1 : 0);
                            $adjHome2 = $homeScore + ($team === 'home' ? $l2 : 0);
                            $adjAway2 = $awayScore + ($team === 'away' ? $l2 : 0);
                            $win1 = $adjHome1 > $adjAway1; $win2 = $adjHome2 > $adjAway2;
                            $win = $win1 && $win2;
                            if (!$win && ($win1 || $win2)) { $payout = $amount * $odds * 0.5; }
                            $settled = true;
                        } else {
                            if ($adjHome > $adjAway) { $win = true; $settled = true; }
                            elseif ($adjHome == $adjAway) { $payout = $amount; $settled = true; }
                            else { $win = false; $settled = true; }
                        }
                    }
                } elseif (stripos($market, '1 тайм / 2 тайм') !== false) {
                    if ($homeSt1 !== null && $awaySt1 !== null) {
                        $fh = $homeSt1 === $awaySt1 ? 'draw' : ($homeSt1 > $awaySt1 ? 'home' : 'away');
                        $ft = $homeScore === $awayScore ? 'draw' : ($homeScore > $awayScore ? 'home' : 'away');
                        $parts = explode('/', str_replace(' ', '', strtolower($bet->selection)));
                        if (count($parts) === 2) { $win = ($parts[0] === $fh && $parts[1] === $ft); $settled = true; }
                    }
                }
                if ($settled) {
                    $bet->is_win = ($payout === $amount && !$win) ? null : $win;
                    if ($win) { $payout = $payout > 0 ? $payout : ($amount * ($odds ?: 1)); }
                    $bet->payout_demo = $payout;
                    $bet->settled_at = now();
                    $bet->save();
                    $changed[] = $bet->id;
                }
            }
            $affectedCouponIds = $items->pluck('coupon_id')->filter()->unique();
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
        }
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'changed' => $changed,
                'count' => count($changed),
                'fetched' => $bets->pluck('id'),
                'markets' => $bets->pluck('market'),
                'selections' => $bets->pluck('selection'),
            ]);
        }
        return redirect()->route('home')->with('status', 'Нерассчитанные ставки обработаны');
    }
    // Note: Resolver removed in favor of direct tournament fetch by ID for deterministic EPL output.
}
