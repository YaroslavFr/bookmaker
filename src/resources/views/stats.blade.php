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
        .badge { display:inline-block; padding:4px 8px; border-radius:4px; }
        .badge-info { background:#eef; color:#225; }

        /* Новая адаптивная разметка на div вместо таблиц */
        .stat-list { display:flex; flex-direction:column; width:100%; }
        .stat-header { display:none; font-weight:600; padding:8px 0; border-bottom:1px solid #eee; }
        .stat-row { display:flex; justify-content:space-between; gap:12px; padding:8px 0; border-bottom:1px solid #eee; }
        .stat-cell { flex:1; }
        .stat-cell.value { flex:0 0 auto; min-width:64px; text-align:right; }
        @media (min-width: 900px) {
            .stat-header { display:flex; justify-content:space-between; gap:12px; }
        }

        /* Карточки для сводной статистики по командам */
        .rcards { display:grid; grid-template-columns:1fr; gap:12px; }
        @media (min-width: 900px) { .rcards { grid-template-columns: 1fr 1fr; } }
        .team-stat { border:1px solid #eee; border-radius:8px; padding:12px; }
        .team-name { font-weight:600; margin-bottom:8px; }
        .stat-tags { display:flex; flex-wrap:wrap; gap:8px; }
        .stat-tag { font-size:12px; background:#f9fafb; border:1px solid #eee; border-radius:6px; padding:6px 8px; }
        .stat-tag .label { color:#6b7280; margin-right:4px; }
        .stat-tag .value { font-weight:600; }

        /* Аккордион стили */
        .accordion { margin-bottom: 16px; }
        .accordion-header { 
            background: #f8fafc; 
            border: 1px solid #e2e8f0; 
            border-radius: 8px; 
            padding: 16px; 
            cursor: pointer; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            transition: all 0.2s ease;
        }
        .accordion-header:hover { 
            background: #f1f5f9; 
        }
        .accordion-header.active { 
            border-bottom-left-radius: 0; 
            border-bottom-right-radius: 0; 
            border-bottom: none;
        }
        .accordion-title { 
            font-size: 18px; 
            font-weight: 600; 
            color: #1e293b; 
        }
        .accordion-icon { 
            font-size: 14px; 
            color: #64748b; 
            transition: transform 0.2s ease; 
        }
        .accordion-icon.rotated { 
            transform: rotate(180deg); 
        }
        .accordion-content { 
            border: 1px solid #e2e8f0; 
            border-top: none; 
            border-bottom-left-radius: 8px; 
            border-bottom-right-radius: 8px; 
            padding: 0; 
            transition: max-height 0.3s ease, padding 0.3s ease; 
        }
        .accordion-header.active + .accordion-content{
            visibility: visible;
            padding: 16px;
        }
        .accordion-content { 
            visibility: hidden;
            padding: 0; 
        }
    </style>
    </head>
<body>
    @include('partials.header')
    <main>
        <div class="container">
            <div class="row">
                <h1 class="text-2xl font-bold mt-6 mb-6">Статистика</h1>
            </div>

            @if(!empty($error))
                <div class="card"><span class="badge badge-info">Ошибка: {{ $error }}</span></div>
            @endif

            <!-- Аккордион для Английской Премьер-лиги -->
            <div class="accordion">
                <div class="accordion-header">
                    <div class="accordion-title">Английская Премьер-лига</div>
                    <div class="accordion-icon">▼</div>
                </div>
                <div class="accordion-content">
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
                            <div class="stat-list">
                                <div class="stat-header">
                                    <div class="stat-cell">Команда</div>
                                    <div class="stat-cell value">Голы</div>
                                </div>
                                @foreach(($aggr['top_scoring'] ?? []) as $row)
                                    <div class="stat-row">
                                        <div class="stat-cell">{{ $row['team'] }}</div>
                                        <div class="stat-cell value">{{ $row['goals'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="card">
                            <h2>Топ-10 по пропущенным</h2>
                            <div class="stat-list">
                                <div class="stat-header">
                                    <div class="stat-cell">Команда</div>
                                    <div class="stat-cell value">Пропущено</div>
                                </div>
                                @foreach(($aggr['top_conceding'] ?? []) as $row)
                                    <div class="stat-row">
                                        <div class="stat-cell">{{ $row['team'] }}</div>
                                        <div class="stat-cell value">{{ $row['goals'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h2>Топ-10 по победам</h2>
                        <div class="stat-list">
                            <div class="stat-header">
                                <div class="stat-cell">Команда</div>
                                <div class="stat-cell value">Победы</div>
                            </div>
                            @foreach(($aggr['top_wins'] ?? []) as $row)
                                <div class="stat-row">
                                    <div class="stat-cell">{{ $row['team'] }}</div>
                                    <div class="stat-cell value">{{ $row['wins'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    <div class="card">
                        <h2>Все команды — сводная статистика</h2>
                        @php($stats = $teamStats ?? [])
                        @if(empty($stats))
                            <p class="muted">Нет данных для сводной таблицы.</p>
                        @else
                        <div class="rcards">
                            @foreach($stats as $team => $st)
                                <div class="team-stat">
                                    <div class="team-name">{{ $team }}</div>
                                    <div class="stat-tags">
                                        <div class="stat-tag"><span class="label">Матчи</span><span class="value">{{ $st['matches'] }}</span></div>
                                        <div class="stat-tag"><span class="label">Забитые</span><span class="value">{{ $st['goals_for'] }}</span></div>
                                        <div class="stat-tag"><span class="label">Пропущенные</span><span class="value">{{ $st['goals_against'] }}</span></div>
                                        <div class="stat-tag"><span class="label">Победы</span><span class="value">{{ $st['wins'] }}</span></div>
                                        <div class="stat-tag"><span class="label">Ничьи</span><span class="value">{{ $st['draws'] }}</span></div>
                                        <div class="stat-tag"><span class="label">Поражения</span><span class="value">{{ $st['losses'] }}</span></div>
                                        <div class="stat-tag"><span class="label">Дома</span><span class="value">{{ $st['home']['goals_for'] }} / {{ $st['home']['goals_against'] }}</span></div>
                                        <div class="stat-tag"><span class="label">В гостях</span><span class="value">{{ $st['away']['goals_for'] }} / {{ $st['away']['goals_against'] }}</span></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @endif
                    </div>

                    <p class="muted">Источник: API-Sport.ru (EPL). Период: последние 120 дней.</p>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Инициализация аккордиона
            const accordionHeaders = document.querySelectorAll('.accordion-header');
            
            accordionHeaders.forEach(header => {
                header.addEventListener('click', function() {
                    const icon = this.querySelector('.accordion-icon');
                    
                    // Переключаем активное состояние
                    this.classList.toggle('active');
                    icon.classList.toggle('rotated');
                });
            });
        });
    </script>
</body>
</html>