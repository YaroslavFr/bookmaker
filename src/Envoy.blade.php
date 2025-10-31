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

    // Fallback: read ADMIN_* from local .env if not provided via CLI
    if (empty($admin_username) || empty($admin_email) || empty($admin_password)) {
        $localEnvPath = __DIR__ . '/.env';
        $parseEnv = function ($path) {
            $vars = [];
            if (file_exists($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) { continue; }
                    [$k, $v] = array_map('trim', explode('=', $line, 2));
                    $vars[$k] = $v;
                }
            }
            return $vars;
        };
        $vars = $parseEnv($localEnvPath);
        if (empty($vars) && file_exists(__DIR__ . '/.env.example')) {
            $vars = $parseEnv(__DIR__ . '/.env.example');
        }
        if (empty($admin_username) && isset($vars['ADMIN_USERNAME'])) { $admin_username = $vars['ADMIN_USERNAME']; }
        if (empty($admin_email) && isset($vars['ADMIN_EMAIL'])) { $admin_email = $vars['ADMIN_EMAIL']; }
        if (empty($admin_password) && isset($vars['ADMIN_PASSWORD'])) { $admin_password = $vars['ADMIN_PASSWORD']; }
    }
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
@endtask

@task('assets-upload', ['on' => 'local'])
    echo "--- Uploading build to server ---"
    echo "Source: public/build"
    echo "Destination: {{ $ssh }}:{{ $path }}/src/public/build"
    ssh {{ $ssh }} "mkdir -p {{ $path }}/src/public/build/assets"
    scp -r public/build/* {{ $ssh }}:"{{ $path }}/src/public/build/"
    echo "Upload completed"
@endtask

@story('assets-ci')
    assets-build
    assets-upload
    assets
@endstory

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

@task('admin-update', ['on' => 'beget'])
    cd {{ $path }}
    set -e
    echo "--- Admin .env and user sync ---"

    if [ -z "{{ $admin_username }}" ] || [ -z "{{ $admin_email }}" ] || [ -z "{{ $admin_password }}" ]; then
        echo "Укажите параметры: --admin_username= --admin_email= --admin_password="
        echo "Пример: php vendor/bin/envoy run admin-update --admin_username=admin --admin_email=admin@example.com --admin_password=secret"
        exit 1
    fi

    cd src
    if [ -f ".env" ]; then
        cp -f .env .env.admin.bak
        echo "Backup .env -> .env.admin.bak"
    fi

    update_env() {
        KEY="$1"; VALUE="$2";
        if grep -q "^$KEY=" .env 2>/dev/null; then
            # Escape '&' to avoid sed backreference replacement
            SAFE_VALUE=$(printf '%s' "$VALUE" | sed 's/&/\\&/g')
            sed -i "s|^$KEY=.*|$KEY=$SAFE_VALUE|" .env
        else
            printf "\n%s=%s\n" "$KEY" "$VALUE" >> .env
        fi
    }

    touch .env
    update_env "ADMIN_USERNAME" "{{ $admin_username }}"
    update_env "ADMIN_EMAIL" "{{ $admin_email }}"
    update_env "ADMIN_PASSWORD" "{{ $admin_password }}"

    echo "--- Running artisan admin:create (force) ---"
    {{ $php }} artisan admin:create --username="{{ $admin_username }}" --email="{{ $admin_email }}" --password="{{ $admin_password }}" --force || {
        echo "admin:create failed; trying seeder fallback"
        {{ $php }} artisan db:seed --class=Database\\Seeders\\AdminUserSeeder --force
    }

    {{ $php }} artisan optimize:clear
    {{ $php }} artisan config:cache
    echo "Admin sync completed"
@endtask

@story('admin-sync')
    admin-update
@endstory

@story('release')
    assets-build
    assets-upload
    assets
    admin-update
@endstory
