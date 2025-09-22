<?php
require 'config.php';
checkAuth();
$db = getDB();

$stream_id = (int)($_GET['stream_id'] ?? 0);
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$stmt = $db->prepare("SELECT name, url, slug FROM streams WHERE id=?");
$stmt->execute([$stream_id]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);
$campaign_name = $campaign['name'] ?? "Неизвестная";
$campaign_url = $campaign['url'] ?? "#";
$campaign_slug = $campaign['slug'] ?? "";

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$redirect_link = $protocol . '://' . $host . '/' . $campaign_slug;

$where = "stream_id = ?";
$params = [$stream_id];
if ($date_from && $date_to) {
    $where .= " AND DATE(timestamp) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
}

$stmt = $db->prepare("SELECT * FROM logs WHERE $where");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_logs = count($logs);
$unique = count(array_unique(array_column($logs, 'ip')));

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
$geo_data = array_slice($geo_counts, 0, 10, true);
$geo_labels = array_keys($geo_data);
$geo_values = array_values($geo_data);
$geo_colors = ['#ff6384','#36a2eb','#ffcd56','#4bc0c0','#9966ff','#ff9f40','#c9cbcf','#8e5ea2','#3cba9f','#e8c3b9'];
$top_geo = $geo_labels[0] ?? '—';

$botCount = 0;
foreach ($logs as $row) {
    $keywords = explode(',', $row['keyword'] ?? '');
    $keywords = array_map('trim', $keywords);
    if (in_array('bot', $keywords)) {
        $botCount++;
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $campaign_name) . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Дата','Девайс','UA','IP','Гео','Провайдер','PTR','Ключевики'], ';');

    foreach ($logs as $row) {
        fputcsv($output, [
            $row['timestamp'] ?? '',
            $row['device'] ?? '',
            $row['useragent'] ?? '',
            $row['ip'] ?? '',
            $row['geo'] ?? '',
            $row['provider'] ?? '',
            $row['ptr'] ?? '',
            $row['keyword'] ?? ''
        ], ';');
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Статистика кампании</title>

<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
</head>
<body>

<div class="header">Easy TDS</div>

<div class="content">
    <div class="top-controls">
        <a href="dashboard.php" class="back-btn">← К списку кампаний</a>

        <form method="get" style="display:inline-block;">
            <input type="hidden" name="stream_id" value="<?= $stream_id ?>">
            <label>Фильтр по дате С: <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></label>
            <label>По: <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></label>
            <button type="submit" class="add-new-btn">Применить</button>
        </form>

        <form method="get" style="display:inline-block;">
            <input type="hidden" name="stream_id" value="<?= $stream_id ?>">
            <input type="hidden" name="export" value="csv">
            <button type="submit" class="add-new-btn">Экспорт кампании CSV</button>
        </form>
    </div>

    <h2 class="campaign-title">Статистика кампании: <?= htmlspecialchars($campaign_name) ?></h2>

<?php if ($total_logs === 0): ?>
    <div class="no-data">Нету статистики</div>
<?php else: ?>
    <div class="chart-container">
        <div class="chart-block">
            <h3>Девайсы</h3>
            <canvas id="deviceChart"></canvas>
        </div>
        <div class="chart-block">
            <h3>Топ-10 Гео</h3>
            <canvas id="geoChart"></canvas>
        </div>
    </div>

    <div class="stats-card">
        <div class="stats-header">Статистика кликов</div>
        <table class="stats-table">
            <tr>
                <th>Клики</th>
                <th>Уники</th>
                <th>Desktop</th>
                <th>Mobile</th>
                <th>Боты</th>
                <th>Топ Гео</th>
            </tr>
            <tr>
                <td><?= $total_logs ?></td>
                <td><?= $unique ?></td>
                <td><?= $data['desktop'] ?></td>
                <td><?= $data['mobile'] ?></td>
                <td><?= $botCount ?></td>
                <td><?= htmlspecialchars($top_geo) ?></td>
            </tr>
        </table>
    </div>
<?php endif; ?>

    <div class="redirect-block">
        <p>Кампания ведет на URL: <strong><?= htmlspecialchars($campaign_url) ?></strong></p>
        <a href="<?= $redirect_link ?>" target="_blank" class="redirect-btn">Перейти к кампании</a>
    </div>
</div>

<script>
<?php if ($total_logs > 0): ?>
const deviceCtx = document.getElementById('deviceChart').getContext('2d');
new Chart(deviceCtx, {
    type:'doughnut',
    data:{
        labels:['Desktop','Mobile'],
        datasets:[{data:[<?= $data['desktop'] ?>,<?= $data['mobile'] ?>], backgroundColor:['#9b00ff','#28a745']}]
    },
    options:{
        responsive:true,
        maintainAspectRatio:true,
        plugins:{
            legend:{position:'bottom', labels:{color:'#fff'}},
            datalabels:{
                color: '#fff',
                font: {weight: 'bold', size: 14},
                formatter: (value, context) => {
                    if (value === 0) return '';
                    const data = context.chart.data.datasets[0].data.map(Number);
                    const sum = data.reduce((a,b)=>a+b,0);
                    return sum ? (value/sum*100).toFixed(1)+'%' : '';
                }
            }
        }
    },
    plugins: [ChartDataLabels]
});

const geoCtx = document.getElementById('geoChart').getContext('2d');
new Chart(geoCtx, {
    type:'doughnut',
    data:{
        labels:<?= json_encode($geo_labels) ?>,
        datasets:[{data:<?= json_encode($geo_values) ?>, backgroundColor:<?= json_encode($geo_colors) ?>}]
    },
    options:{
        responsive:true,
        maintainAspectRatio:true,
        plugins:{
            legend:{position:'bottom', labels:{color:'#fff'}},
            datalabels:{
                color:'#fff',
                font:{weight:'bold', size:14},
                formatter: (value, context) => {
                    const sum = context.chart.data.datasets[0].data.reduce((a,b)=>a+b,0);
                    return (value/sum*100).toFixed(1)+'%';
                }
            }
        }
    },
    plugins:[ChartDataLabels]
});
<?php endif; ?>
</script>

</body>
</html>