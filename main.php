<?php
require 'config.php';
checkAuth();
checkIP();

// --- Список доступных фоновых тем (картинок) из папки img ---
$bgThemeDir = __DIR__ . "/img/backgrounds";
$bgThemes = [];
if (is_dir($bgThemeDir)) {
    foreach (glob($bgThemeDir . "/*.{jpg,jpeg,png,webp,gif}", GLOB_BRACE) as $bgFile) {
        $bgThemes[] = basename($bgFile);
    }
    sort($bgThemes);
}
$db = initDB();

$geo_json_path = file_exists(__DIR__ . '/geo/map/countries-110m.json') ? 'geo/map/countries-110m.json' : null;

$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';

$where  = "1=1";
$params = [];
if ($date_from && $date_to) {
    $where .= " AND DATE(timestamp) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
}

$stmt = $db->prepare("SELECT * FROM logs WHERE $where");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_logs = count($logs);
$unique     = count(array_unique(array_column($logs, 'ip')));

$data = ['desktop' => 0, 'mobile' => 0];
foreach ($logs as $row) {
    $device = $row['device'] ?? 'desktop';
    if (!isset($data[$device])) $data[$device] = 0;
    $data[$device]++;
}

$geo_counts = [];
foreach ($logs as $row) {
    $geo = $row['geo'] ?: 'UNKNOWN';
    if (!isset($geo_counts[$geo])) $geo_counts[$geo] = 0;
    $geo_counts[$geo]++;
}
arsort($geo_counts);
$geo_data   = array_slice($geo_counts, 0, 10, true);
$geo_labels = array_keys($geo_data);
$geo_values = array_values($geo_data);
$top_geo    = $geo_labels[0] ?? '—';

$botCount = 0;
foreach ($logs as $row) {
    $keywords = array_map('trim', explode(',', $row['keyword'] ?? ''));
    if (in_array('bot', $keywords)) $botCount++;
}

$total_campaigns = (int)$db->query("SELECT COUNT(*) FROM streams")->fetchColumn();

