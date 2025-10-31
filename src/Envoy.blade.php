@setup
    $branch = isset($branch) ? $branch : 'main';
    $path = '/home/r/rundosq0/laravel';
    $php = 'php8.4';
    $file = isset($file) ? $file : 'src/database/seeders/AdminUserSeeder.php';
    $dir = dirname($file);
    $origin = isset($origin) ? $origin : '';
@endsetup

@servers(['beget' => 'rundosq0_main@rundosq0.beget.tech'])

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
    cd {{ $path }}

    set -e

    if [ ! -d ".git" ]; then
        echo "Не найден .git в {{ $path }}. Сначала выполните: envoy run setup --repo=<URL> --branch={{ $branch }}"
        exit 1
    fi

    git fetch --all --prune
    git checkout {{ $branch }}
    # Обновляем локальную ветку из удалённой, чтобы подтянуть недостающие файлы
    git pull --ff-only origin {{ $branch }}

    # Сохраняем текущий src/.env перед жёсткой синхронизацией, чтобы не потерять хостинговые настройки
    if [ -f "src/.env" ]; then
        cp -f src/.env src/.env.host.bak
        echo "Backup src/.env -> src/.env.host.bak"
    fi

    git reset --hard origin/{{ $branch }}

    # Восстанавливаем src/.env из бэкапа, если он был
    if [ -f "src/.env.host.bak" ]; then
        mv -f src/.env.host.bak src/.env
        echo "Restored src/.env from backup to preserve hosting configuration"
    fi

    if [ ! -f "composer.phar" ]; then
        echo "Composer.phar не найден, скачиваю установщик..."
        curl -sS https://getcomposer.org/installer -o composer-setup.php
        {{ $php }} composer-setup.php --quiet
        rm composer-setup.php
    fi

    # Чистая установка зависимостей в каталоге src/, чтобы избежать конфликтов
    if [ -d "src/vendor" ]; then
        echo "Удаляю существующий каталог src/vendor/"
        rm -rf src/vendor
    fi

    {{ $php }} composer.phar install --working-dir=src --no-dev --prefer-dist --no-progress --no-interaction --classmap-authoritative

    {{ $php }} src/artisan migrate --force

    {{ $php }} src/artisan optimize:clear
    {{ $php }} src/artisan config:cache
    {{ $php }} src/artisan route:cache
    {{ $php }} src/artisan view:cache

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

@task('assets', ['on' => 'beget'])
    cd {{ $path }}/src
    echo "--- Checking Node availability ---"
    if command -v node >/dev/null 2>&1; then
        echo "Node found: $(node -v)"
        if [ -f package.json ]; then
            echo "Installing npm deps (ci) in src ..."
            npm ci || npm install
            echo "Building Vite assets ..."
            npm run build || (echo "npm build failed" && exit 1)
        else
            echo "package.json not found in src"
        fi
    else
        echo "Node is not available. Applying simple static fallback to public/css/js."
        mkdir -p public/css public/js
        cp -f resources/css/app.css public/css/app.css 2>/dev/null || echo "No resources/css/app.css"
        cp -f resources/js/app.js public/js/app.js 2>/dev/null || echo "No resources/js/app.js"
    fi

    echo "--- Assets status ---"
    ls -la public/build/manifest.json 2>/dev/null || echo "No manifest (Vite build not present). Using fallback if provided."
    ls -la public/css/app.css 2>/dev/null || true
    ls -la public/js/app.js 2>/dev/null || true
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

