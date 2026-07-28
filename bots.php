<?php
require 'config.php';
$db = initDB();
checkAuth();
checkIP();

// --- Сохранение настроек фильтра ботов ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $fi  = ($_POST['filter_ip']  ?? 'no') === 'yes' ? 'yes' : 'no';
    $fis = ($_POST['filter_isp'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $fp  = ($_POST['filter_ptr'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $fu  = ($_POST['filter_ua']  ?? 'no') === 'yes' ? 'yes' : 'no';
    $db->prepare("UPDATE bot_settings SET filter_ip=?, filter_isp=?, filter_ptr=?, filter_ua=? WHERE id=1")
       ->execute([$fi, $fis, $fp, $fu]);
    header('Location: bots.php?saved=1');
    exit;
}

// --- Загружаем текущие настройки ---
$settings = $db->query("SELECT * FROM bot_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$settings = $settings ?: ['filter_ip'=>'no','filter_isp'=>'no','filter_ptr'=>'no','filter_ua'=>'no'];

$allowed = ['bots_ip.dat', 'bots_isp.dat', 'bots_ptr.dat', 'bots_ua.dat'];
$botDir  = '/var/www/html/easy_tds/bots/';

// --- Сохранение содержимого файла через AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_file'], $_POST['file'], $_POST['content'])) {
    $f = $_POST['file'];
    if (in_array($f, $allowed)) {
        $result = file_put_contents($botDir . $f, str_replace("\\r\\n", "\\n", $_POST['content']));
        echo $result !== false ? 'ok' : 'error';
    } else {
        echo 'error';
    }
    exit;
}

// --- Чтение содержимого файла через AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['read_file'])) {
    $f = $_GET['read_file'];
    if (in_array($f, $allowed)) {
        $path = $botDir . $f;
        echo file_exists($path) ? file_get_contents($path) : '';
    }
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Фильтр ботов — Easy TDS</title>
<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>
var currentFile = '';
var titles = {
    'bots_ip.dat':  'IP-адреса',
    'bots_isp.dat': 'ISP (провайдеры)',
    'bots_ptr.dat': 'PTR-записи',
    'bots_ua.dat':  'User-Agent'
};

function openEditor(filename) {
    currentFile = filename;
    var overlay  = document.getElementById('modalOverlay');
    var titleEl  = document.getElementById('modalTitle');
    var pathEl   = document.getElementById('modalPath');
    var statusEl = document.getElementById('modalStatus');
    var ta       = document.getElementById('modalTextarea');

    titleEl.textContent  = titles[filename] || filename;
    pathEl.textContent   = '/var/www/html/easy_tds/bots/' + filename;
    statusEl.textContent = '';
    statusEl.className   = 'modal-status';
    ta.value = 'Загрузка...';

    overlay.classList.add('active');
    ta.focus();

    fetch('bots.php?read_file=' + encodeURIComponent(filename))
        .then(function(r) { return r.text(); })
        .then(function(text) { ta.value = text; ta.focus(); })
        .catch(function() { ta.value = ''; });
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('active');
    currentFile = '';
}

function saveFile() {
    var statusEl = document.getElementById('modalStatus');
    var content  = document.getElementById('modalTextarea').value;
    statusEl.textContent = 'Сохранение...';
    statusEl.className   = 'modal-status';

    var fd = new FormData();
    fd.append('save_file', '1');
    fd.append('file', currentFile);
    fd.append('content', content);

    fetch('bots.php', { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(res) {
            if (res.trim() === 'ok') {
                statusEl.textContent = '✓ Сохранено';
                statusEl.className   = 'modal-status ok';
            } else {
                statusEl.textContent = '✗ Ошибка';
                statusEl.className   = 'modal-status error';
            }
        })
        .catch(function() {
            statusEl.textContent = '✗ Ошибка';
            statusEl.className   = 'modal-status error';
        });
}

function onToggleChange(key, checkbox) {
    document.getElementById('filter_' + key).value = checkbox.checked ? 'yes' : 'no';
    document.getElementById('save_' + key).style.display = 'inline-flex';
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('modalOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's' &&
            document.getElementById('modalOverlay').classList.contains('active')) {
            e.preventDefault();
            saveFile();
        }
    });
});

function showBottomToast(title, message, type) {
    var el = document.createElement('div');
    el.className = 'bottom-toast bottom-toast-' + (type || 'success');
    el.innerHTML =
        '<div class="bottom-toast-header">' +
            '<span class="bottom-toast-title">' + title + '</span>' +
            '<div class="bottom-toast-header-right">' +
                '<span class="bottom-toast-time">только что</span>' +
                '<button type="button" class="bottom-toast-close" aria-label="Закрыть">&times;</button>' +
            '</div>' +
        '</div>' +
        '<div class="bottom-toast-body">' + message + '</div>';
    document.body.appendChild(el);

    var timer = setTimeout(hide, 5000);
    function hide() {
        el.classList.add('bottom-toast-hide');
        setTimeout(function () { el.remove(); }, 300);
    }
    el.querySelector('.bottom-toast-close').addEventListener('click', function () {
        clearTimeout(timer);
        hide();
    });
}
</script>
</head>
<body class="dashboard-page">

<!-- ========== MODAL ========== -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="modalTitle"></span>
            <span class="modal-filepath" id="modalPath"></span>
            <span class="modal-status" id="modalStatus"></span>
        </div>
        <div class="modal-body">
            <textarea id="modalTextarea" spellcheck="false"></textarea>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeModal()">Закрыть</button>
            <button class="btn-modal-save" onclick="saveFile()">Сохранить</button>
        </div>
    </div>
</div>

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
                <a href="bots.php" class="active">
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

            <?php if (isset($_GET['saved'])): ?>
            <script>
            window.addEventListener('DOMContentLoaded', function() {
                showBottomToast('Фильтр ботов', 'Настройки сохранены', 'success');
            });
            </script>
            <?php endif; ?>

            <div class="page-header-bar">
                <div class="page-header-titles">
                    <h2 class="page-title">Фильтр ботов</h2>
                    <div class="page-breadcrumb"><a href="main.php" class="page-breadcrumb-link">Easy TDS</a> <span>›</span> Фильтр ботов</div>
                </div>
            </div>

            <div class="new-campaign-wrap">

                <!-- Вкладки -->
                <div class="tabs-nav" id="tabsNav">
                    <button type="button" class="tab-btn tab-btn-active" data-tab="0">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M7 10V7a5 5 0 0110 0v3"/></svg>
                        IP
                    </button>
                    <button type="button" class="tab-btn" data-tab="1">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 4 6 4 9s-1.5 6.4-4 9c-2.5-2.6-4-6-4-9s1.5-6.4 4-9z"/></svg>
                        ISP
                    </button>
                    <button type="button" class="tab-btn" data-tab="2">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v10H8l-4 4V4z"/></svg>
                        PTR
                    </button>
                    <button type="button" class="tab-btn" data-tab="3">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9h.01M7 13h6"/></svg>
                        User-Agent
                    </button>
                </div>

                <form method="post" id="botsForm">
                    <input type="hidden" name="save_settings" value="1">

                    <!-- Панель IP -->
                    <div class="tab-panel tab-panel-active" data-tab-panel="0">
                        <div class="form-card">
                            <div class="form-card-header-row">
                                <h3 class="form-card-title">Фильтрация по IP</h3>
                                <div class="form-card-header-actions">
                                    <button type="button" id="edit_ip" onclick="openEditor('bots_ip.dat')" class="header-icon-btn" style="background:#ffc107;" title="Редактировать список">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                    </button>
                                    <button type="submit" id="save_ip" class="header-icon-btn" style="display:none;background:#28a745;" title="Сохранить">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="toggle-row">
                                <label class="toggle-switch">
                                    <input type="checkbox" <?= $settings['filter_ip']==='yes' ? 'checked' : '' ?> onchange="onToggleChange('ip', this)">
                                    <span class="toggle-switch-slider"></span>
                                </label>
                                <span class="toggle-row-label">Фильтровать трафик по списку IP-адресов</span>
                            </div>
                            <input type="hidden" name="filter_ip" id="filter_ip" value="<?= $settings['filter_ip'] ?>">
                            <div class="form-note">Список адресов редактируется по кнопке карандаша выше</div>
                        </div>
                    </div>

                    <!-- Панель ISP -->
                    <div class="tab-panel" data-tab-panel="1">
                        <div class="form-card">
                            <div class="form-card-header-row">
                                <h3 class="form-card-title">Фильтрация по ISP</h3>
                                <div class="form-card-header-actions">
                                    <button type="button" id="edit_isp" onclick="openEditor('bots_isp.dat')" class="header-icon-btn" style="background:#ffc107;" title="Редактировать список">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                    </button>
                                    <button type="submit" id="save_isp" class="header-icon-btn" style="display:none;background:#28a745;" title="Сохранить">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="toggle-row">
                                <label class="toggle-switch">
                                    <input type="checkbox" <?= $settings['filter_isp']==='yes' ? 'checked' : '' ?> onchange="onToggleChange('isp', this)">
                                    <span class="toggle-switch-slider"></span>
                                </label>
                                <span class="toggle-row-label">Фильтровать трафик по списку провайдеров (ISP)</span>
                            </div>
                            <input type="hidden" name="filter_isp" id="filter_isp" value="<?= $settings['filter_isp'] ?>">
                            <div class="form-note">Список провайдеров редактируется по кнопке карандаша выше</div>
                        </div>
                    </div>

                    <!-- Панель PTR -->
                    <div class="tab-panel" data-tab-panel="2">
                        <div class="form-card">
                            <div class="form-card-header-row">
                                <h3 class="form-card-title">Фильтрация по PTR</h3>
                                <div class="form-card-header-actions">
                                    <button type="button" id="edit_ptr" onclick="openEditor('bots_ptr.dat')" class="header-icon-btn" style="background:#ffc107;" title="Редактировать список">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                    </button>
                                    <button type="submit" id="save_ptr" class="header-icon-btn" style="display:none;background:#28a745;" title="Сохранить">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="toggle-row">
                                <label class="toggle-switch">
                                    <input type="checkbox" <?= $settings['filter_ptr']==='yes' ? 'checked' : '' ?> onchange="onToggleChange('ptr', this)">
                                    <span class="toggle-switch-slider"></span>
                                </label>
                                <span class="toggle-row-label">Фильтровать трафик по PTR-записям</span>
                            </div>
                            <input type="hidden" name="filter_ptr" id="filter_ptr" value="<?= $settings['filter_ptr'] ?>">
                            <div class="form-note">Список PTR-записей редактируется по кнопке карандаша выше</div>
                        </div>
                    </div>

                    <!-- Панель UA -->
                    <div class="tab-panel" data-tab-panel="3">
                        <div class="form-card">
                            <div class="form-card-header-row">
                                <h3 class="form-card-title">Фильтрация по User-Agent</h3>
                                <div class="form-card-header-actions">
                                    <button type="button" id="edit_ua" onclick="openEditor('bots_ua.dat')" class="header-icon-btn" style="background:#ffc107;" title="Редактировать список">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                    </button>
                                    <button type="submit" id="save_ua" class="header-icon-btn" style="display:none;background:#28a745;" title="Сохранить">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="toggle-row">
                                <label class="toggle-switch">
                                    <input type="checkbox" <?= $settings['filter_ua']==='yes' ? 'checked' : '' ?> onchange="onToggleChange('ua', this)">
                                    <span class="toggle-switch-slider"></span>
                                </label>
                                <span class="toggle-row-label">Фильтровать трафик по User-Agent</span>
                            </div>
                            <input type="hidden" name="filter_ua" id="filter_ua" value="<?= $settings['filter_ua'] ?>">
                            <div class="form-note">Список User-Agent строк редактируется по кнопке карандаша выше</div>
                        </div>
                    </div>

                </form>

            </div>

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

(function () {
    var tabBtns   = Array.prototype.slice.call(document.querySelectorAll('.tab-btn'));
    var tabPanels = Array.prototype.slice.call(document.querySelectorAll('.tab-panel'));

    function showTab(idx) {
        tabBtns.forEach(function (b) {
            b.classList.toggle('tab-btn-active', parseInt(b.getAttribute('data-tab'), 10) === idx);
        });
        tabPanels.forEach(function (p) {
            var isTarget = parseInt(p.getAttribute('data-tab-panel'), 10) === idx;
            if (isTarget) {
                p.style.display = 'block';
                void p.offsetWidth;
                p.classList.add('tab-panel-active');
            } else {
                p.classList.remove('tab-panel-active');
                p.style.display = 'none';
            }
        });
    }

    tabBtns.forEach(function (b) {
        b.addEventListener('click', function () {
            showTab(parseInt(b.getAttribute('data-tab'), 10));
        });
    });
}());
</script>

</body>
</html>