// ── Определяем доминирующую валюту ────────────────────────────────────────────
$dominantCurrency = 'USD';
$currencySymbols  = ['USD' => '$', 'EUR' => '€', 'RUB' => '₽'];
try {
    $stmtCur = $db->prepare("
        SELECT currency, COUNT(*) AS cnt
        FROM goals
        WHERE is_revenue = 1 AND currency IS NOT NULL
        GROUP BY currency
        ORDER BY cnt DESC
        LIMIT 1
    ");
    $stmtCur->execute();
    $row = $stmtCur->fetch(PDO::FETCH_ASSOC);
    if ($row) $dominantCurrency = $row['currency'];
} catch (Exception $e) { $dominantCurrency = 'USD'; }

$dominantSymbol = $currencySymbols[$dominantCurrency] ?? $dominantCurrency;

// ── Общий профит (только по доминирующей валюте) ──────────────────────────────
$totalProfit = 0;
try {
    $stmtP = $db->prepare("
        SELECT COALESCE(SUM(c.value), 0)
        FROM conversions c
        JOIN goals g ON g.id = c.goal_id
        WHERE g.is_revenue = 1 AND g.currency = ?
    ");
    $stmtP->execute([$dominantCurrency]);
    $totalProfit = (float)$stmtP->fetchColumn();
} catch (Exception $e) { $totalProfit = 0; }

// ── Самая профитная кампания (по доминирующей валюте) ─────────────────────────
$topCampaign = null;
try {
    $stmtTop = $db->prepare("
        SELECT s.name, COALESCE(SUM(c.value), 0) AS profit
        FROM conversions c
        JOIN goals g ON g.id = c.goal_id
        JOIN streams s ON s.id = c.stream_id
        WHERE g.is_revenue = 1 AND g.currency = ?
        GROUP BY c.stream_id
        ORDER BY profit DESC
        LIMIT 1
    ");
    $stmtTop->execute([$dominantCurrency]);
    $topCampaign = $stmtTop->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $topCampaign = null; }

// ── Топ-3 профитных кампании (по доминирующей валюте) ────────────────────────
$topCampaigns = [];
try {
    $stmtTopN = $db->prepare("
        SELECT s.name, COALESCE(SUM(c.value), 0) AS profit
        FROM conversions c
        JOIN goals g ON g.id = c.goal_id
        JOIN streams s ON s.id = c.stream_id
        WHERE g.is_revenue = 1 AND g.currency = ?
        GROUP BY c.stream_id
        ORDER BY profit DESC
        LIMIT 3
    ");
    $stmtTopN->execute([$dominantCurrency]);
    $topCampaigns = $stmtTopN->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $topCampaigns = []; }

// ── Топ-3 целей по конверсиям ─────────────────────────────────────────────────
$topGoals = [];
try {
    $stmtGoals = $db->prepare("
        SELECT g.name, COUNT(c.id) AS cnt
        FROM conversions c
        JOIN goals g ON g.id = c.goal_id
        WHERE g.is_revenue = 0
        GROUP BY c.goal_id
        ORDER BY cnt DESC
        LIMIT 3
    ");
    $stmtGoals->execute();
    $topGoals = $stmtGoals->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $topGoals = []; }
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Главная — Easy TDS</title>
<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="geo/map/topojson-client.min.js"></script>
<script src="geo/map/d3.min.js"></script>
</head>
<body class="dashboard-page">

<!-- ========== TOP HEADER ========== -->
<header class="top-header">
    <button class="hamburger-btn" id="hamburgerBtn" title="Свернуть меню" aria-label="Toggle sidebar">
        <span></span>
        <span></span>
        <span></span>
    </button>
    <a href="main.php" style="text-decoration:none; display:flex; align-items:center;">
    <img src="/img/logo.png" alt="Easy TDS" style="height:40px; width:auto;">
</a>

    <div class="header-right">
        <div class="theme-menu" id="themeMenu">
            <button class="theme-toggle-btn" id="themeToggleBtn" type="button" aria-label="Сменить тему">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 000 18c1.5 0 2-.8 2-1.8 0-.5-.2-.9-.5-1.2-.3-.3-.5-.7-.5-1.2 0-1 .8-1.8 1.8-1.8H16a3 3 0 003-3c0-4.4-3.1-9-7-9z"/><circle cx="8" cy="10" r="1"/><circle cx="12" cy="7" r="1"/><circle cx="16" cy="10" r="1"/></svg>
            </button>
            <div class="theme-dropdown" id="themeDropdown">
                <?php if (empty($bgThemes)): ?>
                    <span class="theme-option" style="cursor:default;color:#888;">Нет доступных тем</span>
                <?php else: ?>
                    <?php foreach ($bgThemes as $bgFile):
                        $bgLabel = pathinfo($bgFile, PATHINFO_FILENAME);
                    ?>
                    <button type="button" class="theme-option" data-file="<?= htmlspecialchars($bgFile) ?>"><?= htmlspecialchars($bgLabel) ?></button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="profile-menu" id="profileMenu">
            <button class="profile-avatar" id="profileAvatarBtn" type="button" aria-label="Профиль"><?= htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['username'] ?? 'A', 0, 1))) ?></button>
            <div class="profile-dropdown" id="profileDropdown">
                <a href="credentials.php">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 4a4 4 0 1 1 0 8 4 4 0 0 1 0-8zm0 10c4.418 0 8 1.79 8 4v1H4v-1c0-2.21 3.582-4 8-4z"/></svg>
                    <span>Учетная запись</span>
                </a>
                <a href="logout.php" style="color:#ff6666;">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M16 13v-2H7V8l-5 4 5 4v-3h9zm2-11H6a2 2 0 0 0-2 2v4h2V4h12v16H6v-4H4v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/></svg>
                    <span>Выйти</span>
                </a>
            </div>
        </div>
    </div>
</header>

<!-- ========== MAIN WRAPPER ========== -->
<div class="main-wrapper">

    <!-- ========== SIDEBAR ========== -->
    <nav class="sidebar" id="sidebar">
        <ul class="sidebar-nav">

            <li class="sidebar-section-label">Обзор</li>

            <li data-tooltip="Главная">
                <a href="main.php" class="active">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                        </svg>
                    </span>
                    <span class="nav-label">Главная</span>
                </a>
            </li>

            <li class="sidebar-section-label">Управление</li>

            <li data-tooltip="Кампании">
                <a href="campaigns.php">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M20 6h-3V4a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v2H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2zm-9-2h2v2h-2V4zm-2 0h2v2H9V4zm11 15H4V8h16v11z"/>
                        </svg>
                    </span>
                    <span class="nav-label">Кампании</span>
                </a>
            </li>

            <li data-tooltip="Фильтр ботов">
                <a href="bots.php">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h3a3 3 0 0 1 3 3v1h.5a1.5 1.5 0 0 1 0 3H19v1a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3v-1h-.5a1.5 1.5 0 0 1 0-3H5v-1a3 3 0 0 1 3-3h3V5.73A2 2 0 0 1 10 4a2 2 0 0 1 2-2zm-2 9a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm4 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm-5 5v1h6v-1H9z"/>
                        </svg>
                    </span>
                    <span class="nav-label">Фильтр ботов</span>
                </a>
            </li>

        </ul>
    </nav>
    <!-- /sidebar -->

    <!-- ========== PAGE CONTENT ========== -->
    <div class="page-content">
        <div class="content">

            <div class="page-header-bar">
                <div class="page-header-titles">
                    <h2 class="page-title">Главная</h2>
                    <div class="page-breadcrumb"><a href="main.php" class="page-breadcrumb-link">Easy TDS</a> <span>›</span> Обзор</div>
                </div>

                <form method="get" class="date-filter-form" id="dateFilterForm">
<button type="button" class="date-filter-pill" id="dateFilterBtn">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18"/><path d="M8 2.5v4"/><path d="M16 2.5v4"/></svg>
    <span id="dateFilterLabel">Все даты</span>
</button>
                    <input type="hidden" name="date_from" id="dateFromHidden" value="<?= htmlspecialchars($date_from) ?>">
                    <input type="hidden" name="date_to" id="dateToHidden" value="<?= htmlspecialchars($date_to) ?>">

                    <div class="date-calendar-dropdown" id="dateCalendarDropdown">
                        <div class="cal-header">
                            <button type="button" class="cal-nav-btn" id="calPrev">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                            </button>
                            <span id="calMonthLabel" class="cal-month-label"></span>
                            <button type="button" class="cal-nav-btn" id="calNext">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                            </button>
                        </div>
                        <div class="cal-weekdays" id="calWeekdays">
                            <span>Вс</span><span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span>
                        </div>
                        <div class="cal-days" id="calDays"></div>
                        <div class="cal-month-grid" id="calMonthGrid" style="display:none;"></div>
                        <div class="cal-footer">
                            <button type="button" id="calToday">Сегодня</button>
                            <button type="button" id="calClear">Сбросить</button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($total_logs === 0): ?>
                <div class="no-data">Нету статистики</div>
            <?php else: ?>
                
                <!-- ========== INFO CARDS ========== -->
            <div class="info-cards-row">

                <div class="info-cards-left-group">

                    <div class="info-card">
                        <div class="info-card-icon">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20 6h-3V4a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v2H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2zm-9-2h2v2h-2V4zm-2 0h2v2H9V4zm11 15H4V8h16v11z"/></svg>
                        </div>
                        <div class="info-card-text">
                            <div class="info-card-value"><?= $total_campaigns ?></div>
                            <h3>Активных кампаний</h3>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-icon">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2l2.4 7.2H22l-6 4.4 2.3 7.2-6.3-4.5L5.7 20.8 8 13.6 2 9.2h7.6z"/></svg>
                        </div>
                        <div class="info-card-text">
                            <div class="info-card-value">
                                <?php if ($topCampaign): ?>
                                    <?= htmlspecialchars($topCampaign['name']) ?>
                                <?php else: ?>
                                    <span class="info-card-placeholder">Нет данных</span>
                                <?php endif; ?>
                            </div>
                            <h3>Самая профитная кампания</h3>
                        </div>
                    </div>

                </div>

                <div class="info-card info-card-profit">
                    <div class="info-card-icon">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M21 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h17a1 1 0 0 0 1-1V8a1 1 0 0 0-1-1zM4 5h13a2 2 0 0 1 2 2H4a2 2 0 0 1 0-2zm14 10a2 2 0 1 1 0-4 2 2 0 0 1 0 4z"/></svg>
                    </div>
                    <div class="info-card-text">
                        <div class="info-card-value"><?= number_format($totalProfit, 2) . $dominantSymbol ?></div>
                        <h3>Общий профит</h3>
                    </div>
                </div>

            </div>
                
	                <div class="stats-row">

                    <!-- Карта + гео-список в одной карточке (шире) -->
                    <div class="map-block">
                        <h3>Гео трафика</h3>
                        <div id="geoMapWrapper" class="geo-map-relative">
                            <div id="geoMapD3"></div>

                            <input type="range" id="mapZoomSlider" class="map-zoom-slider" min="1" max="6" step="0.1" value="1" title="Масштаб карты">

                            <div class="geo-list-overlay">
                                <?php if (empty($geo_labels)): ?>
                                    <div class="geo-list-item">
                                        <span class="geo-list-name" style="color:#888;">Нет гео-данных</span>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($geo_labels as $i => $iso):
                                        $cnt = (int)$geo_values[$i];
                                        $pct = $total_logs > 0 ? round($cnt / $total_logs * 100) : 0;
                                        $isoLower = strtolower($iso);
                                    ?>
                                    <div class="geo-list-item">
                                        <?php if (strlen($iso) === 2): ?>
                                            <img src="https://flagcdn.com/24x18/<?= $isoLower ?>.png" class="geo-flag" alt="<?= htmlspecialchars($iso) ?>">
                                        <?php else: ?>
                                            <span class="geo-flag geo-flag-placeholder">?</span>
                                        <?php endif; ?>
                                        <div class="geo-list-info">
                                            <span class="geo-list-name"><?= htmlspecialchars($iso) ?></span>
                                            <span class="geo-list-clicks"><?= $cnt ?> кликов</span>
                                        </div>
                                        <span class="geo-list-pct"><?= $pct ?>%</span>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Трафик и девайсы справа (уже, чем карта) -->
                    <div class="side-stats-col">

                        <div class="device-sessions-card">
                            <h3>Трафик</h3>
                            <?php
                                $trafficStats = [
                                    'Клики' => [
                                        'val' => $total_logs, 'color' => '#ff2fd0',
                                        'icon' => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20 2L4 8.5l6.4 2.3L12.7 17 20 2z"/></svg>',
                                    ],
                                    'Уники' => [
                                        'val' => $unique, 'color' => '#22c1c3',
                                        'icon' => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.42 0-8 2.24-8 5v3h16v-3c0-2.76-3.58-5-8-5z"/></svg>',
                                    ],
                                    'Боты'  => [
                                        'val' => $botCount, 'color' => '#ff9f43',
                                        'icon' => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h3a3 3 0 0 1 3 3v1h.5a1.5 1.5 0 0 1 0 3H19v1a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3v-1h-.5a1.5 1.5 0 0 1 0-3H5v-1a3 3 0 0 1 3-3h3V5.73A2 2 0 0 1 10 4a2 2 0 0 1 2-2zm-2 9a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm4 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm-5 5v1h6v-1H9z"/></svg>',
                                    ],
                                ];
                                $trafficMax = max(1, max(array_column($trafficStats, 'val')));
                            ?>
                            <div class="device-bars">
                                <?php foreach ($trafficStats as $label => $t):
                                    $tPct = round($t['val'] / $trafficMax * 100);
                                ?>
                                <div class="device-bar-row">
                                    <div class="device-icon"><?= $t['icon'] ?></div>
                                    <div class="device-bar-track">
                                        <div class="device-bar-fill" style="width:<?= $tPct ?>%; background:<?= $t['color'] ?>;">
                                            <?php if ($tPct >= 20): ?><span class="device-bar-pct"><?= $label ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="device-bar-count"><?= $t['val'] ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="device-legend">
                                <?php foreach ($trafficStats as $label => $t): ?>
                                    <span class="device-legend-item"><span class="device-legend-dot" style="background:<?= $t['color'] ?>;"></span><?= $label ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="device-sessions-card">
                            <h3>Девайсы</h3>
                            <?php
                                $deviceColors = ['desktop' => '#22c1c3', 'mobile' => '#9b00ff', 'tablet' => '#ff9f43'];
                                $deviceIcons = [
                                    'desktop' => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20 3H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h5l-1 3h8l-1-3h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 12H4V5h16v10z"/></svg>',
                                    'mobile'  => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M16 2H8a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zm0 16H8V5h8v13z"/></svg>',
                                    'tablet'  => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 2H5a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zm0 16H5V5h14v13z"/></svg>',
                                ];
                                $deviceIconDefault = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20 3H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h5l-1 3h8l-1-3h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 12H4V5h16v10z"/></svg>';
                                $deviceTotal  = array_sum($data) ?: 1;
                            ?>
                            <div class="device-bars">
                                <?php foreach ($data as $devName => $devCount):
                                    $devPct = round($devCount / $deviceTotal * 100);
                                    $color  = $deviceColors[$devName] ?? '#888';
                                    $icon   = $deviceIcons[$devName] ?? $deviceIconDefault;
                                ?>
                                <div class="device-bar-row">
                                    <div class="device-icon"><?= $icon ?></div>
                                    <div class="device-bar-track">
                                        <div class="device-bar-fill" style="width:<?= $devPct ?>%; background:<?= $color ?>;">
                                            <?php if ($devPct >= 12): ?><span class="device-bar-pct"><?= $devPct ?>%</span><?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="device-bar-count"><?= $devCount ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="device-legend">
                                <?php foreach ($data as $devName => $devCount): $color = $deviceColors[$devName] ?? '#888'; ?>
                                    <span class="device-legend-item"><span class="device-legend-dot" style="background:<?= $color ?>;"></span><?= ucfirst($devName) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="stats-row">

                    <!-- Топ кампаний — таблица -->
                    <div class="campaigns-table-card">
                        <h3>Топ кампаний</h3>

                        <?php if (empty($topCampaigns)): ?>
                            <div class="campaigns-table-empty">Не используется</div>
                        <?php else: ?>
                            <div class="campaigns-table-head">
                                <span>Кампания</span>
                                <span>Профит</span>
                            </div>
                            <?php foreach ($topCampaigns as $i => $tc): ?>
                            <div class="campaigns-table-row">
                                <span class="campaigns-table-name"><?= htmlspecialchars($tc['name']) ?></span>
                                <span class="campaigns-table-profit"><?= number_format((float)$tc['profit'], 2) . $dominantSymbol ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Топ целей — прогресс-бары -->
                    <div class="goals-progress-card">
                        <h3>Топ целей</h3>

                        <?php if (empty($topGoals)): ?>
                            <div class="campaigns-table-empty">Нету целей</div>
                        <?php else: ?>
                            <?php
                                $goalColors = ['#28a745', '#22c1c3', '#ff9f43', '#9b00ff', '#ff2fd0'];
                                $goalsMax   = max(1, max(array_column($topGoals, 'cnt')));
                            ?>
                            <?php foreach ($topGoals as $i => $tg):
                                $gPct = round((int)$tg['cnt'] / $goalsMax * 100);
                                $gColor = $goalColors[$i % count($goalColors)];
                            ?>
                            <div class="goal-progress-item">
                                <div class="goal-progress-top">
                                    <span class="goal-progress-label"><?= htmlspecialchars($tg['name']) ?>:</span>
                                    <span class="goal-progress-count"><?= (int)$tg['cnt'] ?></span>
                                </div>
                                <div class="goal-progress-track">
                                    <div class="goal-progress-fill" style="width:<?= $gPct ?>%; background:<?= $gColor ?>;"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                </div>
            <?php endif; ?>

        </div><!-- /content -->
    </div><!-- /page-content -->

</div><!-- /main-wrapper -->

<script>
<?php if ($total_logs > 0): ?>
const geoData = <?= json_encode(!empty($geo_labels) ? array_combine($geo_labels, $geo_values) : (object)[]) ?>;
<?php if ($geo_json_path): ?>
fetch('<?= htmlspecialchars($geo_json_path) ?>')
    .then(function(r){ return r.json(); })
    .then(function(worldData) {
        var wrapper = document.getElementById('geoMapD3');
        var W = wrapper.offsetWidth || 800;

        var projection = d3.geoNaturalEarth1();
        var path = d3.geoPath().projection(projection);

        var countries = topojson.feature(worldData, worldData.objects.countries).features;
        var countriesFC = { type: 'FeatureCollection', features: countries };

        // Fit to the actual landmass bounds (not the full sphere/poles),
        // so empty polar space is cropped and the map stays compact.
        projection.fitWidth(W, countriesFC);
        var bounds = path.bounds(countriesFC);
        var bx = bounds[0][0];
        var by = bounds[0][1];
        var bw = bounds[1][0] - bounds[0][0];
        var bh = bounds[1][1] - bounds[0][1];

        var svg = d3.select('#geoMapD3')
            .append('svg')
            .attr('width', '100%')
            .attr('height', Math.round(bh))
            .attr('viewBox', bx + ' ' + by + ' ' + bw + ' ' + bh)
            .style('display', 'block');

        var zoomLayer = svg.append('g').attr('class', 'zoom-layer');

        var maxVal = 1;
        Object.values(geoData).forEach(function(v){ if(v > maxVal) maxVal = v; });

        function getColor(iso) {
            var v = geoData[iso] || 0;
            if (v === 0) return 'rgb(235,235,245)';
            var t = Math.pow(v / maxVal, 0.4);
            var r = Math.round(80  + t * 175);
            var g = Math.round(0   + t * 20);
            var b = Math.round(100 + t * 155);
            return 'rgb('+r+','+g+','+b+')';
        }

        var tip = d3.select('body').append('div')
            .style('position','fixed')
            .style('background','#2a2a4d')
            .style('border','1px solid #9b00ff')
            .style('border-radius','6px')
            .style('padding','6px 12px')
            .style('color','#fff')
            .style('font-size','13px')
            .style('pointer-events','none')
            .style('display','none')
            .style('z-index','9999');

        zoomLayer.selectAll('path')
            .data(countries)
            .enter()
            .append('path')
            .attr('d', path)
            .attr('fill', function(d) {
                var iso = isoNumericToAlpha2(d.id);
                return getColor(iso);
            })
            .attr('stroke', 'rgba(255,119,255,0.6)')
            .attr('stroke-width', 0.5)
            .on('mousemove', function(event, d) {
                var iso = isoNumericToAlpha2(d.id);
                var name = d.properties.name || iso || '?';
                var v = iso ? (geoData[iso] || 0) : 0;
                var text = v > 0 ? name + ': <b style="color:#ff77ff">' + v + '</b> кликов' : name + ': нет данных';
                tip.style('display','block')
                   .style('left', (event.clientX + 12) + 'px')
                   .style('top',  (event.clientY - 28) + 'px')
                   .html(text);
            })
            .on('mouseleave', function() {
                tip.style('display','none');
            });

        zoomLayer.append('path')
            .datum(topojson.mesh(worldData, worldData.objects.countries, function(a,b){ return a !== b; }))
            .attr('d', path)
            .attr('fill', 'none')
            .attr('stroke', 'rgba(255,119,255,0.3)')
            .attr('stroke-width', 0.3);

        /* ── Масштабирование карты (колёсико / перетаскивание / слайдер) ── */
        var zoomBehavior = d3.zoom()
            .scaleExtent([1, 6])
            .translateExtent([[bx, by], [bx + bw, by + bh]])
            .on('zoom', function (event) {
                zoomLayer.attr('transform', event.transform);
                var slider = document.getElementById('mapZoomSlider');
                if (slider) slider.value = event.transform.k;
            });

        svg.call(zoomBehavior);

        var zoomSlider = document.getElementById('mapZoomSlider');
        if (zoomSlider) {
            zoomSlider.addEventListener('input', function () {
                var scale = parseFloat(this.value);
                svg.transition().duration(120).call(zoomBehavior.scaleTo, scale);
            });
        }

    })
    .catch(function(e) {
        console.error('Map load error:', e);
        document.getElementById('geoMapWrapper').innerHTML = buildGeoTable();
    });
<?php else: ?>
document.getElementById('geoMapWrapper').innerHTML = buildGeoTable();
<?php endif; ?>

function buildGeoTable() {
    var entries = Object.entries(geoData).sort(function(a,b){ return b[1]-a[1]; });
    if (!entries.length) return '<p style="color:#ccc;text-align:center;padding:20px">Нет гео-данных</p>';
    var html = '<table class="stats-table"><tr><th>Страна</th><th>Клики</th></tr>';
    function isoToFlag(iso) {
    if (!iso || iso.length !== 2) return '';
    return String.fromCodePoint(iso.toUpperCase().charCodeAt(0)+127397, iso.toUpperCase().charCodeAt(1)+127397);
}
entries.forEach(function(e){
    var iso = e[0].toLowerCase();
    var flag = iso.length === 2
        ? '<img src="https://flagcdn.com/20x15/' + iso + '.png" style="vertical-align:middle;margin-right:6px;">'
        : '';
    html += '<tr><td>' + flag + e[0] + '</td><td>' + e[1] + '</td></tr>';
});
    return html + '</table>';
}

function isoNumericToAlpha2(id) {
    var map = {
        4:'AF',8:'AL',12:'DZ',24:'AO',32:'AR',36:'AU',40:'AT',50:'BD',56:'BE',
        64:'BT',68:'BO',76:'BR',100:'BG',116:'KH',120:'CM',124:'CA',144:'LK',
        152:'CL',156:'CN',170:'CO',188:'CR',191:'HR',192:'CU',196:'CY',203:'CZ',
        208:'DK',218:'EC',818:'EG',222:'SV',231:'ET',246:'FI',250:'FR',276:'DE',
        288:'GH',300:'GR',320:'GT',332:'HT',340:'HN',348:'HU',356:'IN',360:'ID',
        364:'IR',368:'IQ',372:'IE',376:'IL',380:'IT',388:'JM',392:'JP',400:'JO',
        398:'KZ',404:'KE',408:'KP',410:'KR',414:'KW',418:'LA',422:'LB',430:'LR',
        434:'LY',440:'LT',442:'LU',484:'MX',504:'MA',508:'MZ',516:'NA',524:'NP',
        528:'NL',554:'NZ',558:'NI',566:'NG',578:'NO',586:'PK',591:'PA',598:'PG',
        600:'PY',604:'PE',608:'PH',616:'PL',620:'PT',634:'QA',642:'RO',643:'RU',
        682:'SA',686:'SN',694:'SL',706:'SO',710:'ZA',724:'ES',729:'SD',752:'SE',
        756:'CH',760:'SY',764:'TH',792:'TR',800:'UG',804:'UA',784:'AE',826:'GB',
        840:'US',858:'UY',860:'UZ',862:'VE',704:'VN',887:'YE',894:'ZM',716:'ZW',
        51:'AM',31:'AZ',112:'BY',703:'SK',705:'SI',233:'EE',428:'LV',498:'MD',
        807:'MK',499:'ME',688:'RS',70:'BA',44:'BS',52:'BB',84:'BZ',204:'BJ',
        72:'BW',854:'BF',108:'BI',132:'CV',140:'CF',148:'TD',174:'KM',178:'CG',
        180:'CD',262:'DJ',266:'GA',270:'GM',324:'GN',624:'GW',384:'CI',426:'LS',
        450:'MG',454:'MW',466:'ML',478:'MR',480:'MU',562:'NE',646:'RW',678:'ST',
        690:'SC',728:'SS',748:'SZ',768:'TG',788:'TN',834:'TZ',732:'EH',104:'MM',
        458:'MY',702:'SG',96:'BN',626:'TL',540:'NC',630:'PR'
    };
    return map[parseInt(id)] || null;
}
<?php endif; ?>
</script>

<script>
(function () {
    var SIDEBAR_KEY   = 'sidebar_collapsed';
    var body    = document.body;
    var btn     = document.getElementById('hamburgerBtn');

    if (localStorage.getItem(SIDEBAR_KEY) === '1') {
        body.classList.add('sidebar-collapsed');
    }

    btn.addEventListener('click', function () {
        body.classList.toggle('sidebar-collapsed');
        localStorage.setItem(
            SIDEBAR_KEY,
            body.classList.contains('sidebar-collapsed') ? '1' : '0'
        );
    });

    var profileMenu = document.getElementById('profileMenu');
    var avatarBtn = document.getElementById('profileAvatarBtn');
    if (avatarBtn && profileMenu) {
        avatarBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            profileMenu.classList.toggle('open');
        });
        document.addEventListener('click', function (e) {
            if (!profileMenu.contains(e.target)) {
                profileMenu.classList.remove('open');
            }
        });
    }

    var themeMenu = document.getElementById("themeMenu");
    var themeToggleBtn = document.getElementById("themeToggleBtn");
    var THEME_KEY = "site_bg_theme";

    function applyTheme(file) {
        document.documentElement.style.setProperty("--site-bg-image", "url(/img/backgrounds/" + file + ")");
        document.querySelectorAll(".theme-option").forEach(function (o) {
            o.classList.toggle("theme-option-active", o.getAttribute("data-file") === file);
        });
    }

    if (themeToggleBtn && themeMenu) {
        themeToggleBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            themeMenu.classList.toggle("open");
        });
        document.addEventListener("click", function (e) {
            if (!themeMenu.contains(e.target)) {
                themeMenu.classList.remove("open");
            }
        });
        document.querySelectorAll(".theme-option[data-file]").forEach(function (opt) {
            opt.addEventListener("click", function () {
                var file = this.getAttribute("data-file");
                applyTheme(file);
                localStorage.setItem(THEME_KEY, file);
                themeMenu.classList.remove("open");
            });
        });
        var savedTheme = localStorage.getItem(THEME_KEY);
        if (savedTheme) applyTheme(savedTheme);
    }
}());
</script>