@task('diagnose', ['on' => 'beget'])
    cd {{ $path }}

    echo "Path: {{ $path }}"
    echo "Branch (expected): {{ $branch }}"
    echo "File to check: {{ $file }}"

    if [ ! -d ".git" ]; then
        echo "Не найден .git в {{ $path }} — сначала выполните setup"
        exit 1
    fi

    echo "--- Git remotes ---"
    git remote -v
    echo "--- Current branch ---"
    git branch --show-current || true
    echo "--- Git toplevel ---"
    git rev-parse --show-toplevel || true
    echo "--- core.worktree ---"
    git config --get core.worktree || true
    echo "--- HEAD commit ---"
    git rev-parse HEAD || true
    echo "--- Status (short) ---"
    git status -s || true
    echo "--- Tracked files sample ---"
    git ls-files | head -n 50 || true

    echo "--- Directory listing: {{ $dir }} ---"
    ls -la "{{ $dir }}" || echo "Directory not found: {{ $dir }}"

    if [ -f "{{ $file }}" ]; then
        echo "--- File content head: {{ $file }} ---"
        sed -n '1,120p' "{{ $file }}" || cat "{{ $file }}" || true
        echo "--- File metadata ---"
        ls -l --time-style=long-iso "{{ $file }}" || stat "{{ $file }}" || true
        echo "--- File checksum (md5) ---"
        md5sum "{{ $file }}" || openssl md5 "{{ $file }}" || true
    else
        echo "Файл не найден: {{ $file }}"
    fi

    echo "Diagnostics completed"
@endtask

@task('repair', ['on' => 'beget'])
    cd {{ $path }}

    set -e

    if [ ! -d ".git" ]; then
        echo "Не найден .git в {{ $path }} — сначала выполните setup"
        exit 1
    fi

    echo "--- Проверка и настройка origin ---"
    CURRENT_ORIGIN=$(git remote get-url origin || echo "")
    echo "Текущий origin: $CURRENT_ORIGIN"
    if [ -n "{{ $origin }}" ]; then
        if [ "$CURRENT_ORIGIN" != "{{ $origin }}" ]; then
            echo "Обновляю origin на {{ $origin }}"
            git remote set-url origin {{ $origin }}
        else
            echo "Origin уже корректный"
        fi
    fi

    echo "--- Жёсткая синхронизация ветки {{ $branch }} ---"
    git fetch --all --prune
    # Переключаемся на ветку, создаём локальную если отсутствует
    git checkout {{ $branch }} || git checkout -b {{ $branch }} origin/{{ $branch }}
    # Тянем изменения без merge-коммитов
    git pull --ff-only origin {{ $branch }} || echo "Предупреждение: pull не прошёл fast-forward"
    # Полное соответствие удалённой ветке
    git reset --hard origin/{{ $branch }}
    # Удаляем лишние неотслеживаемые файлы и каталоги (сохранить public_html)
    git clean -df -e public_html

    echo "--- Итоговое состояние ---"
    git rev-parse HEAD || true
    git status -s || true

    if [ -f "{{ $file }}" ]; then
        echo "--- Контрольный просмотр файла: {{ $file }} ---"
        sed -n '1,120p' "{{ $file }}" || cat "{{ $file }}" || true
        md5sum "{{ $file }}" || openssl md5 "{{ $file }}" || true
    else
        echo "Файл не найден: {{ $file }}"
    fi

    echo "Repair completed"
@endtask

@task('logs', ['on' => 'beget'])
    cd {{ $path }}
    echo "--- Available logs in src/storage/logs ---"
    ls -la src/storage/logs || echo "Log directory not found at src/storage/logs"
    LATEST=$(ls -t src/storage/logs/*.log 2>/dev/null | head -n 1)
    if [ -n "$LATEST" ]; then
        echo "--- Tailing latest log (last 120 lines): $LATEST ---"
        tail -n 120 "$LATEST" || echo "Unable to read $LATEST"
    else
        echo "No log files found in src/storage/logs"
    fi
@endtask

@task('webroot', ['on' => 'beget'])
    cd {{ $path }}
    echo "--- public_html listing ---"
    ls -la public_html || echo "public_html not found"
    if [ -f "public_html/index.php" ]; then
        echo "--- public_html/index.php head ---"
        sed -n '1,80p' public_html/index.php || true
    else
        echo "public_html/index.php not found"
    fi
@endtask
