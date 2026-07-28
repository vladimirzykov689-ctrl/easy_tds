<?php
require 'config.php';
$db = initDB();
checkAuth();
checkIP();

// ── Удаление одной кампании ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];
    deleteCampaign($db, $delete_id);
    header('Location: campaigns.php');
    exit;
}

// ── Удаление нескольких выделенных кампаний ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ids'])) {
    $ids = array_filter(array_map('intval', explode(',', $_POST['delete_ids'])));
    foreach ($ids as $id) {
        deleteCampaign($db, $id);
    }
    header('Location: campaigns.php');
    exit;
}

// ── Удаление всех кампаний ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all'])) {
    $db->exec("DELETE FROM logs");
    $db->exec("DELETE FROM conversions");
    $db->exec("DELETE FROM goals");
    $db->exec("DELETE FROM streams");
    $db->exec("UPDATE sqlite_sequence SET seq = 0 WHERE name='streams'");
    header('Location: campaigns.php');
    exit;
}

function deleteCampaign(PDO $db, int $id): void {
    $stmt = $db->prepare("DELETE FROM logs WHERE stream_id=?");
    $stmt->execute([$id]);
    $stmt = $db->prepare("DELETE FROM conversions WHERE stream_id=?");
    $stmt->execute([$id]);
    $stmt = $db->prepare("DELETE FROM goals WHERE stream_id=?");
    $stmt->execute([$id]);
    $stmt = $db->prepare("DELETE FROM streams WHERE id=?");
    $stmt->execute([$id]);
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

$allowedPerPage = [10, 25, 50, 100];
$perPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowedPerPage) ? (int)$_GET['per_page'] : 10;
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;
$total   = $db->query("SELECT COUNT(*) FROM streams")->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $db->prepare("SELECT * FROM streams ORDER BY id ASC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$streams = $stmt->fetchAll(PDO::FETCH_ASSOC);

$createdCampaignName = isset($_GET['created']) ? trim($_GET['created']) : '';

// ── Профит по каждой кампании (одним запросом) ────────────────────────────────
$currencySymbols = ['USD' => '$', 'EUR' => '€', 'RUB' => '₽'];
$profitMap = [];
try {
    $stmtP = $db->query("
        SELECT c.stream_id, SUM(c.value) AS total, MIN(g.currency) AS currency
        FROM conversions c
        JOIN goals g ON g.id = c.goal_id
        WHERE g.is_revenue = 1
        GROUP BY c.stream_id
    ");
    foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $profitMap[(int)$row['stream_id']] = [
            'total'    => (float)$row['total'],
            'currency' => $row['currency'],
        ];
    }
} catch (Exception $e) { $profitMap = []; }
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Кампании — Easy TDS</title>
<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        <div class="profile-menu" id="profileMenu">
            <button class="profile-avatar" id="profileAvatarBtn" type="button" aria-label="Профиль">
                <?= htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['username'] ?? 'A', 0, 1))) ?>
            </button>
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
                <a href="main.php">
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
                <a href="campaigns.php" class="active">
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
                    <h2 class="page-title">Кампании</h2>
                    <div class="page-breadcrumb"><a href="main.php" class="page-breadcrumb-link">Easy TDS</a> <span>›</span> Кампании</div>
                </div>
                <div class="page-header-actions">
                </div>
            </div>

            <div class="campaigns-list-card">

                <div class="campaigns-list-toolbar">
                    <div class="campaigns-list-toolbar-left">
                        <a href="new_campaign.php" class="campaigns-add-btn" title="Создать кампанию">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        </a>
                        <a href="campaigns.php?export=csv" class="header-icon-btn" style="background:#ffc107;" title="Экспорт всех логов">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="#1b1b2f"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zM8 13h8v1.5H8V13zm0 3h8v1.5H8V16zm0-6h3v1.5H8V10z"/></svg>
                        </a>
                        <a href="campaigns.php?export=goals_csv" class="header-icon-btn" style="background:#28a745;" title="Экспорт всех целей">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="#fff"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.88-11.71L10 14.17l-1.88-1.88a.996.996 0 1 0-1.41 1.41l2.59 2.59c.39.39 1.02.39 1.41 0L17.3 9.7a.996.996 0 0 0 0-1.41c-.39-.39-1.03-.39-1.42 0z"/></svg>
                        </a>
                        <button type="button" id="bulkDeleteBtn" class="campaigns-bulk-delete-btn" style="display:none;">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M9 3v1H4v2h1v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6h1V4h-5V3H9zm0 5h2v9H9V8zm4 0h2v9h-2V8z"/></svg>
                            <span>Удалить выбранные (<span id="bulkDeleteCount">0</span>)</span>
                        </button>
                    </div>
                    <div class="campaigns-search-wrap">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                        <input type="text" id="campaignsSearchInput" placeholder="Поиск по названию, ID или slug...">
                    </div>
                </div>

                <?php if (empty($streams)): ?>
                    <div class="campaigns-table-empty" style="padding:30px 14px;text-align:center;">Не найдено кампаний</div>
                <?php else: ?>
                <div class="campaigns-table-wrap">
                    <table class="campaigns-data-table" id="campaignsTable">
                        <thead>
                            <tr>
                                <th class="col-checkbox"><input type="checkbox" id="selectAllCheckbox"></th>
                                <th>Название</th>
                                <th>Slug</th>
                                <th>Профит</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($streams as $s):
                                $sid = (int)$s['id'];
                                $profitInfo = $profitMap[$sid] ?? null;
                                $profitVal  = $profitInfo['total'] ?? 0.0;
                                $profitSym  = $currencySymbols[$profitInfo['currency'] ?? ''] ?? '$';
                            ?>
                            <tr data-name="<?= htmlspecialchars(mb_strtolower($s['name'])) ?>" data-id="<?= $sid ?>" data-slug="<?= htmlspecialchars(mb_strtolower($s['slug'])) ?>">
                                <td class="col-checkbox"><input type="checkbox" class="row-checkbox" value="<?= $sid ?>"></td>
                                <td>
                                    <a href="stats.php?campaign=<?= urlencode($s['slug']) ?>" class="campaigns-name-link"><?= htmlspecialchars($s['name']) ?></a>
                                    <span class="campaigns-id-inline">#<?= $sid ?></span>
                                </td>
                                <td class="campaigns-id-cell"><?= htmlspecialchars($s['slug']) ?></td>
                                <td class="campaigns-profit-cell"><?= number_format($profitVal, 2) . $profitSym ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="no-data" id="campaignsSearchEmpty" style="display:none;">Ничего не найдено</div>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>&per_page=<?= $perPage ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>

            </div>

            <form id="deleteSelectedForm" method="post" style="display:none;">
                <input type="hidden" name="delete_ids" id="deleteIdsInput" value="">
            </form>
            <form id="deleteAllForm" method="post" style="display:none;">
                <input type="hidden" name="delete_all" value="1">
            </form>

            <?php if ($createdCampaignName !== ''): ?>
            <div class="bottom-toast" id="bottomToast">
                <div class="bottom-toast-header">
                    <span class="bottom-toast-title"><?= htmlspecialchars($createdCampaignName) ?></span>
                    <div class="bottom-toast-header-right">
                        <span class="bottom-toast-time">только что</span>
                        <button type="button" class="bottom-toast-close" id="bottomToastClose" aria-label="Закрыть">&times;</button>
                    </div>
                </div>
                <div class="bottom-toast-body">Успешно создана</div>
            </div>
            <?php endif; ?>

        </div><!-- /content -->
    </div><!-- /page-content -->

