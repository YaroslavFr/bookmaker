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

    git reset --hard origin/{{ $branch }}

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

    echo "--- Syncing public assets to public_html (or using src/public if same) ---"
    cd {{ $path }}
    SRC_PUBLIC="{{ $path }}/src/public"
    DEST_PUBLIC="{{ $path }}/public_html"
    REAL_SRC=$(readlink -f "$SRC_PUBLIC" 2>/dev/null || echo "$SRC_PUBLIC")
    REAL_DEST=$(readlink -f "$DEST_PUBLIC" 2>/dev/null || echo "$DEST_PUBLIC")
    if [ "$REAL_SRC" = "$REAL_DEST" ]; then
        echo "public_html points to src/public (same path). Skipping copy; using src/public as web root."
        DEST="$SRC_PUBLIC"
    else
        DEST="$DEST_PUBLIC"
        mkdir -p "$DEST/css" "$DEST/js"
    fi

    if [ -d "$SRC_PUBLIC/build" ]; then
        if [ "$REAL_SRC" != "$REAL_DEST" ]; then
            rm -rf "$DEST/build"
            mkdir -p "$DEST/build"
            cp -r "$SRC_PUBLIC/build"/* "$DEST/build/"
            echo "Copied Vite build to $DEST/build"
        else
            echo "Vite build already present at $DEST/build; skip copy"
        fi
    else
        echo "No Vite build directory found; ensuring fallback css/js exist"
    fi

    if [ -f "$SRC_PUBLIC/css/app.css" ]; then
        SRC_CSS_REAL=$(readlink -f "$SRC_PUBLIC/css/app.css" 2>/dev/null || echo "$SRC_PUBLIC/css/app.css")
        DEST_CSS_REAL=$(readlink -f "$DEST/css/app.css" 2>/dev/null || echo "$DEST/css/app.css")
        if [ "$SRC_CSS_REAL" != "$DEST_CSS_REAL" ]; then
            cp -f "$SRC_PUBLIC/css/app.css" "$DEST/css/app.css" || true
            echo "Copied app.css to $DEST/css"
        else
            echo "app.css already in place at destination"
        fi
    fi
    if [ -f "$SRC_PUBLIC/js/app.js" ]; then
        SRC_JS_REAL=$(readlink -f "$SRC_PUBLIC/js/app.js" 2>/dev/null || echo "$SRC_PUBLIC/js/app.js")
        DEST_JS_REAL=$(readlink -f "$DEST/js/app.js" 2>/dev/null || echo "$DEST/js/app.js")
        if [ "$SRC_JS_REAL" != "$DEST_JS_REAL" ]; then
            cp -f "$SRC_PUBLIC/js/app.js" "$DEST/js/app.js" || true
            echo "Copied app.js to $DEST/js"
        else
            echo "app.js already in place at destination"
        fi
    fi

    echo "--- Destination listing (build/css/js) ---"
    ls -la "$DEST/build" 2>/dev/null || echo "No $DEST/build"
    ls -la "$DEST/css" 2>/dev/null || true
    ls -la "$DEST/js" 2>/dev/null || true
@endtask

@task('assets-build', ['on' => 'local'])
    echo "--- Local assets build (Vite) ---"
    echo "Node version:" && node -v
    echo "Installing dependencies..."
    npm install
    echo "Building..."
    npm run build
    echo "Local assets build completed"
    echo "--- Uploading build to server ---"
    echo "Source: public/build"
    echo "Destination: {{ $ssh }}:{{ $path }}/src/public/build"
    ssh {{ $ssh }} "mkdir -p {{ $path }}/src/public/build/assets"
    scp -r public/build/* {{ $ssh }}:"{{ $path }}/src/public/build/"
    echo "Upload completed"
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

@story('release')
    assets-build
    assets
    admin-update
@endstory
