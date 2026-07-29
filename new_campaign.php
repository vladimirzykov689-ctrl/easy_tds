<?php
require 'config.php';
$db = initDB();
checkAuth();
checkIP();

// --- Список доступных фоновых тем (картинок) из папки img ---
$bgThemeDir = __DIR__ . "/img/backgrounds";
$bgThemes = [];
if (is_dir($bgThemeDir)) {
    foreach (glob($bgThemeDir . "/*.{jpg,jpeg,png,webp,gif}", GLOB_BRACE) as $bgFile) {
        $bgThemes[] = basename($bgFile);
    }
    sort($bgThemes);
}

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
                $goalNames        = $_POST['goal_name']         ?? [];
                $goalParams       = $_POST['goal_param']        ?? [];
                $goalTypes        = $_POST['goal_type']         ?? [];
                $goalCurrencies   = $_POST['goal_currency']     ?? [];
                $goalTargetValues = $_POST['goal_target_value'] ?? [];

                $stmtGoal = $db->prepare("
                    INSERT INTO goals (stream_id, name, param_name, value_type, is_revenue, currency, target_value)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($goalNames as $i => $goalName) {
                    $goalName  = trim($goalName);
                    $goalParam = trim($goalParams[$i] ?? '');
                    $goalType  = $goalTypes[$i] ?? 'flag';

                    if (empty($goalName) || empty($goalParam)) continue;

                    $valueType = $goalType === 'profit' ? 'amount' : 'flag';
                    $isRevenue = $goalType === 'profit' ? 1 : 0;
                    $currency  = $goalType === 'profit' ? ($goalCurrencies[$i] ?? 'USD') : null;

                    $targetValue = trim($goalTargetValues[$i] ?? '') ?: null;
                    $stmtGoal->execute([$streamId, $goalName, $goalParam, $valueType, $isRevenue, $currency, $targetValue]);
                }
            }

            header('Location: campaigns.php?created=' . urlencode($name));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Новая кампания — Easy TDS</title>
<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
    goalItem.querySelector('.goal-target-value-block').style.display = value === 'profit' ? 'none' : 'block';
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
    var uid1 = 'wrap_goal_type_' + Date.now() + '_' + Math.floor(Math.random()*1000);
    var uid2 = 'wrap_goal_currency_' + Date.now() + '_' + Math.floor(Math.random()*1000);
    var div = document.createElement('div');
    div.className = 'goal-item';
    div.innerHTML =
        '<button type="button" class="remove-goal-btn" onclick="removeGoal(this)">&times;</button>' +
        '<div class="form-field"><label>Имя цели</label>' +
        '<input type="text" name="goal_name[]" placeholder="Например: Регистрация"></div>' +
        '<div class="form-field"><label>Параметр</label>' +
        '<input type="text" name="goal_param[]" placeholder="Например: reg"></div>' +
        '<div class="form-field goal-target-value-block"><label>Целевое значение <span class="form-note-inline">(необязательно)</span></label>' +
        '<input type="text" name="goal_target_value[]" placeholder="Например: approved — или оставьте пустым"></div>' +
        '<div class="form-field"><label>Тип цели</label>' +
        '<div class="custom-select-wrapper" id="' + uid1 + '">' +
        '<div class="custom-select-trigger" onclick="toggleCustomSelect(this.parentElement.id)">' +
        '<span class="goal-type-label">Целевое действие</span>' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5H7z"/></svg>' +
        '</div>' +
        '<div class="custom-select-options">' +
        '<div class="custom-select-option selected" onclick="selectGoalType(this,\'flag\')">Целевое действие</div>' +
        '<div class="custom-select-option" onclick="selectGoalType(this,\'profit\')">Профит</div>' +
        '</div>' +
        '<input type="hidden" name="goal_type[]" value="flag">' +
        '</div></div>' +
        '<div class="form-field goal-currency-block" style="display:none;">' +
        '<label>Валюта профита</label>' +
        '<div class="custom-select-wrapper" id="' + uid2 + '">' +
        '<div class="custom-select-trigger" onclick="toggleCustomSelect(this.parentElement.id)">' +
        '<span class="goal-currency-label">USD ($)</span>' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5H7z"/></svg>' +
        '</div>' +
        '<div class="custom-select-options">' +
        '<div class="custom-select-option selected" onclick="selectGoalCurrency(this,\'USD\',\'USD ($)\')">USD ($)</div>' +
        '<div class="custom-select-option" onclick="selectGoalCurrency(this,\'EUR\',\'EUR (€)\')">EUR (€)</div>' +
        '<div class="custom-select-option" onclick="selectGoalCurrency(this,\'RUB\',\'RUB (₽)\')">RUB (₽)</div>' +
        '</div>' +
        '<input type="hidden" name="goal_currency[]" value="USD">' +
        '</div></div>';
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
    initWizard();
});

