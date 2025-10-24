<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Демо-ставки</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
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
            <div class="card">
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
                <h2>Сделать демо-ставку</h2>
                <form method="POST" action="{{ route('bets.store') }}" id="bet-form">
                    @csrf
                    <div class="row row-start mb-20">
                        <label for="bettor_name" class="form-label">Имя игрока</label>
                        <input type="text" id="bettor_name" placeholder="Например: Иван" />
                    </div>
                    <div class="row row-start mb-20">
                        <label for="amount_demo" class="form-label">Сумма (демо)</label>
                        <input type="number" id="amount_demo" placeholder="Например: 100" />
                    </div>
                    <div class="row row-start mb-20">
                        <div class="form-label">Купон</div>
                        <ul id="slip-list"></ul>
                        <div id="slip-empty" class="muted">Добавьте исходы, кликая по коэффициентам в таблице</div>
                    </div>
                    <div class="row">
                        <button class="btn" type="button" id="clear-slip">Очистить купон</button>
                        <button class="btn btn-primary" type="button" id="submit-slip">Поставить все</button>
                    </div>
                </form>
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
                        li.className = 'slip-item';
                        const title = (item.home && item.away) ? `${item.home} vs ${item.away}` : `Event #${item.eventId}`;
                        li.innerHTML = `
                            <div class="row row-between">
                                <div>
                                    <strong>${title}</strong>
                                    <div class="muted">Исход: ${selectionLabel(item.selection, item.home, item.away)} • кэф ${item.odds}</div>
                                </div>
                                <button class="btn" data-remove="${item.eventId}">Удалить</button>
                            </div>
                        `;
                        slipList.appendChild(li);
                    });
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