</div><!-- /main-wrapper -->

<script>
(function () {
    var SIDEBAR_KEY = 'sidebar_collapsed';
    var body = document.body;
    var btn  = document.getElementById('hamburgerBtn');

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
}());
</script>

<script>
(function () {
    var selectAll     = document.getElementById('selectAllCheckbox');
    var rowCheckboxes = Array.prototype.slice.call(document.querySelectorAll('.row-checkbox'));
    var bulkBtn       = document.getElementById('bulkDeleteBtn');
    var bulkCount     = document.getElementById('bulkDeleteCount');
    var deleteForm    = document.getElementById('deleteSelectedForm');
    var deleteIds     = document.getElementById('deleteIdsInput');
    var searchInput   = document.getElementById('campaignsSearchInput');
    var table         = document.getElementById('campaignsTable');

    function updateBulkButton() {
        var checked = rowCheckboxes.filter(function (cb) { return cb.checked; });
        if (checked.length > 0) {
            bulkBtn.style.display = 'inline-flex';
            bulkCount.textContent = checked.length;
        } else {
            bulkBtn.style.display = 'none';
        }
        if (selectAll) {
            selectAll.checked = checked.length === rowCheckboxes.length && rowCheckboxes.length > 0;
        }
    }

    rowCheckboxes.forEach(function (cb) {
        cb.addEventListener('change', updateBulkButton);
    });

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rowCheckboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
            updateBulkButton();
        });
    }

    if (bulkBtn) {
        bulkBtn.addEventListener('click', function () {
            var ids = rowCheckboxes.filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
            if (ids.length === 0) return;
            if (!confirm('Удалить выбранные кампании (' + ids.length + ') и всю их статистику?')) return;
            deleteIds.value = ids.join(',');
            deleteForm.submit();
        });
    }

    if (searchInput && table) {
        var emptyMsg = document.getElementById('campaignsSearchEmpty');
        searchInput.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            var rows = table.querySelectorAll('tbody tr');
            var visibleCount = 0;
            rows.forEach(function (row) {
                var name = row.getAttribute('data-name') || '';
                var id   = row.getAttribute('data-id') || '';
                var slug = row.getAttribute('data-slug') || '';
                var match = !q || name.indexOf(q) !== -1 || id.indexOf(q) !== -1 || slug.indexOf(q) !== -1;
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });
            if (emptyMsg) {
                emptyMsg.style.display = visibleCount === 0 ? '' : 'none';
            }
            table.style.display = visibleCount === 0 ? 'none' : '';
        });
    }

    var perPageWrapper = document.getElementById('perPageWrapper');
    var perPageTrigger = document.getElementById('perPageTrigger');
    var perPageValue   = document.getElementById('perPageValue');
    if (perPageWrapper && perPageTrigger) {
        perPageTrigger.addEventListener('click', function (e) {
            e.stopPropagation();
            perPageWrapper.classList.toggle('open');
        });
        perPageWrapper.querySelectorAll('.custom-select-option').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var val = this.getAttribute('data-value');
                perPageValue.textContent = val;
                var url = new URL(window.location.href);
                url.searchParams.set('per_page', val);
                url.searchParams.set('page', '1');
                window.location.href = url.toString();
            });
        });
        document.addEventListener('click', function (e) {
            if (!perPageWrapper.contains(e.target)) {
                perPageWrapper.classList.remove('open');
            }
        });
    }

    window.confirmDeleteAll = function (e) {
        e.preventDefault();
        if (confirm('Вы уверены, что хотите удалить все кампании и всю статистику?')) {
            document.getElementById('deleteAllForm').submit();
        }
    };

    var bottomToast = document.getElementById('bottomToast');
    if (bottomToast) {
        var bottomToastClose = document.getElementById('bottomToastClose');
        var hideTimer = setTimeout(hideBottomToast, 5000);

        function hideBottomToast() {
            bottomToast.classList.add('bottom-toast-hide');
            setTimeout(function () { bottomToast.remove(); }, 300);
        }

        bottomToastClose.addEventListener('click', function () {
            clearTimeout(hideTimer);
            hideBottomToast();
        });
    }
}());
</script>

</body>
</html>
