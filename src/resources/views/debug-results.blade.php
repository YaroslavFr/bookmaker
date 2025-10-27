<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Отладка результатов АПЛ</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
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
    <header>
        <div class="container">
            <div class="logo">SPORT-KUCKOLD</div>
            <div class="description">Отладка: что приходит из API и как сопоставляется</div>
        </div>
    </header>
    <main>
        <div class="container">
            <div class="row row-between">
                <h1>Отладка результатов АПЛ</h1>
                <a href="{{ route('home') }}" class="btn btn-primary">На главную</a>
            </div>
            @if($error)
                <p class="badge badge-info">{{ $error }}</p>
            @endif

            <div class="card mt-20">
                <h2>АПЛ — турнир</h2>
                @if(!empty($apiSportTournamentRaw))
                    <p class="muted">Турнир: {{ data_get($apiSportTournamentRaw, 'name') }} (ID: {{ data_get($apiSportTournamentRaw, 'id') }})</p>
                    <p class="muted">Категория: {{ data_get($apiSportTournamentRaw, 'category.name') }} (ID: {{ data_get($apiSportTournamentRaw, 'category.id') }})</p>
                @endif
            </div>

            <div class="card mt-20">
                <h2>Предстоящие матчи (неделя)</h2>
                @php($fx = $apiSportFixturesWeek ?? [])
                @if(empty($fx))
                    <p class="muted">Нет предстоящих матчей на ближайшую неделю.</p>
                @else
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Матч</th>
                                <th>Дата/время</th>
                                <th>Коэфф. (Д/Н/Г)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($fx as $m)
                                <tr>
                                    <td data-label="Матч">
                                        @if(!empty($m['home_team']) && !empty($m['away_team']))
                                            {{ $m['home_team'] }} vs {{ $m['away_team'] }}
                                        @else
                                            {{ $m['title'] ?? '—' }}
                                        @endif
                                    </td>
                                    <td data-label="Дата/время">
                                        @php($ct = $m['commence_time'] ?? null)
                                        {{ $ct ? \Illuminate\Support\Carbon::parse($ct)->format('d.m.Y H:i') : '—' }}
                                    </td>
                                    <td data-label="Коэфф. (Д/Н/Г)">
                                        @php($h = $m['home_odds'] ?? null)
                                        @php($d = $m['draw_odds'] ?? null)
                                        @php($a = $m['away_odds'] ?? null)
                                        @php($eid = $m['event_id'] ?? null)
                                        @if($h && $d && $a)
                                            @if($eid)
                                                <span class="odd-btn" data-event-id="{{ $eid }}" data-selection="home" data-home="{{ $m['home_team'] ?? '' }}" data-away="{{ $m['away_team'] ?? '' }}" data-odds="{{ number_format($h, 2) }}">{{ number_format($h, 2) }}</span>
                                                <span class="sep">/</span>
                                                <span class="odd-btn" data-event-id="{{ $eid }}" data-selection="draw" data-home="{{ $m['home_team'] ?? '' }}" data-away="{{ $m['away_team'] ?? '' }}" data-odds="{{ number_format($d, 2) }}">{{ number_format($d, 2) }}</span>
                                                <span class="sep">/</span>
                                                <span class="odd-btn" data-event-id="{{ $eid }}" data-selection="away" data-home="{{ $m['home_team'] ?? '' }}" data-away="{{ $m['away_team'] ?? '' }}" data-odds="{{ number_format($a, 2) }}">{{ number_format($a, 2) }}</span>
                                            @else
                                                {{ number_format($h, 2) }} / {{ number_format($d, 2) }} / {{ number_format($a, 2) }}
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="card mt-20">
                <div id="vue-app" data-csrf="{{ csrf_token() }}" data-post-url="{{ route('bets.store') }}"></div>
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
                                            — выбор: {{ strtoupper($l->selection) }}
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

            <div class="card mt-20 collapsible is-collapsed" id="results-week">
                <div class="card-header row-between">
                    <h2>Результаты за прошлую неделю</h2>
                    <button type="button" class="collapse-toggle btn btn-secondary" aria-expanded="true" aria-controls="results-week-body">
                        <span class="arrow">▾</span>
                    </button>
                </div>
                @php($rw = $apiSportResultsWeek ?? [])
                @if(empty($rw))
                    <p class="muted">Нет завершённых матчей за прошлую неделю.</p>
                @else
                    <div class="collapsible-body" id="results-week-body">
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Матч</th>
                                <th>Дата/время</th>
                                <th>Счёт (Д-Г)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rw as $m)
                                <tr>
                                    <td data-label="Матч">
                                        @if(!empty($m['home_team']) && !empty($m['away_team']))
                                            {{ $m['home_team'] }} vs {{ $m['away_team'] }}
                                        @else
                                            {{ $m['title'] ?? '—' }}
                                        @endif
                                    </td>
                                    <td data-label="Дата/время">
                                        @php($ft = $m['finished_at'] ?? null)
                                        {{ $ft ? \Illuminate\Support\Carbon::parse($ft)->format('d.m.Y H:i') : '—' }}
                                    </td>
                                    <td data-label="Счёт (Д-Г)">
                                        @php($hs = $m['home_score'] ?? null)
                                        @php($as = $m['away_score'] ?? null)
                                        @if($hs !== null && $as !== null)
                                            {{ is_numeric($hs) && is_numeric($as) ? ($hs.' — '.$as) : '—' }}
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
            </div>

            <div class="card mt-20 collapsible is-collapsed" id="results-all">
                <div class="card-header row-between">
                    <h2>Все предыдущие результаты (сезон)</h2>
                    <button type="button" class="collapse-toggle btn btn-secondary" aria-expanded="true" aria-controls="results-all-body">
                        <span class="arrow">▾</span>
                        
                    </button>
                </div>
                @php($ra = $apiSportResultsAll ?? [])
                @if(empty($ra))
                    <p class="muted">Нет завершённых матчей по фильтру сезона.</p>
                @else
                    <div class="collapsible-body" id="results-all-body">
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Матч</th>
                                <th>Дата/время</th>
                                <th>Счёт (Д-Г)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ra as $m)
                                <tr>
                                    <td data-label="Матч">
                                        @if(!empty($m['home_team']) && !empty($m['away_team']))
                                            {{ $m['home_team'] }} vs {{ $m['away_team'] }}
                                        @else
                                            {{ $m['title'] ?? '—' }}
                                        @endif
                                    </td>
                                    <td data-label="Дата/время">
                                        @php($ft = $m['finished_at'] ?? null)
                                        {{ $ft ? \Illuminate\Support\Carbon::parse($ft)->format('d.m.Y H:i') : '—' }}
                                    </td>
                                    <td data-label="Счёт (Д-Г)">
                                        @php($hs = $m['home_score'] ?? null)
                                        @php($as = $m['away_score'] ?? null)
                                        @if($hs !== null && $as !== null)
                                            {{ is_numeric($hs) && is_numeric($as) ? ($hs.' — '.$as) : '—' }}
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
            </div>

            <div class="card mt-20 collapsible is-collapsed">
                <h2>Аналитика сезона</h2>
                
                @php($ag = $apiSportAggregates ?? null)
                @if(!$ag)
                    <p class="muted">Недостаточно данных для аналитики.</p>
                @else
                    <div class="wrap">
                        <p>
                            Самая забивающая команда: 
                            <strong>{{ data_get($ag, 'most_scoring_overall.team') ?? '—' }}</strong>
                            (голы: {{ data_get($ag, 'most_scoring_overall.goals') ?? '—' }})
                        </p>
                        <p>
                            Самая забивающая команда в домашних матчах:
                            <strong>{{ data_get($ag, 'most_scoring_home.team') ?? '—' }}</strong>
                            (голы: {{ data_get($ag, 'most_scoring_home.goals') ?? '—' }})
                        </p>
                        <p>
                            Самая забивающая команда в гостевых матчах:
                            <strong>{{ data_get($ag, 'most_scoring_away.team') ?? '—' }}</strong>
                            (голы: {{ data_get($ag, 'most_scoring_away.goals') ?? '—' }})
                        </p>
                        <p>
                            Самая пропускаемая команда в домашних:
                            <strong>{{ data_get($ag, 'most_conceding_home.team') ?? '—' }}</strong>
                            (голы: {{ data_get($ag, 'most_conceding_home.goals') ?? '—' }})
                        </p>
                        <p>
                            Самая пропускаемая команда в гостевых:
                            <strong>{{ data_get($ag, 'most_conceding_away.team') ?? '—' }}</strong>
                            (голы: {{ data_get($ag, 'most_conceding_away.goals') ?? '—' }})
                        </p>
                    </div>

                    <div class="mt-10 " id="team-stats">
                        <div class="row-between">
                            <h3>Победы и поражения по командам</h3>
                            <button type="button" class="collapse-toggle btn btn-secondary" aria-expanded="true" aria-controls="team-stats-body">
                                <span class="arrow">▾</span>
                                
                            </button>
                        </div>
                    @php($ts = data_get($ag, 'team_stats', []))
                    @if(empty($ts))
                        <p class="muted">Нет статистики по командам.</p>
                    @else
                        <div class="collapsible-body" id="team-stats-body">
                        <table class="responsive-table">
                            <thead>
                                <tr>
                                    <th>Команда</th>
                                    <th>Дом (W/D/L)</th>
                                    <th>Дом (ГД-ГП)</th>
                                    <th>Гость (W/D/L)</th>
                                    <th>Гость (ГД-ГП)</th>
                                    <th>Итого (W/D/L)</th>
                                    <th>Матчи</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($ts as $team => $st)
                                    <tr>
                                        <td data-label="Команда">{{ $team }}</td>
                                        <td data-label="Дом (W/D/L)">
                                            {{ ($st['home']['wins'] ?? 0) }} / {{ ($st['home']['draws'] ?? 0) }} / {{ ($st['home']['losses'] ?? 0) }}
                                        </td>
                                        <td data-label="Дом (ГД-ГП)">
                                            {{ ($st['home']['goals_for'] ?? 0) }} — {{ ($st['home']['goals_against'] ?? 0) }}
                                        </td>
                                        <td data-label="Гость (W/D/L)">
                                            {{ ($st['away']['wins'] ?? 0) }} / {{ ($st['away']['draws'] ?? 0) }} / {{ ($st['away']['losses'] ?? 0) }}
                                        </td>
                                        <td data-label="Гость (ГД-ГП)">
                                            {{ ($st['away']['goals_for'] ?? 0) }} — {{ ($st['away']['goals_against'] ?? 0) }}
                                        </td>
                                        <td data-label="Итого (W/D/L)">
                                            {{ ($st['wins'] ?? 0) }} / {{ ($st['draws'] ?? 0) }} / {{ ($st['losses'] ?? 0) }}
                                        </td>
                                        <td data-label="Матчи">{{ ($st['matches'] ?? 0) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                    </div>
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