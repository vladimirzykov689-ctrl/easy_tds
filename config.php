<?php
session_start();
$ALLOWED_IPS = '';
define('PANEL_USER_HASH', '$2y$10$Tv/AHMbRn/rFXnQcyy/GtusPNEaNrYze/S0gUFb9F9JVOzLoVd8bO');
define('PANEL_PASS_HASH', '$2y$10$qQih3sjG8.Rd5mUFRu8sie8b.L2.zJN3Mm5PS/0oBCeq1gI.3p1vK');
define('API_KEY_HASH', '$2y$10$NPi8pBMSkunsDdWr9qe8t.uNAHVBlOwnNud.U5KxaeLEhlsuzDvNW');
define('DB_PATH', '/var/www/html/easy_tds/db/campaigns.db');

function getDB() {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

function initDB() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS streams (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, url TEXT NOT NULL, geo_filter_type TEXT NOT NULL DEFAULT 'none', geo_filter_list TEXT, geo_redirect_urls TEXT, bot_filter TEXT NOT NULL DEFAULT 'off', bot_redirect_urls TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS logs (id INTEGER PRIMARY KEY AUTOINCREMENT, stream_id INTEGER NOT NULL, device TEXT NOT NULL, ip TEXT NOT NULL, geo TEXT NOT NULL, provider TEXT, keyword TEXT, timestamp DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now','localtime')), useragent TEXT, ptr TEXT DEFAULT 'UNKNOWN', click_id TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS bot_settings (id INTEGER PRIMARY KEY DEFAULT 1, filter_ip TEXT NOT NULL DEFAULT 'no', filter_isp TEXT NOT NULL DEFAULT 'no', filter_ptr TEXT NOT NULL DEFAULT 'no', filter_ua TEXT NOT NULL DEFAULT 'no')");
    $db->exec("CREATE TABLE IF NOT EXISTS goals (id INTEGER PRIMARY KEY AUTOINCREMENT, stream_id INTEGER NOT NULL, name TEXT NOT NULL, param_name TEXT NOT NULL, value_type TEXT NOT NULL DEFAULT 'flag', is_revenue INTEGER DEFAULT 0, currency TEXT DEFAULT NULL, target_value TEXT DEFAULT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS conversions (id INTEGER PRIMARY KEY AUTOINCREMENT, click_id TEXT NOT NULL, stream_id INTEGER NOT NULL, goal_id INTEGER NOT NULL, value REAL DEFAULT 0, created_at DATETIME DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now','localtime')))");
    $db->exec("INSERT OR IGNORE INTO bot_settings (id) VALUES (1)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_stream_id ON logs(stream_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_timestamp ON logs(timestamp)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_geo ON logs(geo)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_device ON logs(device)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_keyword ON logs(keyword)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_click_id ON logs(click_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_goals_stream_id ON goals(stream_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_conversions_click_id ON conversions(click_id)");
    return $db;
}

function checkIP() {
    global $ALLOWED_IPS;
    if (!empty($ALLOWED_IPS)) {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $clientIP = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $clientIP = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        } else {
            $clientIP = $_SERVER['REMOTE_ADDR'];
        }
        $ips = array_map('trim', explode(',', $ALLOWED_IPS));
        if (!in_array($clientIP, $ips)) {
            header('HTTP/1.0 403 Forbidden');
            exit('Access denied: your IP is not allowed. Your IP: ' . $clientIP);
        }
    }
}

function checkAuth() {
    checkIP();
    if (!isset($_SESSION['username'])) {
        header('Location: login.php');
        exit;
    }
}
