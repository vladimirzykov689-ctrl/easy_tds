<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// ── Helpers ───────────────────────────────────────────────────────────────────

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    jsonResponse(['ok' => false, 'error' => $message], $code);
}

// ── Авторизация по API ключу ──────────────────────────────────────────────────

$apiKey = trim($_GET['secret'] ?? '');

if (empty($apiKey)) {
    jsonError('Forbidden: missing secret', 403);
}

if (!defined('API_KEY_HASH') || empty(API_KEY_HASH)) {
    jsonError('Forbidden: API key not configured', 403);
}

if (!password_verify($apiKey, API_KEY_HASH)) {
    jsonError('Forbidden: invalid secret', 403);
}

// ── Роутинг ───────────────────────────────────────────────────────────────────

$action = trim($_GET['action'] ?? '');
$db     = initDB();

switch ($action) {

    // ── Общая статистика ──────────────────────────────────────────────────────
    case 'stats':
        $date_from = $_GET['date_from'] ?? '';
        $date_to   = $_GET['date_to']   ?? '';

        $where  = "1=1";
        $params = [];
        if ($date_from && $date_to) {
            $where   .= " AND DATE(timestamp) BETWEEN ? AND ?";
            $params[] = $date_from;
            $params[] = $date_to;
        }

        $stmt = $db->prepare("SELECT ip, device, keyword, geo FROM logs WHERE $where");
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalClicks = count($logs);
        $uniqueIps   = count(array_unique(array_column($logs, 'ip')));
        $botCount    = 0;
        $devices     = ['desktop' => 0, 'mobile' => 0];
        $geoCounts   = [];

        foreach ($logs as $row) {
            $keywords = array_map('trim', explode(',', $row['keyword'] ?? ''));
            if (in_array('bot', $keywords)) $botCount++;

            $dev = $row['device'] ?? 'desktop';
            if (!isset($devices[$dev])) $devices[$dev] = 0;
            $devices[$dev]++;

            $geo = $row['geo'] ?: 'UNKNOWN';
            if (!isset($geoCounts[$geo])) $geoCounts[$geo] = 0;
            $geoCounts[$geo]++;
        }

        arsort($geoCounts);
        $topGeo = array_slice($geoCounts, 0, 5, true);

        // Профит
        $stmtP = $db->query("
            SELECT COALESCE(SUM(c.value), 0)
            FROM conversions c
            JOIN goals g ON g.id = c.goal_id
            WHERE g.is_revenue = 1
        ");
        $totalProfit = round((float)$stmtP->fetchColumn(), 2);

        // Топ-3 кампании по профиту
        $stmtTop = $db->query("
            SELECT s.name, COALESCE(SUM(c.value), 0) AS profit
            FROM conversions c
            JOIN goals g ON g.id = c.goal_id
            JOIN streams s ON s.id = c.stream_id
            WHERE g.is_revenue = 1
            GROUP BY c.stream_id
            ORDER BY profit DESC
            LIMIT 3
        ");
        $topCampaigns = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

        // Топ-3 цели по конверсиям
        $stmtGoals = $db->query("
            SELECT g.name, COUNT(c.id) AS cnt
            FROM conversions c
            JOIN goals g ON g.id = c.goal_id
            GROUP BY c.goal_id
            ORDER BY cnt DESC
            LIMIT 3
        ");
        $topGoals = $stmtGoals->fetchAll(PDO::FETCH_ASSOC);

        $totalCampaigns = (int)$db->query("SELECT COUNT(*) FROM streams")->fetchColumn();

        jsonResponse([
            'ok'               => true,
            'total_clicks'     => $totalClicks,
            'unique_ips'       => $uniqueIps,
            'bots'             => $botCount,
            'total_campaigns'  => $totalCampaigns,
            'total_profit'     => $totalProfit,
            'devices'          => $devices,
            'top_geo'          => $topGeo,
            'top_campaigns'    => $topCampaigns,
            'top_goals'        => $topGoals,
        ]);

    // ── Список всех кампаний ──────────────────────────────────────────────────
    case 'campaigns':
        $stmt = $db->query("
            SELECT
                s.id,
                s.name,
                s.slug,
                s.url,
                COUNT(DISTINCT l.id)   AS total_clicks,
                COUNT(DISTINCT l.ip)   AS unique_ips,
                COALESCE(SUM(CASE WHEN g.is_revenue = 1 THEN c.value ELSE 0 END), 0) AS profit
            FROM streams s
            LEFT JOIN logs l        ON l.stream_id = s.id
            LEFT JOIN conversions c ON c.stream_id = s.id
            LEFT JOIN goals g       ON g.id = c.goal_id
            GROUP BY s.id
            ORDER BY s.id ASC
        ");
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($campaigns as &$c) {
            $c['profit']       = round((float)$c['profit'], 2);
            $c['total_clicks'] = (int)$c['total_clicks'];
            $c['unique_ips']   = (int)$c['unique_ips'];
        }
        unset($c);

        jsonResponse([
            'ok'        => true,
            'count'     => count($campaigns),
            'campaigns' => $campaigns,
        ]);

    // ── Статистика конкретной кампании ────────────────────────────────────────
    case 'campaign':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('Missing parameter: id');

        $stmtS = $db->prepare("SELECT * FROM streams WHERE id = ?");
        $stmtS->execute([$id]);
        $stream = $stmtS->fetch(PDO::FETCH_ASSOC);
        if (!$stream) jsonError('Campaign not found', 404);

        $date_from = $_GET['date_from'] ?? '';
        $date_to   = $_GET['date_to']   ?? '';

        $where  = "stream_id = ?";
        $params = [$id];
        if ($date_from && $date_to) {
            $where   .= " AND DATE(timestamp) BETWEEN ? AND ?";
            $params[] = $date_from;
            $params[] = $date_to;
        }

        $stmtL = $db->prepare("SELECT ip, device, keyword, geo FROM logs WHERE $where");
        $stmtL->execute($params);
        $logs = $stmtL->fetchAll(PDO::FETCH_ASSOC);

        $totalClicks = count($logs);
        $uniqueIps   = count(array_unique(array_column($logs, 'ip')));
        $botCount    = 0;
        $devices     = ['desktop' => 0, 'mobile' => 0];
        $geoCounts   = [];

        foreach ($logs as $row) {
            $keywords = array_map('trim', explode(',', $row['keyword'] ?? ''));
            if (in_array('bot', $keywords)) $botCount++;

            $dev = $row['device'] ?? 'desktop';
            if (!isset($devices[$dev])) $devices[$dev] = 0;
            $devices[$dev]++;

            $geo = $row['geo'] ?: 'UNKNOWN';
            if (!isset($geoCounts[$geo])) $geoCounts[$geo] = 0;
            $geoCounts[$geo]++;
        }

        arsort($geoCounts);
        $topGeo = array_slice($geoCounts, 0, 5, true);

        // Профит кампании
        $stmtP = $db->prepare("
            SELECT COALESCE(SUM(c.value), 0)
            FROM conversions c
            JOIN goals g ON g.id = c.goal_id
            WHERE c.stream_id = ? AND g.is_revenue = 1
        ");
        $stmtP->execute([$id]);
        $profit = round((float)$stmtP->fetchColumn(), 2);

        // Цели кампании с конверсиями
        $stmtG = $db->prepare("
            SELECT
                g.name,
                g.param_name,
                g.value_type,
                g.is_revenue,
                COUNT(c.id)              AS conversions,
                COALESCE(SUM(c.value), 0) AS total_value
            FROM goals g
            LEFT JOIN conversions c ON c.goal_id = g.id
            WHERE g.stream_id = ?
            GROUP BY g.id
            ORDER BY conversions DESC
        ");
        $stmtG->execute([$id]);
        $goals = $stmtG->fetchAll(PDO::FETCH_ASSOC);

        foreach ($goals as &$g) {
            $g['conversions']  = (int)$g['conversions'];
            $g['total_value']  = round((float)$g['total_value'], 2);
            $g['is_revenue']   = (bool)$g['is_revenue'];
        }
        unset($g);

        jsonResponse([
            'ok'           => true,
            'campaign'     => [
                'id'   => (int)$stream['id'],
                'name' => $stream['name'],
                'slug' => $stream['slug'],
                'url'  => $stream['url'],
            ],
            'total_clicks' => $totalClicks,
            'unique_ips'   => $uniqueIps,
            'bots'         => $botCount,
            'profit'       => $profit,
            'devices'      => $devices,
            'top_geo'      => $topGeo,
            'goals'        => $goals,
        ]);

    // ── Неизвестный action ────────────────────────────────────────────────────
    default:
        jsonError('Unknown action. Available: stats, campaigns, campaign');
}
