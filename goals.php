<?php
require __DIR__ . '/config.php';
$db = getDB();

// ── Ищем click_id ─────────────────────────────────────────────────────────────
$clickId = trim($_GET['clickid'] ?? '');

if (!$clickId) {
    http_response_code(400);
    exit('Bad Request: clickid not found');
}

// ── Ищем stream_id по click_id ────────────────────────────────────────────────
$stmtLog = $db->prepare("SELECT stream_id FROM logs WHERE click_id = ? LIMIT 1");
$stmtLog->execute([$clickId]);
$log = $stmtLog->fetch(PDO::FETCH_ASSOC);

if (!$log) {
    http_response_code(404);
    exit('Not Found: click_id not in logs');
}

$streamId = (int)$log['stream_id'];

// ── Загружаем все цели стрима ─────────────────────────────────────────────────
$stmtGoals = $db->prepare("SELECT id, param_name, value_type, target_value FROM goals WHERE stream_id = ?");
$stmtGoals->execute([$streamId]);
$campaignGoals = $stmtGoals->fetchAll(PDO::FETCH_ASSOC);

// ── Строим индекс: param_name => goal ─────────────────────────────────────────
$goalsIndex = [];
foreach ($campaignGoals as $g) {
    $goalsIndex[$g['param_name']] = $g;
}

// ── Перебираем все GET-параметры и ищем совпадения с целями ──────────────────
$tracked = 0;
$skipped = 0;

// ── Сначала валидируем все параметры ─────────────────────────────────────────
$toProcess = [];
foreach ($_GET as $key => $val) {
    if ($key === 'clickid') continue;
    if (!isset($goalsIndex[$key])) continue;

    $goal = $goalsIndex[$key];

    // Если есть целевое значение и оно не совпадает — весь постбек невалиден
    if (!empty($goal['target_value']) && (string)$val !== (string)$goal['target_value']) {
        http_response_code(200);
        exit("OK: tracked=0 skipped=0 reason=target_value_mismatch({$key}={$val})");
    }

    $toProcess[$key] = $val;
}

// ── Теперь записываем все прошедшие проверку цели ────────────────────────────
foreach ($toProcess as $key => $val) {
    $goal       = $goalsIndex[$key];
    $goalId     = (int)$goal['id'];
    $value      = (float)$val;
    $finalValue = $goal['value_type'] === 'flag' ? 0.0 : $value;

    // Защита от дублей
    $stmtDup = $db->prepare("SELECT COUNT(*) FROM conversions WHERE click_id = ? AND goal_id = ?");
    $stmtDup->execute([$clickId, $goalId]);
    if ((int)$stmtDup->fetchColumn() > 0) {
        $skipped++;
        continue;
    }

    $stmtInsert = $db->prepare("INSERT INTO conversions (click_id, stream_id, goal_id, value) VALUES (?, ?, ?, ?)");
    $stmtInsert->execute([$clickId, $streamId, $goalId, $finalValue]);
    $tracked++;
}

http_response_code(200);
exit("OK: tracked={$tracked} skipped={$skipped}");
