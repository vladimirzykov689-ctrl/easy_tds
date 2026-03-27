<?php
require 'config.php';
$db = initDB();
checkAuth();
checkIP();

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $changeLogin = ($_POST['change_login'] ?? 'no') === 'yes';
    $changePass  = ($_POST['change_pass']  ?? 'no') === 'yes';
    $changeIP    = ($_POST['change_ip']    ?? 'no') === 'yes';
    $sslAction   = $_POST['ssl_action']   ?? 'none';

    $configPath    = __DIR__ . '/config.php';
    $configContent = file_get_contents($configPath);

    preg_match("/define\\('PANEL_USER_HASH',\\s*'([^']+)'\\)/", $configContent, $mUser);
    preg_match("/define\\('PANEL_PASS_HASH',\\s*'([^']+)'\\)/", $configContent, $mPass);
    $currentUserHash = $mUser[1] ?? '';
    $currentPassHash = $mPass[1] ?? '';

    $newUserHash = $currentUserHash;
    $newPassHash = $currentPassHash;

    // --- Смена логина ---
    if ($changeLogin) {
        $currentLogin = trim($_POST['current_login'] ?? '');
        $newLogin     = trim($_POST['new_login'] ?? '');
        if (empty($currentLogin) || empty($newLogin)) {
            $errors[] = 'Заполните оба поля для смены логина.';
        } elseif (!password_verify($currentLogin, $currentUserHash)) {
            $errors[] = 'Неверный логин.';
        } else {
            $newUserHash = password_hash($newLogin, PASSWORD_DEFAULT);
        }
    }

    // --- Смена пароля ---
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

    // --- Смена IP ---
    $newAllowedIPs = '';
    if ($changeIP) {
        $ipRaw  = trim($_POST['allowed_ips'] ?? '');
        $ipList = array_filter(array_map('trim', explode(',', $ipRaw)));
        $newAllowedIPs = implode(',', $ipList);
    }

    // --- SSL ---
    if (in_array($sslAction, ['add', 'remove'])) {
        $rawDomains = trim($_POST['ssl_domain'] ?? '');

        if (empty($rawDomains)) {
            $errors[] = 'Укажите домен.';
        } else {
            $domains = array_filter(array_map('trim', explode(',', $rawDomains)));

            $sslSuccess = [];
            $sslErrors  = [];

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
                        $cmd = "sudo certbot --nginx -d " . escapeshellarg($domain) . " --non-interactive --agree-tos --register-unsafely-without-email > /dev/null 2>&1; echo $?";
                        $exitCode = trim(shell_exec($cmd));
                        if ($exitCode === '0') {
                            $sslSuccess[] = $domain;
                        } else {
                            $sslErrors[] = $domain;
                        }
                    }

                    if (!empty($sslSuccess)) {
                        $success = 'SSL сертификат успешно выдан для: ' . implode(', ', $sslSuccess);
                    }
                    if (!empty($sslErrors)) {
                        $errors[] = 'Certbot вернул ошибку для: ' . htmlspecialchars(implode(', ', $sslErrors)) . '. Убедитесь что домены указывают на этот сервер.';
                    }

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
                                    if (!empty($result) && preg_match('/^\s*#[^\n]*managed by Certbot/i', end($result))) {
                                        array_pop($result);
                                    }
                                    continue;
                                }
                                $result[] = $line;
                            }

                            $nginx = implode("\n", $result);
                            $hasOtherSSL = (bool) preg_match('/^\s*ssl_certificate\s+/m', $nginx);

                            if (!$hasOtherSSL) {
                                $nginx = preg_replace('/^\s*listen\s+443\s+ssl[^\n]*\n?/m',                '', $nginx);
                                $nginx = preg_replace('/^\s*listen\s+\[::\]:443\s+ssl[^\n]*\n?/m',        '', $nginx);
                                $nginx = preg_replace('/^\s*include\s+[^\n]*options-ssl-nginx[^\n]*\n?/m', '', $nginx);
                                $nginx = preg_replace('/^\s*ssl_dhparam\s+[^\n]+\n?/m',                   '', $nginx);
                                $nginx = preg_replace('/^\s*#[^\n]*managed by Certbot[^\n]*\n?/m',        '', $nginx);
                            }

                            file_put_contents($nginxConf, $nginx);
                        }

                        $sslSuccess[] = $domain;
                    }

                    $testCode = trim(shell_exec("sudo nginx -t > /dev/null 2>&1; echo $?"));
                    $testOut  = shell_exec("sudo nginx -t 2>&1");

                    if ($testCode === '0') {
                        shell_exec("sudo systemctl reload nginx > /dev/null 2>&1");
                        if (!empty($sslSuccess)) {
                            $success = 'Сертификат удалён для: ' . htmlspecialchars(implode(', ', $sslSuccess));
                        }
                    } else {
                        $errors[] = 'Сертификаты удалены, но nginx -t вернул ошибку — перезагрузка отменена. Проверьте конфиг вручную: ' . htmlspecialchars($testOut);
                    }
                }
            }
        }
    }

    // --- Записываем config.php ---
    if (empty($errors) && ($changeLogin || $changePass || $changeIP)) {
        preg_match("/define\\('PANEL_USER_HASH',\\s*'([^']*)'\\)/", $configContent, $oldUser);
        preg_match("/define\\('PANEL_PASS_HASH',\\s*'([^']*)'\\)/", $configContent, $oldPass);
        preg_match("/\\\$ALLOWED_IPS\\s*=\\s*'([^']*)';/", $configContent, $oldIP);

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
            $errors[] = 'Ошибка записи в config.php. Проверьте права доступа.';
        }
    }
}

