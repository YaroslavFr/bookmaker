<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BetController — документация</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .doc-container { max-width: 960px; margin: 0 auto; padding: 16px; }
        .doc-section { margin-top: 24px; }
        .doc-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; }
        .doc-card h2 { font-weight: 700; font-size: 18px; margin-bottom: 12px; }
        .doc-list { list-style: auto; padding-left: 20px; }
        .doc-code { margin: 5px 0; background: linear-gradient(135deg,#001628 0%,#910b87 100%); color: #e6edf3; border-radius: 6px; padding: 12px; overflow: auto; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 13px; }
        .doc-toc { margin-top: 12px; padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px; }
        .doc-toc-list { list-style: none; padding-left: 0; display: flex; flex-direction: column; gap: 6px; }
        .doc-toc-list a { color: #2563eb; text-decoration: none; }
        .doc-toc-list a:hover { text-decoration: underline; }
        .muted { color: #6b7280; }
        .doc-code.collapsed { max-height: 80px; overflow: hidden; }
        .doc-code-toggle { padding: 6px 10px; border: 1px solid #ccc; background: #f7f7f7; cursor: pointer; border-radius: 4px; font-size: 13px; margin: 15px 0; display: inline-block; }
    </style>
</head>
<body>
    @include('partials.header')
    <main>
        <div class="doc-container">
            <h1 class="text-2xl font-bold mt-6 mb-2">BetController — документация</h1>
            <p class="muted">Контроллер отвечает за ленты событий, создание купонов, расчёт ставок и автоматическую синхронизацию результатов.</p>

            <div class="doc-toc" aria-label="Содержание">
                <div class="doc-toc-title">Содержание</div>
                <ul class="doc-toc-list">
                    <li><a href="#index">index()</a></li>
                    <li><a href="#store">store()</a></li>
                    <li><a href="#settle">settle()</a></li>
                    <li><a href="#auto-settle-due">autoSettleDue()</a></li>
                    <li><a href="#check-result-schedule">checkResultSchedule()</a></li>
                    <li><a href="#cron-status">cronStatus()</a></li>
                    <li><a href="#settle-unsettled">settleUnsettledBets()</a></li>
                    <li><a href="#full">Полный код BetController.php</a></li>
                </ul>
            </div>

            <section class="doc-section" id="index">
                <div class="doc-card">
                    <h2>index()</h2>
                    <ul class="doc-list">
                        <li>Собирает события по лигам, готовит заголовки и карту внешних ID.</li>
                        <li>Формирует данные для представления `home` и историю купонов.</li>
                        <li>Источник: `app/Http/Controllers/BetController.php:18`.</li>
                    </ul>
                    <div class="doc-code collapsed" id="code-index">
<pre><code>// BetController@index — см. файл по ссылке ниже</code></pre>
                    </div>
                    <button class="doc-code-toggle" data-target="code-index">Показать/скрыть</button>
                </div>
            </section>

            <section class="doc-section" id="store">
                <div class="doc-card">
                    <h2>store()</h2>
                    <ul class="doc-list">
                        <li>Создаёт купон с выбранными ставками и считает итоговый коэффициент.</li>
                        <li>Возвращает JSON с `coupon_id`, `total_odds`, `balance` или редирект на `home`.</li>
                        <li>Источник: `app/Http/Controllers/BetController.php:130`.</li>
                    </ul>
                    <div class="doc-code collapsed" id="code-store">
<pre><code>// BetController@store — см. файл по ссылке ниже</code></pre>
                    </div>
                    
                </div>
            </section>

            <section class="doc-section" id="settle">
                <div class="doc-card">
                    <h2>settle()</h2>
                    <ul class="doc-list">
                        <li>Ручная установка результата события (`home/draw/away`).</li>
                        <li>Рассчитывает связанные ставки по кэфам события и, при необходимости, весь купон.</li>
                        <li>Источник: `app/Http/Controllers/BetController.php:294`.</li>
                    </ul>
                    <div class="doc-code collapsed" id="code-settle">
<pre><code>// BetController@settle — см. файл по ссылке ниже</code></pre>
                    </div>
                </div>
            </section>

            <section class="doc-section" id="auto-settle-due">
                <div class="doc-card">
                    <h2>autoSettleDue()</h2>
                    <ul class="doc-list">
                        <li>Автоматически подбирает прошедшие события и тянет результаты из API.</li>
                        <li>Выставляет `result` события и рассчитывает ставки по рынкам.</li>
                        <li>Источник: `app/Http/Controllers/BetController.php:340`.</li>
                    </ul>
                    <div class="doc-code collapsed" id="code-auto">
<pre><code>// BetController@autoSettleDue — см. файл по ссылке ниже</code></pre>
                    </div>
                </div>
            </section>

            <section class="doc-section" id="check-result-schedule">
                <div class="doc-card">
                    <h2>checkResultSchedule()</h2>
                    <ul class="doc-list">
                        <li>Возвращает список `external_id` событий, запланированных на сегодня (+6 часов).</li>
                        <li>Используется авто‑процессингом и кроном.</li>
                        <li>Источник: `app/Http/Controllers/BetController.php:535`.</li>
                    </ul>
                    <div class="doc-code collapsed" id="code-check">
<pre><code>// BetController@checkResultSchedule — см. файл по ссылке ниже</code></pre>
                    </div>
                </div>
            </section>

            <section class="doc-section" id="cron-status">
                <div class="doc-card">
                    <h2>cronStatus()</h2>
                    <ul class="doc-list">
                        <li>Статус задач: счётчик автосеттла, последняя активность и параметры окружения.</li>
                        <li>Источник: `app/Http/Controllers/BetController.php:553`.</li>
                    </ul>
                    <div class="doc-code collapsed" id="code-cron">
<pre><code>// BetController@cronStatus — см. файл по ссылке ниже</code></pre>
                    </div>
                </div>
            </section>

            <section class="doc-section" id="settle-unsettled">
                <div class="doc-card">
                    <h2>settleUnsettledBets()</h2>
                    <ul class="doc-list">
                        <li>Перерасчитывает ставки без `settled_at`, опционально по `event_id`/`bet_id`.</li>
                        <li>Тянет недостающие результаты из API и обновляет исходы.</li>
                        <li>Источник: `app/Http/Controllers/BetController.php:573`.</li>
                    </ul>
                    <div class="doc-code collapsed" id="code-unsettled">
<pre><code>// BetController@settleUnsettledBets — см. файл по ссылке ниже</code></pre>
                    </div>
                </div>
            </section>

            <section class="doc-section" id="full">
                <div class="doc-card">
                    <h2>Полный код BetController.php</h2>
                    <p class="muted">Файл читается напрямую из исходников, чтобы содержимое всегда было актуальным.</p>
                    @php
                        $path = base_path('app/Http/Controllers/BetController.php');
                        $code = is_file($path) ? file_get_contents($path) : '';
                    @endphp
                    <div class="doc-code collapsed" id="code-full">
<pre><code>{!! $code !!}</code></pre>
                    </div>
                    <button class="doc-code-toggle" data-target="code-full">Показать/скрыть</button>
                </div>
            </section>
        </div>
    </main>
    <script>
        document.querySelectorAll('.doc-code-toggle').forEach(function(btn){
            btn.addEventListener('click', function(){
                var id = btn.getAttribute('data-target');
                var el = document.getElementById(id);
                if (!el) return;
                el.classList.toggle('collapsed');
            });
        });
    </script>
</body>
</html>
