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
    sudo tee "$NGINX_CONF" > /dev/null << 'EOF'
server {
listen 80 default_server;
listen [::]:80 default_server;

root /var/www/html;
index index.html index.htm index.nginx-debian.html;

server_name _;

location / {
try_files $uri $uri/ =404;
}
}
EOF
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

sudo systemctl stop apache2 || true

sudo mkdir -p "$INSTALL_DIR"
sudo chown -R $USER:$USER "$INSTALL_DIR"

git clone "$REPO" "$INSTALL_DIR"
rm -rf "$INSTALL_DIR/easy_tds_installer.sh"
rm -rf "$INSTALL_DIR/.git"

mkdir -p "$INSTALL_DIR/db"
touch "$INSTALL_DIR/db/tds.db"

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

# Генерация config.php через временный файл
PANEL_USER_ESC=$(printf '%s' "$PANEL_USER" | sed "s/'/\\\\'/g")
PANEL_PASS_ESC=$(printf '%s' "$PANEL_PASS" | sed "s/'/\\\\'/g")
ALLOWED_IPS_ESC=$(printf '%s' "$ALLOWED_IPS" | sed "s/'/\\\\'/g")

php -r "
\$userHash = password_hash('${PANEL_USER_ESC}', PASSWORD_BCRYPT);
\$passHash = password_hash('${PANEL_PASS_ESC}', PASSWORD_BCRYPT);
\$allowedIps = '${ALLOWED_IPS_ESC}';
\$installDir = '${INSTALL_DIR}';

\$c  = '<?php' . PHP_EOL;
\$c .= 'session_start();' . PHP_EOL;
\$c .= '\\\$ALLOWED_IPS = \"' . \$allowedIps . '\";' . PHP_EOL;
\$c .= 'define(\'PANEL_USER_HASH\', \'' . \$userHash . '\');' . PHP_EOL;
\$c .= 'define(\'PANEL_PASS_HASH\', \'' . \$passHash . '\');' . PHP_EOL;
\$c .= 'define(\'DB_PATH\', \'' . \$installDir . '/db/tds.db\');' . PHP_EOL;
\$c .= '' . PHP_EOL;
\$c .= 'function getDB() {' . PHP_EOL;
\$c .= '    \\\$db = new PDO(\'sqlite:\' . DB_PATH);' . PHP_EOL;
\$c .= '    \\\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);' . PHP_EOL;
\$c .= '    return \\\$db;' . PHP_EOL;
\$c .= '}' . PHP_EOL;
\$c .= '' . PHP_EOL;
\$c .= 'function initDB() {' . PHP_EOL;
\$c .= '    \\\$db = getDB();' . PHP_EOL;
\$c .= '    \\\$db->exec(\"CREATE TABLE IF NOT EXISTS streams (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, url TEXT NOT NULL, geo_filter_type TEXT NOT NULL DEFAULT \'none\', geo_filter_list TEXT, geo_redirect_urls TEXT, bot_filter TEXT NOT NULL DEFAULT \'off\', bot_redirect_urls TEXT)\");' . PHP_EOL;
\$c .= '    \\\$db->exec(\"CREATE TABLE IF NOT EXISTS logs (id INTEGER PRIMARY KEY AUTOINCREMENT, stream_id INTEGER NOT NULL, device TEXT NOT NULL, ip TEXT NOT NULL, geo TEXT NOT NULL, provider TEXT, keyword TEXT, timestamp DATETIME NOT NULL DEFAULT (strftime(\'%Y-%m-%d %H:%M:%S\',\'now\',\'localtime\')), useragent TEXT, ptr TEXT DEFAULT \'UNKNOWN\')\");' . PHP_EOL;
\$c .= '    \\\$db->exec(\"CREATE TABLE IF NOT EXISTS bot_settings (id INTEGER PRIMARY KEY DEFAULT 1, filter_ip TEXT NOT NULL DEFAULT \'no\', filter_isp TEXT NOT NULL DEFAULT \'no\', filter_ptr TEXT NOT NULL DEFAULT \'no\', filter_ua TEXT NOT NULL DEFAULT \'no\')\");' . PHP_EOL;
\$c .= '    \\\$db->exec(\"INSERT OR IGNORE INTO bot_settings (id) VALUES (1)\");' . PHP_EOL;
\$c .= '    \\\$db->exec(\"CREATE INDEX IF NOT EXISTS idx_logs_stream_id ON logs(stream_id)\");' . PHP_EOL;
\$c .= '    \\\$db->exec(\"CREATE INDEX IF NOT EXISTS idx_logs_timestamp ON logs(timestamp)\");' . PHP_EOL;
\$c .= '    \\\$db->exec(\"CREATE INDEX IF NOT EXISTS idx_logs_geo ON logs(geo)\");' . PHP_EOL;
\$c .= '    \\\$db->exec(\"CREATE INDEX IF NOT EXISTS idx_logs_device ON logs(device)\");' . PHP_EOL;
\$c .= '    \\\$db->exec(\"CREATE INDEX IF NOT EXISTS idx_logs_keyword ON logs(keyword)\");' . PHP_EOL;
\$c .= '    return \\\$db;' . PHP_EOL;
\$c .= '}' . PHP_EOL;
\$c .= '' . PHP_EOL;
\$c .= 'function checkIP() {' . PHP_EOL;
\$c .= '    global \\\$ALLOWED_IPS;' . PHP_EOL;
\$c .= '    if (!empty(\\\$ALLOWED_IPS)) {' . PHP_EOL;
\$c .= '        if (!empty(\\\$_SERVER[\'HTTP_CF_CONNECTING_IP\'])) {' . PHP_EOL;
\$c .= '            \\\$clientIP = \\\$_SERVER[\'HTTP_CF_CONNECTING_IP\'];' . PHP_EOL;
\$c .= '        } elseif (!empty(\\\$_SERVER[\'HTTP_X_FORWARDED_FOR\'])) {' . PHP_EOL;
\$c .= '            \\\$clientIP = trim(explode(\',\', \\\$_SERVER[\'HTTP_X_FORWARDED_FOR\'])[0]);' . PHP_EOL;
\$c .= '        } else {' . PHP_EOL;
\$c .= '            \\\$clientIP = \\\$_SERVER[\'REMOTE_ADDR\'];' . PHP_EOL;
\$c .= '        }' . PHP_EOL;
\$c .= '        \\\$ips = array_map(\'trim\', explode(\',\', \\\$ALLOWED_IPS));' . PHP_EOL;
\$c .= '        if (!in_array(\\\$clientIP, \\\$ips)) {' . PHP_EOL;
\$c .= '            header(\'HTTP/1.0 403 Forbidden\');' . PHP_EOL;
\$c .= '            exit(\'Access denied: your IP is not allowed. Your IP: \' . \\\$clientIP);' . PHP_EOL;
\$c .= '        }' . PHP_EOL;
\$c .= '    }' . PHP_EOL;
\$c .= '}' . PHP_EOL;
\$c .= '' . PHP_EOL;
\$c .= 'function checkAuth() {' . PHP_EOL;
\$c .= '    checkIP();' . PHP_EOL;
\$c .= '    if (!isset(\\\$_SESSION[\'username\'])) {' . PHP_EOL;
\$c .= '        header(\'Location: login.php\');' . PHP_EOL;
\$c .= '        exit;' . PHP_EOL;
\$c .= '    }' . PHP_EOL;
\$c .= '}' . PHP_EOL;

