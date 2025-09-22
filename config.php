<?php
session_start();

define('DB_FILE', __DIR__ . '/db/campaigns.db');

$CREDENTIALS = [
    'admin' => 'admin'
];


function getDB() {
    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("
        CREATE TABLE IF NOT EXISTS streams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            url TEXT NOT NULL,
            geo_filter_type TEXT DEFAULT 'none',
            geo_filter_list TEXT
        );
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stream_id INTEGER NOT NULL,
            device TEXT NOT NULL,
            ip TEXT,
            geo TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    return $db;
}


function checkAuth() {
    if (!isset($_SESSION['username'])) {
        header('Location: login.php');
        exit;
    }
}
