<?php
require __DIR__ . '/config.php';
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
            header('Location: dashboard.php');
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
<meta charset="UTF-8">
<title>Вход в Easy TDS</title>

<link rel="icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="shortcut icon" type="image/x-icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/css/style.css">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="login-page">

<div>
    <div class="header">Easy TDS</div>
    <div class="login-container">
        <h2>Вход</h2>
        <form method="post">
            <input type="text" name="username" placeholder="Логин" required>
            <input type="password" name="password" placeholder="Пароль" required>
            <button type="submit">Войти</button>
        </form>
        <?php if($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
