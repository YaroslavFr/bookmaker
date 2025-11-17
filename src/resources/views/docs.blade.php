<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Документация — Данные событий</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .doc-container { max-width: 960px; margin: 0 auto; padding: 16px; }
        .doc-section { margin-top: 24px; }
        .doc-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; }
        .doc-card h2 { font-weight: 700; font-size: 18px; margin-bottom: 12px; }
        .doc-list { list-style: auto; padding-left: 20px; }
        .doc-code { 
                margin: 5px 0;
                background: linear-gradient(135deg, 
                #001628 0%, 
                #910b87 100%);
                color: #e6edf3; border-radius: 6px; padding: 12px; overflow: auto; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 13px; }
        .doc-list li { margin-bottom: 8px; }
        .doc-kbd { background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 4px; padding: 2px 6px; font-family: ui-monospace, monospace; }
        .muted { color: #6b7280; }
        /* Содержание */
        .doc-toc { margin-top: 12px; padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px; }
        .doc-toc-title { font-weight: 700; font-size: 16px; margin-bottom: 8px; }
        .doc-toc-list { list-style: none; padding-left: 0; display: flex; flex-direction: column; gap: 6px; }
        .doc-toc-list a { color: #2563eb; text-decoration: none; }
        .doc-toc-list a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    @include('partials.header')
    <main>
        <div class="doc-container">
            <h1 class="text-2xl font-bold mt-6 mb-2">Документация</h1>
            <p class="muted">Наглядное описание источников данных, маршрутов, контроллеров, моделей и синхронизации.</p>
            <div class="doc-toc" aria-label="Содержание">
                <div class="doc-toc-title">Содержание</div>
                <ul class="doc-toc-list">
                    <li><a href="#chain">Ключевая цепочка</a></li>
                    <li><a href="#model">Модель и миграции</a></li>
                    <li><a href="#sync-odds">Синхронизация коэффициентов</a></li>
                    <li><a href="#sync-results">Синхронизация результатов</a></li>
                    <li><a href="#display">Отображение и купон</a></li>
                    <li><a href="#keys">Ключи и планировщик</a></li>
                    <li><a href="#stats">Статистика (страница /stats)</a></li>
                    <li><a href="#deploy">Деплой</a></li>
                </ul>
            </div>

            <div class="doc-global-controls" style="margin: 12px 0; display: flex; gap: 8px; align-items: center;">
                <button id="doc-expand-all" type="button" class="doc-btn" style="padding:6px 10px; border:1px solid #ccc; background:#f7f7f7; cursor:pointer; border-radius:4px;">Развернуть весь код</button>
                <button id="doc-collapse-all" type="button" class="doc-btn" style="padding:6px 10px; border:1px solid #ccc; background:#f7f7f7; cursor:pointer; border-radius:4px;">Свернуть весь код</button>
            </div>

            <style>
                /* Стили для сворачиваемых блоков кода */
                .doc-code { margin-top: 8px; }
                .doc-code.collapsed { 
                    max-height: 80px;
                    overflow: hidden;
                }
                .doc-code-toggle {
                    padding: 6px 10px;
                    border: 1px solid #ccc;
                    background: #f7f7f7;
                    cursor: pointer;
                    border-radius: 4px;
                    font-size: 13px;
                    margin: 6px 0 0;
                    display: block;
                    margin: 15px 0;
                }
                .doc-code-toggle + .doc-code { margin-top: 6px; }
            </style>

            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const codeBlocks = Array.from(document.querySelectorAll('.doc-code'));
                    // Вставляем кнопку перед каждым блоком .doc-code и сворачиваем по умолчанию
                    codeBlocks.forEach(function (block, idx) {
                        block.classList.add('collapsed');
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'doc-code-toggle';
                        btn.setAttribute('aria-controls', 'doc-code-' + idx);
                        btn.setAttribute('aria-expanded', 'false');
                        btn.textContent = 'Показать код';
                        // Присвоим id блоку для ARIA
                        if (!block.id) block.id = 'doc-code-' + idx;
                        btn.addEventListener('click', function () {
                            const collapsed = block.classList.toggle('collapsed');
                            const isCollapsed = block.classList.contains('collapsed');
                            btn.setAttribute('aria-expanded', (!isCollapsed).toString());
                            btn.textContent = isCollapsed ? 'Показать код' : 'Скрыть код';
                        });
                        block.parentNode.insertBefore(btn, block);
                    });

                    // Глобальные кнопки
                    const expandAllBtn = document.getElementById('doc-expand-all');
                    const collapseAllBtn = document.getElementById('doc-collapse-all');
                    if (expandAllBtn) {
                        expandAllBtn.addEventListener('click', function () {
                            codeBlocks.forEach(function (block) { block.classList.remove('collapsed'); });
                            document.querySelectorAll('.doc-code-toggle').forEach(function (btn) {
                                btn.setAttribute('aria-expanded', 'true');
                                btn.textContent = 'Скрыть код';
                            });
                        });
                    }
                    if (collapseAllBtn) {
                        collapseAllBtn.addEventListener('click', function () {
                            codeBlocks.forEach(function (block) { block.classList.add('collapsed'); });
                            document.querySelectorAll('.doc-code-toggle').forEach(function (btn) {
                                btn.setAttribute('aria-expanded', 'false');
                                btn.textContent = 'Показать код';
                            });
                        });
                    }
                });
            </script>

            <section class="doc-section" id="chain">
                <div class="doc-card">
                    <h2>Ключевая цепочка</h2>
                    <ol class="doc-list">
                        <li>Маршрут <code class="doc-kbd">GET /</code> указывает на <code class="doc-kbd">BetController@index</code> — рендер главной страницы.</li>
                        <li> Мы делаем команду Консольную команду ребилда событий, чтобы события по api из сайта sstats.net загрузились в базу данных
                            <div class="doc-code">
<pre>
    <code>// Консольная команда ребилда событий
        Просто PHP - 
        php artisan events:rebuild

        Если через докер - 
        docker compose exec app php artisan events:rebuild

        # Опционально: жёсткий ребилд с очисткой таблицы (опасно)
        php artisan events:rebuild --hard
    </code>
</pre>
</div> 
<p>Лиги подтягиваются по ID в массиве в файле src/config/leagues.php</p>
<div class="doc-code">
<pre>
    <code>'leagues' => [
        'UCL' => ['id' => 2,   'title' => 'Лига чемпионов УЕФА',       'slug' => 'ucl'],
        'EPL' => ['id' => 39,  'title' => 'Английская Премьер-лига',   'slug' => 'epl'],
        'FRA' => ['id' => 61,  'title' => 'Французская Лига 1',        'slug' => 'ligue-1'],
        'GER' => ['id' => 78,  'title' => 'Бундеслига',                'slug' => 'bundesliga'],
        'ARG' => ['id' => 128, 'title' => 'Аргентинская Премьер-лига', 'slug' => 'primera-division'],
        'ITA' => ['id' => 135, 'title' => 'Итальянская Серия А',       'slug' => 'serie-a'],
        'ESP' => ['id' => 140, 'title' => 'Испанская Ла Лига',         'slug' => 'la-liga'],
        'RUS' => ['id' => 235, 'title' => 'Российская Премьер-лига',   'slug' => 'rpl'],
        'RUS2'=> ['id' => 236, 'title' => 'Российская Первая лига',    'slug' => 'fnl'],
    ],
    </code>
</pre>    
<p>И дефолтный набор лиг для агрегированной статистики главной страницы</p>                      
<pre>
    <code>$defLeagueIds = [ 'EPL' => 39, 'UCL' =>  2, 'ITA' => 135 , 'RUS2' => 236];</code>
</pre>
</div>
<p class="mt-4">Далее идет $prepareForView, для обработки событий перед отображением на главной странице.</p>
<p> Делается это с помощью map </p>
<p>Пример работы map: </p>
<div class="doc-code">
<pre>
    <code>
    $collection = collect([1, 2, 3]);

    $multipliedCollection = $collection->map(function ($item) {
        return $item * 2;
    });

    // $multipliedCollection will be collect([2, 4, 6])
    </code>
</pre>
</div>
<div class="doc-code">
<pre>
    <code>
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
    </code>
</pre>
    </div>
    <p>И в конце циклом формируем человекочитаемый заголовок для каждой лиги.</p>
    <div class="doc-code">
<pre>
    <code>
        $leagueTitlesByCode = [];
        foreach (config('leagues.leagues') as $code => $info) {
            // $code содержит:
            // "UCL" например

            // Массив $info содержит:
            // "id" => 2
            // "title" => "Лига чемпионов УЕФА"
            // "slug" => "ucl"

            // Формируем человекочитаемый заголовок: используем "title" или сам код, если "title" отсутствует
            $leagueTitlesByCode[$code] = $info['title'] ?? $code;
        }
    </code>
</pre>
    </div>
                        </li>
                        
                        <li>Доп. маршруты: <code class="doc-kbd">GET /odds</code>, <code class="doc-kbd">GET /events/{event}/markets</code>, <code class="doc-kbd">GET /odds/game/{gameId}</code>.</li>
                        <li><code class="doc-kbd">index()</code> собирает ленты EPL/UCL/ITA, карту <code class="doc-kbd">event_id→external_id</code> и историю купонов.</li>
                        <li>Представление: <code class="doc-kbd">resources/views/home.blade.php</code> показывает матчи, коэффициенты, купоны и купон-форму (Vue).
                    <div class="doc-code"><pre><code>// routes/web.php
Route::get('/', [BetController::class, 'index'])->name('home');
Route::get('/odds', [OddsController::class, 'odds'])->name('odds.index');
Route::get('/events/{event}/markets', [OddsController::class, 'markets'])->name('events.markets');
Route::get('/odds/game/{gameId}', [OddsController::class, 'marketsByGame'])->name('odds.byGame');

// app/Http/Controllers/BetController.php (фрагмент)
public function index()
{
    $marketsMap = [];
    $gameIdsMap = [];

    foreach ([$eventsEpl, $eventsUcl, $eventsIta] as $collection) {
        foreach ($collection as $ev) {
            if (!empty($ev->external_id)) {
                $gameIdsMap[$ev->id] = (string)$ev->external_id;
            }
        }
    }

    $coupons = Coupon::with(['bets.event'])->latest()->limit(50)->get();

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
</code></pre></div>
</li>
</ol>
                    <h3>Что принимает и возвращает view()</h3>
                    <ul class="doc-list">
                        <li><code class="doc-kbd">view(name, data)</code> принимает имя шаблона и массив данных.</li>
                        <li>Возвращает <code class="doc-kbd">Illuminate\View\View</code>, который преобразуется в HTML.</li>
                        <li>Ключи массива становятся переменными в Blade: например, <code class="doc-kbd">$leagues</code>, <code class="doc-kbd">$coupons</code>.</li>
                        <li>Этот блок документирует именно главную страницу.</li>
                    </ul>
                </div>
            </section>
            <section class="doc-section" id="migrations">
                <div class="doc-card">
                    <h2>Миграции: команды</h2>
                    <ul class="doc-list">
                        <li>Локально: <code class="doc-kbd">php artisan migrate</code></li>
                        <li>Прод: <code class="doc-kbd">php artisan migrate --force</code></li>
                        <li>Конкретный файл: <code class="doc-kbd">php artisan migrate --path=database/migrations/2025_11_15_120000_add_role_to_users_table.php</code></li>
                        <li>Полная пересоздание: <code class="doc-kbd">php artisan migrate:fresh --seed</code></li>
                        <li>Сидеры: <code class="doc-kbd">php artisan db:seed --class=ModeratorUserSeeder</code></li>
                    </ul>
                    <div class="doc-code"><pre><code># Локально
php artisan migrate

# В продакшне
php artisan migrate --force

# Конкретная миграция по пути
php artisan migrate --path=database/migrations/2025_11_15_120000_add_role_to_users_table.php --force

# Полная пересоздание схемы + сиды (ОПАСНО)
php artisan migrate:fresh --seed --force

# Сиды
php artisan db:seed --class=ModeratorUserSeeder --force
</code></pre></div>
                </div>
            </section>

            <section class="doc-section" id="model">
                <div class="doc-card">
                    <h2>Модель и миграции</h2>
                    <ul class="doc-list">
                        <li>Таблица <code class="doc-kbd">events</code>: <span class="muted">title, starts_at, ends_at, status, result</span>.</li>
                        <li>Поля EPL: <span class="muted">home_team, away_team, home_odds, draw_odds, away_odds</span>.</li>
                    </ul>
                    <div class="doc-code"><pre><code>// database/migrations/create_events_table.php
$table->string('title');
$table->dateTime('starts_at')->nullable();
$table->enum('status', ['scheduled','live','finished'])->default('scheduled');
$table->enum('result', ['home','draw','away'])->nullable();

// add_epl_fields_to_events_table.php
$table->string('home_team')->nullable();
$table->string('away_team')->nullable();
$table->decimal('home_odds', 8, 2)->nullable();
$table->decimal('draw_odds', 8, 2)->nullable();
$table->decimal('away_odds', 8, 2)->nullable();
</code></pre></div>
                </div>
            </section>

            <section class="doc-section" id="sync-odds">
                <div class="doc-card">
                    <h2>Синхронизация коэффициентов (epl:sync-odds)</h2>
                    <ul class="doc-list">
                        <li>Команды: <strong>sstats.net</strong> (список EPL).</li>
                        <li>Коэффициенты: <strong>sstats.net</strong> (рынок 1x2/Match Odds, формат decimal).</li>
                        <li>Сохранение: <code class="doc-kbd">Event::updateOrCreate(...)</code> со статусом <code class="doc-kbd">scheduled</code>.</li>
                    </ul>
                    <div class="doc-code"><pre><code>// app/Console/Commands/SyncEplOdds.php
Event::updateOrCreate(
  ['title' => $m['home_team'].' vs '.$m['away_team'], 'starts_at' => $m['commence_time']],
  [
    'home_team' => $m['home_team'],
    'away_team' => $m['away_team'],
    'status' => 'scheduled',
    'home_odds' => $m['home_odds'],
    'draw_odds' => $m['draw_odds'],
    'away_odds' => $m['away_odds'],
  ]
);
</code></pre></div>
                    <p class="muted">Запуск: <code class="doc-kbd">php artisan epl:sync-odds --limit=10</code> • ключи <code class="doc-kbd">SSTATS_API_KEY</code> и <code class="doc-kbd">SSTATS_BASE</code> в <code class="doc-kbd">.env</code>.</p>
                    <h3>Разбор SyncEplOdds.php для новичков</h3>
                    <ul class="doc-list">
                        <li><strong>Сигнатура команды</strong>: объявляет имя <code class="doc-kbd">epl:sync-odds</code> и опцию <code class="doc-kbd">--limit</code>.</li>
                        <li><strong>Конфигурация</strong>: читает <code class="doc-kbd">SSTATS_API_KEY</code> и <code class="doc-kbd">SSTATS_BASE</code> из <code class="doc-kbd">config/services.php</code>.</li>
                        <li><strong>HTTP‑клиент</strong>: получает список игр и коэффициенты, подстраиваясь под разные форматы ответа.</li>
                        <li><strong>Сохранение</strong>: использует <code class="doc-kbd">Event::updateOrCreate</code> по паре <code class="doc-kbd">title+starts_at</code>.</li>
                        <li><strong>Хелперы</strong>: методы <code class="doc-kbd">fetchUpcomingWithOddsFromSstats</code>, <code class="doc-kbd">parseOddsFromGame</code>, <code class="doc-kbd">fetchOddsForGame</code>, <code class="doc-kbd">extractStartTime</code>, <code class="doc-kbd">avg</code>.</li>
                    </ul>
                    <div class="doc-code"><pre><code>// Ключевые строки из app/Console/Commands/SyncEplOdds.php
protected $signature = 'epl:sync-odds {--limit=10}'; // имя команды и опция
protected $description = 'Sync upcoming EPL matches and odds from sstats.net API';

$base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/'); // базовый URL
$apiKey = config('services.sstats.key'); // ключ API из конфигурации
$headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json']; // заголовки запроса

$matches = $this->fetchUpcomingWithOddsFromSstats($base, $headers, $limit); // загрузка матчей и коэффициентов
if (!empty($matches)) {
  // upsert событий по title+starts_at
  Event::updateOrCreate([
    'title' => $m['home_team'].' vs '.$m['away_team'],
    'starts_at' => $m['commence_time'],
  ], [
    'home_team' => $m['home_team'],
    'away_team' => $m['away_team'],
    'status' => 'scheduled',
    'home_odds' => $m['home_odds'],
    'draw_odds' => $m['draw_odds'],
    'away_odds' => $m['away_odds'],
  ]);
} else {
  // фоллбэк: создаём события с базовыми кэфами
  Event::firstOrCreate([
    'title' => $title,
  ], [
    'home_team' => $home,
    'away_team' => $away,
    'status' => 'scheduled',
    'starts_at' => now()->addDays(rand(1,7)),
    'home_odds' => 2.00,
    'draw_odds' => 3.40,
    'away_odds' => 3.60,
  ]);
}
</code></pre></div>
                    <p class="muted">Эти строки отражают общий ход команды: чтение конфигурации, запросы к API,
                        сохранение событий и безопасный фоллбэк при проблемах с внешними сервисами.</p>
                </div>
            </section>

            <section class="doc-section" id="sync-results">
                <div class="doc-card">
                    <h2>Синхронизация результатов (epl:sync-results)</h2>
                    <ul class="doc-list">
                        <li>Источник: <strong>sstats.net</strong> (прошедшие матчи EPL).</li>
                        <li>Обновляет <code class="doc-kbd">status=finished</code> и <code class="doc-kbd">result</code> (home/draw/away).</li>
                        <li>Рассчитывает связанные ставки: победа/проигрыш и выплату.</li>
                    </ul>
                    <div class="doc-code"><pre><code>// app/Console/Commands/SyncEplResults.php
$ev->status = 'finished';
$ev->result = $result; // home/draw/away
$ev->ends_at = $apiTime ?: now();
$ev->save();

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
</code></pre></div>
                    <p class="muted">Запуск: <code class="doc-kbd">php artisan epl:sync-results</code> • планировщик ежечасно.</p>
                </div>
            </section>

            <section class="doc-section" id="display">
                <div class="doc-card">
                    <h2>Отображение и купон на главной</h2>
                    <ul class="doc-list">
                        <li>В колонке «Коэфф. (П1 / Ничья / П2)» показаны <code class="doc-kbd">home_odds/draw_odds/away_odds</code>.</li>
                        <li>Доп. рынки подгружаются по требованию и кнопки имеют <code class="doc-kbd">data-market</code>, <code class="doc-kbd">data-selection</code>, <code class="doc-kbd">data-odds</code>.</li>
                        <li>Клик по коэффициенту добавляет исход в купон (Vue компонент). Купон поддерживает несколько событий.</li>
                        <li>Отправка на <code class="doc-kbd">POST /bets</code> массивом <code class="doc-kbd">items</code> — сервер создаёт один купон с несколькими ставками.</li>
                    </ul>
                    @verbatim
                    <div class="doc-code"><pre><code>&lt;!-- Домашние кэфы 1x2 --&gt;
&lt;span class="odd-btn" data-event-id="{{ $ev->id }}" data-selection="home" data-odds="{{ number_format($h, 2) }}"&gt;П1 {{ number_format($h, 2) }}&lt;/span&gt;

&lt;!-- Кнопки доп. рынков: есть market, selection, odds --&gt;
&lt;button class="odd-btn" data-event-id="{{ $ev->id }}" data-market="Тотал 2.5" data-selection="Больше" data-odds="1.90"&gt;Больше 2.5 (1.90)&lt;/button&gt;

// resources/js/components/BetSlip.vue
function handleOddClick(e) {
  const btn = e.target.closest('.odd-btn');
  if (!btn) return;
  const eventId = btn.getAttribute('data-event-id');
  const market = btn.getAttribute('data-market');
  const selection = btn.getAttribute('data-selection');
  const home = btn.getAttribute('data-home');
  const away = btn.getAttribute('data-away');
  const odds = btn.getAttribute('data-odds');
  addOrReplaceSlipItem({ eventId, home, away, selection, odds, market });
}

// Купон поддерживает несколько разных событий.
// Для одного события хранится один выбранный исход; повторный клик обновляет его.
</code></pre></div>
                    @endverbatim
                </div>
            </section>

            <section class="doc-section" id="keys">
                <div class="doc-card">
                    <h2>Ключи и планировщик</h2>
                    <ul class="doc-list">
                        <li><code class="doc-kbd">SSTATS_API_KEY</code>, <code class="doc-kbd">SSTATS_BASE</code> — sstats.net (коэффициенты).</li>
                        <li>Планировщик: ежедневно <code class="doc-kbd">epl:sync-odds</code> в 06:00; ежечасно <code class="doc-kbd">epl:sync-results</code>.</li>
                        <li>Маршрут быстрого обновления результатов: <code class="doc-kbd">GET /events/sync-results</code>.</li>
                        <li>Ребилд событий: <code class="doc-kbd">php artisan events:rebuild</code> или <code class="doc-kbd">php artisan events:rebuild --hard</code> (опасно, очищает таблицу).</li>
                    </ul>
                    
                </div>
            </section>

            <section class="doc-section" id="stats">
                <div class="doc-card">
                    <h2>Статистика (страница /stats)</h2>
                    <ul class="doc-list">
                        <li>Маршрут: <code class="doc-kbd">GET /stats</code>, имя <code class="doc-kbd">stats.index</code>, контроллер <code class="doc-kbd">StatsController</code>.</li>
                        <li>Представление: <code class="doc-kbd">resources/views/stats.blade.php</code> — сводка по командам и агрегаты.</li>
                        <li>Источник данных: <strong>sstats.net</strong>; ключ и базовый URL берутся из <code class="doc-kbd">config/services.php</code> (<code class="doc-kbd">SSTATS_API_KEY</code>, <code class="doc-kbd">SSTATS_BASE</code>).</li>
                        <li>Турнир по умолчанию: <span class="muted">EPL (tournamentId=17)</span>; период выборки — <span class="muted">последние 120 дней</span>.</li>
                        <li>Кеширование результатов матчей и расчёт метрик: <span class="muted">матчи, забитые/пропущенные, победы/ничьи/поражения, дома/в гостях</span>.</li>
                        <li>Агрегаты: <span class="muted">самые забивающие/пропускающие (дом/гости), топ-10 по голам и победам</span>.</li>
                        <li>Обработка ошибок: при недоступности API выводится сообщение и используются безопасные значения <code class="doc-kbd">—</code>.</li>
                    </ul>
                    <div class="doc-code"><pre><code>// routes/web.php
Route::get('/stats', StatsController::class)->name('stats.index');

// config/services.php (.env)
// sstats
SSTATS_API_KEY=your_sstats_key
SSTATS_BASE=https://&lt;base&gt;

// Примечание: смена турнира — корректируйте ID в StatsController
</code></pre></div>
                    <p class="muted">Быстрая проверка: установите ключи в <code class="doc-kbd">.env</code>, запустите сервер и откройте <code class="doc-kbd">/stats</code>.</p>
                </div>
            </section>

            <section class="doc-section" id="deploy">
                <div class="doc-card">
                    <h2>Деплой на хостинг</h2>
                    <p class="muted">Краткое резюме процесса и команд по материалам DEPLOY_INSTRUCTIONS.md.</p>
                    <h2>Envoy: задачи и команды</h2>
                    <ul class="doc-list">
                        <li><strong>setup</strong> — первичная настройка сервера: обновление пакетов, создание директорий, установка прав.</li>
                        <li><strong>deploy</strong> — основной деплой: вытягивание кода из <code class="doc-kbd">origin</code>, установка зависимостей, прогрев кешей.</li>
                        <li><strong>migrate-fresh</strong> — полная пересоздание схемы БД: <code class="doc-kbd">migrate:fresh</code> для чистого развёртывания.</li>
                        <li><strong>seed</strong> — запуск сидов: заполнение начальными данными (<code class="doc-kbd">db:seed</code>).</li>
                        <li><strong>assets</strong> — сборка фронта на сервере: <code class="doc-kbd">npm ci</code> и <code class="doc-kbd">npm run build</code>.</li>
                        <li><strong>assets-build</strong> — быстрая пересборка ассетов без установки зависимостей (если уже установлен <code class="doc-kbd">node_modules</code>).</li>
                        <li><strong>sync-odds</strong> — ручной запуск синхронизации коэффициентов (<code class="doc-kbd">epl:sync-odds</code>).</li>
                        <li><strong>sync-results</strong> — ручной запуск синхронизации результатов (<code class="doc-kbd">epl:sync-results</code>).</li>
                        <li><strong>admin-update</strong> — сброс пароля и обновление данных администратора через <code class="doc-kbd">tinker</code>.</li>
                        <li><strong>release</strong> — сборная задача для релиза: кеши, миграции, очистка и подготовка окружения.</li>
                    </ul>

                <div class="doc-code"><pre><code># Примеры запуска задач Envoy
# Укажите сервер, если их несколько (например, beget или local)
envoy run setup --server=beget
envoy run deploy --server=beget --branch=main
envoy run migrate-fresh --server=beget
envoy run seed --server=beget
envoy run assets --server=beget
envoy run assets-build --server=beget
envoy run sync-odds --server=beget
envoy run sync-results --server=beget
envoy run admin-update --server=beget --admin_email=admin@example.com
envoy run release --server=beget
</code></pre></div>
                    <h2>Перенос данных БД (локальная → удалённая)</h2>
                    <ul class="doc-list">
                        <li>Рекомендуется использовать дамп/импорт СУБД.</li>
                        <li>Seeder'ы не переносят текущие данные, они генерируют предопределённые.</li>
                    </ul>
                    <div class="doc-code"><pre><code># MySQL/MariaDB: экспорт локальной БД
mysqldump -u &lt;user&gt; -p &lt;database&gt; &gt; dump.sql

# Копируем dump.sql на сервер
scp dump.sql user@server:/path/to/

# Импорт на удалённой БД
mysql -u &lt;remote_user&gt; -p &lt;remote_database&gt; &lt; /path/to/dump.sql

# PostgreSQL
pg_dump -U &lt;user&gt; -d &lt;database&gt; -f dump.sql
psql -U &lt;remote_user&gt; -d &lt;remote_database&gt; -f dump.sql

# SQLite: копирование файла БД
cp database/database.sqlite /remote/path/database.sqlite

# Через Laravel (две коннекции):
# добавьте "remote" в config/database.php и перенесите данные командой с chunkById+upsert
</code></pre></div>

                    <h2>Запуск релиза</h2>
                    <ul class="doc-list">
                        <li><strong>Что делает:</strong> последовательно запускает <code class="doc-kbd">deploy</code>, <code class="doc-kbd">assets-build</code>, <code class="doc-kbd">assets</code>, <code class="doc-kbd">admin-update</code>.</li>
                        <li><strong>Предусловия:</strong> корректно настроены <code class="doc-kbd">@servers</code>, переменные <code class="doc-kbd">$path</code>, <code class="doc-kbd">$branch</code>, доступ по SSH; установлены PHP/Composer/Node на сервере.</li>
                        <li><strong>Параметры (опционально):</strong> <code class="doc-kbd">--branch=main</code>, <code class="doc-kbd">--server=beget</code>, для <code class="doc-kbd">admin-update</code> можно передать <code class="doc-kbd">--admin_username</code>, <code class="doc-kbd">--admin_email</code>, <code class="doc-kbd">--admin_password</code>.</li>
                    </ul>
                    <div class="doc-code"><pre><code># Базовый запуск релиза на beget
envoy run release --server=beget --branch=main

# С обновлением администратора в рамках релиза
envoy run release --server=beget --branch=main \
  --admin_username=admin \
  --admin_email=admin@example.com \
  --admin_password="S3curePass!"

# Локальная проверка ассетов (если требуется)
envoy run assets-build --server=local && envoy run assets --server=beget
</code></pre></div>

                    <h2>Как запустить Envoy</h2>
                    <ul class="doc-list">
                        <li><strong>Через Docker Compose (рекомендуется):</strong> команды исполняются внутри контейнера <code class="doc-kbd">app</code>.</li>
                        <li><strong>Локально (Windows):</strong> выполните из каталога <code class="doc-kbd">src</code> команду <code class="doc-kbd">php vendor/bin/envoy ...</code>.</li>
                    </ul>
                    <div class="doc-code"><pre><code># Проверить, что Envoy доступен (в контейнере)
docker compose exec app vendor/bin/envoy list

# Запустить релиз (в контейнере)
docker compose exec app vendor/bin/envoy run release --server=beget --branch=main

# Запустить релиз локально (если PHP установлен и вы в каталоге src)
php8.4 vendor/bin/envoy run release --server=beget --branch=main

# Подсказка: чтобы увидеть список задач
docker compose exec app vendor/bin/envoy tasks

# Запустить Laravel dev-сервер (в контейнере)
docker compose exec app php artisan serve --host=0.0.0.0 --port=8000 --no-reload

# Альтернатива без Compose (по имени контейнера)
docker exec games_app php artisan serve --host=0.0.0.0 --port=8000 --no-reload
</code></pre></div>
<div>
    <pre>
    Где и как считается результат

- BetController@autoSettleDue — автоматический расчёт по времени и данным API.
  
  - Отбор прошедших событий: src/app/Http/Controllers/BetController.php:435–440
  - Получение результатов матча и таймов из API: src/app/Http/Controllers/BetController.php:442–450
  - Восстановление счета 2‑го тайма при его отсутствии: src/app/Http/Controllers/BetController.php:451–454
  - Установка результата события ( home/draw/away ): src/app/Http/Controllers/BetController.php:458–461
  - Расчёт ставок по рынкам:
    - 1x2 (итог матча): src/app/Http/Controllers/BetController.php:468–471
    - «2 тайм»: src/app/Http/Controllers/BetController.php:471–475
    - «Тоталы 1 тайм»: src/app/Http/Controllers/BetController.php:476–484
    - «Тоталы 2 тайм»: src/app/Http/Controllers/BetController.php:485–493
    - «Тоталы» (общий тотал, включая четвертные линии): src/app/Http/Controllers/BetController.php:494–509
    - «Обе забьют»: src/app/Http/Controllers/BetController.php:510–513
    - «1 забьет / не забьет»: src/app/Http/Controllers/BetController.php:514–517
    - «2 забьет / не забьет»: src/app/Http/Controllers/BetController.php:518–521
    - «Азиатский Гандикап»/«Фора» (включая четвертные линии): src/app/Http/Controllers/BetController.php:522–541
    - «1 Тайм / 2 Тайм»: src/app/Http/Controllers/BetController.php:542–548
  - Обновление ставки: is_win , payout_demo , settled_at : src/app/Http/Controllers/BetController.php:550–556
  - Итоги купона, выставление is_win , payout_demo , settled_at : src/app/Http/Controllers/BetController.php:558–569
- BetController@settle — ручная установка результата матча.
  
  - Установка статуса и результата события: src/app/Http/Controllers/BetController.php:292–296
  - Расчёт связанных ставок 1x2 по кэфам события: src/app/Http/Controllers/BetController.php:298–311
  - Итоги купона (если все ноги рассчитаны): src/app/Http/Controllers/BetController.php:315–327
- BetController@syncResults — пакетная синхронизация завершённых матчей из API.
  
  - Установка результата и завершение события: src/app/Http/Controllers/BetController.php:395–405
  - Расчёт связанных ставок 1x2 по кэфам события: src/app/Http/Controllers/BetController.php:407–419
Тестовые данные для рынков

- В режиме теста рынки подтягиваются из тестового файла:
  - Условие для тестового источника (события TEST или external_id с префиксом): src/app/Http/Controllers/OddsController.php:17
  - Тестовый файл с рынками: src/odds_test.json (изменён под ваши показатели)
Если нужно, могу добавить маршрут для autoSettleDue и запуск по расписанию, чтобы расчёт происходил автоматически.
</pre>
</div>
                </div>
            </section>
        </div>
    </main>
</body>
</html>