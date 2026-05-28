<?php
require 'config.php';
checkAuth();
checkIP();
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
<script src="geo/map/topojson-client.min.js"></script>
<script src="geo/map/d3.min.js"></script>
<style>
.info-cards-row {
    display: flex;
    gap: 18px;
    margin-top: 18px;
    margin-bottom: 40px;
}
.info-card {
    flex: 1;
    background: rgba(30, 15, 60, 0.85);
    border: 1px solid rgba(155, 0, 255, 0.35);
    border-radius: 12px;
    padding: 22px 26px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.info-card h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: #ffffff;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    text-shadow: 0 0 5px #ff77ff, 0 0 10px #ff00ff;
}
.info-card-value {
    font-size: 32px;
    font-weight: 700;
    color: #ffffff;
    line-height: 1;
}
.info-card-placeholder {
    color: rgba(255, 255, 255, 0.25);
    font-style: italic;
}
@media (max-width: 700px) {
    .info-cards-row { flex-direction: column; }
}
</style>
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
</header>

<!-- ========== MAIN WRAPPER ========== -->
<div class="main-wrapper">

    <!-- ========== SIDEBAR ========== -->
    <nav class="sidebar" id="sidebar">
        <ul class="sidebar-nav">

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

            <li class="sidebar-divider"></li>

            <li data-tooltip="Кампании">
                <div class="sidebar-group-row">
                    <a href="campaigns.php" class="sidebar-group-link">
                        <span class="nav-icon">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path d="M20 6h-3V4a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v2H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2zm-9-2h2v2h-2V4zm-2 0h2v2H9V4zm11 15H4V8h16v11z"/>
                            </svg>
                        </span>
                        <span class="nav-label">Кампании</span>
                    </a>
                    <button class="nav-arrow-btn open" id="campaignsToggle" type="button" title="Свернуть">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M7 10l5 5 5-5H7z"/>
                        </svg>
                    </button>
                </div>

                <ul class="sidebar-subnav open" id="campaignsSubnav">
                    <li>
                        <a href="new_campaign.php">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M11 11V5h2v6h6v2h-6v6h-2v-6H5v-2z"/>
                                </svg>
                            </span>
                            <span class="nav-label">Создать новую</span>
                        </a>
                    </li>
<li>
                        <a href="?export=csv">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zM8 13h8v1.5H8V13zm0 3h8v1.5H8V16zm0-6h3v1.5H8V10z"/>
                                </svg>
                            </span>
                            <span class="nav-label">Экспорт логов</span>
                        </a>
                    </li>
