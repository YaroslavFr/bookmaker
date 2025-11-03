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
        .doc-list { list-style: disc; padding-left: 20px; }
        .doc-code { background: #0b1020; color: #e6edf3; border-radius: 6px; padding: 12px; overflow: auto; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 13px; }
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
            <h1 class="text-2xl font-bold mt-6 mb-2">Как формируются события на главной</h1>
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
                    <li><a href="#deploy">Деплой</a></li>
                </ul>
            </div>

            <section class="doc-section" id="chain">
                <div class="doc-card">
                    <h2>Ключевая цепочка</h2>
                    <ul class="doc-list">
                        <li>Маршрут <code class="doc-kbd">GET /</code> указывает на <code class="doc-kbd">BetController@index</code>.</li>
                        <li><code class="doc-kbd">index()</code> загружает <code class="doc-kbd">$events</code> и <code class="doc-kbd">$coupons</code> из БД.</li>
                        <li>Представление: <code class="doc-kbd">resources/views/home.blade.php</code> показывает таблицу событий и купон.</li>
                        <li>Модель: <code class="doc-kbd">App\Models\Event</code> с полями команд и коэффициентов.</li>
                    </ul>
                    <div class="doc-code"><pre><code>// routes/web.php
Route::get('/', [BetController::class, 'index'])->name('home');

// app/Http/Controllers/BetController.php
public function index()
{
    $events = Event::with('bets')
        ->orderByDesc('starts_at')
        ->orderByDesc('id')
        ->get();
    $coupons = Coupon::with(['bets.event'])->latest()->limit(50)->get();
    return view('home', compact('events', 'coupons'));
}
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
                        <li>Команды: <code class="doc-kbd">TheSportsDB</code> (список EPL).</li>
                        <li>Коэффициенты: <code class="doc-kbd">The Odds API</code> (рынок h2h, формат decimal).</li>
                        <li>Сохранение: <code class="doc-kbd">Event::updateOrCreate(...)</code> со статусом <code class="doc-kbd">scheduled</code>.</li>
                        <li>Фоллбэк при отсутствии ключа — пары команд с базовыми кэфами.</li>
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
                    <p class="muted">Запуск: <code class="doc-kbd">php artisan epl:sync-odds --limit=10</code> • ключ <code class="doc-kbd">ODDS_API_KEY</code> в <code class="doc-kbd">.env</code>.</p>
                </div>
            </section>

            <section class="doc-section" id="sync-results">
                <div class="doc-card">
                    <h2>Синхронизация результатов (epl:sync-results)</h2>
                    <ul class="doc-list">
                        <li>Источник: <code class="doc-kbd">TheSportsDB</code> (прошедшие матчи EPL).</li>
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
                        <li>Клик по коэффициенту добавляет исход в купон (Vue компонент).</li>
                        <li>Отправка на <code class="doc-kbd">POST /bets</code>, где сохраняются купон и ставки.</li>
                    </ul>
                    @verbatim
                    <div class="doc-code"><pre><code>&lt;span class="odd-btn" data-event-id="{{ $ev->id }}" data-selection="home"&gt;П1 {{ number_format($h, 2) }}&lt;/span&gt;

// resources/js/components/BetSlip.vue
function handleOddClick(e) {
  const btn = e.target.closest('.odd-btn');
  if (!btn) return;
  const eventId = btn.getAttribute('data-event-id');
  const selection = btn.getAttribute('data-selection');
  const home = btn.getAttribute('data-home');
  const away = btn.getAttribute('data-away');
  const odds = btn.getAttribute('data-odds');
  addOrReplaceSlipItem({ eventId, home, away, selection, odds });
}
</code></pre></div>
                    @endverbatim
                </div>
            </section>

            <section class="doc-section" id="keys">
                <div class="doc-card">
                    <h2>Ключи и планировщик</h2>
                    <ul class="doc-list">
                        <li><code class="doc-kbd">ODDS_API_KEY</code> — The Odds API (коэффициенты).</li>
                        <li>Планировщик: ежедневно <code class="doc-kbd">epl:sync-odds</code> в 06:00; ежечасно <code class="doc-kbd">epl:sync-results</code>.</li>
                        <li>Маршрут быстрого обновления результатов: <code class="doc-kbd">GET /events/sync-results</code>.</li>
                    </ul>
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
php vendor/bin/envoy run release --server=beget --branch=main

# Подсказка: чтобы увидеть список задач
docker compose exec app vendor/bin/envoy tasks
</code></pre></div>
                </div>
            </section>
        </div>
    </main>
</body>
</html>