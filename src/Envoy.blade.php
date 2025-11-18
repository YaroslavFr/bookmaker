@setup
    $branch = isset($branch) ? $branch : 'main';
    $path = '/home/r/rundosq0/laravel';
    $php = 'php8.4';
    $origin = isset($origin) ? $origin : '';
    $ssh = 'rundosq0_main@rundosq0.beget.tech';
    // Admin credentials (optional). Pass via: --admin_username= --admin_email= --admin_password=
    $admin_username = isset($admin_username) ? $admin_username : '';
    $admin_email = isset($admin_email) ? $admin_email : '';
    $admin_password = isset($admin_password) ? $admin_password : '';
@endsetup

@servers(['beget' => 'rundosq0_main@rundosq0.beget.tech', 'local' => '127.0.0.1'])

@task('setup', ['on' => 'beget'])
    @if (!isset($repo) || empty($repo))
        echo "Ошибка: укажите --repo=<SSH_OR_HTTPS_URL> при запуске setup"
        exit 1
    @endif

    mkdir -p {{ $path }}
    cd {{ $path }}

    if [ -d ".git" ]; then
        echo "Репозиторий уже инициализирован: {{ $path }}"
    else
        echo "Клонирую репозиторий: {{ $repo }} в {{ $path }}"
        git clone {{ $repo }} . --branch {{ $branch }}
    fi
@endtask

@task('deploy', ['on' => 'beget'])
    # Переходим в корневую директорию проекта на сервере
    cd {{ $path }}

    # Останавливаем выполнение при любой ошибке (fail fast)
    set -e

    # Проверяем, что каталог является git-репозиторием
    if [ ! -d ".git" ]; then
        echo "Не найден .git в {{ $path }}. Сначала выполните: envoy run setup --repo=<URL> --branch={{ $branch }}"
        exit 1
    fi

    # Обновляем ссылки на удалённые ветки и очищаем устаревшие
    git fetch --all --prune
    # Переключаемся на целевую ветку деплоя
    git checkout {{ $branch }}
    # Обновляем локальную ветку из origin, допускаем только fast-forward, без merge-коммитов
    git pull --ff-only origin {{ $branch }}

    # Жёстко синхронизируем рабочее дерево со состоянием origin/{{ $branch }}
    git reset --hard origin/{{ $branch }}

    # Скачиваем локальный composer.phar, если он отсутствует
    if [ ! -f "composer.phar" ]; then
        echo "Composer.phar не найден, скачиваю установщик..."
        curl -sS https://getcomposer.org/installer -o composer-setup.php
        {{ $php }} composer-setup.php --quiet
        rm composer-setup.php
    fi

    # Миграции содержат защиту от повторного создания таблицы sessions

    # Удаляем существующие зависимости (vendor) для чистой установки без конфликтов
    if [ -d "src/vendor" ]; then
        echo "Удаляю существующий каталог src/vendor/"
        rm -rf src/vendor
    fi

    # Устанавливаем прод-зависимости внутри каталога src/
    #  --no-dev             исключаем dev-пакеты
    #  --prefer-dist        загружаем дистрибутивы (быстрее, меньше трафика)
    #  --no-progress        скрываем прогресс-бар (чище логи)
    #  --no-interaction     не задаём вопросы (автоматический режим)
    #  --classmap-authoritative ускоряет автозагрузку в продакшене
    {{ $php }} composer.phar install --working-dir=src --no-dev --prefer-dist --no-progress --no-interaction --classmap-authoritative

    # Применяем миграции базы данных в продакшене
    {{ $php }} src/artisan migrate --force

    # Очищаем кеши, затем прогреваем основные кеши приложения
    {{ $php }} src/artisan optimize:clear   # очищаем все кеши (config, route, view, app)
    {{ $php }} src/artisan config:cache    # компилируем и кешируем конфигурацию
    {{ $php }} src/artisan route:cache     # компилируем и кешируем маршруты
    {{ $php }} src/artisan view:cache      # компилируем Blade-шаблоны

    # Финальное сообщение об успешном завершении деплоя
    echo "Deployment completed successfully"
