<?php
require 'config.php';
$db = initDB();
checkAuth();
checkIP();

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
<title>Учетная запись</title>
<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">
<style>
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
    if (!isOpen) wrapper.classList.add('open');
}

function selectCustomOption(wrapperId, inputId, value, label, el) {
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
function toggleSection(selectId, sectionId) {
    document.getElementById(sectionId).style.display =
        document.getElementById(selectId).value === 'yes' ? 'block' : 'none';
}
function toggleSSL() {
    var val = document.getElementById('ssl_action').value;
    document.getElementById('section_ssl').style.display =
        (val === 'add' || val === 'remove') ? 'block' : 'none';
}
window.addEventListener('DOMContentLoaded', function () {
    toggleSection('change_ip', 'section_ip');
    toggleSSL();
});
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
</script>
</head>
<body class="dashboard-page">
<div id="toast" class="toast"></div>

<header class="top-header">
    <button class="hamburger-btn" id="hamburgerBtn" title="Свернуть меню" aria-label="Toggle sidebar">
        <span></span><span></span><span></span>
    </button>
    <a href="main.php" style="text-decoration:none; display:flex; align-items:center;">
    <img src="/img/logo.png" alt="Easy TDS" style="height:40px; width:auto;">
</a>
</header>

<div class="main-wrapper">

    <nav class="sidebar" id="sidebar">
        <ul class="sidebar-nav">
            <li data-tooltip="Главная">
                <a href="main.php">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg></span>
                    <span class="nav-label">Главная</span>
                </a>
            </li>
            <li class="sidebar-divider"></li>
            <li data-tooltip="Кампании">
                <div class="sidebar-group-row">
                    <a href="campaigns.php" class="sidebar-group-link">
                        <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M20 6h-3V4a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v2H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2zm-9-2h2v2h-2V4zm-2 0h2v2H9V4zm11 15H4V8h16v11z"/></svg></span>
                        <span class="nav-label">Кампании</span>
                    </a>
                    <button class="nav-arrow-btn" id="campaignsToggle" type="button">
                        <svg viewBox="0 0 24 24"><path d="M7 10l5 5 5-5H7z"/></svg>
                    </button>
                </div>
                <ul class="sidebar-subnav" id="campaignsSubnav">
                    <li><a href="new_campaign.php"><span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M11 11V5h2v6h6v2h-6v6h-2v-6H5v-2z"/></svg></span><span class="nav-label">Новая кампания</span></a></li>
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
                    </li>                    <li><a href="#" onclick="confirmDeleteAll(event)" style="color:#ff6666;"><span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M9 3v1H4v2h1v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6h1V4h-5V3H9zm0 5h2v9H9V8zm4 0h2v9h-2V8z"/></svg></span><span class="nav-label">Удалить все</span></a>
                        <form id="deleteAllForm" method="post" action="campaigns.php" style="display:none;"><input type="hidden" name="delete_all" value="1"></form>
                    </li>
                </ul>
            </li>
            <li class="sidebar-divider"></li>
            <li data-tooltip="Фильтр ботов">
                <a href="bots.php">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h3a3 3 0 0 1 3 3v1h.5a1.5 1.5 0 0 1 0 3H19v1a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3v-1h-.5a1.5 1.5 0 0 1 0-3H5v-1a3 3 0 0 1 3-3h3V5.73A2 2 0 0 1 10 4a2 2 0 0 1 2-2zm-2 9a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm4 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm-5 5v1h6v-1H9z"/></svg></span>
                    <span class="nav-label">Фильтр ботов</span>
                </a>
            </li>
            <li class="sidebar-divider"></li>
            <li data-tooltip="Учетная запись">
                <a href="credentials.php" class="active">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M12 4a4 4 0 1 1 0 8 4 4 0 0 1 0-8zm0 10c4.418 0 8 1.79 8 4v1H4v-1c0-2.21 3.582-4 8-4z"/></svg></span>
                    <span class="nav-label">Учетная запись</span>
                </a>
            </li>
            <li class="sidebar-divider"></li>
            <li data-tooltip="Выйти">
                <a href="logout.php" style="color:#ff6666;">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M16 13v-2H7V8l-5 4 5 4v-3h9zm2-11H6a2 2 0 0 0-2 2v4h2V4h12v16H6v-4H4v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/></svg></span>
                    <span class="nav-label">Выйти</span>
                </a>
            </li>
        </ul>
    </nav>

<div class="page-content">
    <div class="content">
        <h2 class="campaign-title">Редактирование учетной записи</h2>

<?php if (!empty($errors)): ?>
<script>
window.addEventListener('DOMContentLoaded', function() {
    showToast('<?= addslashes(implode(' | ', $errors)) ?>', 'error');
});
</script>
<?php endif; ?>

<?php if (!empty($success)): ?>
<script>
window.addEventListener('DOMContentLoaded', function() {
    showToast('✓ <?= addslashes($success) ?>', 'success');
});
</script>
<?php endif; ?>
                	<div class="add-form" style="max-width:100%;">

<!-- ДВУХКОЛОНОЧНАЯ ОБЁРТКА -->
<div style="display:flex;gap:24px;align-items:flex-start;">

    <!-- ЛЕВАЯ КОЛОНКА: Логин + Пароль -->
    <div style="flex:1;">
        <!-- ======= ЛОГИН + ПАРОЛЬ ======= -->
        <form method="post" style="margin-bottom:24px;">
        <div style="margin-bottom:24px;padding:16px;background:rgba(30,15,60,0.85);border:1px solid rgba(155,0,255,0.35);border-radius:10px;">

            <!-- Логин -->
            <div style="margin-bottom:16px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <label style="color:#cc88ff;font-weight:600;text-transform:uppercase;font-size:13px;letter-spacing:0.05em;white-space:nowrap;flex-shrink:0;">
                        Логин
                    </label>
                    <button type="button" id="loginEditBtn" title="Изменить логин"
                            onclick="toggleLoginEdit()"
                            style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#ffc107;box-shadow:0 0 8px #ffc107;flex-shrink:0;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                    </button>
                    <button type="submit" id="loginSaveBtn" title="Сохранить"
                            style="display:none;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#28a745;box-shadow:0 0 8px #28a745;flex-shrink:0;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    </button>
                </div>
                <input type="text" id="login_input" name="new_login" readonly
                       value="<?= htmlspecialchars($currentUserHash ? '(логин задан)' : '') ?>"
                       placeholder="Введите новый логин"
                       style="margin-top:10px;width:100%;padding:8px 12px;border-radius:6px;background:rgba(0,0,0,0.3);border:1px solid rgba(155,0,255,0.3);color:rgba(255,255,255,0.4);font-size:13px;box-sizing:border-box;cursor:default;"
                       onfocus="this.style.cursor='text';">
            </div>

            <!-- Пароль -->
            <div style="padding-top:8px;border-top:1px solid rgba(155,0,255,0.2);">
                <div style="display:flex;align-items:center;gap:10px;">
                    <label style="color:#cc88ff;font-weight:600;text-transform:uppercase;font-size:13px;letter-spacing:0.05em;white-space:nowrap;flex-shrink:0;">
                        Пароль
                    </label>
                    <button type="button" id="passEditBtn" title="Изменить пароль"
                            onclick="togglePassEdit()"
                            style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#ffc107;box-shadow:0 0 8px #ffc107;flex-shrink:0;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                    </button>
                    <button type="submit" id="passSaveBtn" title="Сохранить"
                            style="display:none;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#28a745;box-shadow:0 0 8px #28a745;flex-shrink:0;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    </button>
                </div>
                <div style="margin-top:10px;">
                    <input type="password" disabled id="pass_dots" value="password"
                           style="width:100%;padding:8px 12px;border-radius:6px;background:rgba(0,0,0,0.3);border:1px solid rgba(155,0,255,0.2);color:#fff;font-size:13px;box-sizing:border-box;cursor:default;">
                </div>
                <div id="passFields" style="display:none;margin-top:8px;">
                    <input type="password" name="current_pass"
                           placeholder="Текущий пароль" autocomplete="off"
                           style="width:100%;padding:8px 12px;border-radius:6px;background:rgba(0,0,0,0.3);border:1px solid rgba(155,0,255,0.3);color:#fff;font-size:13px;box-sizing:border-box;margin-bottom:8px;">
                    <input type="password" name="new_pass"
                           placeholder="Новый пароль" autocomplete="off"
                           style="width:100%;padding:8px 12px;border-radius:6px;background:rgba(0,0,0,0.3);border:1px solid rgba(155,0,255,0.3);color:#fff;font-size:13px;box-sizing:border-box;">
                </div>
            </div>

        </div>
        </form>
    </div>
    <!-- КОНЕЦ ЛЕВОЙ КОЛОНКИ -->

    <!-- ПРАВАЯ КОЛОНКА: API Ключ + Токен бота -->
    <div style="flex:1;">

        <!-- ======= API КЛЮЧ ======= -->
        <div style="margin-bottom:24px;padding:16px;background:rgba(30,15,60,0.85);border:1px solid rgba(155,0,255,0.35);border-radius:10px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <label style="color:#cc88ff;font-weight:600;text-transform:uppercase;font-size:13px;letter-spacing:0.05em;margin:0;">
                    API Ключ
                </label>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="generate_api" value="1">
                    <button type="submit"
                            onclick="return confirm('Сгенерировать новый API ключ? Старый перестанет работать.');"
                            title="Перегенерировать ключ"
                            style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#ffc107;box-shadow:0 0 8px #ffc107;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#1b1b2f"><path d="M12 5V2L8 6l4 4V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>
                    </button>
                </form>
            </div>
            <?php if (!empty($currentApiKey)): ?>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <code id="api_key_display" style="flex:1;background:rgba(0,0,0,0.3);padding:8px 12px;border-radius:6px;font-size:13px;color:#fff;word-break:break-all;border:1px solid rgba(155,0,255,0.2);"><?= htmlspecialchars($currentApiKey) ?></code>
                </div>
            <?php else: ?>
                <div style="color:rgba(255,255,255,0.3);font-style:italic;">Ключ не сгенерирован</div>
            <?php endif; ?>
        </div>

        <!-- ======= ТОКЕН БОТА ======= -->
        <div style="margin-bottom:24px;padding:16px;background:rgba(30,15,60,0.85);border:1px solid rgba(155,0,255,0.35);border-radius:10px;">
            <form method="post" style="margin:0;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <label style="color:#cc88ff;font-weight:600;text-transform:uppercase;font-size:13px;letter-spacing:0.05em;white-space:nowrap;flex-shrink:0;">
                        Токен Telegram бота
                    </label>
                    <button type="button" id="botTokenEditBtn" title="Редактировать токен"
                            onclick="document.getElementById('bot_token_input').removeAttribute('readonly');document.getElementById('bot_token_input').focus();document.getElementById('bot_token_input').style.cursor='text';this.style.display='none';document.getElementById('botTokenSaveBtn').style.display='inline-flex';"
                            style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#ffc107;box-shadow:0 0 8px #ffc107;flex-shrink:0;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="#1b1b2f"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                    </button>
                    <button type="submit" id="botTokenSaveBtn" title="Сохранить токен"
                            style="display:none;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#28a745;box-shadow:0 0 8px #28a745;flex-shrink:0;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    </button>
                </div>
                <input type="text" id="bot_token_input" name="bot_token" readonly
                       value="<?= htmlspecialchars($currentBotToken) ?>"
                       placeholder="<?= empty($currentBotToken) ? 'Токен не задан' : '' ?>"
                       style="margin-top:10px;width:100%;padding:8px 12px;border-radius:6px;background:rgba(0,0,0,0.3);border:1px solid rgba(155,0,255,0.3);color:<?= empty($currentBotToken) ? 'rgba(255,255,255,0.3)' : '#fff' ?>;font-size:13px;box-sizing:border-box;cursor:default;"
                       onfocus="this.style.cursor='text';">
            </form>
        </div>

    </div>
    <!-- КОНЕЦ ПРАВОЙ КОЛОНКИ -->

</div>
<!-- КОНЕЦ ДВУХКОЛОНОЧНОЙ ОБЁРТКИ -->

<!-- ======= IP + SSL ======= -->
<form method="post" id="ip_ssl_form">
<div style="display:flex;gap:24px;align-items:flex-start;">

    <!-- ЛЕВАЯ: IP -->
    <div style="flex:1;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:5px;">
            <label style="color:#cc88ff;font-weight:600;text-transform:uppercase;font-size:13px;letter-spacing:0.05em;margin:0;">Ограничить доступ по IP</label>
            <button type="submit" id="saveIpBtn"
                    style="display:none;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#28a745;box-shadow:0 0 8px #28a745;flex-shrink:0;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
            </button>
        </div>
        <div class="custom-select-wrapper" id="wrap_change_ip">
            <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_change_ip')">
                <span id="label_change_ip"><?= $ipRestricted ? 'Да' : 'Нет' ?></span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="#cc88ff"><path d="M7 10l5 5 5-5H7z"/></svg>
            </div>
            <div class="custom-select-options">
                <div class="custom-select-option <?= !$ipRestricted ? 'selected' : '' ?>"
                     onclick="selectCustomOption('wrap_change_ip','change_ip','no','Нет',this);toggleSection('change_ip','section_ip');toggleSaveBtn('saveIpBtn','no','no')">Нет</div>
                <div class="custom-select-option <?= $ipRestricted ? 'selected' : '' ?>"
                     onclick="selectCustomOption('wrap_change_ip','change_ip','yes','Да',this);toggleSection('change_ip','section_ip');toggleSaveBtn('saveIpBtn','yes','no')">Да</div>
            </div>
            <input type="hidden" name="change_ip" id="change_ip" value="<?= $ipRestricted ? 'yes' : 'no' ?>">
        </div>
        <div id="section_ip" style="display:<?= $ipRestricted ? 'block' : 'none' ?>;">
            <label>Список IP-адресов:</label>
            <textarea name="allowed_ips" oninput="document.getElementById('saveIpBtn').style.display='inline-flex'"><?= htmlspecialchars($currentIPs) ?></textarea>
            <div class="note">Укажите IP-адреса через запятую. Например: 192.168.1.1,10.0.0.1</div>
        </div>
    </div>

    <!-- ПРАВАЯ: SSL -->
    <div style="flex:1;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:5px;">
            <label style="color:#cc88ff;font-weight:600;text-transform:uppercase;font-size:13px;letter-spacing:0.05em;margin:0;">SSL для доменов</label>
            <button type="submit" id="saveSslBtn"
                    style="display:none;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;cursor:pointer;background:#28a745;box-shadow:0 0 8px #28a745;flex-shrink:0;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
            </button>
        </div>
        <div class="custom-select-wrapper" id="wrap_ssl_action">
            <div class="custom-select-trigger" onclick="toggleCustomSelect('wrap_ssl_action')">
                <span id="label_ssl_action">Нет</span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="#cc88ff"><path d="M7 10l5 5 5-5H7z"/></svg>
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
        <div id="section_ssl" style="display:none;">
            <label>Домены (через запятую):</label>
            <textarea name="ssl_domain" placeholder="example.com, domain2.com"
                      oninput="document.getElementById('saveSslBtn').style.display='inline-flex'"><?= htmlspecialchars($_POST['ssl_domain'] ?? '') ?></textarea>
        </div>
    </div>

</div>
</form>

            </div>
        </div>
    </div>

</div>

<script>
(function () {
    var SIDEBAR_KEY   = 'sidebar_collapsed';
    var ACCORDION_KEY = 'campaigns_open';
    var body   = document.body;
    var btn    = document.getElementById('hamburgerBtn');
    var toggle = document.getElementById('campaignsToggle');
    var subnav = document.getElementById('campaignsSubnav');

    if (localStorage.getItem(SIDEBAR_KEY) === '1') body.classList.add('sidebar-collapsed');

    var accordionOpen = localStorage.getItem(ACCORDION_KEY) === '1';
    setAccordion(accordionOpen, false);

    btn.addEventListener('click', function () {
        body.classList.toggle('sidebar-collapsed');
        localStorage.setItem(SIDEBAR_KEY, body.classList.contains('sidebar-collapsed') ? '1' : '0');
    });

    toggle.addEventListener('click', function () {
        setAccordion(!subnav.classList.contains('open'), true);
    });

    window.confirmDeleteAll = function (e) {
        e.preventDefault();
        if (confirm('Вы уверены, что хотите удалить все кампании и всю статистику?')) {
            document.getElementById('deleteAllForm').submit();
        }
    };

    function setAccordion(open, save) {
        subnav.classList.toggle('open', open);
        toggle.classList.toggle('open', open);
        if (save) localStorage.setItem(ACCORDION_KEY, open ? '1' : '0');
    }
}());

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

</body>
</html>
