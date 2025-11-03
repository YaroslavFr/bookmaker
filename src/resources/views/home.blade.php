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
        .muted { color: #6b7280; font-size: 12px; }
        .wrap { word-break: break-word; }
        .collapsible .collapse-toggle { cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-size: 14px; padding: 6px 10px; }
        .collapsible .arrow { font-size: 16px; line-height: 1; }
        .collapsible.is-collapsed .collapsible-body { display: none; }
        .row-between { display: flex; align-items: center; justify-content: space-between; }
        .card-header { margin-bottom: 8px; }
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
                    <table class="responsive-table">
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
                                            <span class="teams-title">{{ $ev->home_team }} vs {{ $ev->away_team }}</span>
                                        @else
                                            <b>{{ $ev->title ?? ('Event #'.$ev->id) }}</b>
                                        @endif
                                    </td>
                                    <td data-label="Дата/время">
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
                                    <td data-label="Статус">{{ $ev->status ?? '—' }}</td>
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
                                        <div>
                                            @if($l->event && $l->event->home_team && $l->event->away_team)
                                                {{ $l->event->home_team }} vs {{ $l->event->away_team }}
                                            @else
                                                {{ $l->event->title ?? ('Event #'.$l->event_id) }}
                                            @endif
                                            @php($selMap = ['home' => 'П1', 'draw' => 'Ничья', 'away' => 'П2'])
                                            — выбор: {{ $selMap[$l->selection] ?? strtoupper($l->selection) }}
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