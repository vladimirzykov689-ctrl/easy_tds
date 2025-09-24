<?php
require 'config.php';
$db = getDB();
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

            header('Location: dashboard.php');
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
    document.getElementById('bot_redirect_label').style.display = type==='on' ? 'block' : 'none';
    document.getElementById('bot_redirect_urls').style.display = type==='on' ? 'block' : 'none';
    document.getElementById('bot_redirect_note').style.display = type==='on' ? 'block' : 'none';
}

window.addEventListener('DOMContentLoaded', () => {
    toggleGeoInputs();
    toggleBotInputs();
});
</script>
</head>
<body>

<div class="header">Easy TDS</div>

<div class="content">
    <div class="top-controls">
        <a href="dashboard.php" class="back-btn">← К списку кампаний</a>
    </div>

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
</div>

</body>

</html>
