<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>sstats.net</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <script src="https://cdn.tailwindcss.com"></script>
    @endif
    <style>
        .muted { color:#777; font-size:12px; }
        .grid { display:grid; grid-template-columns: 1fr; gap: 16px; }
        .card { background:#fff; border-radius:8px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
        table { width:100%; border-collapse: collapse; }
        th, td { padding:8px; border-bottom:1px solid #eee; text-align:left; }
        .badge { display:inline-block; padding:4px 8px; border-radius:4px; }
        .badge-info { background:#eef; color:#225; }
        .row { display:flex; gap:8px; align-items:center; }
    </style>
    </head>
<body>
    @include('partials.header')
    <main>
        <div class="container">
            @if(!empty($error))
                <p class="badge badge-info">Ошибка: {{ $error }}</p>
            @endif

            <div class="card">
                <div class="row row-between" style="justify-content: space-between;">
                    <h2>Английская Премьер-лига — предстоящие матчи</h2>
                </div>
                <div class="muted">Ключ: {{ config('services.sstats.key') ? 'задан' : 'не задан' }} · База: {{ config('services.sstats.base_url') }} · Лига: {{ $leagueName ?? 'English Premier League' }}</div>
            </div>

            {{-- Лиги не выводим: страница фиксирована на АПЛ --}}

            @if($games)
                <div class="card">
                    <h2>Английская Премьер-лига — предстоящие матчи ({{ $year }})</h2>
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Матч</th>
                                <th>Статус</th>
                                <th>Коэфф. (Д/Н/Г)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($games as $g)
                                @php
                                    $homeOdd = null; $drawOdd = null; $awayOdd = null;
                                    $oddsList = $g['odds'] ?? [];
                                    if (is_array($oddsList)) {
                                        // Shape A: direct markets under $g['odds']
                                        foreach ($oddsList as $mk) {
                                            foreach (($mk['odds'] ?? []) as $o) {
                                                $sel = strtolower((string)($o['name'] ?? ($o['selectionName'] ?? '')));
                                                $val = $o['value'] ?? ($o['odd'] ?? ($o['rate'] ?? null));
                                                if ($val !== null) {
                                                    if ($homeOdd === null && (str_contains($sel, 'home') || $sel === '1')) { $homeOdd = $val; }
                                                    if ($drawOdd === null && (str_contains($sel, 'draw') || $sel === 'x')) { $drawOdd = $val; }
                                                    if ($awayOdd === null && (str_contains($sel, 'away') || $sel === '2')) { $awayOdd = $val; }
                                                }
                                            }
                                            if ($homeOdd !== null && $drawOdd !== null && $awayOdd !== null) { break; }
                                        }
                                        // Shape B: bookmaker -> markets -> odds
                                        if ($homeOdd === null || $drawOdd === null || $awayOdd === null) {
                                            foreach ($oddsList as $book) {
                                                foreach (($book['odds'] ?? []) as $market) {
                                                    $mName = strtolower((string)($market['marketName'] ?? ''));
                                                    if (str_contains($mName, '1x2') || str_contains($mName, 'full time') || str_contains($mName, 'match odds')) {
                                                        foreach (($market['odds'] ?? []) as $o) {
                                                            $sel = strtolower((string)($o['name'] ?? ($o['selectionName'] ?? '')));
                                                            $val = $o['value'] ?? ($o['odd'] ?? ($o['rate'] ?? null));
                                                            if ($val !== null) {
                                                                if ($homeOdd === null && (str_contains($sel, 'home') || $sel === '1')) { $homeOdd = $val; }
                                                                if ($drawOdd === null && (str_contains($sel, 'draw') || $sel === 'x')) { $drawOdd = $val; }
                                                                if ($awayOdd === null && (str_contains($sel, 'away') || $sel === '2')) { $awayOdd = $val; }
                                                            }
                                                        }
                                                        break;
                                                    }
                                                }
                                                if ($homeOdd !== null && $drawOdd !== null && $awayOdd !== null) { break; }
                                            }
                                        }
                                    }
                                @endphp
                                <tr>
                                    <td data-label="Дата">{{ $g['date'] ?? '' }}</td>
                                    <td data-label="Матч">{{ data_get($g,'homeTeam.name') }} vs {{ data_get($g,'awayTeam.name') }}</td>
                                    <td data-label="Статус">{{ $g['statusName'] ?? ($g['status'] ?? '') }}</td>
                                    <td data-label="Коэфф. (Д/Н/Г)">
                                        @if($homeOdd && $drawOdd && $awayOdd)
                                            {{ number_format((float)$homeOdd, 2) }} / {{ number_format((float)$drawOdd, 2) }} / {{ number_format((float)$awayOdd, 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($game)
                <div class="card">
                    <h2>Матч #{{ $game['game']['id'] ?? $game['id'] ?? '' }}</h2>
                    @php $gi = $game['game'] ?? $game; @endphp
                    <div class="row" style="gap:24px; align-items:flex-start;">
                        <div style="flex:1;">
                            <h3>Основное</h3>
                            <p><strong>Дата:</strong> {{ $gi['date'] ?? '' }}</p>
                            <p><strong>Матч:</strong> {{ data_get($gi,'homeTeam.name') }} vs {{ data_get($gi,'awayTeam.name') }}</p>
                            <p><strong>Лига:</strong> {{ data_get($gi,'season.league.name') }} ({{ data_get($gi,'season.year') }})</p>
                            <p><strong>Счёт:</strong> {{ $gi['homeResult'] ?? '' }} : {{ $gi['awayResult'] ?? '' }}</p>
                            <p><strong>Статус:</strong> {{ $gi['statusName'] ?? ($gi['status'] ?? '') }}</p>
                        </div>
                        <div style="flex:1;">
                            <h3>Коэффициенты (prematch)</h3>
                            @if($odds)
                                @foreach($odds as $book)
                                    <div class="card" style="margin-bottom:8px;">
                                        <div><strong>{{ $book['bookmakerName'] ?? ('Bookmaker '.$book['bookmakerId'] ?? '') }}</strong></div>
                                        @foreach(($book['odds'] ?? []) as $market)
                                            <div class="muted">{{ $market['marketName'] ?? ('Market '.$market['marketId'] ?? '') }}</div>
                                            <div class="row" style="flex-wrap:wrap;">
                                                @foreach(($market['odds'] ?? []) as $o)
                                                    <span class="badge badge-info">{{ $o['name'] }}: {{ $o['value'] }}</span>
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            @else
                                <div class="muted">Нет данных по коэффициентам</div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h3>Glicko 2</h3>
                    @if($glicko)
                        <pre style="white-space:pre-wrap;">{{ json_encode($glicko, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
                    @else
                        <div class="muted">Нет данных Glicko</div>
                    @endif
                </div>

                <div class="card">
                    <h3>Анализ прибыльности</h3>
                    @if($profits)
                        <pre style="white-space:pre-wrap;">{{ json_encode($profits, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
                    @else
                        <div class="muted">Нет данных по прибыльности</div>
                    @endif
                </div>
            @endif
        </div>
    </main>
</body>
</html>