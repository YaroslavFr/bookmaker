# Статистика: источники данных и процесс агрегирования

Этот документ описывает, откуда и как приложение получает статистику для страницы `/stats`, а также конфигурацию и агрегацию данных.

## Маршрут и контроллер
- URL: `GET /stats`
- Маршрут определён в `routes/web.php` и указывает на `App\Http\Controllers\StatsController@index`.
- Представление: `resources/views/stats.blade.php`.

## Источник данных: API‑Sport.ru
- Базовый URL: `https://api.api-sport.ru` (настраивается через переменную окружения `API_SPORT_BASE`).
- Авторизация: заголовок `Authorization: <API_SPORT_KEY>`.
- Ключ и базовый URL конфигурируются в `config/services.php`:
  - `services.api_sport.key` ← `.env: API_SPORT_KEY`
  - `services.api_sport.base_url` ← `.env: API_SPORT_BASE` (по умолчанию `https://api.api-sport.ru`)

## Выбор чемпионата
- Чемпионат задаётся переменной окружения `.env: API_SPORT_TOURNAMENT_ID`.
- По умолчанию используется Английская Премьер‑лига (EPL) — ID `17`.

## Как формируется статистика
1. Определение контекста турнира:
   - Запрос: `GET /v2/football/tournament/{tournamentId}`.
   - Из ответа извлекаются `category.id` и максимальный `season.id`.
2. Получение результатов матчей за последние 120 дней:
   - Для каждого дня в диапазоне `[сегодня − 120 дней; сегодня]` выполняется `GET /v2/football/matches` с параметрами:
     - `date=<YYYY-MM-DD>`
     - `status=finished`
     - `tournament_id=<ID>`
     - опционально `season_id`, `category_id`
   - Матчи фильтруются по выбранному турниру/категории.
3. Кэширование результатов:
   - Ключ: `stats:results_all:football:{tournamentId}:{seasonId|none}:cat:{categoryId|none}:days120`.
   - TTL: 12 часов (`Cache::remember(...)`).
4. Агрегация по командам:
   - Считаются метрики: `matches`, `goals_for`, `goals_against`, `wins`, `losses`, `draws`.
   - Отдельные сплиты по дом/выезд: `home.*` и `away.*`.
5. Формирование сводных метрик (передаются во вьюху):
   - `most_scoring_overall`, `most_scoring_home`, `most_scoring_away`
   - `most_conceding_home`, `most_conceding_away`
   - Топ‑10: `top_scoring` (забитые), `top_conceding` (пропущенные), `top_wins` (победы)

## Надёжность и обработка ошибок
- Если `API_SPORT_KEY` отсутствует, страница сообщает об этом и не делает запросы.
- HTTP‑клиент настроен на `timeout(20)`, `connectTimeout(5)`, `retry(2, 500)`.

## Смена чемпионата
- Установите нужный ID в `.env: API_SPORT_TOURNAMENT_ID` и перезапустите приложение.
- При смене ID заново вычисляются `seasonId`/`categoryId` и обновляется кэш.

## Быстрая проверка
- Локально: `http://127.0.0.1:8000/stats`.

## Дополнительно: страница `/sstats`
- Маршрут: `GET /sstats` — контроллер `App\Http\Controllers\SstatsController@index` (требует аутентификации).
- Источник: `https://api.sstats.net` (`services.sstats.base_url` ← `SSTATS_BASE`, ключ `SSTATS_API_KEY`).
- Используются эндпоинты `/Games/*` и `/Odds/*` для просмотра игр, коэффициентов, рейтингов Glicko и аналитики профитов. Это отдельный «эксплорер» и не влияет на расчёты `/stats`.