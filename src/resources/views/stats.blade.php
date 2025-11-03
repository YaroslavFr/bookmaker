<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Статистика</title>
    <link rel="stylesheet" href="{{ asset('css/bets.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
<body>
    @include('partials.header')
    <main>
        <div class="container">
            <div class="row">
                <h1 class="text-2xl font-bold mt-6 mb-6">Статистика</h1>
            </div>

            @if(!empty($error))
                <div class="bg-white rounded-lg p-4 shadow"><span class="inline-block px-2 py-1 rounded bg-blue-100 text-blue-900">Ошибка: {{ $error }}</span></div>
            @endif

            @php($leagues = $leagues ?? [])
            @foreach($leagues as $lg)
            <div class="accordion mb-4">
                <div class="accordion-header border-2 border-gray-100 rounded-lg p-4 cursor-pointer flex justify-between items-center transition hover:bg-slate-100">
                    <div class="accordion-title text-lg font-semibold text-slate-800">{{ $lg['name'] }}</div>
                    <div class="accordion-icon text-sm text-slate-500 transition-transform">▼</div>
                </div>
                <div class="accordion-content border-2 border-gray-100 rounded-b-lg p-0 hidden">
                    @php($aggr = $lg['aggregates'] ?? null)
                    @if($aggr)
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="bg-white rounded-lg p-4 shadow">
                            <h2>Самая забивающая команда</h2>
                            <p><strong>Всего:</strong> {{ $aggr['most_scoring_overall']['team'] ?? '—' }} ({{ $aggr['most_scoring_overall']['goals'] ?? '—' }})</p>
                            <p><strong>Дома:</strong> {{ $aggr['most_scoring_home']['team'] ?? '—' }} ({{ $aggr['most_scoring_home']['goals'] ?? '—' }})</p>
                            <p><strong>В гостях:</strong> {{ $aggr['most_scoring_away']['team'] ?? '—' }} ({{ $aggr['most_scoring_away']['goals'] ?? '—' }})</p>
                        </div>
                        <div class="bg-white rounded-lg p-4 shadow">
                            <h2>Самая пропускающая команда</h2>
                            <p><strong>Дома:</strong> {{ $aggr['most_conceding_home']['team'] ?? '—' }} ({{ $aggr['most_conceding_home']['goals'] ?? '—' }})</p>
                            <p><strong>В гостях:</strong> {{ $aggr['most_conceding_away']['team'] ?? '—' }} ({{ $aggr['most_conceding_away']['goals'] ?? '—' }})</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 mt-4">
                        <div class="bg-white rounded-lg p-4 shadow">
                            <h2 class="font-bold mb-4">Топ-10 по забитым</h2>
                            <div class="flex flex-col w-full">
                                <div class="hidden md:flex justify-between gap-3 border border-gray-100 py-2 font-semibold">
                                    <div class="flex-1">Команда</div>
                                    <div class="flex-none text-right min-w-[64px]">Голы</div>
                                </div>
                                @foreach(($aggr['top_scoring'] ?? []) as $row)
                                    <div class="flex justify-between gap-3 border-b border-gray-100 p-2">
                                        <div class="flex-1">{{ $row['team'] }}</div>
                                        <div class="flex-none text-right min-w-[64px]">{{ $row['goals'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="bg-white rounded-lg p-4 shadow">
                            <h2 class="font-bold mb-4">Топ-10 по пропущенным</h2>
                            <div class="flex flex-col w-full">
                                <div class="hidden md:flex justify-between gap-3 border-b border-gray-100 py-2 font-semibold">
                                    <div class="flex-1">Команда</div>
                                    <div class="flex-none text-right min-w-[64px]">Пропущено</div>
                                </div>
                                @foreach(($aggr['top_conceding'] ?? []) as $row)
                                    <div class="flex justify-between gap-3 border-b border-gray-100 py-2">
                                        <div class="flex-1">{{ $row['team'] }}</div>
                                        <div class="flex-none text-right min-w-[64px]">{{ $row['goals'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg p-4 shadow mt-4">
                        <h2 class="font-bold mb-4">Топ-10 по победам</h2>
                        <div class="flex flex-col w-full">
                            <div class="hidden md:flex justify-between gap-3 border-b py-2 border-gray-100 font-semibold">
                                <div class="flex-1">Команда</div>
                                <div class="flex-none text-right min-w-[64px]">Победы</div>
                            </div>
                            @foreach(($aggr['top_wins'] ?? []) as $row)
                                <div class="flex justify-between gap-3 border-gray-100 border-b py-2">
                                    <div class="flex-1">{{ $row['team'] }}</div>
                                    <div class="flex-none text-right min-w-[64px]">{{ $row['wins'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    <div class="bg-white rounded-lg p-4 shadow mt-4">
                        <h2 class="font-bold mb-4">Все команды — сводная статистика</h2>
                        @php($stats = $lg['teamStats'] ?? [])
                        @if(empty($stats))
                            <p class="text-gray-500 text-xs">Нет данных для сводной таблицы.</p>
                        @else
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            @foreach($stats as $team => $st)
                                <div class="border border-gray-200 rounded-lg p-3">
                                    <div class="font-semibold mb-2">{{ $team }}</div>
                                    <div class="flex flex-wrap gap-2">
                                        <div class="text-xs bg-gray-50 border border-gray-200 rounded-md px-2 py-1"><span class="text-gray-500 mr-1">Матчи</span><span class="font-semibold">{{ $st['matches'] }}</span></div>
                                        <div class="text-xs bg-gray-50 border border-gray-200 rounded-md px-2 py-1"><span class="text-gray-500 mr-1">Забитые</span><span class="font-semibold">{{ $st['goals_for'] }}</span></div>
                                        <div class="text-xs bg-gray-50 border border-gray-200 rounded-md px-2 py-1"><span class="text-gray-500 mr-1">Пропущенные</span><span class="font-semibold">{{ $st['goals_against'] }}</span></div>
                                        <div class="text-xs bg-gray-50 border border-gray-200 rounded-md px-2 py-1"><span class="text-gray-500 mr-1">Победы</span><span class="font-semibold">{{ $st['wins'] }}</span></div>
                                        <div class="text-xs bg-gray-50 border border-gray-200 rounded-md px-2 py-1"><span class="text-gray-500 mr-1">Ничьи</span><span class="font-semibold">{{ $st['draws'] }}</span></div>
                                        <div class="text-xs bg-gray-50 border border-gray-200 rounded-md px-2 py-1"><span class="text-gray-500 mr-1">Поражения</span><span class="font-semibold">{{ $st['losses'] }}</span></div>
                                        <div class="text-xs bg-gray-50 border border-gray-200 rounded-md px-2 py-1"><span class="text-gray-500 mr-1">Дома</span><span class="font-semibold">{{ $st['home']['goals_for'] }} / {{ $st['home']['goals_against'] }}</span></div>
                                        <div class="text-xs bg-gray-50 border border-gray-200 rounded-md px-2 py-1"><span class="text-gray-500 mr-1">В гостях</span><span class="font-semibold">{{ $st['away']['goals_for'] }} / {{ $st['away']['goals_against'] }}</span></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @endif
                    </div>

                    <p class="text-gray-500 text-xs">Источник: sstats.net. Период: последние 120 дней.</p>
                </div>
            </div>
            @endforeach
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const accordionHeaders = document.querySelectorAll('.accordion-header');
            accordionHeaders.forEach(header => {
                header.addEventListener('click', function() {
                    const icon = this.querySelector('.accordion-icon');
                    const content = this.nextElementSibling;
                    // Tailwind-переключения
                    this.classList.toggle('rounded-b-none');
                    this.classList.toggle('border-b-0');
                    icon.classList.toggle('rotate-180');
                    content.classList.toggle('hidden');
                    content.classList.toggle('p-4');
                });
            });
        });
    </script>
</body>
</html>