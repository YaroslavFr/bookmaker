<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Event;

class OddsController extends Controller
{
    /**
     * Public JSON endpoint returning sstats EPL finished games info.
     */
    public function api(Request $request)
    {
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        $apiKey = config('services.sstats.key');
        if (!$apiKey) {
            return response()->json([
                'ok' => false,
                'error' => 'Missing SSTATS_API_KEY',
            ], 500);
        }
        $headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'];
        $leagueId = 39; // EPL
        $year = (int) date('Y');
        $url = $base.'/games/list';

        $attempts = [
            ['Year' => $year, 'status' => '2'],
        ];

        $last = null; $games = [];
        foreach ($attempts as $paramsExtra) {
            $params = ['LeagueId' => $leagueId, 'Limit' => 12] + $paramsExtra;
            try {
                $resp = Http::withHeaders($headers)->timeout(20)->get($url, $params);
                $last = [
                    'url' => $url,
                    'status' => $resp->status(),
                    'params' => $params,
                ];
                $json = $resp->ok() ? ($resp->json() ?? []) : [];
                // extract games array from various shapes
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
                if (!empty($games)) {
                    break;
                }
            } catch (\Throwable $e) {
                $last = ['error' => $e->getMessage(), 'params' => $params, 'url' => $url, 'status' => null];
                continue;
            }
        }

        $count = is_array($games) ? count($games) : 0;
        $sample = array_slice(is_array($games) ? $games : [], 0, 3);

        return response()->json([
            'ok' => true,
            'http' => $last,
            'count' => $count,
            'sample' => $sample,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Upcoming matches with live odds (1x2) fetched directly from API.
     */
    public function odds(Request $request)
    {
        // Базовый URL sstats.net; rtrim снимает завершающий слэш, чтобы стабильно строить пути
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        // Ключ API из конфига services.php; обязателен для запросов
        $apiKey = config('services.sstats.key');
        // Если ключа нет — отдаём 500 и объясняем причину
        if (!$apiKey) {
            return response()->json(['ok' => false, 'error' => 'Missing SSTATS_API_KEY'], 500);
        }
        // Заголовки запроса: X-API-KEY для авторизации и Accept JSON
        $headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'];
        // Идентификатор лиги: EPL = 39; год берём текущий из системного времени
        $leagueId = 39; $year = (int) date('Y');
        // Конечная точка получения списка матчей
        $url = $base.'/games/list';
        // Лимит отображаемых матчей на панели; можно переопределить через query ?limit=
        $limit = (int) ($request->query('limit', 12));
        // Флаг для редкого фоллбэка: отдельно запрашивать odds/list для игр без inline 1x2 (по умолчанию выключено, чтобы минимизировать запросы)
        $useFallback = (bool) $request->boolean('fallback', false);

        // Делаем ровно один запрос: предстоящие матчи (Status=2) за текущий год
        $attempts = [
            ['Year' => $year, 'Status' => 2],
        ];
        $games = [];
        // Перебираем варианты параметров, делаем запрос и пытаемся распарсить массив матчей
        foreach ($attempts as $paramsExtra) {
            try {
                // Строим параметры: минимизируем размер и число запросов
                $params = ['LeagueId' => $leagueId, 'Limit' => $limit] + $paramsExtra;
                // Умеренный таймаут, чтобы UI не зависал
                $resp = Http::withHeaders($headers)->timeout(8)->get($url, $params);
                // Если ответ OK — берём JSON; иначе пусто
                $json = $resp->ok() ? ($resp->json() ?? []) : [];
                // Универсальная распаковка, поскольку API может отдавать разные формы
                if (is_array($json)) {
                    // Форма: массив игр
                    if (isset($json[0])) {
                        $games = $json;
                    } elseif (isset($json['data'])) {
                        // Форма: объект с полем data, в котором может быть несколько вариантов массивов
                        $data = $json['data'];
                        if (is_array($data)) {
                            $games = $data['games'] ?? $data['items'] ?? $data['list'] ?? $data['results'] ?? (isset($data[0]) ? $data : []);
                        }
                    } else {
                        // Форма: корневой объект c полями games/items/list/results
                        $games = $json['games'] ?? $json['items'] ?? $json['list'] ?? $json['results'] ?? (isset($json[0]) ? $json : []);
                        if (!is_array($games)) { $games = []; }
                    }
                }
                // Если что-то распаковали — прекращаем попытки, идём дальше
                if (!empty($games)) break;
            } catch (\Throwable $e) {
                // Ошибки сетевые/парсинга игнорируем и пробуем следующий вариант
                continue;
            }
        }

        // Оставляем только предстоящие матчи и сортируем по дате
        $now = now();
        $futureGames = [];
        foreach ($games as $g) {
            // Вытаскиваем дату начала из возможных полей (API может менять названия)
            $commence = data_get($g, 'start') ?? data_get($g, 'datetime') ?? data_get($g, 'date') ?? data_get($g, 'startTime') ?? null;
            // Статус матча: нужен, чтобы отсечь явные завершённые
            $status = data_get($g, 'statusName') ?? data_get($g, 'status');
            if (!$commence) { continue; }
            try {
                // Парсим дату в Carbon для сравнения и сортировки
                $dt = \Carbon\Carbon::parse($commence);
            } catch (\Throwable $e) {
                continue;
            }
            // Пропускаем явно завершённые и прошедшие матчи
            $isEnded = $status ? (stripos((string)$status, 'finish') !== false || stripos((string)$status, 'ended') !== false) : false;
            if ($isEnded || $dt->lte($now)) { continue; }
            // Кэшируем распарсенную дату и исходное значение для последующей сборки
            $g['__dt'] = $dt; $g['__commence'] = $commence;
            $futureGames[] = $g;
        }
        // Сортируем будущие матчи по времени начала (раньше — выше)
        usort($futureGames, function($a, $b){
            $da = $a['__dt'] ?? null; $db = $b['__dt'] ?? null;
            if ($da && $db) return $da->greaterThan($db) ? 1 : ($da->eq($db) ? 0 : -1);
            return 0;
        });

        // Собираем элементы ответа: идём по будущим матчам,
        // извлекаем 1x2 коэффициенты (inline или через odds/list) и
        // останавливаемся после limit валидных записей.
        $items = [];
        foreach ($futureGames as $g) {
            // Нормализуем названия команд из разных возможных полей
            $home = data_get($g, 'homeTeam.name') ?? ($g['home'] ?? ($g['Home'] ?? null));
            $away = data_get($g, 'awayTeam.name') ?? ($g['away'] ?? ($g['Away'] ?? null));
            // Заголовок: "Home vs Away" или запасной вариант
            $title = (is_string($home) && is_string($away)) ? ($home.' vs '.$away) : (data_get($g, 'title') ?? 'Match');
            // Время начала: берём кэшированное значение, иначе ищем заново
            $commence = $g['__commence'] ?? (data_get($g, 'start') ?? data_get($g, 'datetime') ?? data_get($g, 'date') ?? data_get($g, 'startTime') ?? null);
            // Идентификатор игры для фоллбэк-запроса odds/list
            $gameId = data_get($g, 'id') ?? data_get($g, 'game.id') ?? data_get($g, 'GameId') ?? null;
            // Сначала пытаемся вытащить коэффициенты напрямую из games/list
            [$h,$d,$a] = $this->extractInlineOdds($g);
            // Фоллбэк выключен по умолчанию для минимизации количества запросов
            if ((!is_numeric($h) || !is_numeric($d) || !is_numeric($a)) && $useFallback) {
                [$h,$d,$a] = $this->fetchOddsForGame($base, $headers, $gameId);
            }
            // Пропускаем матчи без полного набора 1x2 коэффициентов
            if (!is_numeric($h) || !is_numeric($d) || !is_numeric($a)) {
                continue;
            }
            // Находим существующее событие по каноническим названиям и времени, без создания новых
            $startsAt = $g['__dt'] ?? null;
            if (!$startsAt && $commence) {
                try { $startsAt = \Carbon\Carbon::parse($commence); } catch (\Throwable $e) { $startsAt = null; }
            }
            $homeC = $this->canonicalTeam($home);
            $awayC = $this->canonicalTeam($away);
            $event = null; $eventId = null;
            if ($startsAt) {
                $event = Event::whereRaw('LOWER(home_team) = ?', [strtolower($homeC)])
                    ->whereRaw('LOWER(away_team) = ?', [strtolower($awayC)])
                    ->where('starts_at', $startsAt)
                    ->first();
                if ($event) { $eventId = $event->id; }
            }
            $items[] = [
                'title' => $homeC.' vs '.$awayC,
                'home_team' => $homeC,
                'away_team' => $awayC,
                'commence_time' => $commence,
                'home_odds' => (float)$h,
                'draw_odds' => (float)$d,
                'away_odds' => (float)$a,
                'event_id' => $eventId,
            ];
            // Прекращаем сбор, как только набрали лимит
            if (count($items) >= $limit) { break; }
        }

        // Возвращаем JSON: ok-флаг, количество и сами элементы
        return response()->json(['ok' => true, 'count' => count($items), 'items' => $items]);
    }

    /**
     * Fetch odds for a single game and extract 1x2 market.
     */
    private function fetchOddsForGame(string $base, array $headers, $gameId): array
    {
        if (!$gameId) return [null, null, null];
        try {
            // Пробуем оба варианта пути и нижний регистр параметров
            $json = [];
            foreach ([
                [$base.'/odds/list', ['gameid' => $gameId]],
                [$base.'/Odds/list', ['gameid' => $gameId]],
            ] as [$endpoint, $params]) {
                $resp = Http::withHeaders($headers)->timeout(15)->get($endpoint, $params);
                if ($resp->ok()) { $json = $resp->json() ?? []; break; }
            }
            $blocks = [];
            if (isset($json['data'])) {
                $data = $json['data'];
                $blocks = is_array($data) ? ($data['markets'] ?? $data['odds'] ?? (isset($data[0]) ? $data : [])) : [];
            } elseif (is_array($json)) {
                $blocks = $json['markets'] ?? $json['odds'] ?? (isset($json[0]) ? $json : []);
            }
            // Find market 1x2 or Match Odds
            $home = null; $draw = null; $away = null;
            $markets = isset($blocks[0]) ? $blocks : ($blocks['markets'] ?? []);
            foreach ($markets as $m) {
                $name = strtolower((string)($m['name'] ?? $m['market'] ?? ''));
                $marketId = $m['marketId'] ?? $m['id'] ?? null;
                if ($marketId === 1 || str_contains($name, '1x2') || str_contains($name, 'match odds') || str_contains($name, 'win-draw-win')) {
                    $selections = $m['selections'] ?? $m['runners'] ?? $m['odds'] ?? [];
                    foreach ($selections as $sel) {
                        $label = strtolower((string)($sel['name'] ?? $sel['label'] ?? $sel['runner'] ?? ''));
                        $price = $sel['price'] ?? $sel['decimal'] ?? $sel['odds'] ?? $sel['value'] ?? null;
                        if (is_numeric($price)) {
                            if (str_contains($label, 'home') || str_contains($label, 'п1') || str_contains($label, 'победа хозяев')) $home = (float)$price;
                            elseif (str_contains($label, 'draw') || str_contains($label, 'ничья')) $draw = (float)$price;
                            elseif (str_contains($label, 'away') || str_contains($label, 'п2') || str_contains($label, 'победа гостей')) $away = (float)$price;
                        }
                    }
                    break;
                }
            }
            return [$home, $draw, $away];
        } catch (\Throwable $e) {
            return [null, null, null];
        }
    }

    /**
     * Извлечение коэффициентов 1x2 прямо из объекта игры (games/list).
     */
    private function extractInlineOdds(array $game): array
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
     * Нормализует названия команд к каноническому виду, чтобы сводить синонимы (Wolves → Wolverhampton Wanderers и т.п.).
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
}