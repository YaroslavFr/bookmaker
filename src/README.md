<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Главная страница: ключевая цепочка, маршруты, события и view()

Ниже — подробный разбор того, как устроена главная страница: какие маршруты задействованы, что такое `events`, как они формируются, и что принимает/возвращает функция `view()`.

### Ключевая цепочка (для главной страницы)
- Маршрут `GET /` определён в `routes/web.php` и указывает на `BetController@index` — это рендер главной страницы.
- `BetController@index` читает события и историю купонов из БД, подготавливает вспомогательные структуры и передаёт их в представление `resources/views/home.blade.php`.

```php
// routes/web.php
Route::get('/', [BetController::class, 'index'])->name('home');
// Дополнительные JSON-эндпоинты для главной страницы
Route::get('/odds', [OddsController::class, 'odds'])->name('odds.index');
Route::get('/events/{event}/markets', [OddsController::class, 'markets'])->name('events.markets');
Route::get('/odds/game/{gameId}', [OddsController::class, 'marketsByGame'])->name('odds.byGame');

// app/Http/Controllers/BetController.php (фрагмент)
public function index()
{
    $marketsMap = [];
    $gameIdsMap = [];

    $hasCompetition = Schema::hasColumn('events', 'competition');
    if ($hasCompetition) {
        $eventsEpl = Event::with('bets')
            ->where('competition', 'EPL')
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(12)
            ->get();
        $eventsUcl = Event::with('bets')
            ->where('competition', 'UCL')
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(12)
            ->get();
        $eventsIta = Event::with('bets')
            ->where('competition', 'ITA')
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(12)
            ->get();
    } else {
        // Фоллбэк до применения миграции: показываем все события
        $eventsEpl = Event::with('bets')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();
        $eventsUcl = collect();
        $eventsIta = collect();
    }

    // Карта event_id -> external_id (для ленивой подгрузки доп. рынков)
    foreach ([$eventsEpl, $eventsUcl, $eventsIta] as $collection) {
        foreach ($collection as $ev) {
            if (!empty($ev->external_id)) {
                $gameIdsMap[$ev->id] = (string)$ev->external_id;
            }
        }
    }

    $coupons = Coupon::with(['bets.event'])->latest()->limit(50)->get();

    $leagues = [
        ['title' => 'Чемпионат Англии (EPL)', 'events' => $eventsEpl],
        ['title' => 'Лига чемпионов (UCL)', 'events' => $eventsUcl],
        ['title' => 'Серия А (ITA)', 'events' => $eventsIta],
    ];

    return view('home', [
        'leagues' => $leagues,
        'eventsEpl' => $eventsEpl,
        'eventsUcl' => $eventsUcl,
        'eventsIta' => $eventsIta,
        'coupons' => $coupons,
        'marketsMap' => $marketsMap,
        'gameIdsMap' => $gameIdsMap,
    ]);
}
```

### Что такое `events` и как формируется список
- `events` — это таблица в БД с данными о матчах. Модель: `app/Models/Event.php`.
- Основные поля (`fillable` в модели): `title`, `competition`, `starts_at`, `ends_at`, `status`, `result`, `home_team`, `away_team`, `home_odds`, `draw_odds`, `away_odds`, `external_id`.
- Формирование списка для главной страницы происходит в контроллере: выбираются только «запланированные» матчи будущего (`status = scheduled`, `starts_at > now()`), разнесённые по лигам EPL/UCL/ITA, либо все события, если колонка `competition` ещё не добавлена.
- Дополнительные рынки (тоталы, форы и т.п.) не хранятся напрямую в `events` — они подгружаются по требованию с внешнего API через маршруты `/events/{event}/markets` или `/odds/game/{gameId}` (см. `OddsController`).
- Поле `external_id` помогает связать локальное событие с внешним матчем, чтобы по нему запрашивать доп. рынки.

```php
// app/Models/Event.php (фрагмент)
protected $fillable = [
    'title', 'competition', 'starts_at', 'ends_at', 'status', 'result',
    'home_team', 'away_team', 'home_odds', 'draw_odds', 'away_odds',
    'external_id',
];
```

### Что принимает и что возвращает `view()`
- `view(string $name, array $data = [])` — вспомогательная функция Laravel, которая принимает:
  - имя шаблона (`resources/views/{name}.blade.php`),
  - ассоциативный массив данных для шаблона.
- Возвращает объект `Illuminate\View\View`, который фреймворк сериализует в HTML-ответ.
- Все ключи из `$data` становятся доступными в Blade-шаблоне как переменные: например, ключ `leagues` доступен как `{{ $leagues }}`.
- В нашей главной странице `home.blade.php` используются, среди прочего: `leagues`, `eventsEpl`, `eventsUcl`, `eventsIta`, `coupons`, `gameIdsMap`.

### Итого (для главной страницы)
- Запрос к `GET /` попадает в `BetController@index`.
- Контроллер собирает события (events) и купоны, формирует вспомогательные данные (`gameIdsMap` для доп. рынков) и вызывает `view('home', ...)`.
- Представление `home.blade.php` рендерит карточки матчей, основные коэффициенты и историю купонов, а Vue-компонент `BetSlip.vue` обеспечивает сбор исходов и отправку купона (`POST /bets`).

Эта логика описывает именно главную страницу: от маршрута до данных, их источников и рендера.
