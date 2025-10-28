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

## Как на главной формируются «События» и откуда берутся значения

Ниже — краткий, удобочитаемый разбор источников данных и цепочки, по которой «События» появляются на главной странице.

### Ключевая цепочка
- Маршрут `GET /` определён в `routes/web.php` и указывает на `BetController@index`.
- В `BetController@index` выбираются события и история купонов из базы данных и передаются в представление `resources/views/home.blade.php`.

```php
// routes/web.php
Route::get('/', [BetController::class, 'index'])->name('home');

// app/Http/Controllers/BetController.php
public function index()
{
    $events = Event::with('bets')
        ->orderByDesc('starts_at')
        ->orderByDesc('id')
        ->get();
    $coupons = Coupon::with(['bets.event'])->latest()->limit(50)->get();

    return view('home', compact('events', 'coupons'));
}
```

### Что такое «Событие» в базе
- Таблица `events` создаётся миграциями:
  - `title`, `starts_at`, `ends_at`, `status` (`scheduled|live|finished`), `result` (`home|draw|away`).
  - Доп. поля EPL: `home_team`, `away_team`, `home_odds`, `draw_odds`, `away_odds`.
- Модель: `app/Models/Event.php` — перечисляет `fillable` и приводит типы полей.

```php
// database/migrations/2025_10_24_122358_create_events_table.php
$table->string('title');
$table->dateTime('starts_at')->nullable();
$table->dateTime('ends_at')->nullable();
$table->enum('status', ['scheduled','live','finished'])->default('scheduled');
$table->enum('result', ['home','draw','away'])->nullable();

// database/migrations/2025_10_24_122935_add_epl_fields_to_events_table.php
$table->string('home_team')->nullable();
$table->string('away_team')->nullable();
$table->decimal('home_odds', 8, 2)->nullable();
$table->decimal('draw_odds', 8, 2)->nullable();
$table->decimal('away_odds', 8, 2)->nullable();
```

### Откуда появляются записи и коэффициенты
- Команда `epl:sync-odds` наполняет предстоящие матчи и коэффициенты.
  - Источник команд: `TheSportsDB` (список команд лиги EPL).
  - Источник коэффициентов: `The Odds API` (рынок `h2h`, формат `decimal`).
  - Данные сохраняются в `events` через `updateOrCreate` (если матч уже есть — обновление, иначе — создание).
  - Если ключ к Odds API не задан, создаются пары команд с дефолтными коэффициентами как фоллбэк.

```php
// app/Console/Commands/SyncEplOdds.php
Event::updateOrCreate(
    ['title' => $m['home_team'].' vs '.$m['away_team'], 'starts_at' => $m['commence_time']],
    [
        'home_team' => $m['home_team'],
        'away_team' => $m['away_team'],
        'status' => 'scheduled',
        'home_odds' => $m['home_odds'],
        'draw_odds' => $m['draw_odds'],
        'away_odds' => $m['away_odds'],
    ]
);
```

- Где брать ключи и как запускать:
  - Установите `ODDS_API_KEY` в `.env` или `config/services.php` (`services.odds_api.key`).
  - Запуск вручную: `php artisan epl:sync-odds --limit=10`.
  - Планировщик (`app/Console/Kernel.php`) вызывает `epl:sync-odds` ежедневно в `06:00`.

```php
// app/Console/Kernel.php
$schedule->command('epl:sync-odds --limit=10')->dailyAt('06:00');
```

### Как обновляются результаты завершённых матчей
- Команда `epl:sync-results` берёт прошедшие матчи из `TheSportsDB` и отмечает локальные события `finished` + выставляет `result` (`home|draw|away`).
- Параллельно рассчитываются ставки и купоны (победа/проигрыш, выплаты).
- Запуск вручную: `php artisan epl:sync-results` (окно сопоставления по времени управляется опцией `--window`).
- Планировщик: каждые 10 минут.

```php
// app/Console/Commands/SyncEplResults.php
$ev->status = 'finished';
$ev->result = $result; // home/draw/away
$ev->ends_at = $apiTime ?: now();
$ev->save();

// Расчёт ставок
$ev->bets()->each(function(Bet $bet) use ($ev) {
    $win = $bet->selection === $ev->result;
    $odds = match ($bet->selection) {
        'home' => $ev->home_odds,
        'draw' => $ev->draw_odds,
        'away' => $ev->away_odds,
    };
    $bet->is_win = $win;
    $bet->payout_demo = $win ? ($bet->amount_demo * ($odds ?? 2)) : 0;
    $bet->settled_at = now();
    $bet->save();
});
```

Дополнительно есть маршрут `GET /events/sync-results` (см. `routes/web.php`) — контроллер `BetController@syncResults` делает похожую синхронизацию результатов и возврат на главную.

### Как это отображается на главной
- Представление: `resources/views/home.blade.php`.
- Для каждого события выводятся:
  - Название матча: `home_team vs away_team` или `title`.
  - Время начала: `starts_at`.
  - Коэффициенты: `home_odds / draw_odds / away_odds` под подписью `Коэфф. (П1 / Ничья / П2)`.
- Клики по коэффициентам (элементы `.odd-btn`) добавляют исходы в купон через Vue-компонент `BetSlip.vue` и отправляют на маршрут `POST /bets`.

```html
<!-- home.blade.php (фрагмент) -->
<span class="odd-btn" data-event-id="{{ $ev->id }}" data-selection="home" ...>П1 {{ number_format($h, 2) }}</span>
<span class="odd-btn" data-event-id="{{ $ev->id }}" data-selection="draw" ...>Ничья {{ number_format($d, 2) }}</span>
<span class="odd-btn" data-event-id="{{ $ev->id }}" data-selection="away" ...>П2 {{ number_format($a, 2) }}</span>
```

```js
// resources/js/components/BetSlip.vue (фрагмент)
function handleOddClick(e) {
  const btn = e.target.closest('.odd-btn');
  if (!btn) return;
  const eventId = btn.getAttribute('data-event-id');
  const selection = btn.getAttribute('data-selection');
  const home = btn.getAttribute('data-home');
  const away = btn.getAttribute('data-away');
  const odds = btn.getAttribute('data-odds');
  addOrReplaceSlipItem({ eventId, home, away, selection, odds });
}
```

### Итого: откуда берутся значения
- Список «Событий» на главной — это записи из БД (`events`), подготовленные командами синхронизации.
- Команды берут исходные данные из открытых API:
  - Состав пар команд — `TheSportsDB`.
  - Коэффициенты — `The Odds API` (усреднение по букмейкерам для рынка `h2h`).
  - Результаты завершённых матчей — `TheSportsDB`.
- Представление просто читает эти значения и даёт интерфейс для формирования демо-купона.

Если нужно, могу добавить на страницу отдельный блок «Источник данных» с живым статусом ключей/последней синхронизации.
