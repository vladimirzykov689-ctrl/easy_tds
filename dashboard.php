<?php 
require 'config.php';
$db = getDB();
checkAuth();
checkIP();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];

    $stmt = $db->prepare("DELETE FROM logs WHERE stream_id=?");
    $stmt->execute([$delete_id]);

    $stmt = $db->prepare("DELETE FROM streams WHERE id=?");
    $stmt->execute([$delete_id]);

    $stmt = $db->prepare("UPDATE streams SET id = id - 1 WHERE id > ?");
    $stmt->execute([$delete_id]);

    $db->exec("UPDATE sqlite_sequence SET seq = (SELECT MAX(id) FROM streams) WHERE name='streams'");

    header('Location: dashboard.php'); 
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all'])) {
    $db->exec("DELETE FROM logs");
    $db->exec("DELETE FROM streams");
    $db->exec("UPDATE sqlite_sequence SET seq = 0 WHERE name='streams'");

    header('Location: dashboard.php'); 
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $stmt = $db->prepare("
        SELECT logs.*, streams.name
        FROM logs
        JOIN streams ON logs.stream_id = streams.id
        ORDER BY logs.timestamp DESC
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="All_Campaigns.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['Кампания','Дата','Девайс','UA','IP','Гео','Провайдер','PTR','Ключевики'], ';');

    foreach ($logs as $row) {
        fputcsv($output, [
            $row['name'] ?? '',
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

$perPage = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;
$total = $db->query("SELECT COUNT(*) FROM streams")->fetchColumn();
$totalPages = ceil($total / $perPage);

$stmt = $db->prepare("SELECT * FROM streams ORDER BY id ASC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Панель управления</title>
<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">
</head>
<body class="dashboard-page">

<div class="header">Easy TDS</div>
<div class="content">

<div class="top-buttons">
    <button onclick="window.location.href='new_campaign.php';" class="add-new-btn">Создать новую кампанию</button>

    <div style="margin-left:auto; display:flex; gap:10px;">
        <form method="get">
            <input type="hidden" name="export" value="csv">
            <button type="submit" class="export-btn">Экспорт всех кампаний CSV</button>
        </form>

        <form method="post" onsubmit="return confirm('Вы уверены, что хотите удалить все кампании и всю статистику?');">
            <input type="hidden" name="delete_all" value="1">
            <button type="submit" class="delete-all-btn">Удалить все кампании</button>
        </form>
    </div>
</div>

<?php if(empty($streams)): ?>
    <div style="text-align:center; margin:40px; font-size:20px; color:#ccc;">Не найдено кампаний</div>
<?php else: ?>
<div class="campaigns-card">
    <div class="campaigns-header">Список кампаний</div>
    <table>
        <tr>
            <th>№</th>
            <th>Название кампании</th>
            <th>Идентификатор кампании</th>
            <th>Статистика кампании</th>
            <th>Действие</th>
        </tr>
        <?php foreach($streams as $s): ?>
        <tr>
            <td><?= $s['id'] ?></td>
            <td><?= htmlspecialchars($s['name']) ?></td>
            <td><?= htmlspecialchars($s['slug']) ?></td>
            <td>
                <button onclick="window.location.href='stats.php?stream_id=<?= $s['id'] ?>'" 
                        class="stats-btn">Посмотреть</button>
            </td>
            <td>
                <form method="post" onsubmit="return confirm('Вы уверены, что хотите удалить кампанию и всю статистику?');">
                    <input type="hidden" name="delete_id" value="<?= $s['id'] ?>">
                    <button type="submit" class="delete-btn">Удалить</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if($totalPages > 1): ?>
<div class="pagination">
    <?php for($i=1; $i<=$totalPages; $i++): ?>
        <?php if($i == $page): ?>
            <span><?= $i ?></span>
        <?php else: ?>
            <a href="?page=<?= $i ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

</div>

<div class="footer">
    <form action="logout.php" method="get">
        <button type="submit" class="delete-all-btn">Выйти</button>
    </form>
</div>

</body>
</html>
