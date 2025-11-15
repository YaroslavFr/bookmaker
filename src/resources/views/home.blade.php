<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sport FreeBets</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @if (file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
        <script src="{{ asset('js/app.js') }}" defer></script>
    @endif
    </head>
<body>
    @include('partials.header')
    <main>
        <div class="container">
            <div class="row">
                <h1 class="text-2xl font-bold mt-6 mb-6">Линия событий</h1>
            </div>
            <div id="mainrow" class="grid grid-cols-1 md:grid-cols-[7fr_3fr] gap-4">
                <div>
                @php($leagues = is_array($leagues ?? null) ? $leagues : [])

                @foreach($leagues as $league)
                    @php($events = $league['events'] ?? [])
                    @if(count($events))
                        @include('partials.league-card', [
                            'title' => ($league['title'] ?? 'Лига'),
                            'events' => $events,
                            'first' => $loop->first,
                        ])
                    @endif
                @endforeach
                </div>
            
            @if(auth()->check())
            <div class="card mt-20 ">
                <div id="vue-app"
                     data-csrf="{{ csrf_token() }}"
                     data-post-url="{{ route('bets.store') }}"
                     data-is-auth="{{ auth()->check() ? '1' : '0' }}"
                     data-username="{{ auth()->check() ? (auth()->user()->username ?? '') : '' }}">
                </div>
            </div>
            @endif
            </div>
            <a href="/events/settle-test/1000000000666777" class="btn btn-primary">Тестовый расчёт</a>
            @php($coupons = $coupons ?? [])
            <div class="card mt-20 coupons-block">
                <h2 class="px-4 pl-8">Последние ставки (купоны)</h2>
                @if(empty($coupons))
                    <p class="muted">История ставок пуста.</p>
                @else
                <div class="responsive-table rt rt--coupons">
                    <div class="rt-head">
                        <div class="rt-th">Купон ID</div>
                        <div class="rt-th">Игрок</div>
                        <div class="rt-th">События (экспресс)</div>
                        <div class="rt-th">Сумма</div>
                        <div class="rt-th">Итоговый кэф</div>
                        <div class="rt-th">Потенц. выплата</div>
                        <div class="rt-th">Статус</div>
                        <div class="rt-th">Дата расчета</div>
                        <div class="rt-th">Дата ставки</div>
                    </div>
                    <div class="rt-body">
                        @foreach($coupons as $coupon)
                            @php($potential = ($coupon->total_odds && $coupon->amount_demo) ? ($coupon->amount_demo * $coupon->total_odds) : null)
                            @php($evTimes = collect($coupon->bets ?? [])
                                ->filter(function($b){ return $b && $b->event && $b->event->starts_at; })
                                ->map(function($b){ return $b->event->starts_at; }))
                            @php($latestStart = $evTimes->max())
                            @php($settlementAt = $latestStart ? $latestStart->copy()->addMinutes(120)->setTimezone('Europe/Moscow') : null)
                            <div class="rt-row">
                                <div class="rt-cell" data-label="Купон ID">{{ $coupon->id }}</div>
                                <div class="rt-cell" data-label="Игрок">{{ $coupon->bettor_name }}</div>
                                <div class="rt-cell" data-label="События (экспресс)">
                                    @foreach($coupon->bets as $l)
                                        @php($selMap = ['home' => 'П1', 'draw' => 'Ничья', 'away' => 'П2'])
                                        @php($selKey = strtolower(trim($l->selection)))
                                        @php($placedOdds = $l->placed_odds ?? ($l->event ? match($selKey) {
                                            'home' => $l->event->home_odds,
                                            'draw' => $l->event->draw_odds,
                                            'away' => $l->event->away_odds,
                                            default => null,
                                        } : null))
                                        <div class="mb-2 text-sm">
                                            <div class="font-bold">
                                            @if($l->event && $l->event->home_team && $l->event->away_team)
                                                {{ $l->event->home_team }} vs {{ $l->event->away_team }}
                                            @else
                                                {{ $l->event->title ?? ('Event #'.$l->event_id) }}
                                            @endif
                                            </div>
                                            <div>Ставка - @if(!empty($l->market))<span class="market-title">{{ $l->market }}</span> — @endif<span class="font-medium">{{ $selMap[$selKey] ?? $l->selection }}</span>
                                            @if($placedOdds)
                                                <span class="muted">(кэф.: <span class="text-orange-400 text-base">{{ number_format($placedOdds, 2) }}</span>)</span>
                                            @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="rt-cell" data-label="Сумма">{{ $coupon->amount_demo ? number_format($coupon->amount_demo, 2) : '—' }}</div>
                                <div class="rt-cell" data-label="Итоговый кэф">{{ $coupon->total_odds ? number_format($coupon->total_odds, 2) : '—' }}</div>
                                <div class="rt-cell" data-label="Потенц. выплата">{{ $potential ? number_format($potential, 2) : '—' }}</div>
                                <div class="rt-cell text-xs {{ $coupon->is_win === null ? 'text-gray-500' : ($coupon->is_win ? 'text-green-500' : 'text-red-600') }}" data-label="Статус">{{ $coupon->is_win === null ? 'Не рассчитано' : ($coupon->is_win ? 'Выиграно' : 'Проигрыш') }}</div>
                                <div class="rt-cell" data-label="Дата расчета">{{ $settlementAt ? $settlementAt->format('Y-m-d H:i') : '—' }}</div>
                                <div class="rt-cell text-xs" data-label="Дата ставки">{{ $coupon->created_at ? $coupon->created_at->format('Y-m-d H:i') : '—' }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const oddsBody = document.getElementById('odds-body');
        const oddsLast = document.getElementById('odds-last');
        function formatOdds(v) { return (typeof v === 'number' && isFinite(v)) ? v.toFixed(2) : '—'; }
        async function refreshOdds() {
            if (!oddsBody || !oddsLast) return; // панель авто-обновления отсутствует
            try {
                const res = await fetch("{{ route('odds.index') }}");
                const json = await res.json();
                oddsLast.textContent = 'Последнее обновление: ' + new Date().toLocaleString();
                const items = Array.isArray(json.items) ? json.items : [];
                if (!items.length) {
                    oddsBody.innerHTML = '<tr><td colspan="3" class="muted">Нет данных от API</td></tr>';
                    return;
                }
                oddsBody.innerHTML = items.map(it => {
                    const h = formatOdds(it.home_odds);
                    const d = formatOdds(it.draw_odds);
                    const a = formatOdds(it.away_odds);
                    const dt = it.commence_time ? new Date(it.commence_time).toLocaleString() : '—';
                    const title = `${it.home_team ?? ''} vs ${it.away_team ?? ''}`;
                    const oddsHtml = (it.event_id)
                        ? `<div class="odd-group">
                               <span class="odd-btn odd-btn--home" data-event-id="${it.event_id}" data-selection="home" data-home="${it.home_team ?? ''}" data-away="${it.away_team ?? ''}" data-odds="${h}">П1 ${h}</span>
                               <span class="odd-btn odd-btn--draw" data-event-id="${it.event_id}" data-selection="draw" data-home="${it.home_team ?? ''}" data-away="${it.away_team ?? ''}" data-odds="${d}">Ничья ${d}</span>
                               <span class="odd-btn odd-btn--away" data-event-id="${it.event_id}" data-selection="away" data-home="${it.home_team ?? ''}" data-away="${it.away_team ?? ''}" data-odds="${a}">П2 ${a}</span>
                           </div>`
                        : `${h} / ${d} / ${a}`;
                    return `<tr>
                        <td>${title}</td>
                        <td>${dt}</td>
                        <td>${oddsHtml}</td>
                    </tr>`;
                }).join('');
            } catch (e) {
                oddsBody.innerHTML = `<tr><td colspan="3" class="muted">Ошибка API: ${e && e.message ? e.message : String(e)}</td></tr>`;
            }
        }
        if (oddsBody && oddsLast) {
            refreshOdds();
            setInterval(refreshOdds, 60000); // refresh every 60s
        }

        document.querySelectorAll('.collapsible .collapse-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const wrap = btn.closest('.collapsible');
                if (!wrap) return;
                const arrow = btn.querySelector('.arrow');
                const isCollapsed = wrap.classList.toggle('is-collapsed');
                if (arrow) arrow.textContent = isCollapsed ? '▸' : '▾';
                btn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            });
        });

        // Инъекция карт из бэкенда (ленивая загрузка — без предзагрузки рынков)
        window.MARKETS_MAP = window.MARKETS_MAP || {};
        window.GAME_IDS_MAP = (typeof @json($gameIdsMap ?? []) !== 'undefined' ? @json($gameIdsMap ?? []) : {});

        // Extra markets toggler: при раскрытии делаем запрос /odds/game/{gameId}?bookmakerId=2
        document.addEventListener('click', async function(e) {
            const t = e.target.closest('.extra-toggle');
            if (!t) return;
            const targetId = t.getAttribute('data-target-id');
            const row = document.getElementById(targetId);
            if (!row) return;
            // Toggle visibility (используем вычисленный стиль, чтобы корректно сворачивать)
            const isHidden = window.getComputedStyle(row).display === 'none';
            row.style.display = isHidden ? '' : 'none';
            // Обновляем текст кнопки
            t.textContent = isHidden ? '−' : '+';
            if (!isHidden) return; // при сворачивании — без запроса
            const box = row.querySelector('.extra-markets');
            const state = row.querySelector('[data-state]');
            if (!box || !state) return;
            const loaded = box.getAttribute('data-loaded');
            if (loaded === '1') return; // уже загружено
            state.textContent = 'Загружаю доп. ставки…';
            // Сначала всегда пробуем свежие рынки по gameId
            const eid = t.getAttribute('data-event-id');
            const homeName = t.getAttribute('data-home') || '';
            const awayName = t.getAttribute('data-away') || '';
            const gid = window.GAME_IDS_MAP ? window.GAME_IDS_MAP[eid] : null;
            if (!gid) {
                state.textContent = 'Игра не найдена для доп. ставок';
                return;
            }
            try {
                // Переводы названий рынков доп. ставок
                const MARKET_TRANSLATIONS = {
                    // базовые соответствия (нижний регистр)
                    'second half winner': '2 тайм',
                    'asian handicap': 'Азиатский Гандикап',
                    'goals over/under': 'Тоталы',
                    'goals over/under first half': 'Тоталы 1 тайм',
                    'goals over/under - second half': 'Тоталы 2 тайм',
                    'goals over/under second half': 'Тоталы 2 тайм',
                    'ht/ft double': '1 Тайм / 2 Тайм',
                    'both teams score': 'Обе забьют',
                    'win to nil - home': '1 забьет / не забьет',
                    'win to nil - away': '2 забьет / не забьет',
                    'handicap result': 'Фора',
                };
                function translateMarketTitle(name) {
                    if (!name) return '';
                    const raw = String(name).trim();
                    const norm = raw.toLowerCase().replace(/\s+/g, ' ');
                    // точное совпадение
                    if (MARKET_TRANSLATIONS[norm]) return MARKET_TRANSLATIONS[norm];
                    // эвристики по включению ключевых слов
                    if (norm.includes('asian handicap')) return MARKET_TRANSLATIONS['asian handicap'];
                    if (norm.includes('handicap') && norm.includes('result')) return MARKET_TRANSLATIONS['handicap result'];
                    if (norm.includes('goals over/under') && norm.includes('first half')) return MARKET_TRANSLATIONS['goals over/under first half'];
                    if (norm.includes('goals over/under') && (norm.includes('second half') || norm.includes('2nd half'))) return MARKET_TRANSLATIONS['goals over/under second half'];
                    if (norm.includes('goals over/under')) return MARKET_TRANSLATIONS['goals over/under'];
                    if (norm.includes('ht/ft') || norm.includes('half time/full time')) return MARKET_TRANSLATIONS['ht/ft double'];
                    if (norm.includes('both teams score')) return MARKET_TRANSLATIONS['both teams score'];
                    if (norm.includes('win to nil') && norm.includes('home')) return MARKET_TRANSLATIONS['win to nil - home'];
                    if (norm.includes('win to nil') && norm.includes('away')) return MARKET_TRANSLATIONS['win to nil - away'];
                    if (norm.includes('second half winner')) return MARKET_TRANSLATIONS['second half winner'];
                    return raw; // без перевода — оригинал
                }
                const base = "{{ url('/odds/game') }}";
                const url = base + '/' + encodeURIComponent(String(gid)) + '?bookmakerId=2&_ts=' + Date.now();
                const resp = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const json = await resp.json();
                if (!json || json.ok !== true || !Array.isArray(json.markets)) throw new Error(json && json.error ? json.error : 'Bad payload');
                const markets = json.markets;
                if (!markets.length) {
                    // Если с сервера пусто — попробуем предзагруженные рынки как фоллбэк
                    const preEmpty = (window.MARKETS_MAP && window.MARKETS_MAP[eid]) ? window.MARKETS_MAP[eid] : null;
                    if (Array.isArray(preEmpty) && preEmpty.length) {
                        state.remove();
                        box.setAttribute('data-loaded', '1');
                        const headerHtml0 = '<div class="muted" style="margin-bottom:6px;"></div>';
                        box.innerHTML = headerHtml0 + preEmpty.map(function(m) {
                            const sels = Array.isArray(m.selections) ? m.selections : [];
                            const selsHtml = sels.map(function(s) {
                                const p = (typeof s.price === 'number' && isFinite(s.price)) ? s.price.toFixed(2) : '—';
                                return '<span class="market-sel">' + s.label + ' • ' + p + '</span>';
                            }).join('');
                            return '<div class="market-box">'
                                 +   '<div class="market-title">' + translateMarketTitle(m.name) + '</div>'
                                 +   '<div class="market-sels">' + selsHtml + '</div>'
                                 + '</div>';
                        }).join('');
                        return;
                    }
                    state.textContent = 'Доп. рынки не найдены';
                    return;
                }
                state.remove();
                box.setAttribute('data-loaded', '1');
                const headerHtml2 = '<div class="muted" style="margin-bottom:6px;"></div>';
                box.innerHTML = headerHtml2 + markets.map(function(m) {
                    const sels = Array.isArray(m.selections) ? m.selections : [];
                    const selsHtml = sels.map(function(s) {
                        const p = (typeof s.price === 'number' && isFinite(s.price)) ? s.price.toFixed(2) : '—';
                        const selLabel = String(s.label || '').trim();
                        const selData = selLabel;
                        return '<span class="market-sel odd-btn"'
                             + ' data-event-id="' + String(eid) + '"'
                             + ' data-market="' + String(translateMarketTitle(m.name) || '').replace(/\"/g, '&quot;') + '"'
                             + ' data-selection="' + selData.replace(/\"/g, '&quot;') + '"'
                             + ' data-home="' + homeName.replace(/\"/g, '&quot;') + '"'
                             + ' data-away="' + awayName.replace(/\"/g, '&quot;') + '"'
                             + ' data-odds="' + p + '">' + selLabel + ' • ' + p + '</span>';
                    }).join('');
                    return '<div class="market-box">'
                         +   '<div class="market-title">' + translateMarketTitle(m.name) + '</div>'
                         +   '<div class="market-sels">' + selsHtml + '</div>'
                         + '</div>';
                }).join('');
            } catch (err) {
                // При ошибке запроса — фоллбэк на предзагруженные рынки, если есть
                const pre = (window.MARKETS_MAP && window.MARKETS_MAP[eid]) ? window.MARKETS_MAP[eid] : null;
                if (Array.isArray(pre) && pre.length) {
                    state.remove();
                    box.setAttribute('data-loaded', '1');
                    const headerHtml = '<div class="muted" style="margin-bottom:6px;"></div>';
                    box.innerHTML = headerHtml + pre.map(function(m) {
                        const sels = Array.isArray(m.selections) ? m.selections : [];
                    const selsHtml = sels.map(function(s) {
                        const p = (typeof s.price === 'number' && isFinite(s.price)) ? s.price.toFixed(2) : '—';
                        const selLabel = String(s.label || '').trim();
                        const selData = selLabel;
                        return '<span class="market-sel odd-btn"'
                             + ' data-event-id="' + String(eid) + '"'
                             + ' data-market="' + String(translateMarketTitle(m.name) || '').replace(/\"/g, '&quot;') + '"'
                             + ' data-selection="' + selData.replace(/\"/g, '&quot;') + '"'
                             + ' data-home="' + homeName.replace(/\"/g, '&quot;') + '"'
                             + ' data-away="' + awayName.replace(/\"/g, '&quot;') + '"'
                             + ' data-odds="' + p + '">' + selLabel + ' • ' + p + '</span>';
                    }).join('');
                        return '<div class="market-box">'
                             +   '<div class="market-title">' + translateMarketTitle(m.name) + '</div>'
                             +   '<div class="market-sels">' + selsHtml + '</div>'
                             + '</div>';
                    }).join('');
                } else {
                    state.textContent = 'Ошибка загрузки доп. ставок';
                }
            }
        });
    });
    </script>

        </div>
    </main>
</body>
</html>