<li>
                        <a href="?export=goals_csv">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.88-11.71L10 14.17l-1.88-1.88a.996.996 0 1 0-1.41 1.41l2.59 2.59c.39.39 1.02.39 1.41 0L17.3 9.7a.996.996 0 0 0 0-1.41c-.39-.39-1.03-.39-1.42 0z"/>
                                </svg>
                            </span>
                            <span class="nav-label">Экспорт целей</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" onclick="confirmDeleteAll(event)" style="color:#ff6666;">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M9 3v1H4v2h1v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6h1V4h-5V3H9zm0 5h2v9H9V8zm4 0h2v9h-2V8z"/>
                                </svg>
                            </span>
                            <span class="nav-label">Удалить все</span>
                        </a>
                        <form id="deleteAllForm" method="post" action="campaigns.php" style="display:none;">
                            <input type="hidden" name="delete_all" value="1">
                        </form>
                    </li>
                </ul>
            </li>

            <li class="sidebar-divider"></li>

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

            <li class="sidebar-divider"></li>

            <li data-tooltip="Учетная запись">
                <a href="credentials.php">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 4a4 4 0 1 1 0 8 4 4 0 0 1 0-8zm0 10c4.418 0 8 1.79 8 4v1H4v-1c0-2.21 3.582-4 8-4z"/>
                        </svg>
                    </span>
                    <span class="nav-label">Учетная запись</span>
                </a>
            </li>

            <li class="sidebar-divider"></li>

            <li data-tooltip="Выйти">
                <a href="logout.php" style="color:#ff6666;">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M16 13v-2H7V8l-5 4 5 4v-3h9zm2-11H6a2 2 0 0 0-2 2v4h2V4h12v16H6v-4H4v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/>
                        </svg>
                    </span>
                    <span class="nav-label">Выйти</span>
                </a>
            </li>

        </ul>
    </nav>
    <!-- /sidebar -->

    <!-- ========== PAGE CONTENT ========== -->
    <div class="page-content">
        <div class="content">

            <h2 class="campaign-title">Общая статистика всех кампаний</h2>

            <?php if ($total_logs === 0): ?>
                <div class="no-data">Нету статистики</div>
            <?php else: ?>
                
                <!-- ========== INFO CARDS ========== -->
            <div class="info-cards-row">

                <div class="info-card">
                    <h3>Активных кампаний</h3>
                    <div class="info-card-value"><?= $total_campaigns ?></div>
                </div>

                <div class="info-card">
                    <h3>Самая профитная кампания</h3>
                    <div class="info-card-value">
                        <?php if ($topCampaign): ?>
                            <?= htmlspecialchars($topCampaign['name']) ?>
                        <?php else: ?>
                            <span class="info-card-placeholder" style="font-size:16px;">Нет данных</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-card">
                    <h3>Общий профит</h3>
                    <div class="info-card-value">
                        <?= number_format($totalProfit, 2) . $dominantSymbol ?>
                    </div>
                </div>

            </div>
                
	                <div class="stats-row">

                    <!-- Карта слева -->
                    <div class="map-block">
                        <h3>Детализация трафика</h3>
                        <div id="geoMapWrapper">
                            <div id="geoMapD3"></div>
                        </div>
                    </div>

                    <!-- Топ кампаний и целей справа -->
                    <div class="chart-block">

                        <h3>Топ профитных кампаний</h3>
                        <div class="stat-list">
                            <?php if (empty($topCampaigns)): ?>
                                <div class="stat-item">
                                    <span class="stat-label" style="color:#888;font-size:13px;">Не используется</span>
                                </div>
                            <?php else: ?>
                                <?php foreach ($topCampaigns as $i => $tc): ?>
                                <div class="stat-item">
                                    <span class="stat-label"><?= ($i + 1) . '. ' . htmlspecialchars($tc['name']) ?></span>
                                    <span class="stat-value"><?= number_format((float)$tc['profit'], 2) . $dominantSymbol ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <h3 style="margin-top:16px;">Топ целей кампаний</h3>
                        <div class="stat-list">
                            <?php if (empty($topGoals)): ?>
                                <div class="stat-item">
                                    <span class="stat-label" style="color:#888;font-size:13px;">Нету целей</span>
                                </div>
                            <?php else: ?>
                                <?php foreach ($topGoals as $i => $tg): ?>
                                <div class="stat-item">
                                    <span class="stat-label"><?= ($i + 1) . '. ' . htmlspecialchars($tg['name']) ?></span>
                                    <span class="stat-value"><?= (int)$tg['cnt'] ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
            <?php endif; ?>

