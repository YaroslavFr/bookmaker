# Инструкция по деплою Laravel приложения на FTP хостинг

## Подготовка завершена ✅

1. **Production build фронтенда** - создан (`npm run build`)
2. **Composer dependencies** - установлены для production (`--no-dev`)
3. **Production .env файл** - создан (`.env.production`)

## Файлы для загрузки на FTP

### ✅ ЗАГРУЖАТЬ эти папки и файлы:

```
src/
├── app/                    # Вся папка
├── bootstrap/              # Вся папка
├── config/                 # Вся папка
├── database/               # Вся папка (миграции, seeders)
├── public/                 # Вся папка (включая build/)
├── resources/              # Вся папка
├── routes/                 # Вся папка
├── storage/                # Вся папка (создать права 755/777)
├── vendor/                 # Вся папка (после composer install --no-dev)
├── .htaccess              # Если есть
├── artisan                # Файл
├── composer.json          # Файл
├── composer.lock          # Файл
└── .env.production        # Переименовать в .env на сервере
```

### ❌ НЕ ЗАГРУЖАТЬ:

```
├── .env                   # Локальный файл
├── .env.example          
├── .git/                  # Git репозиторий
├── .gitignore            
├── node_modules/          # Node.js зависимости
├── package.json          # Не нужен на сервере
├── package-lock.json     
├── phpunit.xml           # Тесты
├── tests/                # Тесты
├── vite.config.js        # Не нужен на сервере
├── docker-compose.yml    # Docker файлы
├── Dockerfile           
└── README.md             # Документация
```

## Пошаговая инструкция деплоя

### 1. Подготовка на хостинге

1. **Создайте базу данных MySQL** в панели управления хостингом
2. **Запишите данные подключения:**
   - Хост БД (обычно `localhost`)
   - Имя базы данных rundosq0_rundosq
   - Пользователь БД rundosq0_rundosq
   - Пароль БД e8ZK0WE!Cx&k

### 2. Загрузка файлов

1. **Подключитесь к FTP** (FileZilla, WinSCP и т.д.)
2. **Загрузите все файлы** из списка выше в корневую папку сайта
3. **Переименуйте** `.env.production` в `.env`

### 3. Настройка .env файла

Отредактируйте `.env` файл на сервере:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ваш-домен.com

# ЗАМЕНИТЕ НА ДАННЫЕ ВАШЕЙ БД:
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=ваша_база_данных
DB_USERNAME=ваш_пользователь
DB_PASSWORD=ваш_пароль
```

### 4. Настройка прав доступа

Установите права на папки (через FTP или SSH):
```bash
chmod 755 storage/
chmod 755 storage/logs/
chmod 755 storage/framework/
chmod 755 storage/framework/cache/
chmod 755 storage/framework/sessions/
chmod 755 storage/framework/views/
chmod 755 bootstrap/cache/
```

### 5. Выполнение команд на сервере

Если есть SSH доступ, выполните:
```bash
php artisan key:generate
php artisan migrate
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Если SSH нет - создайте файл `deploy.php` в корне сайта:
```php
<?php
// Временный файл для настройки - УДАЛИТЬ ПОСЛЕ ИСПОЛЬЗОВАНИЯ!
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

echo "Выполняем миграции...\n";
$kernel->call('migrate', ['--force' => true]);

echo "Кэшируем конфигурацию...\n";
$kernel->call('config:cache');

echo "Готово! УДАЛИТЕ ЭТОТ ФАЙЛ!\n";
?>
```

### 6. Настройка веб-сервера

**Для Apache (.htaccess уже настроен):**
- Убедитесь что DocumentRoot указывает на папку `public/`

**Для Nginx:**
```nginx
server {
    listen 80;
    server_name ваш-домен.com;
    root /path/to/your/project/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## Проверка работы

1. Откройте ваш сайт в браузере
2. Проверьте что:
   - ✅ Главная страница загружается
   - ✅ События отображаются
   - ✅ Клики по коэффициентам работают
   - ✅ Купон функционирует
   - ✅ История ставок показывается

## Возможные проблемы

**500 Internal Server Error:**
- Проверьте права на папки `storage/` и `bootstrap/cache/`
- Проверьте настройки БД в `.env`
- Посмотрите логи в `storage/logs/laravel.log`

**Белая страница:**
- Включите отображение ошибок PHP временно
- Проверьте что `vendor/` папка загружена полностью

**Стили не загружаются:**
- Проверьте что папка `public/build/` загружена
- Проверьте настройку `APP_URL` в `.env`

## Безопасность

После деплоя:
1. **Удалите** `deploy.php` если создавали
2. **Проверьте** что `.env` не доступен через браузер
3. **Установите** SSL сертификат
4. **Настройте** регулярные бэкапы БД

---

**Готово!** Ваше приложение должно работать на хостинге.