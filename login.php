<?php
require __DIR__ . '/config.php';
initDB();
checkIP();

$error = '';
$maxAttempts = 5;
$lockoutTime = 300;

if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['last_attempt'])) $_SESSION['last_attempt'] = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($_SESSION['login_attempts'] >= $maxAttempts && (time() - $_SESSION['last_attempt'] < $lockoutTime)) {
        $remaining = $lockoutTime - (time() - $_SESSION['last_attempt']);
        $minutes = floor($remaining / 60);
        $seconds = $remaining % 60;
        $error = "Слишком много попыток. Попробуйте через $minutes минут $seconds секунд.";
    } else {
        $user = $_POST['username'] ?? '';
        $pass = $_POST['password'] ?? '';

        if (!empty(PANEL_USER_HASH) && !empty(PANEL_PASS_HASH) &&
            password_verify($user, PANEL_USER_HASH) &&
            password_verify($pass, PANEL_PASS_HASH)) {

            $_SESSION['username'] = $user;
            $_SESSION['login_attempts'] = 0;
            header('Location: main.php');
            exit;
        } else {
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt'] = time();
            $error = 'Неверный логин или пароль';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<script>
(function () {
    var saved = localStorage.getItem('site_bg_theme');
    if (saved) {
        document.documentElement.style.setProperty('--site-bg-image', 'url(/img/backgrounds/' + saved + ')');
    }
}());
</script>
<meta charset="UTF-8">
<title>Вход в Easy TDS</title>
<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="login-page">

<div class="login-container">
    <img src="/img/logo.png" alt="Easy TDS" class="login-logo">
    <h2>Добро пожаловать в Easy TDS!</h2>
    <p class="login-subtitle">Войдите, чтобы продолжить работу с системой</p>
    <form method="post">
        <div class="input-group">
            <span class="input-icon">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-3.3 3.6-6 8-6s8 2.7 8 6"/></svg>
            </span>
            <input type="text" name="username" placeholder="Логин" required>
        </div>
        <div class="input-group">
            <span class="input-icon">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M7 10V7a5 5 0 0110 0v3"/></svg>
            </span>
            <input type="password" name="password" placeholder="Пароль" required>
        </div>
        <button type="submit">Войти</button>
    </form>
    <?php if($error): ?>
        <div class="error-message"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
</div>

</body>
</html>