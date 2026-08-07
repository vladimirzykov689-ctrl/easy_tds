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

$errors  = [];
$success = '';

// ── Читаем config.php ─────────────────────────────────────────────────────────
$configPath    = __DIR__ . '/config.php';
$configContent = file_get_contents($configPath);

preg_match("/define\('PANEL_USER_HASH',\s*'([^']*)'\)/", $configContent, $mUser);
preg_match("/define\('PANEL_PASS_HASH',\s*'([^']*)'\)/", $configContent, $mPass);
preg_match("/define\('API_KEY_HASH',\s*'([^']*)'\)/",    $configContent, $mApi);
preg_match('/\$ALLOWED_IPS\s*=\s*\'([^\']*)\';/',        $configContent, $mIP);

$currentUserHash = $mUser[1] ?? '';
$currentPassHash = $mPass[1] ?? '';
$currentApiHash  = $mApi[1]  ?? '';
$currentIPs      = $mIP[1]   ?? '';
$ipRestricted    = !empty($currentIPs);

// ── Читаем открытый ключ из tg_config.json ───────────────────────────────────────
define('TG_CONFIG_FILE', __DIR__ . '/tg_config.json');
function loadTgConfig(): array {
    if (!file_exists(TG_CONFIG_FILE)) return [];
    return json_decode(file_get_contents(TG_CONFIG_FILE), true) ?? [];
}
function saveTgConfig(array $data): void {
    file_put_contents(TG_CONFIG_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
$tgConfig      = loadTgConfig();
$currentApiKey   = $tgConfig['api_key']   ?? '';
$currentBotToken = $tgConfig['bot_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

$changeLogin = !empty(trim($_POST['new_login'] ?? ''));
$changePass  = !empty(trim($_POST['new_pass']  ?? ''));
$changeIP    = ($_POST['change_ip'] ?? 'no') === 'yes';
    $generateApi = isset($_POST['generate_api']);
    $sslAction   = $_POST['ssl_action']   ?? 'none';

    $newUserHash = $currentUserHash;
    $newPassHash = $currentPassHash;

    // ── Генерация нового API ключа ────────────────────────────────────────────
    if ($generateApi) {
        $newApiKey  = bin2hex(random_bytes(32));
        $newApiHash = password_hash($newApiKey, PASSWORD_BCRYPT);

        // Читаем свежий конфиг
        $configContent = file_get_contents($configPath);

        // Находим старый хэш через regex
        preg_match("/define\('API_KEY_HASH',\s*'([^']*)'\)/", $configContent, $mOldApi);
        $oldApiHash = $mOldApi[1] ?? '';

        // Используем str_replace — без проблем с backreference
        if ($oldApiHash !== '' || strpos($configContent, "define('API_KEY_HASH'") !== false) {
            $configContent = str_replace(
                "define('API_KEY_HASH', '" . $oldApiHash . "')",
                "define('API_KEY_HASH', '" . $newApiHash . "')",
                $configContent
            );
        } else {
            // Строки нет вообще — добавляем после PANEL_PASS_HASH
            $configContent = str_replace(
                "define('PANEL_PASS_HASH', '" . $currentPassHash . "')",
                "define('PANEL_PASS_HASH', '" . $currentPassHash . "')" . PHP_EOL . "define('API_KEY_HASH', '" . $newApiHash . "')",
                $configContent
            );
        }

if (file_put_contents($configPath, $configContent) !== false) {
            $fresh = loadTgConfig();
            $fresh['api_key'] = $newApiKey;
            saveTgConfig($fresh);
            $tgConfig = $fresh;
            $currentApiKey = $newApiKey;
            $success = 'Новый API ключ успешно сгенерирован.';
        } else {
            $errors[] = 'Ошибка записи в config.php.';
        }
    }
    

// ── Сохранение токена бота ────────────────────────────────────────────────
if (!empty($_POST['bot_token'])) {
    $newToken = trim($_POST['bot_token']);
    $fresh = loadTgConfig();
    $fresh['bot_token'] = $newToken;
    saveTgConfig($fresh);
    $currentBotToken = $newToken;
    $success = 'Токен бота сохранён.';
}

// ── Запуск / остановка бота ─────────────────────────────────────────────────
$botAction = $_POST['bot_action'] ?? '';
if ($botAction === 'start') {
    shell_exec('pkill -f tg_stats.php 2>/dev/null');
    shell_exec('nohup php /var/www/html/easy_tds/tg_stats.php > /var/www/html/easy_tds/tg_stats.log 2>&1 &');
    $success = 'Бот успешно запущен.';
} elseif ($botAction === 'stop') {
    shell_exec('pkill -f tg_stats.php 2>/dev/null');
    $success = 'Бот успешно остановлен.';
}

// ── Смена логина ──────────────────────────────────────────────────────────
    if ($changeLogin) {
        $newLogin = trim($_POST['new_login'] ?? '');
        if (empty($newLogin)) {
            $errors[] = 'Введите новый логин.';
        } else {
            $newUserHash = password_hash($newLogin, PASSWORD_DEFAULT);
        }
    }

    // ── Смена пароля ──────────────────────────────────────────────────────────
    if ($changePass) {
        $currentPass = trim($_POST['current_pass'] ?? '');
        $newPass     = trim($_POST['new_pass'] ?? '');
        if (empty($currentPass) || empty($newPass)) {
            $errors[] = 'Заполните оба поля для смены пароля.';
        } elseif (!password_verify($currentPass, $currentPassHash)) {
            $errors[] = 'Неверный пароль.';
        } else {
            $newPassHash = password_hash($newPass, PASSWORD_DEFAULT);
        }
    }

    // ── Смена IP ──────────────────────────────────────────────────────────────
    $newAllowedIPs = '';
    if ($changeIP) {
        $ipRaw  = trim($_POST['allowed_ips'] ?? '');
        $ipList = array_filter(array_map('trim', explode(',', $ipRaw)));
        $newAllowedIPs = implode(',', $ipList);
    }

    // ── SSL ───────────────────────────────────────────────────────────────────
    if (in_array($sslAction, ['add', 'remove'])) {
        $rawDomains = trim($_POST['ssl_domain'] ?? '');
        if (empty($rawDomains)) {
            $errors[] = 'Укажите домен.';
        } else {
            $domains        = array_filter(array_map('trim', explode(',', $rawDomains)));
            $sslSuccess     = [];
            $sslErrors      = [];
            $validDomains   = [];
            $invalidDomains = [];

            foreach ($domains as $d) {
                if (preg_match('/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $d)) {
                    $validDomains[] = $d;
                } else {
                    $invalidDomains[] = $d;
                }
            }

            if (!empty($invalidDomains)) {
                $errors[] = 'Некорректные домены: ' . htmlspecialchars(implode(', ', $invalidDomains));
            }

            if (!empty($validDomains)) {
                if ($sslAction === 'add') {
                    shell_exec("(crontab -l 2>/dev/null | grep -q 'certbot renew') || (crontab -l 2>/dev/null; echo '0 3 * * * certbot renew --quiet --nginx') | crontab -");
                    shell_exec("(crontab -l 2>/dev/null | grep -q 'reload nginx') || (crontab -l 2>/dev/null; echo '30 3 * * * systemctl reload nginx') | crontab -");
                    foreach ($validDomains as $domain) {
                        $cmd      = "sudo certbot --nginx -d " . escapeshellarg($domain) . " --non-interactive --agree-tos --register-unsafely-without-email > /dev/null 2>&1; echo $?";
                        $exitCode = trim(shell_exec($cmd));
                        if ($exitCode === '0') $sslSuccess[] = $domain;
                        else $sslErrors[] = $domain;
                    }
                    if (!empty($sslSuccess)) $success = 'SSL сертификат успешно выдан для: ' . implode(', ', $sslSuccess);
                    if (!empty($sslErrors))  $errors[] = 'Certbot вернул ошибку для: ' . htmlspecialchars(implode(', ', $sslErrors));

                } elseif ($sslAction === 'remove') {
                    $nginxConf = '/etc/nginx/sites-enabled/default';
                    foreach ($validDomains as $domain) {
                        shell_exec("sudo certbot delete --cert-name " . escapeshellarg($domain) . " --non-interactive 2>/dev/null");
                        $nginx = file_get_contents($nginxConf);
                        if ($nginx !== false) {
                            $domainPattern = preg_quote($domain, '/');
                            $lines  = explode("\n", $nginx);
                            $result = [];
                            foreach ($lines as $line) {
                                if (preg_match('/^\s*ssl_certificate(?:_key)?\s+[^\n]*\/live\/' . $domainPattern . '\//i', $line)) {
                                    if (!empty($result) && preg_match('/^\s*#[^\n]*managed by Certbot/i', end($result))) array_pop($result);
                                    continue;
                                }
                                $result[] = $line;
                            }
                            $nginx       = implode("\n", $result);
                            $hasOtherSSL = (bool)preg_match('/^\s*ssl_certificate\s+/m', $nginx);
                            if (!$hasOtherSSL) {
                                $nginx = preg_replace('/^\s*listen\s+443\s+ssl[^\n]*\n?/m', '', $nginx);
                                $nginx = preg_replace('/^\s*listen\s+\[::\]:443\s+ssl[^\n]*\n?/m', '', $nginx);
                                $nginx = preg_replace('/^\s*include\s+[^\n]*options-ssl-nginx[^\n]*\n?/m', '', $nginx);
                                $nginx = preg_replace('/^\s*ssl_dhparam\s+[^\n]+\n?/m', '', $nginx);
                                $nginx = preg_replace('/^\s*#[^\n]*managed by Certbot[^\n]*\n?/m', '', $nginx);
                            }
                            file_put_contents($nginxConf, $nginx);
                        }
                        $sslSuccess[] = $domain;
                    }
                    $testCode = trim(shell_exec("sudo nginx -t > /dev/null 2>&1; echo $?"));
                    $testOut  = shell_exec("sudo nginx -t 2>&1");
                    if ($testCode === '0') {
                        shell_exec("sudo systemctl reload nginx > /dev/null 2>&1");
                        if (!empty($sslSuccess)) $success = 'Сертификат удалён для: ' . htmlspecialchars(implode(', ', $sslSuccess));
                    } else {
                        $errors[] = 'Сертификаты удалены, но nginx -t вернул ошибку: ' . htmlspecialchars($testOut);
                    }
                }
            }
        }
    }

    // ── Записываем config.php ─────────────────────────────────────────────────
    if (empty($errors) && ($changeLogin || $changePass || $changeIP)) {
        $configContent = file_get_contents($configPath);

        preg_match("/define\('PANEL_USER_HASH',\s*'([^']*)'\)/", $configContent, $oldUser);
        preg_match("/define\('PANEL_PASS_HASH',\s*'([^']*)'\)/", $configContent, $oldPass);
        preg_match('/\$ALLOWED_IPS\s*=\s*\'([^\']*)\';/', $configContent, $oldIP);

        $configContent = str_replace(
            "define('PANEL_USER_HASH', '" . ($oldUser[1] ?? '') . "')",
            "define('PANEL_USER_HASH', '" . $newUserHash . "')",
            $configContent
        );
        $configContent = str_replace(
            "define('PANEL_PASS_HASH', '" . ($oldPass[1] ?? '') . "')",
            "define('PANEL_PASS_HASH', '" . $newPassHash . "')",
            $configContent
        );
        $configContent = str_replace(
            '$ALLOWED_IPS = \'' . ($oldIP[1] ?? '') . '\';',
            '$ALLOWED_IPS = \'' . $newAllowedIPs . '\';',
            $configContent
        );

        if (file_put_contents($configPath, $configContent) !== false) {
            $success = 'Изменения успешно сохранены.';
        } else {
            $errors[] = 'Ошибка записи в config.php.';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Учетная запись — Easy TDS</title>
<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>
function toggleSaveBtn(btnId, value, noneValue) {
    var btn = document.getElementById(btnId);
    btn.style.display = (value !== noneValue) ? 'inline-flex' : 'none';
}
function toggleCustomSelect(wrapperId) {
    var wrapper = document.getElementById(wrapperId);
    var isOpen = wrapper.classList.contains('open');
    document.querySelectorAll('.custom-select-wrapper.open').forEach(function(w) {
        w.classList.remove('open');
    });
    document.querySelectorAll('.form-card.select-open').forEach(function(c) {
        c.classList.remove('select-open');
    });
    if (!isOpen) {
        wrapper.classList.add('open');
        var card = wrapper.closest('.form-card');
        if (card) card.classList.add('select-open');
    }
}
function selectCustomOption(wrapperId, inputId, value, label, el) {
    document.getElementById(inputId).value = value;
    document.getElementById('label_' + inputId).textContent = label;
    el.closest('.custom-select-options').querySelectorAll('.custom-select-option').forEach(function(o) {
        o.classList.remove('selected');
    });
    el.classList.add('selected');
    document.getElementById(wrapperId).classList.remove('open');
    var card = document.getElementById(wrapperId).closest('.form-card');
    if (card) card.classList.remove('select-open');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.custom-select-wrapper')) {
        document.querySelectorAll('.custom-select-wrapper.open').forEach(function(w) {
            w.classList.remove('open');
        });
        document.querySelectorAll('.form-card.select-open').forEach(function(c) {
            c.classList.remove('select-open');
        });
    }
});
function toggleSection(selectId, sectionId) {
    document.getElementById(sectionId).style.display =
        document.getElementById(selectId).value === 'yes' ? 'block' : 'none';
}
function toggleSSL() {
    var val = document.getElementById('ssl_action').value;
    document.getElementById('section_ssl').style.display =
        (val === 'add' || val === 'remove') ? 'block' : 'none';
}
function toggleLoginEdit() {
    var input = document.getElementById('login_input');
    input.removeAttribute('readonly');
    input.value = '';
    input.placeholder = 'Введите новый логин';
    input.style.color = '#fff';
    input.style.cursor = 'text';
    input.focus();
    document.getElementById('loginEditBtn').style.display = 'none';
    document.getElementById('loginSaveBtn').style.display = 'inline-flex';
}
function togglePassEdit() {
    document.getElementById('pass_dots').style.display = 'none';
    document.getElementById('passFields').style.display = 'block';
    document.getElementById('passEditBtn').style.display = 'none';
    document.getElementById('passSaveBtn').style.display = 'inline-flex';
    document.getElementById('passFields').querySelector('input').focus();
}
function toggleBotTokenEdit() {
    var input = document.getElementById('bot_token_input');
    input.removeAttribute('readonly');
    input.style.cursor = 'text';
    input.focus();
    document.getElementById('botTokenEditBtn').style.display = 'none';
    document.getElementById('botTokenSaveBtn').style.display = 'inline-flex';
}
window.addEventListener('DOMContentLoaded', function () {
    toggleSection('change_ip', 'section_ip');
    toggleSSL();
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

            <?php if (!empty($errors)): ?>
            <script>
            window.addEventListener('DOMContentLoaded', function() {
                showBottomToast('Учетная запись', '<?= addslashes(implode(' | ', $errors)) ?>', 'error');
            });
            </script>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
            <script>
            window.addEventListener('DOMContentLoaded', function() {
                showBottomToast('Учетная запись', '<?= addslashes($success) ?>', 'success');
            });
            </script>
            <?php endif; ?>

            <div class="page-header-bar">
                <div class="page-header-titles">
                    <h2 class="page-title">Учетная запись</h2>
                    <div class="page-breadcrumb"><a href="main.php" class="page-breadcrumb-link">Easy TDS</a> <span>›</span> Учетная запись</div>
                </div>
            </div>

            <div class="new-campaign-wrap">

                <!-- Вкладки -->
                <div class="tabs-nav" id="tabsNav">
                    <button type="button" class="tab-btn tab-btn-active" data-tab="0">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-3.3 3.6-6 8-6s8 2.7 8 6"/></svg>
                        Доступ
                    </button>
                    <button type="button" class="tab-btn" data-tab="1">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 1 0 0 5.66l1.3 1.3-.9.9 1.4 1.4.9-.9 1 1v2h2v-2l1-1-1.4-1.4-1 1-2.5-2.5a4 4 0 0 0-2.7-5.46z"/></svg>
                        API и TG бот
                    </button>
                    <button type="button" class="tab-btn" data-tab="2">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M7 10V7a5 5 0 0110 0v3"/></svg>
                        IP и SSL
                    </button>
                </div>

                <!-- Панель 1: Доступ -->
                <div class="tab-panel tab-panel-active" data-tab-panel="0">

                    <form method="post">
                        <div class="form-card">
                            <div class="form-card-header-row">
                                <h3 class="form-card-title">Логин</h3>
                                <button type="button" id="loginEditBtn" class="header-icon-btn" style="background:#ffc107;" title="Изменить логин" onclick="toggleLoginEdit()">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                </button>
                                <button type="submit" id="loginSaveBtn" class="header-icon-btn" style="display:none;background:#28a745;" title="Сохранить">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                                </button>
                            </div>
                            <div class="form-field">
                                <input type="password" id="login_input" name="new_login" readonly
                                       value="<?= $currentUserHash ? 'placeholder' : '' ?>"
                                       placeholder="Введите новый логин">
                            </div>
                        </div>
                    </form>

                    <form method="post">
                        <div class="form-card">
                            <div class="form-card-header-row">
                                <h3 class="form-card-title">Пароль</h3>
                                <button type="button" id="passEditBtn" class="header-icon-btn" style="background:#ffc107;" title="Изменить пароль" onclick="togglePassEdit()">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                </button>
                                <button type="submit" id="passSaveBtn" class="header-icon-btn" style="display:none;background:#28a745;" title="Сохранить">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                                </button>
                            </div>
                            <div class="form-field" id="pass_dots_wrap">
                                <input type="password" disabled id="pass_dots" value="password">
                            </div>
                            <div id="passFields" style="display:none;">
                                <div class="form-field">
                                    <label>Текущий пароль</label>
                                    <input type="password" name="current_pass" placeholder="Текущий пароль" autocomplete="off">
                                </div>
                                <div class="form-field">
                                    <label>Новый пароль</label>
                                    <input type="password" name="new_pass" placeholder="Новый пароль" autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </form>

                </div>

                <!-- Панель 2: API и бот -->
                <div class="tab-panel" data-tab-panel="1">

                    <form method="post">
                        <div class="form-card">
                            <div class="form-card-header-row">
                                <h3 class="form-card-title">API Ключ</h3>
                                <input type="hidden" name="generate_api" value="">
                                <button type="submit" name="generate_api" value="1"
                                        onclick="return confirm('Сгенерировать новый API ключ? Старый перестанет работать.');"
                                        class="header-icon-btn" style="background:#ffc107;" title="Перегенерировать ключ">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="#1b1b2f"><path d="M12 5V2L8 6l4 4V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>
                                </button>
                            </div>
                            <code class="api-key-display" style="color:<?= !empty($currentApiKey) ? 'var(--text)' : 'rgba(255,255,255,0.35)' ?>;font-style:<?= !empty($currentApiKey) ? 'normal' : 'italic' ?>;">
<?= !empty($currentApiKey) ? htmlspecialchars($currentApiKey) : 'Ключ не сгенерирован' ?>
                            </code>
                        </div>
                    </form>

                    <form method="post">
                        <div class="form-card">
                            <div class="form-card-header-row">
                                <h3 class="form-card-title">Настройки Telegram бота</h3>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <button type="button" id="botTokenEditBtn" class="header-icon-btn" style="background:#ffc107;" title="Редактировать токен" onclick="toggleBotTokenEdit()">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                    </button>
                                    <button type="submit" id="botTokenSaveBtn" class="header-icon-btn" style="display:none;background:#28a745;" title="Сохранить токен">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                                    </button>
                                    <?php if (!empty($currentBotToken)): ?>
                                    <button type="button" id="botStartBtn" class="header-icon-btn" style="background:#28a745;" title="Запустить бота" onclick="runBotAction('start')">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M8 5v14l11-7z"/></svg>
                                    </button>
                                    <button type="button" id="botStopBtn" class="header-icon-btn" style="background:#dc3545;" title="Остановить бота" onclick="runBotAction('stop')">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M6 6h12v12H6z"/></svg>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="form-field">
                                <input type="text" id="bot_token_input" name="bot_token" readonly
                                       value="<?= htmlspecialchars($currentBotToken) ?>"
                                       placeholder="<?= empty($currentBotToken) ? 'Токен не задан' : '' ?>">
                            </div>
                        </div>
                    </form>

                    <form method="post" id="bot_action_form" style="display:none;">
                        <input type="hidden" name="bot_action" id="bot_action_input" value="">
                    </form>

                </div>

                <!-- Панель 3: IP и SSL -->
                <div class="tab-panel" data-tab-panel="2">

                    <form method="post" id="ip_ssl_form_1">
                        <div class="form-card">
                            <div class="form-card-header-row">
                                <h3 class="form-card-title">Ограничить доступ по IP</h3>
                                <button type="submit" id="saveIpBtn" class="header-icon-btn" style="display:none;background:#28a745;" title="Сохранить">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                                </button>
                            </div>

                            <div class="form-field">
                                <label>Ограничение включено?</label>
                                <div class="custom-select-wrapper" id="wrap_change_ip">
                                    <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_change_ip')">
                                        <span id="label_change_ip"><?= $ipRestricted ? 'Да' : 'Нет' ?></span>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5H7z"/></svg>
                                    </div>
                                    <div class="custom-select-options">
                                        <div class="custom-select-option <?= !$ipRestricted ? 'selected' : '' ?>"
                                             onclick="selectCustomOption('wrap_change_ip','change_ip','no','Нет',this);toggleSection('change_ip','section_ip');toggleSaveBtn('saveIpBtn','no','no')">Нет</div>
                                        <div class="custom-select-option <?= $ipRestricted ? 'selected' : '' ?>"
                                             onclick="selectCustomOption('wrap_change_ip','change_ip','yes','Да',this);toggleSection('change_ip','section_ip');toggleSaveBtn('saveIpBtn','yes','no')">Да</div>
                                    </div>
                                    <input type="hidden" name="change_ip" id="change_ip" value="<?= $ipRestricted ? 'yes' : 'no' ?>">
                                </div>
                            </div>

                            <div id="section_ip" style="display:<?= $ipRestricted ? 'block' : 'none' ?>;">
                                <div class="form-field">
                                    <label>Список IP-адресов</label>
                                    <textarea name="allowed_ips" rows="2" oninput="document.getElementById('saveIpBtn').style.display='inline-flex'"><?= htmlspecialchars($currentIPs) ?></textarea>
                                    <div class="form-note">Укажите IP-адреса через запятую. Например: 192.168.1.1,10.0.0.1</div>
                                </div>
                            </div>
                        </div>
                    </form>

                    <form method="post" id="ip_ssl_form_2">
                        <div class="form-card">
                            <div class="form-card-header-row">
                                <h3 class="form-card-title">SSL для доменов</h3>
                                <button type="submit" id="saveSslBtn" class="header-icon-btn" style="display:none;background:#28a745;" title="Сохранить">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                                </button>
                            </div>

                            <div class="form-field">
                                <label>Действие</label>
                                <div class="custom-select-wrapper" id="wrap_ssl_action">
                                    <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_ssl_action')">
                                        <span id="label_ssl_action">Нет</span>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5H7z"/></svg>
                                    </div>
                                    <div class="custom-select-options">
                                        <div class="custom-select-option selected"
                                             onclick="selectCustomOption('wrap_ssl_action','ssl_action','none','Нет',this);toggleSSL();toggleSaveBtn('saveSslBtn','none','none')">Нет</div>
                                        <div class="custom-select-option <?= ($_POST['ssl_action'] ?? '') === 'add' ? 'selected' : '' ?>"
                                             onclick="selectCustomOption('wrap_ssl_action','ssl_action','add','Добавить',this);toggleSSL();toggleSaveBtn('saveSslBtn','add','none')">Добавить</div>
                                        <div class="custom-select-option <?= ($_POST['ssl_action'] ?? '') === 'remove' ? 'selected' : '' ?>"
                                             onclick="selectCustomOption('wrap_ssl_action','ssl_action','remove','Удалить',this);toggleSSL();toggleSaveBtn('saveSslBtn','remove','none')">Удалить</div>
                                    </div>
                                    <input type="hidden" name="ssl_action" id="ssl_action" value="<?= htmlspecialchars($_POST['ssl_action'] ?? 'none') ?>">
                                </div>
                            </div>

                            <div id="section_ssl" style="display:none;">
                                <div class="form-field">
                                    <label>Домены (через запятую)</label>
                                    <textarea name="ssl_domain" rows="2" placeholder="example.com, domain2.com"
                                              oninput="document.getElementById('saveSslBtn').style.display='inline-flex'"><?= htmlspecialchars($_POST['ssl_domain'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </form>

                </div>

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

(function () {
    var sslForm = document.getElementById('ip_ssl_form_2');
    if (sslForm) {
        sslForm.addEventListener('submit', function () {
            var action = document.getElementById('ssl_action').value;
            if (action === 'add' || action === 'remove') {
                showSslLoadingOverlay(action);
            }
        });
    }

    function showSslLoadingOverlay(action) {
        var ov = document.createElement('div');
        ov.className = 'page-loading-overlay';
        var text = action === 'add' ? 'Выпускаем SSL-сертификат…' : 'Удаляем SSL-сертификат…';
        ov.innerHTML =
            '<div class="page-loading-box">' +
                '<div class="page-loading-spinner"></div>' +
                '<div class="page-loading-text">' + text + '</div>' +
                '<div class="page-loading-subtext">Это может занять до минуты</div>' +
            '</div>';
        document.body.appendChild(ov);
    }
}());

function runBotAction(action) {
    var ov = document.createElement('div');
    ov.className = 'page-loading-overlay';
    var text = action === 'start' ? 'Запускаем бота…' : 'Останавливаем бота…';
    ov.innerHTML =
        '<div class="page-loading-box">' +
            '<div class="page-loading-spinner"></div>' +
            '<div class="page-loading-text">' + text + '</div>' +
            '<div class="page-loading-subtext">Пожалуйста, подождите</div>' +
        '</div>';
    document.body.appendChild(ov);

    document.getElementById('bot_action_input').value = action;
    document.getElementById('bot_action_form').submit();
}

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
