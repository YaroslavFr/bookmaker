<?php
// Этот файл содержит консольную команду Laravel для синхронизации
// предстоящих матчей и коэффициентов Английской Премьер‑Лиги (EPL)
// из внешнего API sstats.net, с безопасным фоллбэком при недоступности API.

namespace App\Console\Commands; // Пространство имён для консольных команд

use App\Models\Event;              // Модель Eloquent для таблицы events
use Illuminate\Console\Command;    // Базовый класс для Artisan-команды
use Illuminate\Support\Facades\Http; // HTTP‑клиент Laravel для внешних запросов
use Illuminate\Support\Str;        // Утилита для работы со строками
use Carbon\Carbon;                 // Работа с датами/временем

// Класс команды. Имя файла и класса совпадает по PSR‑4 автозагрузке
class SyncEplOdds extends Command
{
    // Сигнатура команды: имя и опции (здесь --limit для ограничения числа матчей)
    protected $signature = 'epl:sync-odds {--limit=10}';
    // Короткое описание, показывается в списке команд
    protected $description = 'Sync upcoming EPL matches and odds from sstats.net API';

    // Главный метод команды. Выполняется при запуске через php artisan epl:sync-odds
    public function handle()
    {
        // Информационное сообщение в консоль
        $this->info('Синхронизация матчей и коэффициентов EPL из sstats.net...');

        // Читаем опцию --limit и приводим к целому числу
        $limit = (int) $this->option('limit');

        // 1) Читаем конфиг sstats (config/services.php), задаём базовый URL и ключ API
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        $apiKey = config('services.sstats.key');
        // 2) Если ключ отсутствует — завершаем команду с ошибкой
        if (!$apiKey) {
            $this->error('SSTATS_API_KEY не задан. Установите ключ в .env или services.sstats.key.');
            return self::FAILURE;
        }
        // 3) Заголовки запроса к sstats: ключ и формат JSON
        $headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'];

        // 4) Получаем предстоящие матчи и коэффициенты из sstats
        $matches = $this->fetchUpcomingWithOddsFromSstats($base, $headers, $limit);

        // 5) Если список непустой — сохраняем/обновляем события в БД
        if (!empty($matches)) {
            foreach ($matches as $m) {
                // Формируем заголовок матча: «Команда A vs Команда B»
                $title = $m['home_team'].' vs '.$m['away_team'];
                // Нормализуем время начала матча в UTC
                $commenceRaw = $m['commence_time'] ?? null;
                $commence = $commenceRaw ? Carbon::parse($commenceRaw)->utc()->second(0)->micro(0) : null;
                $event = Event::updateOrCreate(
                    // Критерии уникальности: title + starts_at
                    ['title' => $title, 'starts_at' => $commence],
                    [
                        // Поля для отображения и расчёта ставок
                        'home_team' => $m['home_team'],
                        'away_team' => $m['away_team'],
                        'status' => 'scheduled',
                        'home_odds' => $m['home_odds'],
                        'draw_odds' => $m['draw_odds'],
                        'away_odds' => $m['away_odds'],
                    ]
                );
                // Печатаем строку в консоль для контроля
                $this->line("Upserted: {$event->title} ({$event->home_odds}/{$event->draw_odds}/{$event->away_odds})");
            }
        } else {
            // 6) Без фоллбэка: если не удалось получить матчи или коэффициенты — завершаем без создания событий
            $this->warn('Не удалось получить матчи/коэффициенты из sstats. События не созданы.');
        }

        // Финальное сообщение: команда завершена успешно
        $this->info('Синхронизация EPL завершена.');
        return self::SUCCESS;
    }