@endtask

@task('migrate-fresh', ['on' => 'beget'])
    cd {{ $path }}

    set -e

    echo "--- Running migrate:fresh (drops all tables and re-creates) ---"
    {{ $php }} src/artisan migrate:fresh --force

    echo "--- Seeding default data ---"
    {{ $php }} src/artisan db:seed --force

    echo "--- Rebuilding caches ---"
    {{ $php }} src/artisan optimize:clear
    {{ $php }} src/artisan config:cache
    {{ $php }} src/artisan route:cache
    {{ $php }} src/artisan view:cache

    echo "migrate-fresh completed"
@endtask

@task('seed', ['on' => 'beget'])
    cd {{ $path }}

    set -e

    echo "--- Seeding database (DatabaseSeeder + AdminUserSeeder) ---"
    {{ $php }} src/artisan db:seed --force
    echo "Seed completed"
@endtask

@task('assets-build', ['on' => 'local'])
    powershell -NoProfile -Command "
        Write-Host '--- Local assets build (Vite) ---';
        Write-Host 'Checking Node/npm versions ...';
        node -v;
        npm -v;
        Write-Host 'Installing dependencies...';
        npm install;
        Write-Host 'Building...';
        npm run build;
        Write-Host 'Local assets build completed';
        Write-Host '--- Uploading build to server ---';
        Write-Host 'Source: public/build';
        Write-Host 'Destination: {{ $ssh }}:{{ $path }}/src/public/build';
        ssh {{ $ssh }} 'mkdir -p {{ $path }}/src/public/build/assets';
        scp -r public/build/* {{ $ssh }}:'{{ $path }}/src/public/build/';
        Write-Host 'Upload completed';
    "
@endtask

@task('sync-odds', ['on' => 'beget'])
    cd {{ $path }}
    echo "--- Syncing EPL odds and upcoming events ---"
    {{ $php }} src/artisan epl:sync-odds --limit=10
    echo "Sync odds completed"
@endtask

@task('sync-results', ['on' => 'beget'])
    cd {{ $path }}
    echo "--- Syncing EPL finished results and settling bets ---"
    {{ $php }} src/artisan epl:sync-results --window=48
    echo "Sync results completed"
@endtask

@task('admin-update', ['on' => 'beget'])
    cd {{ $path }}
    set -e
    echo "--- Admin update without touching .env ---"

    if [ -z "{{ $admin_username }}" ] || [ -z "{{ $admin_email }}" ] || [ -z "{{ $admin_password }}" ]; then
        echo "Параметры администратора не переданы; пропускаю admin-update."
        echo "Чтобы обновить админа: --admin_username= --admin_email= --admin_password="
        exit 0
    fi

    cd src

    echo "--- Running artisan admin:create (force) ---"
    {{ $php }} artisan admin:create --username="{{ $admin_username }}" --email="{{ $admin_email }}" --password="{{ $admin_password }}" --force || {
        echo "admin:create failed; trying seeder fallback with env injection"
        ADMIN_USERNAME="{{ $admin_username }}" ADMIN_EMAIL="{{ $admin_email }}" ADMIN_PASSWORD="{{ $admin_password }}" \
        {{ $php }} artisan db:seed --class=Database\\Seeders\\AdminUserSeeder --force
    }

    {{ $php }} artisan optimize:clear
    {{ $php }} artisan config:cache
    echo "Admin update completed (no .env changes)"
@endtask

@task('env-apply', ['on' => 'beget'])
    cd {{ $path }}

    set -e

    echo "--- Applying production env (.env.production -> .env) ---"
    if [ -f "src/.env.production" ]; then
        cp -f src/.env.production src/.env
    else
        echo "src/.env.production not found" && exit 1
    fi

    {{ $php }} src/artisan config:clear
    {{ $php }} src/artisan cache:clear
    {{ $php }} src/artisan config:cache

    echo "Env applied and caches rebuilt"
@endtask

@story('release')
    env-apply
    deploy
    assets-build
@endstory
