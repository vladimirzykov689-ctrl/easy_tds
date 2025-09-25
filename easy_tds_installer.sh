#!/bin/bash
set -e

REPO="https://github.com/vladimirzykov689-ctrl/easy_tds.git"
INSTALL_DIR="/var/www/html/easy_tds"
NGINX_CONF="/etc/nginx/sites-available/default"

echo "=============================="
echo "Добро пожаловать в установщик Easy Tds"
echo "=============================="

echo "Режимы установщика:"
echo "1) Установка Easy Tds"
echo "2) Удаление Easy Tds"
read -rp "Выберите режим (1/2): " MODE

if [[ "$MODE" == "2" ]]; then
    echo "Удаляем Easy Tds..."
    sudo rm -rf "$INSTALL_DIR"
    sudo rm -f "$NGINX_CONF"
    sudo systemctl reload nginx || true
    echo "Удаление завершено!"
    exit 0
fi

read -rp "Введите желаемый логин: " PANEL_USER
while true; do
    read -rp "Введите желаемый пароль: " PANEL_PASS
    echo
    read -rp "Подтвердите свой пароль: " PANEL_PASS_CONFIRM
    echo
    [[ "$PANEL_PASS" == "$PANEL_PASS_CONFIRM" ]] && break
    echo "Пароли не совпадают, попробуйте снова."
done

read -rp "Ограничить доступ по IP? (да/нет): " IP_RESTRICT
ALLOWED_IPS=""
if [[ "$IP_RESTRICT" =~ ^(да)$ ]]; then
    read -rp "Введите IP-адреса через запятую (без пробелов): " ALLOWED_IPS
fi

echo "=============================="
echo "Начало установки Easy Tds"
echo "=============================="

export DEBIAN_FRONTEND=noninteractive
sudo systemctl mask packagekit.service || true
sudo systemctl stop packagekit.service || true

sudo apt update

sudo apt install -y \
php8.1 php8.1-fpm php8.1-curl php8.1-mbstring php8.1-xml php8.1-zip php8.1-sqlite3 \
sqlite3 git unzip curl composer nginx -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"

sudo systemctl stop apache2

sudo mkdir -p "$INSTALL_DIR"
sudo chown -R $USER:$USER "$INSTALL_DIR"

git clone "$REPO" "$INSTALL_DIR"
rm -rf /var/www/html/easy_tds/easy_tds_installer.sh
rm -rf /var/www/html/easy_tds/.git

mkdir -p "$INSTALL_DIR/db"
mkdir -p "$INSTALL_DIR/geo"

if ! command -v composer >/dev/null 2>&1; then
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1
    rm composer-setup.php
fi

cd "$INSTALL_DIR/geo"
export COMPOSER_ALLOW_SUPERUSER=1
composer init --name="easytds/geolite2" --require="geoip2/geoip2:^3.2" --no-interaction >/dev/null 2>&1
composer install --no-interaction --no-progress >/dev/null 2>&1
cd -

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

sudo tee "$NGINX_CONF" > /dev/null <<EOL
server {
    listen 80 default_server;
    listen [::]:80 default_server;

    server_name _;

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

sudo nginx -t && sudo systemctl reload nginx

PANEL_USER_HASH=$(php -r "echo password_hash('$PANEL_USER', PASSWORD_DEFAULT);")
PANEL_PASS_HASH=$(php -r "echo password_hash('$PANEL_PASS', PASSWORD_DEFAULT);")

cat > "$INSTALL_DIR/config.php" <<PHP
<?php
session_start();

define('DB_FILE', __DIR__ . '/db/campaigns.db');

define('PANEL_USER_HASH', '$PANEL_USER_HASH');
define('PANEL_PASS_HASH', '$PANEL_PASS_HASH');

\$ALLOWED_IPS = '$ALLOWED_IPS';

function getDB() {
    \$db = new PDO('sqlite:' . DB_FILE);
    \$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return \$db;
}

function checkIP() {
    global \$ALLOWED_IPS;

    if (!empty(\$ALLOWED_IPS)) {
        if (!empty(\$_SERVER['HTTP_CF_CONNECTING_IP'])) {
            \$clientIP = \$_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty(\$_SERVER['HTTP_X_FORWARDED_FOR'])) {
            \$clientIP = trim(explode(',', \$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        } else {
            \$clientIP = \$_SERVER['REMOTE_ADDR'];
        }

        \$ips = array_map('trim', explode(',', \$ALLOWED_IPS));

        if (!in_array(\$clientIP, \$ips)) {
            header('HTTP/1.0 403 Forbidden');
            exit('Access denied: your IP is not allowed. Your IP: ' . \$clientIP);
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

sudo chown www-data:www-data $INSTALL_DIR
sudo chmod -R 770 "$INSTALL_DIR/db"
sudo chmod 644 "$INSTALL_DIR/bots"
sudo chmod 644 "$INSTALL_DIR/css"
sudo chmod 644 "$INSTALL_DIR/geo"
sudo chmod 644 "$INSTALL_DIR/img"
sudo chmod 644 "$INSTALL_DIR/config.php"
sudo chmod 644 "$INSTALL_DIR/dashboard.php"
sudo chmod 644 "$INSTALL_DIR/new_campaign.php"
sudo chmod 644 "$INSTALL_DIR/login.php"
sudo chmod 644 "$INSTALL_DIR/stats.php"
sudo chmod 644 "$INSTALL_DIR/stream.php"
sudo chmod 644 "$INSTALL_DIR/logout.php"

echo "=============================="
echo "Установка Easy Tds завершена!"
echo "Доступ: your_domain/login.php"
echo "Логин: $PANEL_USER"
echo "Пароль: $PANEL_PASS"
echo "=============================="
