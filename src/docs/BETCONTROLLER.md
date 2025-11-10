# BetController — подробная документация

Этот контроллер отвечает за вывод событий на главную страницу, создание купонов, расчёт исходов и синхронизацию результатов из внешнего API.

## index()

Назначение:
- Собирает ленты событий по чемпионатам для главной страницы.
- Готовит человекочитаемые заголовки лиг и карту идентификаторов внешних матчей.
- Возвращает представление `home` с данными для рендера.

Вход:
- Нет явных параметров запроса, но поддерживается `?debug=competitions` для логирования в Debugbar.

Выход:
- `view('home', [...])` с ключами: `leagues`, `coupons`, `marketsMap`, `gameIdsMap`.

Основные шаги:
- Готовит функцию `prepareForView()`, формирующую заголовок события из пар команд (`home_team vs away_team`).
- Формирует маппинг заголовков лиг `leagueTitlesByCode` из единого конфига `config('leagues.leagues')`.
- Проверяет наличие столбца `events.competition` через `Schema::hasColumn`.
  - Если столбец есть:
    - Запрашивает список ближайших запланированных чемпионатов: события со статусом `scheduled` и временем `starts_at > now()`; вытягивает `distinct competition`.
    - При `?debug=competitions` включает Debugbar (`Debugbar::enable()`) и логирует:
      - `competitions` — список кодов чемпионатов;
      - `titlesByCompetition` — человекочитаемые названия по каждому коду;
      - `competitions.summary` — количество чемпионатов.
    - Для каждого чемпионата выбирает до 12 последних событий (`orderByDesc('starts_at'), orderByDesc('id')`), применяет `prepareForView()`.
    - Добавляет ленту в `leagues[]` с ключами `{ title, events }`.
    - Кэширует выборку в `eventsByCompetition[code]` (локально в методе).
  - Если столбца нет:
    - Делает фоллбэк: получает все события одной лентой `События`.
- Формирует `gameIdsMap[event_id] = external_id` по всем лентам для ленивой загрузки рынков.
- Получает последние 50 купонов `Coupon::with(['bets.event'])->latest()->limit(50)->get()`.
- Возвращает `view('home', [...])`.

Примечания:
- Заголовки лиг берутся из `config/leagues.php` (единый источник: код → `{id, title, slug}`).
- Включение Debugbar требует зарегистрированного провайдера и включённого `APP_DEBUG`/`DEBUGBAR_ENABLED`.

## store(Request $request)

Назначение:
- Создаёт купон (парлей) по выбранным событиям/исходам.

Валидация входа (`$request->validate`):
- `bettor_name`: строка до 100 символов; `required`, если пользователь не авторизован; `nullable` — если авторизован.
- `amount_demo`: `required`, `numeric`, `min:0.01` — сумма ставки (демо).
- `items`: `required`, `array`, `min:1` — элементы купона.
- `items.*.event_id`: `required`, `exists:events,id` — идентификатор события.
- `items.*.selection`: `required`, `string`, `max:100` — выбранный исход (`home`, `draw`, `away` или произвольный рынок).
- `items.*.odds`: `nullable`, `numeric`, `min:0.01` — кэф для доп. рынков (если исход не базовый).

Логика:
- Определяет имя игрока: если пользователь авторизован, берёт `username` (либо `email`), иначе — из формы (`bettor_name`), с фоллбэком `'User'`.
- Считает итоговый кэф купона `totalOdds`: перемножает кэфы каждого события.
  - Если `selection` ∈ {`home`,`draw`,`away`}, берёт кэфы из полей события (`home_odds`, `draw_odds`, `away_odds`).
  - Иначе — берёт `items.*.odds` из payload (если число), иначе 1.
- Создаёт запись `Coupon` с `bettor_name`, `amount_demo`, `total_odds`.
- Для каждого элемента создаёт `Bet` с полями `event_id`, `bettor_name`, `amount_demo`, `selection`, `coupon_id`.

Ответ:
- Если `$request->wantsJson()` — возвращает JSON `{status: 'ok', coupon_id, total_odds}`.
- Иначе — редирект на `home` с флеш‑сообщением `Купон создан`.

## settle(Event $event, Request $request)

