@setup
    $branch = isset($branch) ? $branch : 'main';
    $path = '/home/r/rundosq0/laravel';
    $php = 'php8.4';
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
    git reset --hard origin/{{ $branch }}

    if [ ! -f "composer.phar" ]; then
        echo "Composer.phar не найден, скачиваю установщик..."
        curl -sS https://getcomposer.org/installer -o composer-setup.php
        {{ $php }} composer-setup.php --quiet
        rm composer-setup.php
    fi

    # Чистая установка зависимостей, чтобы избежать конфликтов и предупреждений
    if [ -d "vendor" ]; then
        echo "Удаляю существующий каталог vendor/"
        rm -rf vendor
    fi

    {{ $php }} composer.phar install --no-dev --prefer-dist --no-progress --no-interaction --classmap-authoritative

    {{ $php }} artisan migrate --force

    {{ $php }} artisan optimize:clear
    {{ $php }} artisan config:cache
    {{ $php }} artisan route:cache
    {{ $php }} artisan view:cache

    echo "Deployment completed successfully"
@endtask
