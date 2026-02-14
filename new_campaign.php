<?php
require 'config.php';
$db = initDB();
checkAuth();
checkIP();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'], $_POST['slug'], $_POST['url'])) {
    $name = trim($_POST['name']);
    $slug = trim($_POST['slug']);
    $urlInput = trim($_POST['url']);

    if (empty($name) || empty($slug) || empty($urlInput)) {
        $error = "Пожалуйста, заполните все обязательные поля: Название, Идентификатор и URL.";
    } else {
        $stmtCheckName = $db->prepare("SELECT COUNT(*) FROM streams WHERE name=?");
        $stmtCheckName->execute([$name]);
        if ($stmtCheckName->fetchColumn() > 0) {
            $error = "Кампания с таким названием уже существует.";
        }

        $stmtCheckSlug = $db->prepare("SELECT COUNT(*) FROM streams WHERE slug=?");
        $stmtCheckSlug->execute([$slug]);
        if ($stmtCheckSlug->fetchColumn() > 0) {
            $error = "Идентификатор кампании уже занят.";
        }

        if (empty($error)) {
            $urlList = array_map('trim', explode(',', $urlInput));
            $urlList = array_filter($urlList, fn($u) => !empty($u));
            $url = implode(',', $urlList);

            $geoFilterType = $_POST['geo_filter_type'] ?? 'none';
            $geoFilterList = $geoFilterType !== 'none' ? trim($_POST['geo_filter_list'] ?? '') : '';
            $geoRedirectList = $geoFilterType !== 'none' ? array_map('trim', explode(',', trim($_POST['geo_redirect_urls'] ?? ''))) : [];
            $geoRedirectUrls = implode(',', array_filter($geoRedirectList, fn($u) => !empty($u)));

            $botFilter = $_POST['bot_filter'] ?? 'off';
            $botRedirectList = $botFilter === 'on' ? array_map('trim', explode(',', trim($_POST['bot_redirect_urls'] ?? ''))) : [];
            $botRedirectUrls = implode(',', array_filter($botRedirectList, fn($u) => !empty($u)));

            $stmt = $db->prepare("
                INSERT INTO streams 
                (name, slug, url, geo_filter_type, geo_filter_list, geo_redirect_urls, bot_filter, bot_redirect_urls) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $slug, $url, $geoFilterType, $geoFilterList, $geoRedirectUrls, $botFilter, $botRedirectUrls]);

            header('Location: campaigns.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Новая кампания</title>
<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">
<script>
function toggleGeoInputs() {
    const type = document.getElementById('geo_filter_type').value;
    const show = type !== 'none';
    document.getElementById('geo_label').style.display = show ? 'block' : 'none';
    document.getElementById('geo_filter_list').style.display = show ? 'block' : 'none';
    document.getElementById('geo_list_note').style.display = show ? 'block' : 'none';
    document.getElementById('geo_redirect_label').style.display = show ? 'block' : 'none';
    document.getElementById('geo_redirect_urls').style.display = show ? 'block' : 'none';
    document.getElementById('geo_redirect_note').style.display = show ? 'block' : 'none';
}

function toggleBotInputs() {
    const type = document.getElementById('bot_filter').value;
    document.getElementById('bot_redirect_label').style.display = type === 'on' ? 'block' : 'none';
    document.getElementById('bot_redirect_urls').style.display = type === 'on' ? 'block' : 'none';
    document.getElementById('bot_redirect_note').style.display = type === 'on' ? 'block' : 'none';
}

window.addEventListener('DOMContentLoaded', () => {
    toggleGeoInputs();
    toggleBotInputs();
});
</script>
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
                        <a href="new_campaign.php" class="active">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M11 11V5h2v6h6v2h-6v6h-2v-6H5v-2z"/>
                                </svg>
                            </span>
                            <span class="nav-label">Новая кампания</span>
                        </a>
                    </li>
                    <li>
                        <a href="campaigns.php?export=csv">
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
                        <form id="deleteAllForm" method="post" action="campaigns.php" style="display:none;">
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
        <div class="content content-centered">

            <div class="add-form">
                <h2>Создание новой кампании</h2>

                <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>

                <form method="post">
                    <label for="name">Название кампании:</label>
                    <input type="text" id="name" name="name" required>

                    <label for="slug">Идентификатор кампании:</label>
                    <input type="text" id="slug" name="slug" required>

                    <label for="url">URL для перенаправления:</label>
                    <textarea id="url" name="url" required></textarea>
                    <div class="note">Можно указать несколько ссылок через запятую</div>

                    <label for="geo_filter_type">GEO-фильтр:</label>
                    <select id="geo_filter_type" name="geo_filter_type" onchange="toggleGeoInputs()">
                        <option value="none">Не использовать</option>
                        <option value="allow">Отбирать</option>
                        <option value="deny">Исключать</option>
                    </select>

                    <label id="geo_label" for="geo_filter_list" style="display:none;">Список кодов стран:</label>
                    <textarea id="geo_filter_list" name="geo_filter_list" style="display:none;"></textarea>
                    <div id="geo_list_note" class="note" style="display:none;">
                        Укажите коды стран через запятую. Например: US,RU,DE
                    </div>

                    <label id="geo_redirect_label" for="geo_redirect_urls" style="display:none;">URL для пользователей, не прошедших фильтр:</label>
                    <textarea id="geo_redirect_urls" name="geo_redirect_urls" style="display:none;"></textarea>
                    <div id="geo_redirect_note" class="note" style="display:none;">
                        Можно указать несколько ссылок через запятую
                    </div>

                    <label for="bot_filter">Фильтр ботов:</label>
                    <select id="bot_filter" name="bot_filter" onchange="toggleBotInputs()">
                        <option value="off">Отключить</option>
                        <option value="on">Включить</option>
                    </select>

                    <label id="bot_redirect_label" for="bot_redirect_urls" style="display:none;">URL для ботов:</label>
                    <textarea id="bot_redirect_urls" name="bot_redirect_urls" style="display:none;"></textarea>
                    <div id="bot_redirect_note" class="note" style="display:none;">
                        Можно указать несколько ссылок через запятую
                    </div>

                    <button type="submit">Создать кампанию</button>
                </form>
            </div>

        </div><!-- /content -->

        <div class="footer">Easy TDS</div>
    </div><!-- /page-content -->

</div><!-- /main-wrapper -->

<script>
(function () {
    var SIDEBAR_KEY   = 'sidebar_collapsed';
    var ACCORDION_KEY = 'campaigns_open';
    var body    = document.body;
    var btn     = document.getElementById('hamburgerBtn');
    var toggle  = document.getElementById('campaignsToggle');
    var subnav  = document.getElementById('campaignsSubnav');

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