Назначение:
- Рассчитывает исход события и все связанные ставки; при необходимости обновляет купоны.

Валидация:
- `result`: `required`, одно из `home`, `draw`, `away`.

Логика расчёта события:
- Обновляет событие: `status = finished`, `result = payload['result']`, `ends_at = now()`.
- Для каждой ставки события:
  - Определяет выигрыш: `win = (bet.selection === event.result)`.
  - Выбирает кэф по `selection` из события (`home_odds`/`draw_odds`/`away_odds`).
  - Обновляет ставку: `is_win`, `payout_demo = win ? amount_demo * (odds ?? 2) : 0`, `settled_at = now()`.

Логика расчёта купонов:
- Находит затронутые купоны: `event->bets()->pluck('coupon_id')->unique()`.
- Для каждого купона с предзагрузкой `bets.event`:
  - Проверяет, что все ставки купона рассчитаны (`settled_at !== null`).
  - Если все рассчитаны:
    - Проверяет, что все ставки выиграли (`is_win === true`).
    - Обновляет купон: `is_win = allWin`, `payout_demo = allWin ? amount_demo * total_odds : 0`, `settled_at = now()`.

Ответ:
- Редирект на `home` с флеш‑сообщением `Событие рассчитано`.

## syncResults()

Назначение:
- Синхронизирует результаты из внешнего API `sstats.net` и рассчитывает локальные ставки по завершённым матчам.

Предусловия:
- Требуется `SSTATS_API_KEY` (`config('services.sstats.key')`). Если отсутствует — редирект: `SSTATS_API_KEY отсутствует`.

Логика запроса:
- Формирует базовый URL: `config('services.sstats.base_url', 'https://api.sstats.net')`.
- Запрашивает `GET /Games/list` с параметрами:
  - `leagueid=39` (EPL по умолчанию),
  - `year = текущий год`,
  - `limit = 500`,
  - `ended = true`.
- Таймаут 30 секунд, ключ передаётся в `X-API-KEY`.
- При ошибке ответа — редирект: `Не удалось получить результаты из sstats`.

Обработка результатов:
- Преобразует ответ в коллекцию `eventsApi`.
- Для каждого элемента:
  - Извлекает внешние поля: `id`/`game.id`/`GameId`/`gameid`, имена команд, счёт (`homeResult`, `awayResult`), дату (`date`).
  - Требует валидные данные и прошлую дату (не будущую).
  - Ищет локальное событие по `external_id`.
    - Если не найдено — создаёт новое завершённое `Event` с вычисленным `result` (`home`/`draw`/`away`).
    - Если найдено — при необходимости обновляет статус/результат (`finished`, `result`), выставляет `ends_at` и сохраняет.
  - Для обновлённого события рассчитывает связанные ставки аналогично методу `settle()`.
  - Считает количество обновлённых/созданных записей (`$updated++`).

Ответ:
- Редирект на `home` с флеш‑сообщением: `Синхронизировано результатов: {updated}`.
- В случае исключения — редирект с сообщением `Ошибка API: {message}`.

## Debugbar в index()

- При `?debug=competitions` метод:
  - Включает панель: `Debugbar::enable()`.
  - Логирует три сообщения:
    - `competitions` — массив кодов чемпионатов;
    - `titlesByCompetition` — человекочитаемые названия;
    - `competitions.summary` — количество.

## Данные представления `home`

- `leagues`: массив лент: `{ title: string, events: Collection<Event> }`.
- `coupons`: последние 50 купонов с предзагрузкой `bets.event`.
- `marketsMap`: пустая карта (зарезервировано для ленивой загрузки рынков).
- `gameIdsMap`: соответствие `event_id -> external_id`.

## Зависимости и конфиг

- Конфиг лиг: `config/leagues.php` — единый источник кодов, `id`, названий и `slug`.
- Debugbar: в `bootstrap/providers.php` зарегистрирован `Barryvdh\Debugbar\ServiceProvider::class`; включение определяется `APP_DEBUG`/`DEBUGBAR_ENABLED` или вручную через `Debugbar::enable()`.

## Примечание

- В конце файла есть комментарий: «Resolver removed…» — резолвер турниров заменён на прямой выбор по ID (для детерминированного вывода EPL).