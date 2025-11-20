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

            $user = \Illuminate\Support\Facades\Auth::user();
            $role = is_object($user) ? strtolower((string)($user->role ?? '')) : '';
            $canSeeTest = in_array($role, ['admin','moderator'], true);
            if (!$canSeeTest) {
                $competitions = $competitions->filter(function($code){ return (string)$code !== 'TEST'; })->values();
            }

            // Детерминированный порядок лиг по конфигу leagues.default_ids
            try {
                $defIds = (array) config('leagues.default_ids', []);
                $byIdPos = [];
                foreach ($defIds as $pos => $lid) { $byIdPos[(int)$lid] = (int)$pos; }
                $leaguesConf = (array) config('leagues.leagues', []);
                $getPos = function ($code) use ($leaguesConf, $byIdPos) {
                    $code = (string) $code;
                    $info = $leaguesConf[$code] ?? null;
                    $lid = $info['id'] ?? null;
                    $pos = $lid !== null && isset($byIdPos[(int)$lid]) ? (int)$byIdPos[(int)$lid] : 999999;
                    return $pos;
                };
                $competitions = $competitions->sortBy(function($code){ return (string)$code; })
                    ->sortBy(function($code) use ($getPos){ return $getPos($code); })
                    ->values();
            } catch (\Throwable $e) { /* keep natural order */ }

            $eventsByCompetition = [];
            $leaguesConfAll = (array) config('leagues.leagues', []);
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
                    'league_code' => (string) $comp,
                    'league_id' => (int) ((array_key_exists((string)$comp, $leaguesConfAll) && isset($leaguesConfAll[(string)$comp]['id'])) ? (int)$leaguesConfAll[(string)$comp]['id'] : 0),
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
    
    // Проставление результатов.
    // Автоматическое урегулирование ставок.
    public function autoSettleDue(Request $request)
    {
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        $key = config('services.sstats.key');
        $headers = $key ? ['X-API-KEY' => $key] : [];
        $statusEnd = 8; // Законченные матчи.
        
        // Берём external_id запланированных событий за прошедшие даты чтобы проставить результаты и статус finished
        $scheduledExternalIds = $this->checkResultSchedule();
        
        foreach ($scheduledExternalIds as $extId) {
            $extId = (string) $extId;
            if ($extId === '') { continue; }
            // Обращаемся к апи , чтобы взять по ИД матч.
            $payload = [];
            foreach ([2025, 2024] as $yr) {
                $resp = Http::withHeaders($headers)->timeout(20)->get($base.'/Games/list', ['id' => $extId, 'status' => $statusEnd, 'Year' => $yr]);
                $payload = $resp && !$resp->failed() ? ($resp->json('data.0') ?? $resp->json('data') ?? []) : [];
                if (!empty($payload)) { break; }
            }
            // Проставляем результаты за тайм и матч
            $homeScore = is_numeric(data_get($payload, 'homeResult')) ? (int) data_get($payload, 'homeResult') : null;
            $awayScore = is_numeric(data_get($payload, 'awayResult')) ? (int) data_get($payload, 'awayResult') : null;
            $homeSt1 = is_numeric(data_get($payload, 'homeHTResult')) ? (int) data_get($payload, 'homeHTResult') : null;
            $awaySt1 = is_numeric(data_get($payload, 'awayHTResult')) ? (int) data_get($payload, 'awayHTResult') : null;
            $homeSt2 = is_numeric(data_get($payload, 'homeFTResult')) ? (int) data_get($payload, 'homeFTResult') : null;
            $awaySt2 = is_numeric(data_get($payload, 'awayFTResult')) ? (int) data_get($payload, 'awayFTResult') : null;
            if ($homeSt2 === null && $awaySt2 === null && $homeScore !== null && $awayScore !== null && $homeSt1 !== null && $awaySt1 !== null) {
                $homeSt2 = max(0, $homeScore - $homeSt1);
                $awaySt2 = max(0, $awayScore - $awaySt1);
            }
            if ($homeScore === null || $awayScore === null) { $homeScore = 0; $awayScore = 0; }
            $ev = Event::where('external_id', $extId)->first();
            
            if (!$ev) { continue; }
            $ev->home_result = $homeScore; $ev->away_result = $awayScore;
            $ev->home_ht_result = $homeSt1; $ev->away_ht_result = $awaySt1;
            $ev->home_st2_result = $homeSt2; $ev->away_st2_result = $awaySt2;
            $ev->result = $homeScore === $awayScore ? 'draw' : ($homeScore > $awayScore ? 'home' : 'away');
            $ev->result_text = 'HT(' . ($homeSt1 ?? 0) . ':' . ($awaySt1 ?? 0) . ') 2T(' . ($homeSt2 ?? 0) . ':' . ($awaySt2 ?? 0) . ') FT ' . ($homeScore ?? 0) . ':' . ($awayScore ?? 0);
            if ($ev->status !== 'finished') { $ev->status = 'finished'; $ev->ends_at = Carbon::now(); }
            $ev->save();
            $ev->bets()->whereNull('settled_at')->each(function (Bet $bet) use ($homeScore, $awayScore, $homeSt1, $awaySt1, $homeSt2, $awaySt2, $ev) {
                $market = trim((string) ($bet->market ?? ''));
                $selection = trim(strtolower((string) $bet->selection));
                $sel = $selection;
                if (in_array($sel, ['1','п1','p1'], true)) { $sel = 'home'; }
                elseif (in_array($sel, ['2','п2','p2'], true)) { $sel = 'away'; }
                elseif (in_array($sel, ['x','ничья'], true)) { $sel = 'draw'; }
                $amount = (float) ($bet->amount_demo ?? 0);
                $odds = (float) ($bet->placed_odds ?? 0);
                $win = false; $payout = 0.0; $settled = false;
                if ($market === '' || in_array($sel, ['home','draw','away'], true)) {
                    $win = ($sel === 'home' && $homeScore > $awayScore) || ($sel === 'away' && $awayScore > $homeScore) || ($sel === 'draw' && $homeScore === $awayScore);
                    $settled = true;
                } elseif (stripos($market, '2 тайм') !== false) {
                    if ($homeSt2 !== null && $awaySt2 !== null) {
                        $win = ($sel === 'home' && $homeSt2 > $awaySt2) || ($sel === 'away' && $awaySt2 > $homeSt2) || ($sel === 'draw' && $homeSt2 === $awaySt2);
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
                } elseif (mb_stripos($market, 'обе забьют', 0, 'UTF-8') !== false) {
                    $selRaw = (string) $bet->selection;
                    $yes = (stripos($selRaw, 'yes') !== false) || (stripos($selRaw, 'да') !== false);
                    $no = (stripos($selRaw, 'no') !== false) || (stripos($selRaw, 'нет') !== false);
                    if ($yes || $no) { $win = $yes ? ($homeScore > 0 && $awayScore > 0) : !($homeScore > 0 && $awayScore > 0); $settled = true; }
                } elseif (preg_match('/^1\s*забьет/iu', $market)) {
                    $selRaw = (string) $bet->selection;
                    $yes = (stripos($selRaw, 'yes') !== false) || (stripos($selRaw, 'да') !== false);
                    $no = (stripos($selRaw, 'no') !== false) || (stripos($selRaw, 'нет') !== false);
                    if ($yes || $no) { $win = $yes ? ($homeScore > 0) : ($homeScore === 0); $settled = true; }
                } elseif (preg_match('/^2\s*забьет/iu', $market)) {
                    $selRaw = (string) $bet->selection;
                    $yes = (stripos($selRaw, 'yes') !== false) || (stripos($selRaw, 'да') !== false);
                    $no = (stripos($selRaw, 'no') !== false) || (stripos($selRaw, 'нет') !== false);
                    if ($yes || $no) { $win = $yes ? ($awayScore > 0) : ($awayScore === 0); $settled = true; }
                } elseif (stripos($market, 'азиатский гандикап') !== false || mb_stripos($market, 'фора', 0, 'UTF-8') !== false) {
                    $team = null; $line = null; $selRaw = (string)$bet->selection;
                    if (preg_match('/^(home|away)\s*\(?\s*([+-]?\d+(?:\.\d+)?)\s*\)?$/i', $selRaw, $m)) {
                        $team = strtolower($m[1]); $line = (float) $m[2];
                    } elseif (preg_match('/^(?:фора|fora)\s*(1|2)\s*[\(\s]*([+-]?\d+(?:\.\d+)?)\)?$/iu', $selRaw, $m)) {
                        
                        $team = $m[1] === '1' ? 'home' : 'away'; $line = (float) $m[2];
                    } elseif (preg_match('/^(?:asian\s*handicap|азиатский\s*гандикап)\s*(home|away|1|2)\s*([+-]?\d+(?:\.\d+)?)$/iu', $selRaw, $m)) {
                        $team = in_array(strtolower($m[1]), ['home','away']) ? strtolower($m[1]) : ($m[1] === '1' ? 'home' : 'away'); $line = (float) $m[2];
                    } elseif (preg_match('/^(h|a)\s*\(?([+-]?\d+(?:\.\d+)?)\)?$/i', $selRaw, $m)) {
                        $team = strtolower($m[1]) === 'h' ? 'home' : 'away'; $line = (float) $m[2];
                    }
                    if ($team !== null && $line !== null) {
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
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
            ]);
        }
        return redirect()->route('home')->with('status', 'Авторасчёт завершён');
    }

    public function checkResultSchedule()
    {
        $tz = config('app.timezone');
        $now = Carbon::now($tz);

        $events = Event::query()
            ->whereNotNull('starts_at')
            ->whereNot('competition', 'TEST')
            ->where('starts_at', '<=', $now)
            ->whereIn('status', ['scheduled','live'])
            ->whereHas('bets', function($q){
                $q->whereHas('coupon', function($qc){
                    $qc->whereNull('is_win')->whereNull('settled_at');
                });
            })
            ->orderBy('starts_at')
            ->limit(10)
            ->get();

        $externalIds = $events->pluck('external_id')->filter()->values()->all();

        return $externalIds;
    }

// Непроверенные ставки. Возможно лучше брать по ним. Иначе зачем нам все проверять. Надо проверять только то что поставили - это логично. 
// Функцию пока не удалять. 
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
                $sel = $selection;
                if (in_array($sel, ['1','п1','p1'], true)) { $sel = 'home'; }
                elseif (in_array($sel, ['2','п2','p2'], true)) { $sel = 'away'; }
                elseif (in_array($sel, ['x','ничья'], true)) { $sel = 'draw'; }
                $amount = (float) ($bet->amount_demo ?? 0);
                $odds = (float) ($bet->placed_odds ?? 0);
                $win = false; $payout = 0.0; $settled = false;
                
                if ($market === '' || in_array($sel, ['home','draw','away'], true)) {
                    $win = ($sel === 'home' && $homeScore > $awayScore) || ($sel === 'away' && $awayScore > $homeScore) || ($sel === 'draw' && $homeScore === $awayScore);
                    $settled = true;
                } elseif (stripos($market, '2 тайм') !== false) {
                    if ($homeSt2 !== null && $awaySt2 !== null) {
                        $win = ($sel === 'home' && $homeSt2 > $awaySt2) || ($sel === 'away' && $awaySt2 > $homeSt2) || ($sel === 'draw' && $homeSt2 === $awaySt2);
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
                } elseif (stripos($market, 'Тоталы') !== false) {
                    $total = $homeScore + $awayScore;
                    
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
                    $selRaw = (string) $bet->selection;
                    $yes = stripos($selRaw, 'yes') !== false || stripos($selRaw, 'да') !== false;
                    $no = stripos($selRaw, 'no') !== false || stripos($selRaw, 'нет') !== false;
                    if ($yes || $no) { $win = $yes ? ($homeScore > 0 && $awayScore > 0) : !($homeScore > 0 && $awayScore > 0); $settled = true; }
                } elseif (preg_match('/^1\s*забьет/i', $market)) {
                    $selRaw = (string) $bet->selection;
                    $yes = stripos($selRaw, 'yes') !== false || stripos($selRaw, 'да') !== false;
                    $no = stripos($selRaw, 'no') !== false || stripos($selRaw, 'нет') !== false;
                    if ($yes || $no) { $win = $yes ? ($homeScore > 0) : ($homeScore === 0); $settled = true; }
                } elseif (preg_match('/^2\s*забьет/i', $market)) {
                    $selRaw = (string) $bet->selection;
                    $yes = stripos($selRaw, 'yes') !== false || stripos($selRaw, 'да') !== false;
                    $no = stripos($selRaw, 'no') !== false || stripos($selRaw, 'нет') !== false;
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
