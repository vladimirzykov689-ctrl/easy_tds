<?php 
require 'config.php';
$db = initDB();
checkAuth();
checkIP();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];

    $stmt = $db->prepare("DELETE FROM logs WHERE stream_id=?");
    $stmt->execute([$delete_id]);

    $stmt = $db->prepare("DELETE FROM conversions WHERE stream_id=?");
    $stmt->execute([$delete_id]);

    $stmt = $db->prepare("DELETE FROM goals WHERE stream_id=?");
    $stmt->execute([$delete_id]);

    $stmt = $db->prepare("DELETE FROM streams WHERE id=?");
    $stmt->execute([$delete_id]);

    header('Location: campaigns.php'); 
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all'])) {
    $db->exec("DELETE FROM logs");
    $db->exec("DELETE FROM conversions");
    $db->exec("DELETE FROM goals");
    $db->exec("DELETE FROM streams");
    $db->exec("UPDATE sqlite_sequence SET seq = 0 WHERE name='streams'");

    header('Location: campaigns.php'); 
    exit;
}

// ── Экспорт логов всех кампаний ───────────────────────────────────────────────
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
    header('Content-Disposition: attachment; filename="All_Campaigns_Log.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['Кампания','Дата', 'ClickID', 'Девайс','UA','IP','Гео','Провайдер','PTR','Ключевики'], ';');

    foreach ($logs as $row) {
        fputcsv($output, [
            $row['name'] ?? '',
            $row['timestamp'] ?? '',
            $row['click_id'] ?? '',
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

// ── Экспорт целей всех кампаний ───────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'goals_csv') {

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="All_Campaigns_Goals.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['Кампания','Дата', 'ClickID', 'Название','Параметр','Тип','Доходная', 'Валюта', 'Значение'], ';');

    try {
        $stmtExp = $db->prepare("
            SELECT s.name AS campaign_name,
                   g.name, g.param_name, g.value_type, g.is_revenue, g.currency,
                   c.value, c.click_id, c.created_at
            FROM conversions c
            JOIN goals g ON g.id = c.goal_id
            JOIN streams s ON s.id = c.stream_id
            ORDER BY c.created_at DESC
        ");
        $stmtExp->execute();
        $rows = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['campaign_name'],
                $row['created_at'],
                $row['click_id'],
                $row['name'],
                $row['param_name'],
                $row['value_type'],
                $row['is_revenue'] ? 'Да' : 'Нет',
                $row['currency'] ?? '',
                $row['value']
            ], ';');
        }
    } catch (Exception $e) {}

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

            <!-- === Главная === -->
            <li data-tooltip="Главная">
                <a href="main.php">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                        </svg>
                    </span>
                    <span class="nav-label">Главная</span>
                </a>
            </li>

            <li class="sidebar-divider"></li>

            <!-- === Кампании === -->
            <li data-tooltip="Кампании">
                <div class="sidebar-group-row active">
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
                            <span class="nav-label">Новая кампания</span>
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
                    <a href="#" onclick="confirmDeleteAll(event)" style="color:#ff6666;">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M9 3v1H4v2h1v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6h1V4h-5V3H9zm0 5h2v9H9V8zm4 0h2v9h-2V8z"/>
                                </svg>
                            </span>
                            <span class="nav-label">Удалить все</span>
                        </a>
                        <form id="deleteAllForm" method="post" style="display:none;">
                            <input type="hidden" name="delete_all" value="1">
                        </form>
                    </li>
                </ul>
            </li>

            <li class="sidebar-divider"></li>

            <!-- === Фильтр ботов === -->
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

            <!-- === Учетная запись === -->
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

<?php if(empty($streams)): ?>
    <div style="text-align:center; margin:40px; font-size:20px; color:#ccc;">Не найдено кампаний</div>
<?php else: ?>

<h2 class="campaign-title">Список кампаний</h2>

<div class="add-form" style="max-width:100%;">
<div style="padding:16px;display:flex;flex-direction:column;gap:16px;">
    <?php foreach($streams as $s): ?>
    <div style="padding:16px;background:rgba(30,15,60,0.85);border:1px solid rgba(155,0,255,0.35);border-radius:10px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <label style="color:#cc88ff;font-weight:600;text-transform:uppercase;font-size:13px;letter-spacing:0.05em;white-space:nowrap;flex-shrink:0;">
                <?= htmlspecialchars($s['name']) ?>
            </label>
            <!-- Кнопка глаз (статистика) -->
            <button type="button"
                    onclick="window.location.href='stats.php?stream_id=<?= $s['id'] ?>'"
                    title="Статистика"
                    style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#ffc107;box-shadow:0 0 8px #ffc107;flex-shrink:0;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="#1b1b2f"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zm0 12.5a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg>
            </button>
            <!-- Кнопка удаления (красная с крестиком) -->
            <form method="post" style="margin:0;" onsubmit="return confirm('Вы уверены, что хотите удалить кампанию и всю статистику?');">
                <input type="hidden" name="delete_id" value="<?= $s['id'] ?>">
                <button type="submit"
                        title="Удалить кампанию"
                        style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#dc3545;box-shadow:0 0 8px #dc3545;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="#fff"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                </button>
            </form>
        </div>
        <code style="display:block;background:rgba(0,0,0,0.3);padding:8px 12px;border-radius:6px;font-size:13px;color:#fff;word-break:break-all;border:1px solid rgba(155,0,255,0.2);text-align:left;">
            <?= htmlspecialchars($s['slug']) ?>
        </code>
    </div>
<?php endforeach; ?>
    </div>
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

    </div><!-- /page-content -->

</div><!-- /main-wrapper -->

<script>
(function () {
    var SIDEBAR_KEY  = 'sidebar_collapsed';
    var ACCORDION_KEY = 'campaigns_open';
    var body     = document.body;
    var btn      = document.getElementById('hamburgerBtn');
    var toggle   = document.getElementById('campaignsToggle');
    var subnav   = document.getElementById('campaignsSubnav');

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
