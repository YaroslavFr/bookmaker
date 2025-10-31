<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Статистика</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .muted { color:#6b7280; font-size:12px; }
        .grid { display:grid; grid-template-columns: 1fr; gap: 16px; }
        @media (min-width: 900px) { .grid { grid-template-columns: 1fr 1fr; } }
        .card { background:#fff; border-radius:8px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
        table { width:100%; border-collapse: collapse; }
        th, td { padding:8px; border-bottom:1px solid #eee; text-align:left; }
        .badge { display:inline-block; padding:4px 8px; border-radius:4px; }
        .badge-info { background:#eef; color:#225; }
    </style>
    </head>
<body>
    <header class="hero">
        <div class="container grid grid-cols-1 md:grid-cols-[7fr_3fr]">
            <div>
                <div class="logo">SPORT-KUCKOLD</div>
                <div class="description">Для тех кто любит смотреть спорт</div>
                @include('partials.nav')
            </div>            
            <div>
                @include('partials.lk')
            </div>
        </div>
    </header>
    <main>
        <div class="container">
            <div class="row">
                <h1 class="text-2xl font-bold mt-6 mb-6">Статистика</h1>
            </div>

            @if(!empty($error))
                <div class="card"><span class="badge badge-info">Ошибка: {{ $error }}</span></div>
            @endif

            @php($aggr = $aggregates ?? null)
            @if($aggr)
            <div class="grid">
                <div class="card">
                    <h2>Самая забивающая команда</h2>
                    <p><strong>Всего:</strong> {{ $aggr['most_scoring_overall']['team'] ?? '—' }} ({{ $aggr['most_scoring_overall']['goals'] ?? '—' }})</p>
                    <p><strong>Дома:</strong> {{ $aggr['most_scoring_home']['team'] ?? '—' }} ({{ $aggr['most_scoring_home']['goals'] ?? '—' }})</p>
                    <p><strong>В гостях:</strong> {{ $aggr['most_scoring_away']['team'] ?? '—' }} ({{ $aggr['most_scoring_away']['goals'] ?? '—' }})</p>
                </div>
                <div class="card">
                    <h2>Самая пропускающая команда</h2>
                    <p><strong>Дома:</strong> {{ $aggr['most_conceding_home']['team'] ?? '—' }} ({{ $aggr['most_conceding_home']['goals'] ?? '—' }})</p>
                    <p><strong>В гостях:</strong> {{ $aggr['most_conceding_away']['team'] ?? '—' }} ({{ $aggr['most_conceding_away']['goals'] ?? '—' }})</p>
                </div>
            </div>

            <div class="grid">
                <div class="card">
                    <h2>Топ-10 по забитым</h2>
                    <table class="responsive-table">
                        <thead><tr><th>Команда</th><th>Голы</th></tr></thead>
                        <tbody>
                        @foreach(($aggr['top_scoring'] ?? []) as $row)
                            <tr><td>{{ $row['team'] }}</td><td>{{ $row['goals'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card">
                    <h2>Топ-10 по пропущенным</h2>
                    <table class="responsive-table">
                        <thead><tr><th>Команда</th><th>Пропущено</th></tr></thead>
                        <tbody>
                        @foreach(($aggr['top_conceding'] ?? []) as $row)
                            <tr><td>{{ $row['team'] }}</td><td>{{ $row['goals'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <h2>Топ-10 по победам</h2>
                <table class="responsive-table">
                    <thead><tr><th>Команда</th><th>Победы</th></tr></thead>
                    <tbody>
                    @foreach(($aggr['top_wins'] ?? []) as $row)
                        <tr><td>{{ $row['team'] }}</td><td>{{ $row['wins'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            <div class="card">
                <h2>Все команды — сводная статистика</h2>
                @php($stats = $teamStats ?? [])
                @if(empty($stats))
                    <p class="muted">Нет данных для сводной таблицы.</p>
                @else
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>Команда</th>
                            <th>Матчи</th>
                            <th>Забитые</th>
                            <th>Пропущенные</th>
                            <th>Победы</th>
                            <th>Ничьи</th>
                            <th>Поражения</th>
                            <th>Дома (З/П)</th>
                            <th>В гостях (З/П)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stats as $team => $st)
                            <tr>
                                <td>{{ $team }}</td>
                                <td>{{ $st['matches'] }}</td>
                                <td>{{ $st['goals_for'] }}</td>
                                <td>{{ $st['goals_against'] }}</td>
                                <td>{{ $st['wins'] }}</td>
                                <td>{{ $st['draws'] }}</td>
                                <td>{{ $st['losses'] }}</td>
                                <td>{{ $st['home']['goals_for'] }} / {{ $st['home']['goals_against'] }}</td>
                                <td>{{ $st['away']['goals_for'] }} / {{ $st['away']['goals_against'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>

            <p class="muted">Источник: API-Sport.ru (EPL). Период: последние 120 дней.</p>
        </div>
    </main>
</body>
</html>