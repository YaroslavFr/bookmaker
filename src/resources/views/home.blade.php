<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Спорт КУКОЛД</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @if (file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
        <script src="{{ asset('js/app.js') }}" defer></script>
    @endif
    
    <style>
        .line tr:last-child td{
            border-bottom:0;
        }
        .line td{
            padding:3px 2px;
        }
        .muted { color: #6b7280; font-size: 12px; }
        .wrap { word-break: break-word; }
        .collapsible .collapse-toggle { cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-size: 14px; padding: 6px 10px; }
        .collapsible .arrow { font-size: 16px; line-height: 1; }
        .collapsible.is-collapsed .collapsible-body { display: none; }
        .row-between { display: flex; align-items: center; justify-content: space-between; }
        .card-header { margin-bottom: 8px; }
        .teams-row {font-size: 14px; display: inline-flex; align-items: center; gap: 10px; }
        .team-name { font-weight: 600; }
        .vs-sep { color: #6b7280; }
        .event-sub { margin-top: 4px; font-size: 12px; color: #6b7280; }
    </style>
    </head>
<body>
    @include('partials.header')
    <main>
        <div class="container">
            <div class="row">
                <h1 class="text-2xl font-bold mt-6 mb-6">Линия событий</h1>
            </div>
            <div id="mainrow" class="grid grid-cols-1 md:grid-cols-[7fr_3fr] gap-4">
            <div class="card mt-20">
                <h2 class="font-bold">События</h2>
                @php($events = $events ?? [])
                @if(empty($events))
                    <p class="muted">Нет событий.</p>
                @else
                    <table class="responsive-table line">
                        <thead>
                            <tr>
                                <th>Матч</th>
                                <th>Дата/время</th>
                                <th>Коэфф. (П1 / Ничья / П2)</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($events as $ev)
                                <tr>
                                    <td data-label="Матч">
                                        @if(!empty($ev->home_team) && !empty($ev->away_team))
                                            <div class="teams-row">
                                                <span class="team-name">{{ $ev->home_team }}</span>
                                                <span class="vs-sep">vs</span>
                                                <span class="team-name">{{ $ev->away_team }}</span>
                                            </div>
                                        @else
                                            <b>{{ $ev->title ?? ('Event #'.$ev->id) }}</b>
                                        @endif
                                        
                                    </td>
                                    <td class="text-sm muted" data-label="Дата/время">
                                        {{ $ev->starts_at ? $ev->starts_at->format('d.m.Y H:i') : '—' }}
                                    </td>
                                    <td data-label="Коэфф. (Д/Н/Г)">
                                        @php($h = $ev->home_odds)
                                        @php($d = $ev->draw_odds)
                                        @php($a = $ev->away_odds)
                                        @if($h && $d && $a)
                                            @if(($ev->status ?? 'scheduled') === 'scheduled')
                                                <div class="odd-group">
                                                    <span class="odd-btn odd-btn--home" data-event-id="{{ $ev->id }}" data-selection="home" data-home="{{ $ev->home_team ?? '' }}" data-away="{{ $ev->away_team ?? '' }}" data-odds="{{ number_format($h, 2) }}">П1 {{ number_format($h, 2) }}</span>
                                                    <span class="odd-btn odd-btn--draw" data-event-id="{{ $ev->id }}" data-selection="draw" data-home="{{ $ev->home_team ?? '' }}" data-away="{{ $ev->away_team ?? '' }}" data-odds="{{ number_format($d, 2) }}">Ничья {{ number_format($d, 2) }}</span>
                                                    <span class="odd-btn odd-btn--away" data-event-id="{{ $ev->id }}" data-selection="away" data-home="{{ $ev->home_team ?? '' }}" data-away="{{ $ev->away_team ?? '' }}" data-odds="{{ number_format($a, 2) }}">П2 {{ number_format($a, 2) }}</span>
                                                </div>
                                            @else
                                                {{ number_format($h, 2) }} / {{ number_format($d, 2) }} / {{ number_format($a, 2) }}
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-sm muted {{ $ev->status === 'scheduled' ? 'muted' : 'text-green-500' }}" data-label="Статус">{{ $ev->status ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="card mt-20">
                <div id="vue-app"
                     data-csrf="{{ csrf_token() }}"
                     data-post-url="{{ route('bets.store') }}"
                     data-is-auth="{{ auth()->check() ? '1' : '0' }}"
                     data-username="{{ auth()->check() ? (auth()->user()->username ?? '') : '' }}"></div>
            </div>
            </div>
            @php($coupons = $coupons ?? [])
            <div class="card mt-20">
                <h2>Последние ставки (купоны)</h2>
                @if(empty($coupons))
                    <p class="muted">История ставок пуста.</p>
                @else
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>Купон ID</th>
                            <th>Игрок</th>
                            <th>События (экспресс)</th>
                            <th>Сумма</th>
                            <th>Итоговый кэф</th>
                            <th>Потенц. выплата</th>
                            <th>Статус</th>
                            <th>Дата ставки</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($coupons as $coupon)
                            <tr>
                                <td>{{ $coupon->id }}</td>
                                <td>{{ $coupon->bettor_name }}</td>
                                    <td>
                                    @foreach($coupon->bets as $l)
                                        @php($selMap = ['home' => 'П1', 'draw' => 'Ничья', 'away' => 'П2'])
                                        @php($placedOdds = $l->event ? (match($l->selection){
                                            'home' => $l->event->home_odds,
                                            'draw' => $l->event->draw_odds,
                                            'away' => $l->event->away_odds,
                                        }) : null)
                                        <div class="mb-2 text-sm">
                                            @if($l->event && $l->event->home_team && $l->event->away_team)
                                                {{ $l->event->home_team }} vs {{ $l->event->away_team }}
                                            @else
                                                {{ $l->event->title ?? ('Event #'.$l->event_id) }}
                                            @endif
                                            — {{ $selMap[$l->selection] ?? strtoupper($l->selection) }}
                                            @if($placedOdds)
                                                <span class="muted">(кэф.: {{ number_format($placedOdds, 2) }})</span>
                                            @endif
                                        </div>
                                    @endforeach
                                    </td>
                                <td>{{ $coupon->amount_demo ? number_format($coupon->amount_demo, 2) : '—' }}</td>
                                <td>{{ $coupon->total_odds ? number_format($coupon->total_odds, 2) : '—' }}</td>
                                @php($potential = ($coupon->total_odds && $coupon->amount_demo) ? ($coupon->amount_demo * $coupon->total_odds) : null)
                                <td>{{ $potential ? number_format($potential, 2) : '—' }}</td>
                                <td>{{ $coupon->is_win === null ? '—' : ($coupon->is_win ? 'Выиграно' : 'Проигрыш') }}</td>
                                <td>{{ $coupon->created_at ? $coupon->created_at->format('Y-m-d H:i') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>

            

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const oddsBody = document.getElementById('odds-body');
        const oddsLast = document.getElementById('odds-last');
        function formatOdds(v) { return (typeof v === 'number' && isFinite(v)) ? v.toFixed(2) : '—'; }
        async function refreshOdds() {
            try {
                const res = await fetch('{{ route('debug.odds') }}');
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
        refreshOdds();
        setInterval(refreshOdds, 60000); // refresh every 60s

        document.querySelectorAll('.collapsible .collapse-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var wrap = btn.closest('.collapsible');
                if (!wrap) return;
                var arrow = btn.querySelector('.arrow');
                var isCollapsed = wrap.classList.toggle('is-collapsed');
                if (arrow) arrow.textContent = isCollapsed ? '▸' : '▾';
                btn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            });
        });
    });
    </script>

        </div>
    </main>
</body>
</html>