<div class="date-filter-block">
                <form method="get" class="date-filter-form">
                    <label>С: <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></label>
                    <label>По: <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></label>
                    <a href="main.php" title="Сбросить" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;background:#ffc107;box-shadow:0 0 8px #ffc107;text-decoration:none;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#1b1b2f"><path d="M12 5V2L8 6l4 4V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>
                    </a>
                    <button type="submit" title="Применить" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#28a745;box-shadow:0 0 8px #28a745;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    </button>
                </form>
            </div>

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
        var H = Math.round(W * 0.32);

        var svg = d3.select('#geoMapD3')
            .append('svg')
            .attr('width', '100%')
            .attr('height', H)
            .attr('viewBox', '0 0 ' + W + ' ' + H)
            .style('display', 'block');

        var projection = d3.geoNaturalEarth1();
        var path = d3.geoPath().projection(projection);

        projection.fitSize([W, H], {type: 'Sphere'});
        var countries = topojson.feature(worldData, worldData.objects.countries).features;

        var maxVal = 1;
        Object.values(geoData).forEach(function(v){ if(v > maxVal) maxVal = v; });

        function getColor(iso) {
            var v = geoData[iso] || 0;
            if (v === 0) return 'rgba(40,30,70,0.85)';
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

        svg.selectAll('path')
            .data(countries)
            .enter()
            .append('path')
            .attr('d', path)
            .attr('fill', function(d) {
                var iso = isoNumericToAlpha2(d.id);
                return getColor(iso);
            })
            .attr('stroke', 'rgba(155,0,255,0.35)')
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

        svg.append('path')
            .datum(topojson.mesh(worldData, worldData.objects.countries, function(a,b){ return a !== b; }))
            .attr('d', path)
            .attr('fill', 'none')
            .attr('stroke', 'rgba(155,0,255,0.2)')
            .attr('stroke-width', 0.3);

        /* ── Девайсы (лево-верх) ── */
        var desktop = <?= $data['desktop'] ?>;
        var mobile  = <?= $data['mobile'] ?>;
        var total   = desktop + mobile || 1;
        var devEntries = [
            { label: 'Desktop', val: desktop, pct: Math.round(desktop/total*100) },
            { label: 'Mobile',  val: mobile,  pct: Math.round(mobile/total*100)  }
        ];

        (function() {
var padX = 14, padY = 10, lineH = 24, fontSize = 14;
var boxW = 175, boxH = padY * 2 + lineH * 3;
var bx = 10, by = 10;

            var g2 = svg.append('g').attr('class', 'dev-legend');

            g2.append('rect')
                .attr('x', bx).attr('y', by)
                .attr('width', boxW).attr('height', boxH)
                .attr('rx', 6)
                .attr('fill', 'rgba(20,10,40,0.75)')
                .attr('stroke', 'rgba(155,0,255,0.5)')
                .attr('stroke-width', 1);

            g2.append('text')
                .attr('x', bx + padX).attr('y', by + padY + fontSize)
                .attr('fill', '#ff77ff')
                .attr('font-size', fontSize)
                .attr('font-weight', 'bold')
                .attr('font-family', 'sans-serif')
                .text('ДЕВАЙСЫ');

            var devColors = ['#9b00ff', '#28a745'];
var devIcons = ['🖥️', '📱'];
            devEntries.forEach(function(d, i) {
                var ty = by + padY + lineH * (i + 2);
                g2.append('foreignObject')
                    .attr('x', bx + padX).attr('y', ty - fontSize)
                    .attr('width', 180).attr('height', lineH)
                    .append('xhtml:div')
                    .style('color', '#fff')
                    .style('font-size', fontSize + 'px')
                    .style('font-family', 'sans-serif')
                    .style('line-height', lineH + 'px')
                    .style('white-space', 'nowrap')
                    .html(devIcons[i] + ' ' + d.label + ' — ' + d.val + ' (' + d.pct + '%)');
            });
        })();

        /* ── Трафик (лево-низ, под девайсами) ── */
        (function() {
var padX = 14, padY = 10, lineH = 24, fontSize = 14;
var trafficEntries = [
                { label: 'Клики', val: <?= $total_logs ?> },
                { label: 'Уники', val: <?= $unique ?> },
                { label: 'Боты',  val: <?= $botCount ?> }
            ];
var devBoxH = 10 * 2 + 24 * 3;
var boxH = padY * 2 + lineH * (trafficEntries.length + 1);
var bx = 10, by = 10 + devBoxH + 8;
var boxW = 175;

            var g3 = svg.append('g').attr('class', 'traffic-legend');

            g3.append('rect')
                .attr('x', bx).attr('y', by)
                .attr('width', boxW).attr('height', boxH)
                .attr('rx', 6)
                .attr('fill', 'rgba(20,10,40,0.75)')
                .attr('stroke', 'rgba(155,0,255,0.5)')
                .attr('stroke-width', 1);

            g3.append('text')
                .attr('x', bx + padX).attr('y', by + padY + fontSize)
                .attr('fill', '#ff77ff')
                .attr('font-size', fontSize)
                .attr('font-weight', 'bold')
                .attr('font-family', 'sans-serif')
                .text('ТРАФИК');

var trafficIcons = ['👆', '👤', '🤖'];
            trafficEntries.forEach(function(d, i) {
                var ty = by + padY + lineH * (i + 2);
                g3.append('foreignObject')
                    .attr('x', bx + padX).attr('y', ty - fontSize)
                    .attr('width', 180).attr('height', lineH)
                    .append('xhtml:div')
                    .style('color', '#fff')
                    .style('font-size', fontSize + 'px')
                    .style('font-family', 'sans-serif')
                    .style('line-height', lineH + 'px')
                    .style('white-space', 'nowrap')
                    .html(trafficIcons[i] + ' ' + d.label + ' — ' + d.val);
            });
        })();

        /* ── Топ Гео (право-верх) ── */
var totalClicks = Object.values(geoData).reduce(function(s, v){ return s + v; }, 0) || 1;
var topEntries = Object.entries(geoData)
    .sort(function(a,b){ return b[1]-a[1]; })
    .slice(0, 5);

        if (topEntries.length > 0) {
var padX = 14, padY = 10, lineH = 24, fontSize = 14;
var boxW = 175, boxH = padY * 2 + lineH * (topEntries.length + 1);
var bx = W - boxW - 10, by = 10;

            var g = svg.append('g').attr('class', 'geo-legend');

            g.append('rect')
                .attr('x', bx).attr('y', by)
                .attr('width', boxW).attr('height', boxH)
                .attr('rx', 6)
                .attr('fill', 'rgba(20,10,40,0.75)')
                .attr('stroke', 'rgba(155,0,255,0.5)')
                .attr('stroke-width', 1);

            g.append('text')
                .attr('x', bx + padX).attr('y', by + padY + fontSize)
                .attr('fill', '#ff77ff')
                .attr('font-size', fontSize)
                .attr('font-weight', 'bold')
                .attr('font-family', 'sans-serif')
                .text('ТОП ГЕО');

            topEntries.forEach(function(entry, i) {
                var iso = entry[0], cnt = entry[1];
                var ty = by + padY + lineH * (i + 2);

function isoToFlag(iso) {
                    if (!iso || iso.length !== 2) return '';
                    return String.fromCodePoint(
                        iso.toUpperCase().charCodeAt(0) + 127397,
                        iso.toUpperCase().charCodeAt(1) + 127397
                    );
                }

g.append('foreignObject')
                    .attr('x', bx + padX).attr('y', ty - fontSize)
                    .attr('width', 180).attr('height', lineH)
                    .append('xhtml:div')
                    .style('color', '#fff')
                    .style('font-size', fontSize + 'px')
                    .style('font-family', 'sans-serif')
                    .style('line-height', lineH + 'px')
                    .style('white-space', 'nowrap')
                    .html((iso.length === 2 ? '<img src="https://flagcdn.com/16x12/' + iso.toLowerCase() + '.png" style="vertical-align:middle;margin-right:5px;">' : '') + iso + ' — ' + cnt + ' (' + Math.round(cnt / totalClicks * 100) + '%)');
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
    var ACCORDION_KEY = 'campaigns_open';
    var body    = document.body;
    var btn     = document.getElementById('hamburgerBtn');
    var toggle  = document.getElementById('campaignsToggle');
    var subnav  = document.getElementById('campaignsSubnav');

    if (localStorage.getItem(SIDEBAR_KEY) === '1') {
        body.classList.add('sidebar-collapsed');
    }

    var accordionOpen = localStorage.getItem(ACCORDION_KEY) !== '0';
    setAccordion(accordionOpen, false);

    btn.addEventListener('click', function () {
        body.classList.toggle('sidebar-collapsed');
        localStorage.setItem(
            SIDEBAR_KEY,
            body.classList.contains('sidebar-collapsed') ? '1' : '0'
        );
    });

    toggle.addEventListener('click', function () {
        var isOpen = subnav.classList.contains('open');
        setAccordion(!isOpen, true);
    });

    window.confirmDeleteAll = function (e) {
        e.preventDefault();
        if (confirm('Вы уверены, что хотите удалить все кампании и всю статистику?')) {
            document.getElementById('deleteAllForm').submit();
        }
    };

    function setAccordion(open, save) {
        if (open) {
            subnav.classList.add('open');
            toggle.classList.add('open');
        } else {
            subnav.classList.remove('open');
            toggle.classList.remove('open');
        }
        if (save) {
            localStorage.setItem(ACCORDION_KEY, open ? '1' : '0');
        }
    }
}());
</script>

</body>
</html>
