#!/bin/bash

set -e

INSTALL_DIR="/var/www/html/easy_tds"
NGINX_CONF="/etc/nginx/sites-enabled/easy_tds.conf"

echo "====================================="
echo "  Добро пожаловать в установщик Easy Tds"
echo "====================================="
echo
echo "Выберите режим:"
echo "1) Установка Easy Tds"
echo "2) Удаление Easy Tds"
read -p "Введите номер режима (1 или 2): " MODE

if [ "$MODE" == "2" ]; then
    echo
    echo "====================================="
    echo "  Удаление Easy Tds"
    echo "====================================="
    read -p "Вы уверены, что хотите удалить Easy Tds? (yes/no): " CONFIRM
    if [ "$CONFIRM" != "yes" ]; then
        echo "Удаление отменено."
        exit 0
    fi

    echo ">>> Остановка nginx и php-fpm..."
    systemctl stop nginx >/dev/null 2>&1
    systemctl stop php8.1-fpm >/dev/null 2>&1

    echo ">>> Удаление папки панели..."
    rm -rf $INSTALL_DIR

    echo ">>> Удаление конфигурации Nginx..."
    if [ -f $NGINX_CONF ]; then
        rm -f $NGINX_CONF
    fi

    echo ">>> Перезапуск nginx..."
    systemctl restart nginx

    echo "====================================="
    echo "  Easy Tds успешно удалён!"
    echo "====================================="
    exit 0
fi

# -----------------------------
# Установка Easy Tds
# -----------------------------

# Ввод данных пользователя
read -p "Придумайте логин для входа: " USER_LOGIN

while true; do
    read -s -p "Придумайте пароль: " USER_PASSWORD
    echo
    read -s -p "Подтвердите пароль: " USER_PASSWORD_CONFIRM
    echo
    if [ "$USER_PASSWORD" == "$USER_PASSWORD_CONFIRM" ]; then
        break
    else
        echo "Пароли не совпадают, попробуйте снова."
    fi
done

read -p "Введите домен для панели (например: example.com): " DOMAIN

read -p "Ограничить доступ по IP? (yes/no): " IP_RESTRICT
if [ "$IP_RESTRICT" == "yes" ]; then
    read -p "Введите разрешённые IP через запятую (без пробелов): " USER_IPS
else
    USER_IPS=""
fi

echo
echo "====================================="
echo "  Начало установки Easy Tds"
echo "====================================="

# -----------------------------
# Установка пакетов
# -----------------------------
echo ">>> Установка необходимых пакетов..."
apt update -y
apt install -y php8.1 php8.1-fpm php8.1-sqlite3 sqlite3 nginx git unzip composer

# Остановка apache, если есть
systemctl stop apache2 >/dev/null 2>&1
systemctl disable apache2 >/dev/null 2>&1

# -----------------------------
# Клонирование репозитория
# -----------------------------
echo ">>> Клонирование репозитория..."
rm -rf $INSTALL_DIR
git clone https://github.com/vladimirzykov689-ctrl/easy_tds.git $INSTALL_DIR
chown -R www-data:www-data $INSTALL_DIR
chmod -R 755 $INSTALL_DIR

# -----------------------------
# Создание базы SQLite
# -----------------------------
echo ">>> Создание базы данных SQLite..."
DB_DIR="$INSTALL_DIR/db"
DB_FILE="$DB_DIR/campaigns.db"
mkdir -p $DB_DIR
rm -f $DB_FILE

sqlite3 $DB_FILE <<EOF
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

chown www-data:www-data $DB_FILE
chmod 664 $DB_FILE

# -----------------------------
# Создание config.php
# -----------------------------
echo ">>> Создание config.php..."

CONFIG_FILE="$INSTALL_DIR/config.php"
PASSWORD_HASH=$(php -r "echo password_hash('$USER_PASSWORD', PASSWORD_BCRYPT);")
SECRET_KEY=$(openssl rand -hex 16)

if [ "$IP_RESTRICT" == "yes" ]; then
    ENCRYPTED_IPS=$(php -r "echo openssl_encrypt('$USER_IPS','aes-256-cbc','$SECRET_KEY',0,substr('$SECRET_KEY',0,16));")
else
    ENCRYPTED_IPS=""
fi

cat > $CONFIG_FILE <<EOL
<?php
session_start();

define('DB_FILE', __DIR__ . '/db/campaigns.db');
define('SECRET_KEY', '$SECRET_KEY');

\$CREDENTIALS = [
    '$USER_LOGIN' => '$PASSWORD_HASH'
];

\$ENCRYPTED_IPS = '$ENCRYPTED_IPS';

function getDB() {
    \$db = new PDO('sqlite:' . DB_FILE);
    \$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    \$db->exec("
        CREATE TABLE IF NOT EXISTS streams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            url TEXT NOT NULL,
            geo_filter_type TEXT DEFAULT 'none',
            geo_filter_list TEXT
        );
    ");

    \$db->exec("
        CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stream_id INTEGER NOT NULL,
            device TEXT NOT NULL,
            ip TEXT,
            geo TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    return \$db;
}

function checkAuth() {
    if(!isset(\$_SESSION['username'])) {
        header('Location: login.php');
        exit;
    }

    global \$ENCRYPTED_IPS;
    if (!empty(\$ENCRYPTED_IPS)) {
        \$decrypted_ips = openssl_decrypt(\$ENCRYPTED_IPS, 'aes-256-cbc', SECRET_KEY, 0, substr(SECRET_KEY,0,16));
        \$allowed_ips = explode(',', \$decrypted_ips);
        if (!in_array(\$_SERVER['REMOTE_ADDR'], \$allowed_ips)) {
            header('HTTP/1.0 403 Forbidden');
            exit('Access denied: your IP is not allowed.');
        }
    }
}

function authenticate(\$user, \$pass) {
    global \$CREDENTIALS;
    if (isset(\$CREDENTIALS[\$user]) && password_verify(\$pass, \$CREDENTIALS[\$user])) {
        \$_SESSION['username'] = \$user;
        return true;
    }
    return false;
}
EOL

chown www-data:www-data $CONFIG_FILE
chmod 600 $CONFIG_FILE

# -----------------------------
# Установка GeoLite2
# -----------------------------
echo ">>> Установка библиотеки GeoLite2 (PHP GeoIP2)..."
GEO_DIR="$INSTALL_DIR/geo"
mkdir -p $GEO_DIR
cd $INSTALL_DIR
composer require geoip2/geoip2 >/dev/null 2>&1
cd -

# -----------------------------
# Настройка Nginx
# -----------------------------
echo ">>> Настройка Nginx..."
cat > $NGINX_CONF <<EOL
server {
    listen 80;
    server_name $DOMAIN;

    root $INSTALL_DIR;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
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

systemctl restart php8.1-fpm
systemctl restart nginx

echo
echo "====================================="
echo "  Установка Easy Tds завершена!"
echo "  Панель доступна по адресу: http://$DOMAIN"
echo "====================================="