file_put_contents('${INSTALL_DIR}/config.php', \$c);
echo 'config.php создан успешно' . PHP_EOL;
"

sudo tee "$NGINX_CONF" > /dev/null << 'EOF'
server {
listen 80 default_server;
listen [::]:80 default_server;

root /var/www/html/easy_tds;
index index.php index.html;

server_name _;

location / {
try_files $uri $uri/ /stream.php;
}

location ~ \.php$ {
include snippets/fastcgi-php.conf;
fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
}
}
EOF

sudo chown -R www-data:www-data "$INSTALL_DIR"

sudo find "$INSTALL_DIR" -type d -exec chmod 755 {} \;
sudo chmod 770 "$INSTALL_DIR/db"

sudo find "$INSTALL_DIR" -type f -exec chmod 644 {} \;
sudo chmod 640 "$INSTALL_DIR/config.php"
sudo chmod 660 "$INSTALL_DIR/db/tds.db"

sudo systemctl restart php8.1-fpm
sudo systemctl reload nginx

echo "=============================="
echo "Установка Certbot для управления SSL из панели..."
sudo apt install -y certbot python3-certbot-nginx -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"

(crontab -l 2>/dev/null | grep -q 'certbot renew') || (crontab -l 2>/dev/null; echo "0 3 * * * certbot renew --quiet --nginx") | crontab -
(crontab -l 2>/dev/null | grep -q 'reload nginx') || (crontab -l 2>/dev/null; echo "30 3 * * * systemctl reload nginx") | crontab -

echo "www-data ALL=(ALL) NOPASSWD: /usr/bin/certbot, /usr/sbin/nginx" | sudo tee /etc/sudoers.d/easytds-certbot > /dev/null
sudo chmod 440 /etc/sudoers.d/easytds-certbot

echo "=============================="
echo "Установка Easy Tds завершена!"
echo "Доступ: http://your_ip или your_domain/login.php"
echo "Логин: $PANEL_USER"
echo "Пароль: $PANEL_PASS"
echo "=============================="