    // Основной метод выборки матчей и коэффициентов из sstats
    private function fetchUpcomingWithOddsFromSstats(string $base, array $headers, int $limit): array
    {
        try {
            // Задаём лигу (EPL) и год
            $leagueId = 39; $year = (int) date('Y');
            // Базовые параметры запроса, ограничиваем количество результатов
            $paramsBase = ['LeagueId' => $leagueId, 'Year' => $year, 'Limit' => max(100, $limit*3)];
            // Для предстоящих матчей — Ended=false
            $params = $paramsBase + ['Ended' => 'false'];
            // URL эндпоинта списка игр
            $url = $base.'/games/list';
            // Выполняем первый запрос
            $resp = Http::withHeaders($headers)->timeout(30)->get($url, $params);
            $json = $resp->ok() ? ($resp->json() ?? []) : [];
            // Если не удалось — пытаемся без Ended=false на случай отличий API
            if (!$resp->ok()) {
                $resp2 = Http::withHeaders($headers)->timeout(30)->get($url, $paramsBase);
                $json = $resp2->ok() ? ($resp2->json() ?? []) : [];
            }

            // Универсальное извлечение массива игр из разных возможных форматов ответа
            $games = [];
            if (is_array($json)) {
                if (isset($json[0])) {
                    // Прямой массив
                    $games = $json;
                } elseif (isset($json['data'])) {
                    // Вложение в ключ data
                    $data = $json['data'];
                    if (is_array($data)) {
                        $games = $data['games'] ?? $data['items'] ?? $data['list'] ?? $data['results'] ?? $data['records'] ?? (isset($data[0]) ? $data : []);
                    }
                } else {
                    // Прочие случаи (разные ключи в ответе)
                    $games = $json['games'] ?? $json['Games'] ?? $json['items'] ?? $json['list'] ?? $json['results'] ?? $json['Records'] ?? $json['value'] ?? $json['Value'] ?? [];
                    if (!is_array($games)) { $games = []; }
                }
            }

            // Приводим игры к единому виду данных и парсим коэффициенты
            $out = [];
            foreach ($games as $g) {
                if (count($out) >= $limit) break;
                // Имена команд могут лежать по разным ключам: пробуем несколько
                $homeName = data_get($g, 'homeTeam.name');
                $awayName = data_get($g, 'awayTeam.name');
                $home = is_string($homeName) ? trim($homeName) : ($g['home'] ?? null);
                $away = is_string($awayName) ? trim($awayName) : ($g['away'] ?? null);
                if (!$home || !$away) continue;

                // Время начала матча
                $commence = $this->extractStartTime($g);

                // Пытаемся распарсить коэффициенты из структуры игры; если нет — запрашиваем по GameId
                [$homeOdds, $drawOdds, $awayOdds] = $this->parseOddsFromGame($g);
                if ($homeOdds === null && $drawOdds === null && $awayOdds === null) {
                    $gameId = $g['id'] ?? ($g['gameId'] ?? null);
                    if ($gameId) {
                        [$homeOdds, $drawOdds, $awayOdds] = $this->fetchOddsForGame($base, $headers, $gameId);
                    }
                }

                // Собираем унифицированную запись о матче
                $out[] = [
                    'home_team' => $home,
                    'away_team' => $away,
                    'commence_time' => $commence,
                    'home_odds' => $homeOdds,
                    'draw_odds' => $drawOdds,
                    'away_odds' => $awayOdds,
                ];
            }

            // Возвращаем список матчей для сохранения
            return $out;
        } catch (\Throwable $e) {
            // Логируем ошибку и возвращаем пустой список
            $this->error('Не удалось получить коэффициенты из sstats: '.$e->getMessage());
            return [];
        }
    }

