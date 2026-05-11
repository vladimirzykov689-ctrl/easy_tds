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
<title>Фильтр ботов</title>
<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">
<style>
    /* === Модальное окно === */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.7);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }
    .modal-overlay.active { display: flex; }

    .modal {
        background: #242424;
        border: 1px solid #3a3a3a;
        border-radius: 6px;
        width: 680px;
        max-width: 95vw;
        display: flex;
        flex-direction: column;
        max-height: 80vh;
    }

    .modal-header {
        display: flex;
        align-items: center;
        padding: 12px 16px;
        border-bottom: 1px solid #333;
        gap: 10px;
    }
    .modal-header .modal-title {
        flex: 1;
        font-size: 14px;
        font-weight: 600;
        color: #ccc;
    }
    .modal-header .modal-filepath {
        font-size: 11px;
        color: #555;
        font-family: monospace;
    }
    .modal-status {
        font-size: 12px;
        font-weight: 600;
        min-width: 70px;
        text-align: right;
    }
    .modal-status.ok    { color: #6fcf6f; }
    .modal-status.error { color: #ff6666; }

    .modal-body {
        flex: 1;
        overflow: hidden;
        display: flex;
    }
    .modal-body textarea {
        flex: 1;
        background: #1e1e1e;
        color: #d4d4d4;
        border: none;
        outline: none;
        resize: none;
        font-family: 'Courier New', Courier, monospace;
        font-size: 13px;
        line-height: 1.7;
        padding: 14px 16px;
        min-height: 320px;
    }

    .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 10px 16px;
        border-top: 1px solid #333;
    }
    .modal-footer .btn-cancel {
        padding: 7px 18px;
        background: transparent;
        color: #aaa;
        border: 1px solid #444;
        border-radius: 4px;
        font-size: 13px;
        cursor: pointer;
        transition: border-color 0.2s, color 0.2s;
    }
    .modal-footer .btn-cancel:hover { border-color: #aaa; color: #fff; }
    .modal-footer .btn-modal-save {
        padding: 7px 20px;
        background: #4a90d9;
        color: #fff;
        border: none;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }
    .modal-footer .btn-modal-save:hover { background: #357abd; }
    .add-form { max-width: 100% !important; }
    .toast {
    display: none;
    position: fixed;
    top: 32px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 9999;
    padding: 12px 28px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    box-shadow: 0 4px 24px rgba(0,0,0,0.5);
    opacity: 0;
    transition: opacity 0.3s ease;
    white-space: nowrap;
}
.toast.success { background: rgba(30,60,30,0.97); border: 1px solid #28a745; color: #6fcf6f; }
.toast.error   { background: rgba(60,20,20,0.97); border: 1px solid #dc3545; color: #ff6666; }
.toast.visible { opacity: 1; }
</style>
<script>
window.addEventListener('DOMContentLoaded', function () {
    ['ip','isp','ptr','ua'].forEach(function(k) {
        var val = document.getElementById('filter_' + k).value;
        showBotBtns(k, val);
    });
});
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

function showBotBtns(key, value) {
    var editBtn = document.getElementById('edit_' + key);
    var saveBtn = document.getElementById('save_' + key);
    editBtn.style.display = 'inline-flex';
    saveBtn.style.display = 'inline-flex';
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
function toggleCustomSelect(wrapperId) {
    var wrapper = document.getElementById(wrapperId);
    var isOpen = wrapper.classList.contains('open');
    document.querySelectorAll('.custom-select-wrapper.open').forEach(function(w) {
        w.classList.remove('open');
    });
    if (!isOpen) wrapper.classList.add('open');
}

function selectBotOption(wrapperId, inputId, value, label, el) {
    document.getElementById(inputId).value = value;
    document.getElementById('label_' + inputId).textContent = label;
    el.closest('.custom-select-options').querySelectorAll('.custom-select-option').forEach(function(o) {
        o.classList.remove('selected');
    });
    el.classList.add('selected');
    document.getElementById(wrapperId).classList.remove('open');
    var key = inputId.replace('filter_', '');
    showBotBtns(key, value);
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.custom-select-wrapper')) {
        document.querySelectorAll('.custom-select-wrapper.open').forEach(function(w) {
            w.classList.remove('open');
        });
    }
});

function showToast(message, type) {
    var t = document.getElementById('toast');
    t.textContent = message;
    t.className = 'toast ' + (type || 'success');
    t.style.display = 'block';
    setTimeout(function() { t.classList.add('visible'); }, 10);
    setTimeout(function() {
        t.classList.remove('visible');
        setTimeout(function() { t.style.display = 'none'; }, 300);
    }, 3500);
}
</script>
</head>
<body class="dashboard-page">
	<div id="toast" class="toast"></div>

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
</header>

<!-- ========== MAIN WRAPPER ========== -->
<div class="main-wrapper">

    <!-- ========== SIDEBAR ========== -->
    <nav class="sidebar" id="sidebar">
        <ul class="sidebar-nav">

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
                    <button class="nav-arrow-btn" id="campaignsToggle" type="button" title="Развернуть">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M7 10l5 5 5-5H7z"/>
                        </svg>
                    </button>
                </div>
                <ul class="sidebar-subnav" id="campaignsSubnav">
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
                    <li>
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
                <a href="bots.php" class="active">
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

<h2 class="campaign-title">Фильтр ботов</h2>

<div class="add-form">

<?php if (isset($_GET['saved'])): ?>
<script>
window.addEventListener('DOMContentLoaded', function() {
    showToast('✓ Настройки сохранены', 'success');
});
</script>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="save_settings" value="1">

    <!-- СТРОКА 1: IP (слева) + ISP (справа) -->
    <div style="display:flex; gap:24px; align-items:flex-start; margin-bottom:16px;">

        <!-- IP -->
        <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                <label style="color:#cc88ff;font-weight:600;text-transform:uppercase;font-size:13px;letter-spacing:0.05em;margin:0;">Фильтровать по IP:</label>
                <button type="button" id="edit_ip" onclick="openEditor('bots_ip.dat')"
                        style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#ffc107;box-shadow:0 0 8px #ffc107;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                </button>
                <button type="submit" id="save_ip"
                        style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#28a745;box-shadow:0 0 8px #28a745;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                </button>
            </div>
            <div class="custom-select-wrapper" id="wrap_filter_ip">
                <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_filter_ip')">
                    <span id="label_filter_ip"><?= $settings['filter_ip']==='yes' ? 'Да' : 'Нет' ?></span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#cc88ff"><path d="M7 10l5 5 5-5H7z"/></svg>
                </div>
                <div class="custom-select-options">
                    <div class="custom-select-option <?= $settings['filter_ip']==='no' ? 'selected' : '' ?>"
                         onclick="selectBotOption('wrap_filter_ip','filter_ip','no','Нет',this)">Нет</div>
                    <div class="custom-select-option <?= $settings['filter_ip']==='yes' ? 'selected' : '' ?>"
                         onclick="selectBotOption('wrap_filter_ip','filter_ip','yes','Да',this)">Да</div>
                </div>
                <input type="hidden" name="filter_ip" id="filter_ip" value="<?= $settings['filter_ip'] ?>">
            </div>
        </div>

        <!-- ISP -->
        <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                <label style="color:#cc88ff;font-weight:600;text-transform:uppercase;font-size:13px;letter-spacing:0.05em;margin:0;">Фильтровать по ISP:</label>
                <button type="button" id="edit_isp" onclick="openEditor('bots_isp.dat')"
                        style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#ffc107;box-shadow:0 0 8px #ffc107;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                </button>
                <button type="submit" id="save_isp"
                        style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#28a745;box-shadow:0 0 8px #28a745;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                </button>
            </div>
            <div class="custom-select-wrapper" id="wrap_filter_isp">
                <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_filter_isp')">
                    <span id="label_filter_isp"><?= $settings['filter_isp']==='yes' ? 'Да' : 'Нет' ?></span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#cc88ff"><path d="M7 10l5 5 5-5H7z"/></svg>
                </div>
                <div class="custom-select-options">
                    <div class="custom-select-option <?= $settings['filter_isp']==='no' ? 'selected' : '' ?>"
                         onclick="selectBotOption('wrap_filter_isp','filter_isp','no','Нет',this)">Нет</div>
                    <div class="custom-select-option <?= $settings['filter_isp']==='yes' ? 'selected' : '' ?>"
                         onclick="selectBotOption('wrap_filter_isp','filter_isp','yes','Да',this)">Да</div>
                </div>
                <input type="hidden" name="filter_isp" id="filter_isp" value="<?= $settings['filter_isp'] ?>">
            </div>
        </div>

    </div><!-- /строка 1 -->

    <!-- СТРОКА 2: PTR (слева) + UA (справа) -->
    <div style="display:flex; gap:24px; align-items:flex-start; margin-bottom:24px;">

        <!-- PTR -->
        <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                <label style="color:#cc88ff;font-weight:600;text-transform:uppercase;font-size:13px;letter-spacing:0.05em;margin:0;">Фильтровать по PTR:</label>
                <button type="button" id="edit_ptr" onclick="openEditor('bots_ptr.dat')"
                        style="display:none;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#ffc107;box-shadow:0 0 8px #ffc107;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                </button>
                <button type="submit" id="save_ptr"
                        style="display:none;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#28a745;box-shadow:0 0 8px #28a745;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                </button>
            </div>
            <div class="custom-select-wrapper" id="wrap_filter_ptr">
                <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_filter_ptr')">
                    <span id="label_filter_ptr"><?= $settings['filter_ptr']==='yes' ? 'Да' : 'Нет' ?></span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#cc88ff"><path d="M7 10l5 5 5-5H7z"/></svg>
                </div>
                <div class="custom-select-options">
                    <div class="custom-select-option <?= $settings['filter_ptr']==='no' ? 'selected' : '' ?>"
                         onclick="selectBotOption('wrap_filter_ptr','filter_ptr','no','Нет',this)">Нет</div>
                    <div class="custom-select-option <?= $settings['filter_ptr']==='yes' ? 'selected' : '' ?>"
                         onclick="selectBotOption('wrap_filter_ptr','filter_ptr','yes','Да',this)">Да</div>
                </div>
                <input type="hidden" name="filter_ptr" id="filter_ptr" value="<?= $settings['filter_ptr'] ?>">
            </div>
        </div>

        <!-- UA -->
        <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                <label style="color:#cc88ff;font-weight:600;text-transform:uppercase;font-size:13px;letter-spacing:0.05em;margin:0;">Фильтровать по UA:</label>
                <button type="button" id="edit_ua" onclick="openEditor('bots_ua.dat')"
                        style="display:none;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#ffc107;box-shadow:0 0 8px #ffc107;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                </button>
                <button type="submit" id="save_ua"
                        style="display:none;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#28a745;box-shadow:0 0 8px #28a745;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                </button>
            </div>
            <div class="custom-select-wrapper" id="wrap_filter_ua">
                <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_filter_ua')">
                    <span id="label_filter_ua"><?= $settings['filter_ua']==='yes' ? 'Да' : 'Нет' ?></span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#cc88ff"><path d="M7 10l5 5 5-5H7z"/></svg>
                </div>
                <div class="custom-select-options">
                    <div class="custom-select-option <?= $settings['filter_ua']==='no' ? 'selected' : '' ?>"
                         onclick="selectBotOption('wrap_filter_ua','filter_ua','no','Нет',this)">Нет</div>
                    <div class="custom-select-option <?= $settings['filter_ua']==='yes' ? 'selected' : '' ?>"
                         onclick="selectBotOption('wrap_filter_ua','filter_ua','yes','Да',this)">Да</div>
                </div>
                <input type="hidden" name="filter_ua" id="filter_ua" value="<?= $settings['filter_ua'] ?>">
            </div>
        </div>

    </div><!-- /строка 2 -->

</form>			
			</div>

        </div><!-- /content -->
        	
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

    if (localStorage.getItem(SIDEBAR_KEY) === '1') {
        body.classList.add('sidebar-collapsed');
    }

    var accordionOpen = localStorage.getItem(ACCORDION_KEY) === '1';
    setAccordion(accordionOpen, false);

    btn.addEventListener('click', function () {
        body.classList.toggle('sidebar-collapsed');
        localStorage.setItem(SIDEBAR_KEY, body.classList.contains('sidebar-collapsed') ? '1' : '0');
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
        if (open) { subnav.classList.add('open'); toggle.classList.add('open'); }
        else       { subnav.classList.remove('open'); toggle.classList.remove('open'); }
        if (save) localStorage.setItem(ACCORDION_KEY, open ? '1' : '0');
    }
}());
</script>

</body>
</html>
