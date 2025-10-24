<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Демо-ставки</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 20px; }
        .grid { display: grid; grid-template-columns: 70% 30%; gap: 20px; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 16px; }
        h2 { margin-top: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #eee; padding: 8px; text-align: left; }
        .status { font-size: 12px; color: #666; }
        .row { display: flex; gap: 8px; align-items: center; }
        .btn { padding: 8px 12px; border: 1px solid #444; background: #fff; border-radius: 6px; cursor: pointer; }
        .btn-primary { background: #2563eb; color: #fff; border-color: #2563eb; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
        .badge-scheduled { background: #e0f2fe; color: #0369a1; }
        .badge-live { background: #fef3c7; color: #92400e; }
        .badge-finished { background: #dcfce7; color: #166534; }
        .muted { color:#666; font-size:12px }
        /* Responsive table */
        .responsive-table { width: 100%; }
        @media (max-width: 640px) {
            .grid { grid-template-columns: 1fr; }
            .responsive-table thead { display: none; }
            .responsive-table tr { display: block; border: 1px solid #eee; border-radius: 8px; padding: 10px; margin-bottom: 12px; }
            .responsive-table td { display: flex; justify-content: space-between; align-items: flex-start; border: none; padding: 6px 0; }
            .responsive-table td::before { content: attr(data-label); font-size: 12px; color: #666; margin-right: 8px; }
            .row { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <h1>Платформа демо-ставок на спорт</h1>

    @if(session('status'))
        <p class="badge" style="background:#dbeafe;color:#1e40af">{{ session('status') }}</p>
    @endif

    <div class="grid">
        <div class="card">
            <h2>События АПЛ</h2>
            <table class="responsive-table">
                <thead>
                    <tr>
                        <th>Матч</th>
                        <th>Статус</th>
                        <th>Коэфф. (Д/Н/Г)</th>
                        <th>Результат</th>
                        <th>Действия</th>
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
                                    {{ number_format($event->home_odds, 2) }}/{{ number_format($event->draw_odds, 2) }}/{{ number_format($event->away_odds, 2) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td data-label="Результат">{{ $event->result ?? '—' }}</td>
                            <td data-label="Действия">
                                @if($event->status !== 'finished')
                                    <form method="POST" action="{{ route('events.settle', $event) }}" class="row">
                                        @csrf
                                        <select name="result">
                                            <option value="home">{{ $event->home_team ?? 'home' }}</option>
                                            <option value="draw">draw</option>
                                            <option value="away">{{ $event->away_team ?? 'away' }}</option>
                                        </select>
                                        <button class="btn btn-primary" type="submit">Рассчитать</button>
                                    </form>
                                @else
                                    <span class="status">Рассчитано</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Сделать демо-ставку</h2>
            <form method="POST" action="{{ route('bets.store') }}">
                @csrf
                <div class="row">
                    <label for="event_id" style="width:160px">Событие</label>
                    <select name="event_id" id="event_id" required>
                        @foreach($events as $event)
                            <option value="{{ $event->id }}">
                                @if($event->home_team && $event->away_team)
                                    {{ $event->home_team }} vs {{ $event->away_team }}
                                @else
                                    {{ $event->title }}
                                @endif
                                ({{ $event->status }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="row">
                    <label for="bettor_name" style="width:160px">Имя игрока</label>
                    <input type="text" id="bettor_name" name="bettor_name" required />
                </div>
                <div class="row">
                    <label for="amount_demo" style="width:160px">Сумма (демо)</label>
                    <input type="number" step="0.01" id="amount_demo" name="amount_demo" required />
                </div>
                <div class="row">
                    <label style="width:160px">Исход</label>
                    <label><input type="radio" name="selection" value="home" checked />
                        {{ $event->home_team ?? 'home' }}</label>
                    <label><input type="radio" name="selection" value="draw" /> draw</label>
                    <label><input type="radio" name="selection" value="away" />
                        {{ $event->away_team ?? 'away' }}</label>
                </div>
                <div class="row">
                    <button class="btn btn-primary" type="submit">Поставить</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:20px">
        <h2>Последние ставки</h2>
        <table>
            <thead>
                <tr>
                    <th>Игрок</th>
                    <th>Событие</th>
                    <th>Ставка</th>
                    <th>Исход</th>
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
                        <td>{{ $bet->selection }}</td>
                        <td>{{ $bet->is_win === null ? '—' : ($bet->is_win ? 'Win' : 'Lose') }}</td>
                        <td>{{ $bet->payout_demo ? number_format($bet->payout_demo, 2) : '—' }}</td>
                        <td>{{ $bet->settled_at ? $bet->settled_at->format('Y-m-d H:i') : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>