#!/bin/bash
set -e

REPO="https://github.com/vladimirzykov689-ctrl/easy_tds.git"
INSTALL_DIR="/var/www/html/easy_tds"
NGINX_CONF="/etc/nginx/sites-enabled/easy_tds.conf"

# --- Логин и пароль панели ---
read -rp "Введите логин панели: " PANEL_USER
while true; do
    read -rp "Введите пароль панели: " PANEL_PASS
    echo
    read -rp "Подтвердите пароль: " PANEL_PASS_CONFIRM
    echo
    [[ "$PANEL_PASS" == "$PANEL_PASS_CONFIRM" ]] && break
    echo "Пароли не совпадают, попробуйте снова."
done

read -rp "Введите домен для панели: " PANEL_DOMAIN
read -rp "Ограничить доступ по IP? (да/нет): " IP_RESTRICT
ALLOWED_IPS=""
if [[ "$IP_RESTRICT" =~ ^(да|Да|yes|Yes)$ ]]; then
    read -rp "Введите IP-адреса через запятую (без пробелов): " ALLOWED_IPS
    ALLOWED_IPS=$(echo "$ALLOWED_IPS" | tr -d ' ')
fi

# --- Установка PHP и Nginx ---
sudo apt update
sudo apt install -y php8.1 php8.1-fpm php8.1-curl php8.1-mbstring php8.1-xml php8.1-zip \
sqlite3 git unzip curl composer nginx

# --- Клонируем репозиторий ---
sudo mkdir -p "$INSTALL_DIR"
sudo chown -R $USER:$USER "$INSTALL_DIR"
git clone "$REPO" "$INSTALL_DIR"
mkdir -p "$INSTALL_DIR/db" "$INSTALL_DIR/geo"

# --- Установка GeoLite2 локально ---
cd "$INSTALL_DIR/geo"
export COMPOSER_ALLOW_SUPERUSER=1
composer init --name="easytds/geolite2" --require="geoip2/geoip2:^3.2" --no-interaction >/dev/null 2>&1
composer install --no-interaction --no-progress >/dev/null 2>&1
cd -

# --- Создаём SQLite базу с корректными правами ---
DB_FILE="$INSTALL_DIR/db/campaigns.db"
if [ ! -f "$DB_FILE" ]; then
    sqlite3 "$DB_FILE" <<EOF
CREATE TABLE streams (
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
CREATE TABLE logs (
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
    sudo chown -R www-data:www-data "$INSTALL_DIR/db"
    sudo chmod 660 "$DB_FILE"
fi

# --- Генерация ключа и шифрование пароля и IP ---
SECRET_KEY=$(openssl rand -hex 32)

ENCRYPTED_PANEL_PASS=$(php -r "
echo base64_encode(substr(\$iv = openssl_random_pseudo_bytes(16),0,16) . openssl_encrypt('$PANEL_PASS','AES-256-CBC','$SECRET_KEY',OPENSSL_RAW_DATA,substr('$SECRET_KEY',0,16)));
")

if [[ -n "$ALLOWED_IPS" ]]; then
    ENCRYPTED_IPS=$(php -r "
echo base64_encode(substr(\$iv = openssl_random_pseudo_bytes(16),0,16) . openssl_encrypt('$ALLOWED_IPS','AES-256-CBC','$SECRET_KEY',OPENSSL_RAW_DATA,substr('$SECRET_KEY',0,16)));
")
else
    ENCRYPTED_IPS=""
fi

# --- Создаём config.php ---
cat > "$INSTALL_DIR/config.php" <<PHP
<?php
session_start();

define('DB_FILE', __DIR__ . '/db/campaigns.db');
define('SECRET_KEY', '$SECRET_KEY');
define('ENCRYPTED_PANEL_PASS', '$ENCRYPTED_PANEL_PASS');
define('ENCRYPTED_IPS', '$ENCRYPTED_IPS');
define('PANEL_USER', '$PANEL_USER');

function decrypt(\$encrypted) {
    \$data = base64_decode(\$encrypted);
    \$iv = substr(\$data,0,16);
    \$ciphertext = substr(\$data,16);
    return openssl_decrypt(\$ciphertext,'AES-256-CBC',SECRET_KEY,OPENSSL_RAW_DATA,\$iv);
}

function getDB() {
    \$db = new PDO('sqlite:' . DB_FILE);
    \$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    return \$db;
}

function checkIP() {
    if(!empty(ENCRYPTED_IPS)){
        \$ips = explode(',', decrypt(ENCRYPTED_IPS));
        if(!in_array(\$_SERVER['REMOTE_ADDR'],\$ips)){
            header('HTTP/1.0 403 Forbidden');
            exit('Access denied: your IP is not allowed.');
        }
    }
}

function checkAuth() {
    checkIP();
    if(!isset(\$_SESSION['username'])){
        header('Location: login.php');
        exit;
    }
}
PHP

# --- Настройка Nginx ---
sudo tee /etc/nginx/sites-enabled/easy_tds.conf > /dev/null <<EOL
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

# --- Проверка Nginx и перезагрузка ---
sudo nginx -t
sudo systemctl reload nginx

echo "=============================="
echo "Установка завершена!"
echo "Доступ по адресу: http://$PANEL_DOMAIN/login.php"
echo "Логин: $PANEL_USER"
echo "Пароль: $PANEL_PASS"
echo "=============================="