function initWizard() {
    var steps = Array.prototype.slice.call(document.querySelectorAll('.wizard-step'));
    var progressSteps = Array.prototype.slice.call(document.querySelectorAll('.wizard-progress-step'));
    var progressLines = Array.prototype.slice.call(document.querySelectorAll('.wizard-progress-line'));
    var backBtn = document.getElementById('wizardBackBtn');
    var nextBtn = document.getElementById('wizardNextBtn');
    var form = document.getElementById('campaignForm');
    var current = 0;

    function showStep(index) {
        steps.forEach(function (s, i) {
            if (i === index) {
                s.style.display = 'block';
                void s.offsetWidth;
                s.classList.add('wizard-step-active');
            } else {
                s.classList.remove('wizard-step-active');
                s.style.display = 'none';
            }
        });

        progressSteps.forEach(function (ps, i) {
            ps.classList.remove('wizard-progress-active', 'wizard-progress-done');
            if (i < index) ps.classList.add('wizard-progress-done');
            else if (i === index) ps.classList.add('wizard-progress-active');
        });
        progressLines.forEach(function (line, i) {
            line.classList.toggle('wizard-progress-line-done', i < index);
        });

        backBtn.style.visibility = index === 0 ? 'hidden' : 'visible';
        nextBtn.textContent = index === steps.length - 1 ? 'Создать кампанию' : 'Далее';

        current = index;
    }

    function validateStep(index) {
        var fields = steps[index].querySelectorAll('input[required], textarea[required]');
        for (var i = 0; i < fields.length; i++) {
            if (!fields[i].checkValidity()) {
                fields[i].reportValidity();
                return false;
            }
        }
        return true;
    }

    backBtn.addEventListener('click', function () {
        if (current > 0) showStep(current - 1);
    });

    nextBtn.addEventListener('click', function () {
        if (!validateStep(current)) return;
        if (current === steps.length - 1) {
            form.submit();
        } else {
            showStep(current + 1);
        }
    });

    progressSteps.forEach(function (ps) {
        ps.addEventListener('click', function () {
            var idx = parseInt(ps.getAttribute('data-idx'), 10);
            if (idx < current) showStep(idx);
        });
    });

    showStep(0);
}
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

    <div class="header-right">
        <div class="theme-menu" id="themeMenu">
            <button class="theme-toggle-btn" id="themeToggleBtn" type="button" aria-label="Сменить тему">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 000 18c1.5 0 2-.8 2-1.8 0-.5-.2-.9-.5-1.2-.3-.3-.5-.7-.5-1.2 0-1 .8-1.8 1.8-1.8H16a3 3 0 003-3c0-4.4-3.1-9-7-9z"/><circle cx="8" cy="10" r="1"/><circle cx="12" cy="7" r="1"/><circle cx="16" cy="10" r="1"/></svg>
            </button>
            <div class="theme-dropdown" id="themeDropdown">
                <?php if (empty($bgThemes)): ?>
                    <span class="theme-option" style="cursor:default;color:#888;">Нет доступных тем</span>
                <?php else: ?>
                    <?php foreach ($bgThemes as $bgFile):
                        $bgLabel = pathinfo($bgFile, PATHINFO_FILENAME);
                    ?>
                    <button type="button" class="theme-option" data-file="<?= htmlspecialchars($bgFile) ?>"><?= htmlspecialchars($bgLabel) ?></button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="profile-menu" id="profileMenu">
            <button class="profile-avatar" id="profileAvatarBtn" type="button" aria-label="Профиль"><?= htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['username'] ?? 'A', 0, 1))) ?></button>
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

            <div id="toast" class="toast"></div>

            <?php if (!empty($error)): ?>
            <script>
            window.addEventListener('DOMContentLoaded', function() {
                showToast('<?= addslashes(htmlspecialchars($error)) ?>', 'error');
            });
            </script>
            <?php endif; ?>

            <div class="page-header-bar">
                <div class="page-header-titles">
                    <h2 class="page-title">Новая кампания</h2>
                    <div class="page-breadcrumb"><a href="main.php" class="page-breadcrumb-link">Easy TDS</a> <span>›</span> <a href="campaigns.php" class="page-breadcrumb-link">Кампании</a> <span>›</span> Создание</div>
                </div>
            </div>

            <div class="new-campaign-wrap">

                <!-- Индикатор шагов -->
                <div class="wizard-progress" id="wizardProgress">
                    <div class="wizard-progress-step" data-idx="0">
                        <span class="wizard-progress-num">1</span>
                        <span class="wizard-progress-label">Инфо</span>
                    </div>
                    <div class="wizard-progress-line"></div>
                    <div class="wizard-progress-step" data-idx="1">
                        <span class="wizard-progress-num">2</span>
                        <span class="wizard-progress-label">GEO</span>
                    </div>
                    <div class="wizard-progress-line"></div>
                    <div class="wizard-progress-step" data-idx="2">
                        <span class="wizard-progress-num">3</span>
                        <span class="wizard-progress-label">Боты</span>
                    </div>
                    <div class="wizard-progress-line"></div>
                    <div class="wizard-progress-step" data-idx="3">
                        <span class="wizard-progress-num">4</span>
                        <span class="wizard-progress-label">Цели</span>
                    </div>
                </div>

                <form method="post" id="campaignForm">

                    <!-- Шаг 1: Основная информация -->
                    <div class="wizard-step" data-step="0">
                    <div class="form-card">
                        <h3 class="form-card-title">Основная информация</h3>

                        <div class="form-field">
                            <label for="name">Название кампании</label>
                            <div class="input-group">
                                <span class="input-icon">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 4v5"/></svg>
                                </span>
                                <input type="text" id="name" name="name" required placeholder="Например: Campaign #1">
                            </div>
                        </div>

                        <div class="form-field">
                            <label for="slug">Идентификатор кампании (slug)</label>
                            <div class="input-group">
                                <span class="input-icon">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7l-4 5 4 5M17 7l4 5-4 5M14 4l-4 16"/></svg>
                                </span>
                                <input type="text" id="slug" name="slug" required placeholder="Например: camp1">
                            </div>
                        </div>

                        <div class="form-field">
                            <label for="url">URL для перенаправления</label>
                            <textarea id="url" name="url" required rows="3" placeholder="https://example.com"></textarea>
                            <div class="form-note">Можно указать несколько ссылок через запятую</div>
                        </div>
                    </div>
                    </div>

                    <!-- Шаг 2: GEO-фильтр -->
                    <div class="wizard-step" data-step="1">
                    <div class="form-card">
                        <h3 class="form-card-title">GEO-фильтр</h3>

                        <div class="form-field">
                            <label>Режим фильтрации</label>
                            <div class="custom-select-wrapper" id="wrap_geo_filter_type">
                                <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_geo_filter_type')">
                                    <span id="label_geo_filter_type">Не использовать</span>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5H7z"/></svg>
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
                        </div>

                        <div id="geo_sub" style="display:none;">
                            <div class="form-field">
                                <label>Список кодов стран</label>
                                <textarea name="geo_filter_list" id="geo_filter_list" rows="2" placeholder="US,RU,DE"></textarea>
                                <div class="form-note">Коды стран через запятую</div>
                            </div>
                            <div class="form-field">
                                <label>URL для не прошедших фильтр</label>
                                <textarea name="geo_redirect_urls" id="geo_redirect_urls" rows="2" placeholder="https://fallback.com"></textarea>
                                <div class="form-note">Можно несколько через запятую</div>
                            </div>
                        </div>
                    </div>
                    </div>

                    <!-- Шаг 3: Фильтр ботов -->
                    <div class="wizard-step" data-step="2">
                    <div class="form-card">
                        <h3 class="form-card-title">Фильтр ботов</h3>

                        <div class="form-field">
                            <label>Статус</label>
                            <div class="custom-select-wrapper" id="wrap_bot_filter">
                                <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_bot_filter')">
                                    <span id="label_bot_filter">Отключить</span>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5H7z"/></svg>
                                </div>
                                <div class="custom-select-options">
                                    <div class="custom-select-option selected"
                                         onclick="selectOption('wrap_bot_filter','bot_filter','off','Отключить',this);toggleBotInputs()">Отключить</div>
                                    <div class="custom-select-option"
                                         onclick="selectOption('wrap_bot_filter','bot_filter','on','Включить',this);toggleBotInputs()">Включить</div>
                                </div>
                                <input type="hidden" name="bot_filter" id="bot_filter" value="off">
                            </div>
                        </div>

                        <div id="bot_sub" style="display:none;">
                            <div class="form-field">
                                <label>URL для ботов</label>
                                <textarea name="bot_redirect_urls" id="bot_redirect_urls" rows="2" placeholder="https://bot-redirect.com"></textarea>
                                <div class="form-note">Можно несколько через запятую</div>
                            </div>
                        </div>
                    </div>
                    </div>

                    <!-- Шаг 4: Цели -->
                    <div class="wizard-step" data-step="3">
                    <div class="form-card">
                        <h3 class="form-card-title">Цели кампании</h3>

                        <div class="form-field">
                            <label>Режим</label>
                            <div class="custom-select-wrapper" id="wrap_goals_mode">
                                <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_goals_mode')">
                                    <span id="label_goals_mode">Не использовать</span>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5H7z"/></svg>
                                </div>
                                <div class="custom-select-options">
                                    <div class="custom-select-option selected"
                                         onclick="selectOption('wrap_goals_mode','goals_mode','none','Не использовать',this);toggleGoalsInputs()">Не использовать</div>
                                    <div class="custom-select-option"
                                         onclick="selectOption('wrap_goals_mode','goals_mode','add','Добавить',this);toggleGoalsInputs()">Добавить</div>
                                </div>
                                <input type="hidden" name="goals_mode" id="goals_mode" value="none">
                            </div>
                        </div>

                        <div id="goals_container" style="display:none;">
                            <div id="goals_list"></div>
                            <button type="button" onclick="addGoal()" class="add-goal-btn">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                                Добавить цель
                            </button>
                        </div>
                    </div>
                    </div>

                    <div class="wizard-nav">
                        <button type="button" id="wizardBackBtn" class="wizard-btn wizard-btn-back">Назад</button>
                        <button type="button" id="wizardNextBtn" class="wizard-btn wizard-btn-next">Далее</button>
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

    var themeMenu = document.getElementById("themeMenu");
    var themeToggleBtn = document.getElementById("themeToggleBtn");
    var THEME_KEY = "site_bg_theme";

    function applyTheme(file) {
        document.documentElement.style.setProperty("--site-bg-image", "url(/img/backgrounds/" + file + ")");
        document.querySelectorAll(".theme-option").forEach(function (o) {
            o.classList.toggle("theme-option-active", o.getAttribute("data-file") === file);
        });
    }

    if (themeToggleBtn && themeMenu) {
        themeToggleBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            themeMenu.classList.toggle("open");
        });
        document.addEventListener("click", function (e) {
            if (!themeMenu.contains(e.target)) {
                themeMenu.classList.remove("open");
            }
        });
        document.querySelectorAll(".theme-option[data-file]").forEach(function (opt) {
            opt.addEventListener("click", function () {
                var file = this.getAttribute("data-file");
                applyTheme(file);
                localStorage.setItem(THEME_KEY, file);
                themeMenu.classList.remove("open");
            });
        });
        var savedTheme = localStorage.getItem(THEME_KEY);
        if (savedTheme) applyTheme(savedTheme);
    }
}());
</script>

</body>
</html>