<?php 
require 'config.php';
$db = initDB();
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

    $stmt = $db->prepare("DELETE FROM goals WHERE stream_id=?");
    $stmt->execute([$delete_id]);
    
    $stmt = $db->prepare("DELETE FROM conversions WHERE stream_id=?");
    $stmt->execute([$delete_id]);

    $db->exec("UPDATE sqlite_sequence SET seq = (SELECT MAX(id) FROM streams) WHERE name='streams'");

    header('Location: campaigns.php'); 
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all'])) {
    $db->exec("DELETE FROM logs");
    $db->exec("DELETE FROM streams");
    $db->exec("DELETE FROM conversions");
    $db->exec("DELETE FROM goals");
    $db->exec("UPDATE sqlite_sequence SET seq = 0 WHERE name='streams'");

    header('Location: campaigns.php'); 
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

    fputcsv($output, ['Кампания','Дата','Девайс','UA','IP','Гео','Провайдер','PTR','Ключевики','Click ID'], ';');

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
            $row['keyword'] ?? '',
            $row['click_id'] ?? ''
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

<!-- ========== TOP HEADER ========== -->
<header class="top-header">
    <button class="hamburger-btn" id="hamburgerBtn" title="Свернуть меню" aria-label="Toggle sidebar">
        <span></span>
        <span></span>
        <span></span>
    </button>
    <a href="main.php" class="top-header-title" style="text-decoration:none; color:inherit;">Easy TDS</a>
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

            <!-- === Кампании — группа с аккордеоном === -->
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
                                    <path d="M5 20h14v2H5v-2zm7-2L5.5 11H9V4h6v7h3.5L12 18z"/>
                                </svg>
                            </span>
                            <span class="nav-label">Экспорт CSV</span>
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
                            <path d="M8 1a6 6 0 1 0 0 12 6 6 0 0 0 0-12zm0 2a4 4 0 1 1 0 8A4 4 0 0 1 8 3zm0 1.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM8 6a1 1 0 1 1 0 2A1 1 0 0 1 8 6zm3.8 1.5H22v3h-1.5v2H19v-2h-1.5v2H16v-2h-1.5v-3z"/>
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

            <!-- Campaigns list -->
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

        </div><!-- /content -->

        <div class="footer">Easy TDS</div>
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

    /* --- Restore sidebar state --- */
    if (localStorage.getItem(SIDEBAR_KEY) === '1') {
        body.classList.add('sidebar-collapsed');
    }

    /* --- Restore accordion state (default: open) --- */
    var accordionOpen = localStorage.getItem(ACCORDION_KEY) !== '0';
    setAccordion(accordionOpen, false);

    /* --- Hamburger click --- */
    btn.addEventListener('click', function () {
        body.classList.toggle('sidebar-collapsed');
        localStorage.setItem(
            SIDEBAR_KEY,
            body.classList.contains('sidebar-collapsed') ? '1' : '0'
        );
    });

    /* --- Accordion toggle click --- */
    toggle.addEventListener('click', function () {
        var isOpen = subnav.classList.contains('open');
        setAccordion(!isOpen, true);
    });

    /* --- Delete all confirmation --- */
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
