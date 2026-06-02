<?php
require __DIR__ . '/config.php';

define('TG_CONFIG_FILE', __DIR__ . '/tg_config.json');

// ── Config helpers ────────────────────────────────────────────────────────────

function loadConfig(): array {
    if (!file_exists(TG_CONFIG_FILE)) return [];
    return json_decode(file_get_contents(TG_CONFIG_FILE), true) ?? [];
}

function saveConfig(array $data): void {
    file_put_contents(TG_CONFIG_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function getToken(): string {
    return loadConfig()['bot_token'] ?? '';
}

function getApiKey(): string {
    return loadConfig()['api_key'] ?? '';
}

// ── Date filter helpers ───────────────────────────────────────────────────────

function saveDateFilter(int $chatId, int $campId, string $dateFrom, string $dateTo): void {
    $config = loadConfig();
    $key = "{$chatId}:{$campId}";
    $config['date_filters'][$key] = ['date_from' => $dateFrom, 'date_to' => $dateTo];
    saveConfig($config);
}

function getDateFilter(int $chatId, int $campId): ?array {
    $key = "{$chatId}:{$campId}";
    return loadConfig()['date_filters'][$key] ?? null;
}

function clearDateFilter(int $chatId, int $campId): void {
    $config = loadConfig();
    $key = "{$chatId}:{$campId}";
    unset($config['date_filters'][$key]);
    saveConfig($config);
}

// ── Helper: валюта ────────────────────────────────────────────────────────────

function currencySymbol(string $currency): string {
    $symbols = ['USD' => '$', 'EUR' => '€', 'RUB' => '₽'];
    return $symbols[$currency] ?? $currency;
}

// ── Конвертация ISO-кода страны в emoji-флаг ──────────────────────────────────

function countryFlag(string $iso): string {
    $iso = strtoupper(trim($iso));
    if (strlen($iso) !== 2) return '';
    $offset = 0x1F1E6 - ord('A');
    return
        mb_chr(ord($iso[0]) + $offset, 'UTF-8') .
        mb_chr(ord($iso[1]) + $offset, 'UTF-8') .
        ' ';
}

// ── Telegram API запросы ──────────────────────────────────────────────────────

function tgRequest(string $method, array $params = []): ?array {
    $token = getToken();
    if (empty($token)) return null;

    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 35);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function sendMessage(int $chatId, string $text, array $keyboard = []): void {
    $payload = [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if (!empty($keyboard)) {
        $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    }
    tgRequest('sendMessage', $payload);
}

function sendReplyKeyboard(int $chatId, string $text): void {
    $keyboard = [
        [['text' => '📊 Статистика кампаний']],
[['text' => '📋 Список кампаний']],
    ];
    tgRequest('sendMessage', [
        'chat_id'      => $chatId,
        'text'         => $text,
        'parse_mode'   => 'HTML',
        'reply_markup' => json_encode([
            'keyboard'          => $keyboard,
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
            'persistent'        => true,
        ]),
    ]);
}

function editMessage(int $chatId, int $messageId, string $text, array $keyboard = []): void {
    $payload = [
        'chat_id'                  => $chatId,
        'message_id'               => $messageId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if (!empty($keyboard)) {
        $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    }
    tgRequest('editMessageText', $payload);
}

function answerCallback(string $callbackId, string $text = ''): void {
    tgRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text]);
}

// ── Получение обновлений (polling) ────────────────────────────────────────────

function getUpdates(int $offset = 0): array {
    $token = getToken();
    if (empty($token)) return [];

    $ch = curl_init("https://api.telegram.org/bot{$token}/getUpdates");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'offset'  => $offset,
        'timeout' => 30,   // long polling — ждём до 30 сек
        'limit'   => 100,
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 35);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['result'] ?? [];
}

// ── API запрос к панели ───────────────────────────────────────────────────────

function apiRequest(string $action, array $params = []): ?array {
    $apiKey = getApiKey();
    if (empty($apiKey)) return null;

    $query = http_build_query(array_merge(['secret' => $apiKey, 'action' => $action], $params));
    $ch = curl_init("http://127.0.0.1/api.php?{$query}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return ($data && ($data['ok'] ?? false)) ? $data : null;
}

// ── Меню ──────────────────────────────────────────────────────────────────────

function statsMenu(): array {
    return [[['text' => '🔄 Обновить', 'callback_data' => 'stats_refresh']]];
}

function campStatsMenu(int $campId, bool $hasFilter = false): array {
    return [
[['text' => '🔄 Обновить', 'callback_data' => "camp_refresh:{$campId}"]],
[['text' => $hasFilter ? '🗑️ Сбросить фильтр' : '📅 Фильтр по дате', 'callback_data' => "camp_filter:{$campId}"]],
    ];
}

// ── Формирование сообщений ────────────────────────────────────────────────────

function buildStatsMessage(array $stats): string {
    $currency = $stats['profit_currency'] ?? 'USD';
    $symbol   = currencySymbol($currency);

    $topCamp = '';
    foreach ($stats['top_campaigns'] as $i => $c) {
        $topCamp .= ($i + 1) . '. ' . $c['name'] . ' — ' . number_format((float)$c['profit'], 2) . $symbol . "\n";
    }
    if (!$topCamp) $topCamp = "Нет данных\n";

    $topGoals = '';
    $goalNum  = 1;
    foreach ($stats['top_goals'] as $g) {
        if (!empty($g['is_revenue'])) continue;
        $topGoals .= $goalNum . '. ' . $g['name'] . ' — ' . $g['cnt'] . "\n";
        $goalNum++;
    }
    if (!$topGoals) $topGoals = "Нет данных\n";

$topGeo = '';
    $i = 1;
    $totalGeoClicks = $stats['total_clicks'] ?: 1;
    foreach ($stats['top_geo'] as $iso => $cnt) {
        $pct = round($cnt / $totalGeoClicks * 100);
        $topGeo .= $i . '. ' . countryFlag($iso) . $iso . ' — ' . $cnt . ' (' . $pct . "%)  \n";
        $i++;
    }
    if (!$topGeo) $topGeo = "Нет данных\n";

    $desktop = (int)($stats['devices']['desktop'] ?? 0);
    $mobile  = (int)($stats['devices']['mobile']  ?? 0);
    $total   = $desktop + $mobile ?: 1;
    $deskPct = round($desktop / $total * 100);
    $mobPct  = round($mobile  / $total * 100);

    return
        "📊 <b>Общая статистика кампаний</b>\n\n" .
        "📁 Активных кампаний: <b>{$stats['total_campaigns']}</b>\n\n" .
        "👆 Клики: <b>{$stats['total_clicks']}</b>\n" .
        "👤 Уники: <b>{$stats['unique_ips']}</b>\n" .
        "🤖 Боты: <b>{$stats['bots']}</b>\n\n" .
        "🖥 Desktop: <b>{$desktop}</b> ({$deskPct}%)\n" .
        "📱 Mobile: <b>{$mobile}</b> ({$mobPct}%)\n\n" .
        "🌍 <b>Топ гео:</b>\n" . $topGeo . "\n" .
        "💰 Профит: <b>" . ($stats['total_profit'] > 0 ? number_format($stats['total_profit'], 2) . $symbol : 'Нет данных') . "</b>\n\n" .
        "🏆 <b>Топ кампании:</b>\n" . $topCamp . "\n" .
        "🎯 <b>Топ цели:</b>\n" . $topGoals;
}

function buildCampStatsMessage(array $data, ?array $filter = null): string {
    $camp     = $data['campaign'];
    $currency = $data['profit_currency'] ?? 'USD';
    $symbol   = currencySymbol($currency);

    $filterText = '';
    if ($filter) {
        $filterText = "📅 <b>Фильтр:</b> {$filter['date_from']} — {$filter['date_to']}\n\n";
    }

$topGeo = '';
    $i = 1;
    $totalGeoClicks = $data['total_clicks'] ?: 1;
    foreach ($data['top_geo'] as $iso => $cnt) {
        $pct = round($cnt / $totalGeoClicks * 100);
        $topGeo .= $i . '. ' . countryFlag($iso) . $iso . ' — ' . $cnt . ' (' . $pct . "%)  \n";
        $i++;
    }
    if (!$topGeo) $topGeo = "Нет данных\n";

    $desktop = (int)($data['devices']['desktop'] ?? 0);
    $mobile  = (int)($data['devices']['mobile']  ?? 0);
    $total   = $desktop + $mobile ?: 1;
    $deskPct = round($desktop / $total * 100);
    $mobPct  = round($mobile  / $total * 100);

    $goalsText = '';
    foreach ($data['goals'] as $g) {
        if ($g['is_revenue']) continue;
        $goalsText .= "• " . htmlspecialchars($g['name']) . " — <b>{$g['conversions']}</b>\n";
    }
    if (!$goalsText) $goalsText = "Нет данных\n";

$campUrl  = htmlspecialchars($camp['url'] ?? '—');

    $profitValue = (float)$data['profit'];
    $profitText  = $profitValue > 0 ? number_format($profitValue, 2) . $symbol : "Нет данных";

    return
        "📊 <b>Статистика кампании</b>\n" .
        "<b>" . htmlspecialchars($camp['name']) . "</b>\n\n" .
        $filterText .
"🔗 <b>URL Кампании:</b>\n<a href=\"{$campUrl}\">{$campUrl}</a>\n\n" .
        "👆 Клики: <b>{$data['total_clicks']}</b>\n" .
        "👤 Уники: <b>{$data['unique_ips']}</b>\n" .
        "🤖 Боты: <b>{$data['bots']}</b>\n\n" .
        "🖥 Desktop: <b>{$desktop}</b> ({$deskPct}%)\n" .
        "📱 Mobile: <b>{$mobile}</b> ({$mobPct}%)\n\n" .
        "🌍 <b>Топ гео:</b>\n" . $topGeo . "\n" .
        "💰 Профит: <b>{$profitText}</b>\n\n" .
        "🎯 <b>Цели:</b>\n" . $goalsText;
}

function buildCampaignsList(array $campaigns): array {
    $msg = "📋 <b>Список активных кампаний</b>";
    $keyboard = [];
    foreach ($campaigns as $c) {
        $keyboard[] = [['text' => '📈 ' . htmlspecialchars($c['name']), 'callback_data' => 'camp_stats:' . $c['id']]];
    }
    if (empty($keyboard)) return ["📋 Кампаний пока нет.", []];
    return [$msg, $keyboard];
}

// ── Обработка одного update ───────────────────────────────────────────────────

function processUpdate(array $input): void {
    $message  = $input['message']        ?? null;
    $callback = $input['callback_query'] ?? null;

    // Callback кнопки
    if ($callback) {
        $chatId     = (int)$callback['message']['chat']['id'];
        $messageId  = (int)$callback['message']['message_id'];
        $callbackId = $callback['id'];
        $data       = $callback['data'];

        answerCallback($callbackId);

        if (str_starts_with($data, 'camp_stats:')) {
            $campId = (int)explode(':', $data)[1];
            $filter = getDateFilter($chatId, $campId);
            $params = ['id' => $campId];
            if ($filter) { $params['date_from'] = $filter['date_from']; $params['date_to'] = $filter['date_to']; }
            $result = apiRequest('campaign', $params);
            if (!$result) editMessage($chatId, $messageId, "❌ Не удалось получить статистику кампании.", campStatsMenu($campId, false));
            else editMessage($chatId, $messageId, buildCampStatsMessage($result, $filter), campStatsMenu($campId, $filter !== null));
            return;
        }

        if (str_starts_with($data, 'camp_refresh:')) {
            $campId = (int)explode(':', $data)[1];
            $filter = getDateFilter($chatId, $campId);
            $params = ['id' => $campId];
            if ($filter) { $params['date_from'] = $filter['date_from']; $params['date_to'] = $filter['date_to']; }
            $result = apiRequest('campaign', $params);
            if (!$result) editMessage($chatId, $messageId, "❌ Не удалось получить статистику кампании.", campStatsMenu($campId, $filter !== null));
            else editMessage($chatId, $messageId, buildCampStatsMessage($result, $filter), campStatsMenu($campId, $filter !== null));
            return;
        }

        if (str_starts_with($data, 'camp_filter:')) {
            $campId = (int)explode(':', $data)[1];
            sendMessage($chatId,
                "📅 Введите диапазон дат для фильтрации:\n\n" .
                "<b>Формат:</b> YYYY-MM-DD\n\n" .
                "<b>Пример:</b>\n<code>2024-01-01 2024-01-31</code>\n\n" .
                "Отправьте даты в формате: <b>date_from date_to</b>"
            );
            $config = loadConfig();
            if (!isset($config['temp_filter'])) $config['temp_filter'] = [];
            $config['temp_filter'][$chatId] = ['camp_id' => $campId];
            saveConfig($config);
            return;
        }

        if (str_starts_with($data, 'camp_clear_filter:')) {
            $campId = (int)explode(':', $data)[1];
            clearDateFilter($chatId, $campId);
            $result = apiRequest('campaign', ['id' => $campId]);
            if (!$result) editMessage($chatId, $messageId, "❌ Не удалось получить статистику кампании.", campStatsMenu($campId, false));
            else editMessage($chatId, $messageId, buildCampStatsMessage($result, null), campStatsMenu($campId, false));
            return;
        }

        switch ($data) {
            case 'stats':
            case 'stats_refresh':
                $stats = apiRequest('stats');
                if (!$stats) editMessage($chatId, $messageId, "❌ Не удалось получить статистику.\nПроверьте URL и API ключ.", statsMenu());
                else editMessage($chatId, $messageId, buildStatsMessage($stats), statsMenu());
                break;

            case 'campaigns':
                $result = apiRequest('campaigns');
                if (!$result || $result['count'] === 0) { editMessage($chatId, $messageId, "📋 Кампаний пока нет."); break; }
                [$msg, $keyboard] = buildCampaignsList($result['campaigns']);
                editMessage($chatId, $messageId, $msg, $keyboard);
                break;
        }
        return;
    }

    // Обычные сообщения
    if (!$message) return;

    $chatId = (int)$message['chat']['id'];
    $text   = trim($message['text'] ?? '');

    // Ввод дат для фильтра
    $config = loadConfig();
    if (isset($config['temp_filter'][$chatId])) {
        $campId = $config['temp_filter'][$chatId]['camp_id'];
        unset($config['temp_filter'][$chatId]);
        saveConfig($config);

        $parts = preg_split('/\s+/', $text);
        if (count($parts) !== 2) {
            sendMessage($chatId, "❌ Неверный формат!\n\nОтправьте в виде: <code>YYYY-MM-DD YYYY-MM-DD</code>\nПример: <code>2024-01-01 2024-01-31</code>");
            return;
        }
        [$dateFrom, $dateTo] = $parts;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            sendMessage($chatId, "❌ Неверный формат даты!\n\nИспользуйте YYYY-MM-DD\nПример: <code>2024-01-01 2024-01-31</code>");
            return;
        }
        saveDateFilter($chatId, $campId, $dateFrom, $dateTo);
        $result = apiRequest('campaign', ['id' => $campId, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
        if (!$result) { sendMessage($chatId, "❌ Не удалось получить статистику."); return; }
        sendMessage($chatId, buildCampStatsMessage($result, ['date_from' => $dateFrom, 'date_to' => $dateTo]), campStatsMenu($campId, true));
        return;
    }

    // Кнопки Reply Keyboard
    switch ($text) {
        case '📊 Статистика кампаний':
            $stats = apiRequest('stats');
            if (!$stats) { sendMessage($chatId, "❌ Не удалось получить статистику.\nПроверьте URL и API ключ.", statsMenu()); return; }
            sendMessage($chatId, buildStatsMessage($stats), statsMenu());
            return;

        case '📋 Список кампаний':
            $result = apiRequest('campaigns');
            if (!$result) { sendMessage($chatId, "❌ Не удалось получить список кампаний."); return; }
            if ($result['count'] === 0) { sendMessage($chatId, "📋 Кампаний пока нет."); return; }
            [$msg, $keyboard] = buildCampaignsList($result['campaigns']);
            sendMessage($chatId, $msg, $keyboard);
            return;

}

    // /start и всё остальное
    sendReplyKeyboard($chatId, "👋 <b>Добро пожаловать в Easy TDS</b>");
}

// ── Главный цикл polling ──────────────────────────────────────────────────────

echo "Бот запущен...\n";

$offset = 0;

while (true) {
    $updates = getUpdates($offset);

    foreach ($updates as $update) {
        $offset = $update['update_id'] + 1;
        try {
            processUpdate($update);
        } catch (Throwable $e) {
            echo "⚠️ Ошибка: " . $e->getMessage() . "\n";
        }
    }
}
