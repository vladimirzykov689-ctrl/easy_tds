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

            $streamId = $db->lastInsertId();

            $goalsMode = $_POST['goals_mode'] ?? 'none';
            if ($goalsMode === 'add') {
                $goalNames      = $_POST['goal_name']     ?? [];
                $goalParams     = $_POST['goal_param']    ?? [];
                $goalTypes      = $_POST['goal_type']     ?? [];
                $goalCurrencies = $_POST['goal_currency'] ?? [];

                $stmtGoal = $db->prepare("
                    INSERT INTO goals (stream_id, name, param_name, value_type, is_revenue, currency)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($goalNames as $i => $goalName) {
                    $goalName  = trim($goalName);
                    $goalParam = trim($goalParams[$i] ?? '');
                    $goalType  = $goalTypes[$i] ?? 'flag';

                    if (empty($goalName) || empty($goalParam)) continue;

                    $valueType = $goalType === 'profit' ? 'amount' : 'flag';
                    $isRevenue = $goalType === 'profit' ? 1 : 0;
                    $currency  = $goalType === 'profit' ? ($goalCurrencies[$i] ?? 'USD') : null;

                    $stmtGoal->execute([$streamId, $goalName, $goalParam, $valueType, $isRevenue, $currency]);
                }
            }

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
<style>
.add-form { max-width: 100% !important; }

/* Toast */
.toast {
    display: none; position: fixed; top: 32px; left: 50%;
    transform: translateX(-50%); z-index: 9999;
    padding: 12px 28px; border-radius: 8px; font-size: 14px;
    font-weight: 600; box-shadow: 0 4px 24px rgba(0,0,0,0.5);
    opacity: 0; transition: opacity 0.3s ease; white-space: nowrap;
}
.toast.error   { background: rgba(60,20,20,0.97); border: 1px solid #dc3545; color: #ff6666; }
.toast.visible { opacity: 1; }

/* Custom select — тот же стиль что в bots/credentials */
.custom-select-wrapper {
    position: relative;
    user-select: none;
}
.custom-select-trigger {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 12px; border-radius: 6px; cursor: pointer;
    background: rgba(0,0,0,0.3); border: 1px solid rgba(155,0,255,0.3);
    color: #fff; font-size: 13px; transition: border-color 0.2s;
}
.custom-select-trigger:hover { border-color: #cc88ff; }
.custom-select-wrapper.open .custom-select-trigger { border-color: #cc88ff; }
.custom-select-options {
    display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0;
    background: #1e1230; border: 1px solid rgba(155,0,255,0.4);
    border-radius: 6px; z-index: 100; overflow: hidden;
}
.custom-select-wrapper.open .custom-select-options { display: block; }
.custom-select-option {
    padding: 9px 12px; font-size: 13px; color: #ccc; cursor: pointer;
    transition: background 0.15s;
}
.custom-select-option:hover    { background: rgba(155,0,255,0.15); color: #fff; }
.custom-select-option.selected { background: rgba(155,0,255,0.25); color: #cc88ff; }

/* Двухколоночный layout формы */
.campaign-form-grid {
    display: flex; gap: 24px; align-items: flex-start; margin-bottom: 24px;
}
.campaign-form-col { flex: 1; }

/* Блок секции */
.form-section {
    background: rgba(30,15,60,0.85);
    border: 1px solid rgba(155,0,255,0.35);
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 16px;
}
.form-section label {
    color: #cc88ff; font-weight: 600; text-transform: uppercase;
    font-size: 13px; letter-spacing: 0.05em; display: block; margin-bottom: 6px;
}
.form-section input[type=text],
.form-section textarea {
    width: 100%; padding: 8px 12px; border-radius: 6px;
    background: rgba(0,0,0,0.3); border: 1px solid rgba(155,0,255,0.3);
    color: #fff; font-size: 13px; box-sizing: border-box;
    font-family: inherit; resize: vertical;
}
.form-section input[type=text]:focus,
.form-section textarea:focus {
    outline: none; border-color: #cc88ff;
}
.form-section .note {
    font-size: 11px; color: #666; margin-top: 4px;
}

/* Секция целей */
.goals-section {
    background: rgba(30,15,60,0.85);
    border: 1px solid rgba(155,0,255,0.35);
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 24px;
}
.goals-section > label {
    color: #cc88ff; font-weight: 600; text-transform: uppercase;
    font-size: 13px; letter-spacing: 0.05em; display: block; margin-bottom: 8px;
}

/* Кнопка сабмит */
.btn-create {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 10px 32px; background: #28a745; color: #fff;
    border: none; border-radius: 8px; font-size: 14px; font-weight: 600;
    cursor: pointer; box-shadow: 0 0 10px #28a745; transition: background 0.2s;
}
.btn-create:hover { background: #1e7e34; }

/* geo/bot подсекции */
.sub-section { margin-top: 14px; padding-top: 14px; border-top: 1px solid rgba(155,0,255,0.2); }
</style>
<script>
/* ---- Custom Select ---- */
function toggleCustomSelect(wrapperId) {
    var wrapper = document.getElementById(wrapperId);
    var isOpen = wrapper.classList.contains('open');
    document.querySelectorAll('.custom-select-wrapper.open').forEach(function(w) {
        w.classList.remove('open');
    });
    if (!isOpen) wrapper.classList.add('open');
}
function selectOption(wrapperId, inputId, value, label, el) {
    document.getElementById(inputId).value = value;
    document.getElementById('label_' + inputId).textContent = label;
    el.closest('.custom-select-options').querySelectorAll('.custom-select-option').forEach(function(o) {
        o.classList.remove('selected');
    });
    el.classList.add('selected');
    document.getElementById(wrapperId).classList.remove('open');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.custom-select-wrapper')) {
        document.querySelectorAll('.custom-select-wrapper.open').forEach(function(w) {
            w.classList.remove('open');
        });
    }
});

/* ---- Toggle sections ---- */
function toggleGeoInputs() {
    var type = document.getElementById('geo_filter_type').value;
    var show = type !== 'none';
    document.getElementById('geo_sub').style.display = show ? 'block' : 'none';
}
function toggleBotInputs() {
    var type = document.getElementById('bot_filter').value;
    document.getElementById('bot_sub').style.display = type === 'on' ? 'block' : 'none';
}
function toggleGoalsInputs() {
    var mode = document.getElementById('goals_mode').value;
    document.getElementById('goals_container').style.display = mode === 'add' ? 'block' : 'none';
}

function selectGoalType(el, value) {
    var goalItem = el.closest('.goal-item');
    goalItem.querySelector('input[name="goal_type[]"]').value = value;
    goalItem.querySelector('.goal-type-label').textContent = el.textContent;
    el.closest('.custom-select-options').querySelectorAll('.custom-select-option').forEach(function(o){ o.classList.remove('selected'); });
    el.classList.add('selected');
    el.closest('.custom-select-wrapper').classList.remove('open');
    goalItem.querySelector('.goal-currency-block').style.display = value === 'profit' ? 'block' : 'none';
}
function selectGoalCurrency(el, value, label) {
    var goalItem = el.closest('.goal-item');
    goalItem.querySelector('input[name="goal_currency[]"]').value = value;
    goalItem.querySelector('.goal-currency-label').textContent = label;
    el.closest('.custom-select-options').querySelectorAll('.custom-select-option').forEach(function(o){ o.classList.remove('selected'); });
    el.classList.add('selected');
    el.closest('.custom-select-wrapper').classList.remove('open');
}
function addGoal() {
    var container = document.getElementById('goals_list');
    var div = document.createElement('div');
    div.className = 'goal-item';
    div.innerHTML =
        '<button type="button" class="remove-goal-btn" onclick="removeGoal(this)">&times;</button>' +
        '<label>Имя цели:</label>' +
        '<input type="text" name="goal_name[]" placeholder="Например: Регистрация" style="margin-bottom:8px;">' +
        '<label>Параметр:</label>' +
        '<input type="text" name="goal_param[]" placeholder="Например: reg" style="margin-bottom:8px;">' +
        '<label>Тип цели:</label>' +
'<div class="custom-select-wrapper" id="wrap_goal_type_' + Date.now() + '">' +
  '<div class="custom-select-trigger" onclick="toggleCustomSelect(this.parentElement.id)">' +
    '<span class="goal-type-label">Целевое действие</span>' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="#cc88ff"><path d="M7 10l5 5 5-5H7z"/></svg>' +
  '</div>' +
  '<div class="custom-select-options">' +
    '<div class="custom-select-option selected" onclick="selectGoalType(this,\'flag\')">Целевое действие</div>' +
    '<div class="custom-select-option" onclick="selectGoalType(this,\'profit\')">Профит</div>' +
  '</div>' +
  '<input type="hidden" name="goal_type[]" value="flag">' +
'</div>' +
'<div class="goal-currency-block" style="display:none;margin-top:8px;">' +
  '<label>Валюта профита:</label>' +
  '<div class="custom-select-wrapper" id="wrap_goal_currency_' + Date.now() + 1 + '">' +
    '<div class="custom-select-trigger" onclick="toggleCustomSelect(this.parentElement.id)">' +
      '<span class="goal-currency-label">USD ($)</span>' +
      '<svg width="16" height="16" viewBox="0 0 24 24" fill="#cc88ff"><path d="M7 10l5 5 5-5H7z"/></svg>' +
    '</div>' +
    '<div class="custom-select-options">' +
      '<div class="custom-select-option selected" onclick="selectGoalCurrency(this,\'USD\',\'USD ($)\')">USD ($)</div>' +
      '<div class="custom-select-option" onclick="selectGoalCurrency(this,\'EUR\',\'EUR (€)\')">EUR (€)</div>' +
      '<div class="custom-select-option" onclick="selectGoalCurrency(this,\'RUB\',\'RUB (₽)\')">RUB (₽)</div>' +
    '</div>' +
    '<input type="hidden" name="goal_currency[]" value="USD">' +
  '</div>' +
'</div>';
    container.appendChild(div);
}
function removeGoal(btn) {
    var item = btn.closest('.goal-item');
    var container = document.getElementById('goals_list');
    if (container.querySelectorAll('.goal-item').length > 1) item.remove();
}

/* ---- Toast ---- */
function showToast(message, type) {
    var t = document.getElementById('toast');
    t.textContent = message;
    t.className = 'toast ' + (type || 'error');
    t.style.display = 'block';
    setTimeout(function() { t.classList.add('visible'); }, 10);
    setTimeout(function() {
        t.classList.remove('visible');
        setTimeout(function() { t.style.display = 'none'; }, 300);
    }, 3500);
}

window.addEventListener('DOMContentLoaded', function() {
    toggleGeoInputs();
    toggleBotInputs();
    toggleGoalsInputs();
    addGoal();
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
                            <path d="M16 13v-2H7V8l-5 4 5 4v-3h9zm2-11H6a2 2 0 0 0-2 2v4h2V4h12v16H6v-4H4v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4a2 2 0 0-2-2z"/>
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

        <div id="toast" class="toast"></div>

        <?php if (!empty($error)): ?>
        <script>
        window.addEventListener('DOMContentLoaded', function() {
            showToast('<?= addslashes(htmlspecialchars($error)) ?>', 'error');
        });
        </script>
        <?php endif; ?>

<h2 class="campaign-title">Создание новой кампании</h2>

<div class="add-form">
    <form method="post">

                <!-- ДВУХКОЛОНОЧНЫЙ GRID -->
                <div class="campaign-form-grid">

                    <!-- ЛЕВАЯ КОЛОНКА -->
                    <div class="campaign-form-col">

                        <!-- Название -->
                        <div class="form-section">
                            <label for="name">Название кампании</label>
                            <input type="text" id="name" name="name" required placeholder="Например: Campaign #1">
                        </div>

                        <!-- Идентификатор -->
                        <div class="form-section">
                            <label for="slug">Идентификатор кампании</label>
                            <input type="text" id="slug" name="slug" required placeholder="Например: camp1">
                        </div>

                        <!-- URL -->
                        <div class="form-section">
                            <label for="url">URL для перенаправления</label>
                            <textarea id="url" name="url" required rows="3" placeholder="https://example.com"></textarea>
                            <div class="note">Можно указать несколько ссылок через запятую</div>
                        </div>

                    </div>
                    <!-- /ЛЕВАЯ -->

                    <!-- ПРАВАЯ КОЛОНКА -->
                    <div class="campaign-form-col">

                        <!-- GEO-фильтр -->
                        <div class="form-section">
                            <label>GEO-фильтр</label>
                            <div class="custom-select-wrapper" id="wrap_geo_filter_type">
                                <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_geo_filter_type')">
                                    <span id="label_geo_filter_type">Не использовать</span>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#cc88ff"><path d="M7 10l5 5 5-5H7z"/></svg>
                                </div>
                                <div class="custom-select-options">
                                    <div class="custom-select-option selected"
                                         onclick="selectOption('wrap_geo_filter_type','geo_filter_type','none','Не использовать',this);toggleGeoInputs()">Не использовать</div>
                                    <div class="custom-select-option"
                                         onclick="selectOption('wrap_geo_filter_type','geo_filter_type','allow','Отбирать',this);toggleGeoInputs()">Отбирать</div>
                                    <div class="custom-select-option"
                                         onclick="selectOption('wrap_geo_filter_type','geo_filter_type','deny','Исключать',this);toggleGeoInputs()">Исключать</div>
                                </div>
                                <input type="hidden" name="geo_filter_type" id="geo_filter_type" value="none">
                            </div>
                            <div id="geo_sub" class="sub-section" style="display:none;">
                                <label>Список кодов стран</label>
                                <textarea name="geo_filter_list" id="geo_filter_list" rows="2" placeholder="US,RU,DE"></textarea>
                                <div class="note">Коды стран через запятую</div>
                                <label style="margin-top:10px;">URL для не прошедших фильтр</label>
                                <textarea name="geo_redirect_urls" id="geo_redirect_urls" rows="2" placeholder="https://fallback.com"></textarea>
                                <div class="note">Можно несколько через запятую</div>
                            </div>
                        </div>

                        <!-- Фильтр ботов -->
                        <div class="form-section">
                            <label>Фильтр ботов</label>
                            <div class="custom-select-wrapper" id="wrap_bot_filter">
                                <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_bot_filter')">
                                    <span id="label_bot_filter">Отключить</span>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#cc88ff"><path d="M7 10l5 5 5-5H7z"/></svg>
                                </div>
                                <div class="custom-select-options">
                                    <div class="custom-select-option selected"
                                         onclick="selectOption('wrap_bot_filter','bot_filter','off','Отключить',this);toggleBotInputs()">Отключить</div>
                                    <div class="custom-select-option"
                                         onclick="selectOption('wrap_bot_filter','bot_filter','on','Включить',this);toggleBotInputs()">Включить</div>
                                </div>
                                <input type="hidden" name="bot_filter" id="bot_filter" value="off">
                            </div>
                            <div id="bot_sub" class="sub-section" style="display:none;">
                                <label>URL для ботов</label>
                                <textarea name="bot_redirect_urls" id="bot_redirect_urls" rows="2" placeholder="https://bot-redirect.com"></textarea>
                                <div class="note">Можно несколько через запятую</div>
                            </div>
                        </div>

                    </div>
                    <!-- /ПРАВАЯ -->

                </div>
                <!-- /GRID -->

                <!-- ЦЕЛИ — на всю ширину снизу -->
                <div class="goals-section">
                    <label>Цели кампании</label>
                    <div class="custom-select-wrapper" id="wrap_goals_mode">
                        <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_goals_mode')">
                            <span id="label_goals_mode">Не использовать</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="#cc88ff"><path d="M7 10l5 5 5-5H7z"/></svg>
                        </div>
                        <div class="custom-select-options">
                            <div class="custom-select-option selected"
                                 onclick="selectOption('wrap_goals_mode','goals_mode','none','Не использовать',this);toggleGoalsInputs()">Не использовать</div>
                            <div class="custom-select-option"
                                 onclick="selectOption('wrap_goals_mode','goals_mode','add','Добавить',this);toggleGoalsInputs()">Добавить</div>
                        </div>
                        <input type="hidden" name="goals_mode" id="goals_mode" value="none">
                    </div>

                    <div id="goals_container" style="display:none; margin-top:14px;">
                        <div id="goals_list"></div>
                        <button type="button" onclick="addGoal()" class="add-goal-btn" style="margin-bottom:16px;display:block;margin-left:auto;margin-right:auto;padding:8px 28px;background:#ffc107;color:#1b1b2f;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 0 8px #ffc107;width:auto;">+ Добавить цель</button>
                    </div>
                </div>

                <div style="text-align:center;">
    <button type="submit" class="btn-create" style="padding:10px 40px;width:auto;">Создать кампанию</button>
</div>

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