<script>
(function () {
    var pickerBtn   = document.getElementById('dateFilterBtn');
    var dropdown    = document.getElementById('dateCalendarDropdown');
    var label       = document.getElementById('dateFilterLabel');
    var fromHidden  = document.getElementById('dateFromHidden');
    var toHidden    = document.getElementById('dateToHidden');
    var form        = document.getElementById('dateFilterForm');
    var monthLabel  = document.getElementById('calMonthLabel');
    var weekdaysRow = document.getElementById('calWeekdays');
    var daysGrid    = document.getElementById('calDays');
    var monthGrid   = document.getElementById('calMonthGrid');
    var prevBtn     = document.getElementById('calPrev');
    var nextBtn     = document.getElementById('calNext');
    var todayBtn    = document.getElementById('calToday');
    var clearBtn    = document.getElementById('calClear');

    if (!pickerBtn) return;

    var monthNames = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
    var monthShort = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
    var viewMode = 'days'; // 'days' | 'months'

    function parseDate(str) {
        if (!str) return null;
        var p = str.split('-');
        if (p.length !== 3) return null;
        return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
    }

    function fmt(d) {
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    function fmtDisplay(d) {
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return day + '.' + m + '.' + d.getFullYear();
    }

    function sameDay(a, b) {
        return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    var selStart = parseDate(fromHidden.value);
    var selEnd   = parseDate(toHidden.value);
    var viewDate = selStart ? new Date(selStart.getFullYear(), selStart.getMonth(), 1) : new Date();
    viewDate.setDate(1);

    function updateLabel() {
        if (selStart && selEnd) {
            label.textContent = fmtDisplay(selStart) + ' – ' + fmtDisplay(selEnd);
        } else if (selStart) {
            label.textContent = fmtDisplay(selStart) + ' – …';
        } else {
            label.textContent = 'Все даты';
        }
    }

    function renderHeader() {
        if (viewMode === 'months') {
            monthLabel.textContent = String(viewDate.getFullYear());
        } else {
            monthLabel.textContent = monthNames[viewDate.getMonth()] + ' ' + viewDate.getFullYear();
        }
    }

    function renderDays() {
        daysGrid.innerHTML = '';

        var firstOfMonth = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1);
        var startWeekday = firstOfMonth.getDay();
        var gridStart = new Date(firstOfMonth);
        gridStart.setDate(gridStart.getDate() - startWeekday);

        var today = new Date();
        today.setHours(0,0,0,0);

        for (var i = 0; i < 42; i++) {
            var cellDate = new Date(gridStart);
            cellDate.setDate(gridStart.getDate() + i);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cal-day';
            btn.textContent = cellDate.getDate();

            if (cellDate.getMonth() !== viewDate.getMonth()) btn.classList.add('cal-day-muted');
            if (sameDay(cellDate, today)) btn.classList.add('cal-day-today');

            if (selStart && selEnd) {
                if (sameDay(cellDate, selStart) || sameDay(cellDate, selEnd)) btn.classList.add('cal-day-selected');
                else if (cellDate > selStart && cellDate < selEnd) btn.classList.add('cal-day-in-range');
            } else if (selStart && sameDay(cellDate, selStart)) {
                btn.classList.add('cal-day-selected');
            }

            (function (d) {
                btn.addEventListener('click', function () {
                    if (!selStart || (selStart && selEnd)) {
                        selStart = d;
                        selEnd = null;
                    } else {
                        if (d < selStart) {
                            selEnd = selStart;
                            selStart = d;
                        } else {
                            selEnd = d;
                        }
                    }
                    renderDays();
                    updateLabel();
                    if (selStart && selEnd) {
                        fromHidden.value = fmt(selStart);
                        toHidden.value = fmt(selEnd);
                        dropdown.classList.remove('open');
                        form.submit();
                    }
                });
            })(cellDate);

            daysGrid.appendChild(btn);
        }
    }

    function renderMonths() {
        monthGrid.innerHTML = '';
        var currentYear = new Date().getFullYear();
        var currentMonth = new Date().getMonth();

        for (var m = 0; m < 12; m++) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cal-month-cell';
            btn.textContent = monthShort[m];

            if (viewDate.getFullYear() === currentYear && m === currentMonth) {
                btn.classList.add('cal-day-today');
            }
            if (m === viewDate.getMonth()) {
                btn.classList.add('cal-month-cell-active');
            }

            (function (monthIndex) {
                btn.addEventListener('click', function () {
                    viewDate.setMonth(monthIndex);
                    viewMode = 'days';
                    render();
                });
            })(m);

            monthGrid.appendChild(btn);
        }
    }

    function render() {
        renderHeader();
        if (viewMode === 'months') {
            weekdaysRow.style.display = 'none';
            daysGrid.style.display = 'none';
            monthGrid.style.display = 'grid';
            renderMonths();
        } else {
            weekdaysRow.style.display = '';
            daysGrid.style.display = '';
            monthGrid.style.display = 'none';
            renderDays();
        }
    }

    pickerBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        dropdown.classList.toggle('open');
    });

    document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && e.target !== pickerBtn) {
            dropdown.classList.remove('open');
            viewMode = 'days';
        }
    });

    monthLabel.addEventListener('click', function () {
        viewMode = viewMode === 'months' ? 'days' : 'months';
        render();
    });

    prevBtn.addEventListener('click', function () {
        if (viewMode === 'months') {
            viewDate.setFullYear(viewDate.getFullYear() - 1);
        } else {
            viewDate.setMonth(viewDate.getMonth() - 1);
        }
        render();
    });

    nextBtn.addEventListener('click', function () {
        if (viewMode === 'months') {
            viewDate.setFullYear(viewDate.getFullYear() + 1);
        } else {
            viewDate.setMonth(viewDate.getMonth() + 1);
        }
        render();
    });

    todayBtn.addEventListener('click', function () {
        var t = new Date();
        t.setHours(0,0,0,0);
        selStart = t;
        selEnd = t;
        fromHidden.value = fmt(t);
        toHidden.value = fmt(t);
        dropdown.classList.remove('open');
        form.submit();
    });

    clearBtn.addEventListener('click', function () {
        selStart = null;
        selEnd = null;
        fromHidden.value = '';
        toHidden.value = '';
        dropdown.classList.remove('open');
        form.submit();
    });

    updateLabel();
    render();
}());
</script>

<script src="geo/map/geo-custom-scrollbar.js"></script>

</body>
</html>
