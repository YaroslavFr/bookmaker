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
        .odd-btn { display:inline-block; padding: 2px 6px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; }
        .odd-btn:hover { background: #f3f4f6; }
        .sep { color:#999; margin: 0 4px; }
        @media (max-width: 640px) {
            .grid { grid-template-columns: 1fr; }
            .responsive-table thead { display: none; }
            .responsive-table tr { display: block; border: 1px solid #eee; border-radius: 8px; padding: 10px; margin-bottom: 12px; }
            .responsive-table td { display: flex; justify-content: space-between; align-items: flex-start; border: none; padding: 6px 0; }
            .responsive-table td::before { content: attr(data-label); font-size: 12px; color: #666; margin-right: 8px; }
            .row { flex-direction: column; align-items: flex-start; }
            .odd-btn { padding: 6px 8px; }
        }
    </style>
</head>
<body>
    <div class="logo">SPORT-KUCKOLD</div>

    <div class="description">Платформа демо-ставок на спорт</div>

    @if(session('status'))
        <p class="badge" style="background:#dbeafe;color:#1e40af">{{ session('status') }}</p>
    @endif

    <h1>Все события</h1>
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
            <h2>Сделать демо-ставку</h2>
            <form method="POST" action="{{ route('bets.store') }}" id="bet-form">
                @csrf
                <div class="row">
                    <label for="bettor_name" style="width:160px">Имя игрока</label>
                    <input type="text" id="bettor_name" name="bettor_name" required />
                </div>
                <div class="row">
                    <label for="amount_demo" style="width:160px">Сумма (демо)</label>
                    <input type="number" step="0.01" id="amount_demo" name="amount_demo" required />
                </div>
                <div class="row" style="align-items:flex-start">
                    <div style="width:160px">Купон</div>
                    <div style="flex:1">
                        <ul id="slip-list" style="list-style:none; padding:0; margin:0"></ul>
                        <div id="slip-empty" class="muted">Добавьте исходы, кликая по коэффициентам в таблице</div>
                    </div>
                </div>
                <div class="row">
                    <button class="btn" type="button" id="clear-slip">Очистить купон</button>
                    <button class="btn btn-primary" type="button" id="submit-slip">Поставить все</button>
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('bet-form');
            if (!form) return;
            const slipList = document.getElementById('slip-list');
            const slipEmpty = document.getElementById('slip-empty');
            const submitSlipBtn = document.getElementById('submit-slip');
            const clearSlipBtn = document.getElementById('clear-slip');
            const bettorName = document.getElementById('bettor_name');
            const amountDemo = document.getElementById('amount_demo');
            const csrfToken = form.querySelector('input[name="_token"]').value;
    
            // Купон: ключ — eventId, значение — { eventId, home, away, selection, odds }
            const slip = new Map();
    
            function selectionLabel(sel, home, away) {
                if (sel === 'home') return home || 'home';
                if (sel === 'away') return away || 'away';
                return 'draw';
            }
    
            function renderSlip() {
                slipList.innerHTML = '';
                const items = Array.from(slip.values());
                if (items.length === 0) {
                    slipEmpty.style.display = 'block';
                    submitSlipBtn.disabled = true;
                    clearSlipBtn.disabled = true;
                    return;
                }
                slipEmpty.style.display = 'none';
                submitSlipBtn.disabled = false;
                clearSlipBtn.disabled = false;
                items.forEach(item => {
                    const li = document.createElement('li');
                    li.style.padding = '6px 0';
                    li.style.borderBottom = '1px solid #eee';
                    const title = (item.home && item.away) ? `${item.home} vs ${item.away}` : `Event #${item.eventId}`;
                    li.innerHTML = `
                        <div class="row" style="justify-content:space-between">
                            <div>
                                <strong>${title}</strong>
                                <div class="muted">Исход: ${selectionLabel(item.selection, item.home, item.away)} • кэф ${item.odds}</div>
                            </div>
                            <button class="btn" data-remove="${item.eventId}">Удалить</button>
                        </div>
                    `;
                    slipList.appendChild(li);
                });
                // remove handlers
                slipList.querySelectorAll('[data-remove]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const id = btn.getAttribute('data-remove');
                        slip.delete(id);
                        renderSlip();
                    });
                });
            }
    
            clearSlipBtn.addEventListener('click', () => {
                slip.clear();
                renderSlip();
            });
    
            document.querySelectorAll('.odd-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const eventId = btn.getAttribute('data-event-id');
                    const sel = btn.getAttribute('data-selection');
                    const home = btn.getAttribute('data-home');
                    const away = btn.getAttribute('data-away');
                    const odds = btn.getAttribute('data-odds');
                    slip.set(eventId, { eventId, home, away, selection: sel, odds });
                    renderSlip();
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });
    
            submitSlipBtn.addEventListener('click', async () => {
                const items = Array.from(slip.values());
                if (items.length === 0) return;
                if (!bettorName.value || !amountDemo.value) {
                    alert('Заполните имя игрока и сумму.');
                    return;
                }
                submitSlipBtn.disabled = true;
                submitSlipBtn.textContent = 'Отправка...';
                try {
                    for (const item of items) {
                        const body = new URLSearchParams();
                        body.append('bettor_name', bettorName.value);
                        body.append('amount_demo', amountDemo.value);
                        body.append('event_id', item.eventId);
                        body.append('selection', item.selection);
                        body.append('_token', csrfToken);
                        const res = await fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body,
                        });
                        if (!res.ok) {
                            const text = await res.text();
                            throw new Error('Ошибка ставки: ' + text);
                        }
                    }
                    slip.clear();
                    renderSlip();
                    location.reload();
                } catch (e) {
                    alert(e.message);
                } finally {
                    submitSlipBtn.disabled = false;
                    submitSlipBtn.textContent = 'Поставить все';
                }
            });
    
            // Инициализация
            renderSlip();
        });
    </script>
</body>
</html>