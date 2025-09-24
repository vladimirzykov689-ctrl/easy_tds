#!/bin/bash
set -e

REPO="https://github.com/vladimirzykov689-ctrl/easy_tds.git"
INSTALL_DIR="/var/www/html/easy_tds"
NGINX_CONF="/etc/nginx/sites-enabled/easy_tds.conf"

echo "=============================="
echo "Добро пожаловать в установщик Easy Tds"
echo "=============================="

echo "Режимы установщика:"
echo "1) Установка Easy Tds"
echo "2) Удаление Easy Tds"
read -rp "Введите режим установщика (1 или 2): " MODE

if [[ "$MODE" == "2" ]]; then
    echo "Удаляем Easy Tds..."
    sudo rm -rf "$INSTALL_DIR"
    sudo rm -f "$NGINX_CONF"
    sudo systemctl reload nginx || true
    echo "Удаление завершено!"
    exit 0
fi

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
fi

echo "=============================="
echo "Начало установки Easy Tds"
echo "=============================="

export DEBIAN_FRONTEND=noninteractive
sudo sed -i 's/#\$nrconf{restart} = .*/\$nrconf{restart} = "a";/' /etc/needrestart/needrestart.conf || true
sudo DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt update
sudo DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt install -y \
php8.1 php8.1-fpm php8.1-curl php8.1-mbstring php8.1-xml php8.1-zip \
sqlite3 sqlcipher git unzip curl composer nginx >/dev/null

sudo systemctl stop apache2 || true
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

# --- Создание базы SQLCipher с паролем панели ---
sqlcipher "$INSTALL_DIR/db/campaigns.db" <<EOF
PRAGMA key = '$PANEL_PASS';
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

# --- Конфиг Nginx ---
sudo tee "$NGINX_CONF" > /dev/null <<EOL
server {
    listen 80;
    server_name $PANEL_DOMAIN;

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
sudo systemctl reload nginx || true

# --- Генерация ключа и шифрование пароля и IP ---
SECRET_KEY=$(openssl rand -hex 32)

# Корректное шифрование с отдельным IV
ENCRYPTED_PANEL_PASS=$(php -r "
\$iv = openssl_random_pseudo_bytes(16);
\$enc = openssl_encrypt('$PANEL_PASS', 'AES-256-CBC', '$SECRET_KEY', OPENSSL_RAW_DATA, \$iv);
echo base64_encode(\$iv . \$enc);
")

if [[ -n "$ALLOWED_IPS" ]]; then
    ENCRYPTED_IPS=$(php -r "
    \$iv = openssl_random_pseudo_bytes(16);
    \$enc = openssl_encrypt('$ALLOWED_IPS', 'AES-256-CBC', '$SECRET_KEY', OPENSSL_RAW_DATA, \$iv);
    echo base64_encode(\$iv . \$enc);
    ")
else
    ENCRYPTED_IPS=""
fi

# --- Создание config.php ---
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
    \$password = decrypt(ENCRYPTED_PANEL_PASS); // тот же пароль для SQLCipher
    \$db->exec("PRAGMA key='\$password';");
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

rm -- "$0"

echo "=============================="
echo "Установка Easy Tds завершена!"
echo "Доступ по адресу: http://$PANEL_DOMAIN/login.php"
echo "Логин для входа: $PANEL_USER"
echo "Пароль для входа: $PANEL_PASS"
echo "=============================="
