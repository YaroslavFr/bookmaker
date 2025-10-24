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
            <h1>Все события</h1>
        </div>
        <div class="container">
            <div class="grid">
                <div class="card all-events">
                    <div class="row row-between">
                        <h2>События АПЛ</h2>
                        <a href="{{ route('events.sync') }}" class="btn btn-primary">Синхронизировать результаты</a>
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
                                @php $status = $event->status; @endphp
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
                                        @if($event->home_odds)
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
                <h2>Последние ставки</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Игрок</th>
                            <th>Событие</th>
                            <th>Ставка</th>
                            <th>Выигрыш</th>
                            <th>Выплата</th>
                            <th>Дата расчёта</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bets as $bet)
                            <tr>
                                <td>{{ $bet->bettor_name }}</td>
                                <td>
                                    @if($bet->event->home_team && $bet->event->away_team)
                                        {{ $bet->event->home_team }} vs {{ $bet->event->away_team }}
                                    @else
                                        {{ $bet->event->title }}
                                    @endif
                                </td>
                                <td>{{ number_format($bet->amount_demo, 2) }}</td>
                                <td>{{ $bet->is_win === null ? '—' : ($bet->is_win ? 'Win' : 'Lose') }}</td>
                                <td>{{ $bet->payout_demo ? number_format($bet->payout_demo, 2) : '—' }}</td>
                                <td>{{ $bet->settled_at ? $bet->settled_at->format('Y-m-d H:i') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
    </main>
    </body>
    </html>