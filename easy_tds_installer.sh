#!/bin/bash
set -e

REPO="https://github.com/vladimirzykov689-ctrl/easy_tds.git"
INSTALL_DIR="/var/www/html/easy_tds"
NGINX_CONF="/etc/nginx/sites-enabled/easy_tds.conf"

echo "=============================="
echo "Добро пожаловать в установщик Easy Tds"
echo "=============================="

# --- Выбор режима ---
echo "Выберите режим:"
echo "1) Установка Easy Tds"
echo "2) Удаление Easy Tds"
read -rp "Введите номер: " MODE

if [[ "$MODE" == "2" ]]; then
    echo "Удаляем Easy Tds..."
    sudo rm -rf "$INSTALL_DIR"
    sudo rm -f "$NGINX_CONF"
    sudo systemctl reload nginx || true
    echo "Удаление завершено!"
    exit 0
fi

read -rp "Введите логин: " PANEL_USER
while true; do
    read -srp "Введите пароль: " PANEL_PASS
    echo
    read -srp "Подтвердите пароль: " PANEL_PASS_CONFIRM
    echo
    [[ "$PANEL_PASS" == "$PANEL_PASS_CONFIRM" ]] && break
    echo "Пароли не совпадают, попробуйте снова."
done

read -rp "Введите домен для панели: " PANEL_DOMAIN
read -rp "Ограничить доступ по IP? (да/нет): " IP_RESTRICT
ALLOWED_IPS=""
if [[ "$IP_RESTRICT" =~ ^(да|Да|yes|Yes)$ ]]; then
    read -rp "Введите IP-адреса через запятую (без пробелов): " ALLOWED_IPS
fi

echo "=============================="
echo "Начало установки Easy Tds"
echo "=============================="

# --- Установка PHP и SQLite ---
export DEBIAN_FRONTEND=noninteractive
sudo apt update
sudo apt install -y \
php8.1 php8.1-fpm php8.1-curl php8.1-mbstring php8.1-xml php8.1-zip php8.1-sqlite3 \
sqlite3 git unzip curl composer nginx >/dev/null

sudo systemctl stop apache2 || true

sudo mkdir -p "$INSTALL_DIR"
sudo chown -R $USER:$USER "$INSTALL_DIR"

# --- Клонируем репозиторий ---
git clone "$REPO" "$INSTALL_DIR"

mkdir -p "$INSTALL_DIR/db"
mkdir -p "$INSTALL_DIR/geo"

# --- Установка Composer (если не установлен) ---
if ! command -v composer >/dev/null 2>&1; then
    echo ">>> Установка Composer..."
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1
    rm composer-setup.php
fi

# --- Устанавливаем GeoLite2 через Composer ---
echo ">>> Установка GeoLite2 в $INSTALL_DIR/geo ..."
cd "$INSTALL_DIR/geo"
export COMPOSER_ALLOW_SUPERUSER=1
composer init --name="easytds/geolite2" --require="geoip2/geoip2:^3.2" --no-interaction >/dev/null 2>&1
composer install --no-interaction --no-progress >/dev/null 2>&1
cd -

# --- Создание базы SQLite ---
sqlite3 "$INSTALL_DIR/db/campaigns.db" <<EOF
CREATE TABLE IF NOT EXISTS streams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    url TEXT NOT NULL,
    geo_filter_type TEXT NOT NULL DEFAULT 'none',
    geo_filter_list TEXT,
    geo_redirect_urls TEXT,
    bot_filter TEXT NOT NULL DEFAULT 'off',
    bot_redirect_urls TEXT
);
CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stream_id INTEGER NOT NULL,
    device TEXT NOT NULL,
    ip TEXT NOT NULL,
    geo TEXT NOT NULL,
    provider TEXT,
    keyword TEXT,
    timestamp DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now','localtime')),
    useragent TEXT,
    ptr TEXT DEFAULT 'UNKNOWN'
);
EOF

# --- Выставляем правильные права на папку db и файл базы ---
sudo chown -R www-data:www-data "$INSTALL_DIR/db"
sudo chmod -R 770 "$INSTALL_DIR/db"

# --- Конфигурация Nginx ---
sudo tee "$NGINX_CONF" > /dev/null <<EOL
server {
    listen 80;
    server_name $PANEL_DOMAIN;

    root $INSTALL_DIR;
    index stream.php;

    location / {
        try_files \$uri \$uri/ /stream.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOL

sudo systemctl reload nginx || true

# --- Создание config.php ---
cat > "$INSTALL_DIR/config.php" <<PHP
<?php
session_start();

define('DB_FILE', __DIR__ . '/db/campaigns.db');
define('PANEL_USER', '$PANEL_USER');
define('PANEL_PASS', '$PANEL_PASS');
\$ALLOWED_IPS = '$ALLOWED_IPS';

function getDB() {
    \$db = new PDO('sqlite:' . DB_FILE);
    \$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return \$db;
}

function checkIP() {
    global \$ALLOWED_IPS;
    if (!empty(\$ALLOWED_IPS)) {
        \$ips = explode(',', \$ALLOWED_IPS);
        if (!in_array(\$_SERVER['REMOTE_ADDR'], \$ips)) {
            header('HTTP/1.0 403 Forbidden');
            exit('Access denied: your IP is not allowed.');
        }
    }
}

function checkAuth() {
    checkIP();
    if (!isset(\$_SESSION['username'])) {
        header('Location: login.php');
        exit;
    }
}
PHP

echo "=============================="
echo "Установка Easy Tds завершена!"
echo "Домен панели: http://$PANEL_DOMAIN"
echo "Логин: $PANEL_USER"
echo "Пароль: $PANEL_PASS"
echo "=============================="
