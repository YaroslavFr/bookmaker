<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Демо-ставки</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header>
        <div class="container">
            <div class="logo">SPORT-KUCKOLD</div>
            <div class="description">Платформа демо-ставок на спорт, для тех кто любит смотреть спорт</div>
        </div>
    </header>

    @if(session('status'))
        <div class="container">
            <p class="badge badge-info">{{ session('status') }}</p>
        </div>
    @endif
    <main>
        <div class="container">
            <h1 class="mb-10">Все события</h1>
        </div>
        <div class="container">
            <div class="grid">
                <div class="card all-events">
                    <div class="row row-between">
                        <h2>События АПЛ</h2>
                        <div class="row" style="gap:8px;">
                            <a href="{{ route('events.sync') }}" class="btn btn-primary">Синхронизировать результаты</a>
                            <a href="{{ route('events.debug') }}" class="btn btn-secondary">Отладка результатов</a>
                        </div>
                    </div>
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Матч</th>
                                <th>Статус</th>
                                <th>Коэфф. (Д/Н/Г)</th>
                                <th>Результат</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($events as $event)
                                @php 
                                    $status = $event->status; 
                                    $canBet = $event->starts_at && $event->starts_at->isFuture() && $event->status === 'scheduled';
                                @endphp
                                <tr>
                                    <td data-label="Матч">
                                        @if($event->home_team && $event->away_team)
                                            {{ $event->home_team }} vs {{ $event->away_team }}
                                            <div class="muted">{{ $event->starts_at ? $event->starts_at->format('Y-m-d H:i') : '' }}</div>
                                        @else
                                            {{ $event->title }}
                                        @endif
                                    </td>
                                    <td data-label="Статус">
                                        <span class="badge badge-{{ $status }}">{{ $status }}</span>
                                    </td>
                                    <td data-label="Коэфф. (Д/Н/Г)">
                                        @if($canBet && $event->home_odds)
                                            <span class="odd-btn" data-event-id="{{ $event->id }}" data-selection="home" data-home="{{ $event->home_team }}" data-away="{{ $event->away_team }}" data-odds="{{ number_format($event->home_odds, 2) }}">{{ number_format($event->home_odds, 2) }}</span>
                                            <span class="sep">/</span>
                                            <span class="odd-btn" data-event-id="{{ $event->id }}" data-selection="draw" data-home="{{ $event->home_team }}" data-away="{{ $event->away_team }}" data-odds="{{ number_format($event->draw_odds, 2) }}">{{ number_format($event->draw_odds, 2) }}</span>
                                            <span class="sep">/</span>
                                            <span class="odd-btn" data-event-id="{{ $event->id }}" data-selection="away" data-home="{{ $event->home_team }}" data-away="{{ $event->away_team }}" data-odds="{{ number_format($event->away_odds, 2) }}">{{ number_format($event->away_odds, 2) }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td data-label="Результат">{{ $event->result ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
        
                <div class="card">
                    <div id="vue-app" data-csrf="{{ csrf_token() }}" data-post-url="{{ route('bets.store') }}"></div>
                </div>
            </div>

            <div class="card mt-20">
                <h2>Последние ставки (купоны)</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Купон ID</th>
                            <th>Игрок</th>
                            <th>События (экспресс)</th>
                            <th>Ставка</th>
                            <th>Итоговый кэф</th>
                            <th>Потенц. выплата</th>
                            <th>Статус</th>
                            <th>Дата ставки</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($coupons as $coupon)
                            @php $isSingle = $coupon->bets->count() === 1; $leg = $isSingle ? $coupon->bets->first() : null; @endphp
                            <tr>
                                <td>{{ $coupon->id }}</td>
                                <td>{{ $coupon->bettor_name }}</td>
                                <td>
                                    @foreach($coupon->bets as $l)
                                        @php $showDot = $isSingle && $l->settled_at !== null && $l->is_win !== null; @endphp
                                        <div>
                                            @if($showDot)
                                                <span class="status-dot {{ $l->is_win ? 'status-dot--win' : 'status-dot--lose' }}" aria-hidden="true"></span>
                                            @endif
                                            @if($l->event->home_team && $l->event->away_team)
                                                {{ $l->event->home_team }} vs {{ $l->event->away_team }}
                                            @else
                                                {{ $l->event->title }}
                                            @endif
                                            — выбор: {{ strtoupper($l->selection) }}
                                        </div>
                                    @endforeach
                                </td>
                                <td>{{ number_format($coupon->amount_demo, 2) }}</td>
                                <td>{{ $coupon->total_odds ? number_format($coupon->total_odds, 2) : '—' }}</td>
                                <td>
                                    @php $potential = $coupon->total_odds ? ($coupon->amount_demo * $coupon->total_odds) : null; @endphp
                                    {{ $potential ? number_format($potential, 2) : '—' }}
                                </td>
                                <td>
                                    @if($isSingle)
                                        {{ $leg && $leg->is_win === null ? '—' : ($leg && $leg->is_win ? 'Выиграно' : 'Проигрышь') }}
                                    @else
                                        {{ $coupon->is_win === null ? '—' : ($coupon->is_win ? 'Выиграно' : 'Проигрышь') }}
                                    @endif
                                </td>
                                <td>{{ $coupon->created_at ? $coupon->created_at->format('Y-m-d H:i') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
    </main>
    </body>
    </html>