    // Парсинг коэффициентов рынка 1x2 (П1/Ничья/П2) из разных форматов ответа
    private function parseOddsFromGame(array $g): array
    {
        // Буферы для значений (могут быть несколько у разных букмекеров)
        $homeVals = []; $drawVals = []; $awayVals = [];
        // Первый вариант данных: ключ odds в корне игры
        $oddsList = $g['odds'] ?? null;
        if (is_array($oddsList)) {
            foreach ($oddsList as $mk) {
                $key = strtolower((string)($mk['key'] ?? ($mk['marketKey'] ?? '')));
                $name = strtolower((string)($mk['name'] ?? ($mk['marketName'] ?? '')));
                if ($key === '1x2' || str_contains($name, '1x2') || str_contains($name, 'full time') || str_contains($name, 'match odds')) {
                    foreach (($mk['outcomes'] ?? ($mk['odds'] ?? [])) as $o) {
                        $sel = strtolower((string)($o['name'] ?? ($o['selectionName'] ?? '')));
                        $val = $o['value'] ?? ($o['odd'] ?? ($o['rate'] ?? null));
                        if ($val === null) continue;
                        if (str_contains($sel, 'home') || $sel === '1') { $homeVals[] = (float)$val; }
                        elseif (str_contains($sel, 'draw') || $sel === 'x') { $drawVals[] = (float)$val; }
                        elseif (str_contains($sel, 'away') || $sel === '2') { $awayVals[] = (float)$val; }
                    }
                }
            }
        }

        // Второй вариант: массив букмекеров с вложенными маркетами
        $bookmakers = $g['bookmakers'] ?? null;
        if (is_array($bookmakers)) {
            foreach ($bookmakers as $bm) {
                foreach (($bm['markets'] ?? []) as $market) {
                    $mName = strtolower((string)($market['marketName'] ?? ($market['name'] ?? '')));
                    if (str_contains($mName, '1x2') || str_contains($mName, 'full time') || str_contains($mName, 'match odds')) {
                        foreach (($market['odds'] ?? ($market['outcomes'] ?? [])) as $o) {
                            $sel = strtolower((string)($o['name'] ?? ($o['selectionName'] ?? '')));
                            $val = $o['value'] ?? ($o['odd'] ?? ($o['rate'] ?? null));
                            if ($val === null) continue;
                            if (str_contains($sel, 'home') || $sel === '1') { $homeVals[] = (float)$val; }
                            elseif (str_contains($sel, 'draw') || $sel === 'x') { $drawVals[] = (float)$val; }
                            elseif (str_contains($sel, 'away') || $sel === '2') { $awayVals[] = (float)$val; }
                        }
                        break;
                    }
                }
            }
        }

        // Возвращаем усреднённые значения по рынку
        return [$this->avg($homeVals), $this->avg($drawVals), $this->avg($awayVals)];
    }

    // Запрос коэффициентов отдельно по идентификатору игры, если в основном списке их нет
    private function fetchOddsForGame(string $base, array $headers, $gameId): array
    {
        try {
            // Формируем запрос к /odds/list
            $resp = Http::withHeaders($headers)->timeout(20)->get($base.'/odds/list', ['GameId' => $gameId]);
            $json = $resp->ok() ? ($resp->json() ?? []) : [];
            // Универсальное извлечение блоков коэффициентов
            $oddsBlocks = [];
            if (isset($json['data'])) {
                $data = $json['data'];
                $oddsBlocks = is_array($data) ? ($data['markets'] ?? $data['odds'] ?? (isset($data[0]) ? $data : [])) : [];
            } elseif (is_array($json)) {
                $oddsBlocks = $json['markets'] ?? $json['odds'] ?? (isset($json[0]) ? $json : []);
            }

            // Парсим рынок 1x2 из полученных блоков
            return $this->parseOddsFromGame(['odds' => $oddsBlocks]);
        } catch (\Throwable $e) {
            // В случае ошибки возвращаем пустые значения
            return [null, null, null];
        }
    }

    // Извлечение времени начала матча из разных возможных ключей
    private function extractStartTime(array $g)
    {
        // Список возможных ключей, встречающихся в разных форматах API
        $candidates = [
            'start', 'commence', 'date', 'datetime', 'startTime', 'startsAt', 'start_at', 'scheduled_at'
        ];
        foreach ($candidates as $k) {
            $v = $g[$k] ?? null;
            if (is_string($v) && trim($v) !== '') return $v;
        }
        $v = data_get($g, 'game.time') ?? data_get($g, 'game.timestamp');
        if (is_string($v) && trim($v) !== '') return $v;
        return null;
    }

    // Усреднение массива чисел с округлением до двух знаков
    private function avg(array $vals): ?float
    {
        $vals = array_values(array_filter($vals, fn($v) => is_numeric($v)));
        if (count($vals) === 0) return null;
        return round(array_sum($vals) / count($vals), 2);
    }

    
}