// Читаем текущий IP список для отображения
$configContent = file_get_contents(__DIR__ . '/config.php');
preg_match("/\\\$ALLOWED_IPS\\s*=\\s*'([^']*)';/", $configContent, $mIP);
$currentIPs = $mIP[1] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Учетная запись</title>
<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">
<script>
function toggleSection(selectId, sectionId) {
    const val = document.getElementById(selectId).value;
    document.getElementById(sectionId).style.display = val === 'yes' ? 'block' : 'none';
}
function toggleSSL() {
    const val = document.getElementById('ssl_action').value;
    document.getElementById('section_ssl').style.display = (val === 'add' || val === 'remove') ? 'block' : 'none';
}
window.addEventListener('DOMContentLoaded', function () {
    toggleSection('change_login', 'section_login');
    toggleSection('change_pass',  'section_pass');
    toggleSection('change_ip',    'section_ip');
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
    <a href="main.php" class="top-header-title" style="text-decoration:none; color:inherit;">Easy TDS</a>
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
                        <a href="campaigns.php?export=csv">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M5 20h14v2H5v-2zm7-2L5.5 11H9V4h6v7h3.5L12 18z"/>
                                </svg>
                            </span>
                            <span class="nav-label">Экспорт логов</span>
                        </a>
                    </li>
                    <li>
                        <a href="campaigns.php?export=goals_csv">
                            <span class="nav-icon">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M5 20h14v2H5v-2zm7-2L5.5 11H9V4h6v7h3.5L12 18z"/>
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
                <a href="credentials.php" class="active">
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

    <!-- ========== PAGE CONTENT ========== -->
    <div class="page-content">
        <div class="content content-centered">

            <div class="add-form">
                <h2>Редактирование учетной записи</h2>

                <?php if (!empty($errors)): ?>
                    <?php foreach ($errors as $e): ?>
                        <div class="error"><?= htmlspecialchars($e) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div style="color:#6fcf6f; font-weight:bold; margin-bottom:15px;">
                        &#10003; <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <form method="post">

                    <!-- === Смена логина === -->
                    <label for="change_login">Изменить логин:</label>
                    <select id="change_login" name="change_login" onchange="toggleSection('change_login', 'section_login')">
                        <option value="no">Нет</option>
                        <option value="yes" <?= ($_POST['change_login'] ?? '') === 'yes' ? 'selected' : '' ?>>Да</option>
                    </select>

                    <div id="section_login" style="display:none;">
                        <label for="current_login">Текущий логин:</label>
                        <input type="text" id="current_login" name="current_login" autocomplete="off">
                        <label for="new_login">Новый логин:</label>
                        <input type="text" id="new_login" name="new_login" autocomplete="off">
                    </div>

                    <!-- === Смена пароля === -->
                    <label for="change_pass">Изменить пароль:</label>
                    <select id="change_pass" name="change_pass" onchange="toggleSection('change_pass', 'section_pass')">
                        <option value="no">Нет</option>
                        <option value="yes" <?= ($_POST['change_pass'] ?? '') === 'yes' ? 'selected' : '' ?>>Да</option>
                    </select>

                    <div id="section_pass" style="display:none;">
                        <label for="current_pass">Текущий пароль:</label>
                        <input type="password" id="current_pass" name="current_pass" autocomplete="off">
                        <label for="new_pass">Новый пароль:</label>
                        <input type="password" id="new_pass" name="new_pass" autocomplete="off">
                    </div>

                    <!-- === Ограничение по IP === -->
                    <label for="change_ip">Ограничить доступ по IP:</label>
                    <select id="change_ip" name="change_ip" onchange="toggleSection('change_ip', 'section_ip')">
                        <option value="no">Нет</option>
                        <option value="yes" <?= ($_POST['change_ip'] ?? '') === 'yes' ? 'selected' : '' ?>>Да</option>
                    </select>

                    <div id="section_ip" style="display:none;">
                        <label for="allowed_ips">Список IP-адресов:</label>
                        <textarea id="allowed_ips" name="allowed_ips"><?= htmlspecialchars($currentIPs) ?></textarea>
                        <div class="note">Укажите IP-адреса через запятую. Например: 192.168.1.1,10.0.0.1</div>
                    </div>

                    <!-- === SSL домены === -->
                    <label for="ssl_action">SSL для доменов:</label>
                    <select id="ssl_action" name="ssl_action" onchange="toggleSSL()">
                        <option value="none">Нет</option>
                        <option value="add" <?= ($_POST['ssl_action'] ?? '') === 'add' ? 'selected' : '' ?>>Добавить</option>
                        <option value="remove" <?= ($_POST['ssl_action'] ?? '') === 'remove' ? 'selected' : '' ?>>Удалить</option>
                    </select>

                    <div id="section_ssl" style="display:none;">
                        <label for="ssl_domain">Домены (через запятую):</label>
                        <textarea id="ssl_domain" name="ssl_domain"
                               placeholder="example.com, domain2.com" autocomplete="off"><?= htmlspecialchars($_POST['ssl_domain'] ?? '') ?></textarea>
                    </div>

                    <button type="submit">Сохранить изменения</button>
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
</script>

</body>
</html>
