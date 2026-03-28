<?php
require __DIR__ . '/config.php';

define('TG_CONFIG_FILE', __DIR__ . '/tg_config.json');
define('DATE_FILTER_FILE', __DIR__ . '/date_filters.json');

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

function getPanelUrl(): string {
    return loadConfig()['panel_url'] ?? '';
}

function getApiKey(): string {
    return loadConfig()['api_key'] ?? '';
}

// ── Date filter helpers ───────────────────────────────────────────────────────

function loadDateFilters(): array {
    if (!file_exists(DATE_FILTER_FILE)) return [];
    return json_decode(file_get_contents(DATE_FILTER_FILE), true) ?? [];
}

function saveDateFilter(int $chatId, int $campId, string $dateFrom, string $dateTo): void {
    $filters = loadDateFilters();
    $key = "{$chatId}:{$campId}";
    $filters[$key] = ['date_from' => $dateFrom, 'date_to' => $dateTo];
    file_put_contents(DATE_FILTER_FILE, json_encode($filters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getDateFilter(int $chatId, int $campId): ?array {
    $filters = loadDateFilters();
    $key = "{$chatId}:{$campId}";
    return $filters[$key] ?? null;
}

function clearDateFilter(int $chatId, int $campId): void {
    $filters = loadDateFilters();
    $key = "{$chatId}:{$campId}";
    unset($filters[$key]);
    file_put_contents(DATE_FILTER_FILE, json_encode($filters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ── Helper: конвертация кода валюты в символ (только USD, EUR, RUB) ───────────

function currencySymbol(string $currency): string {
    $symbols = [
        'USD' => '$',
        'EUR' => '€',
        'RUB' => '₽',
    ];
    return $symbols[$currency] ?? $currency;
}

// ── Send message ──────────────────────────────────────────────────────────────

function sendMessage(int $chatId, string $text, array $keyboard = []): void {
    $token = getToken();
    if (empty($token)) return;

    $payload = [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];

    if (!empty($keyboard)) {
        $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    }

    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// ── Send message with Reply Keyboard ─────────────────────────────────────────

function sendReplyKeyboard(int $chatId, string $text): void {
    $token = getToken();
    if (empty($token)) return;

    // 🔗 Текущий URL TDS — ПО ВЕСЬ ТОП, остальные ДВЕ рядом ПОД НЕЙ
    $keyboard = [
        [
            ['text' => '🔗 Текущий URL TDS'],
        ],
        [
            ['text' => '📊 Статистика кампаний'],
            ['text' => '📋 Список кампаний'],
        ],
    ];

    $payload = [
        'chat_id'      => $chatId,
        'text'         => $text,
        'parse_mode'   => 'HTML',
        'reply_markup' => json_encode([
            'keyboard'          => $keyboard,
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
            'persistent'        => true,
        ]),
    ];

    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// ── Edit message ──────────────────────────────────────────────────────────────

function editMessage(int $chatId, int $messageId, string $text, array $keyboard = []): void {
    $token = getToken();
    if (empty($token)) return;

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

    $ch = curl_init("https://api.telegram.org/bot{$token}/editMessageText");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function answerCallback(string $callbackId, string $text = ''): void {
    $token = getToken();
    $ch = curl_init("https://api.telegram.org/bot{$token}/answerCallbackQuery");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['callback_query_id' => $callbackId, 'text' => $text]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// ── Webhook registration ──────────────────────────────────────────────────────

function registerWebhook(string $token, string $panelUrl): bool {
    $ch = curl_init("https://api.telegram.org/bot{$token}/setWebhook");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['url' => $panelUrl . '/tg_stats.php']));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $response['ok'] ?? false;
}

// ── API запрос к панели ───────────────────────────────────────────────────────

function apiRequest(string $action, array $params = []): ?array {
    $panelUrl = getPanelUrl();
    $apiKey   = getApiKey();

    if (empty($panelUrl) || empty($apiKey)) return null;

    $query = http_build_query(array_merge([
        'secret' => $apiKey,
        'action' => $action,
    ], $params));

    $ch = curl_init("{$panelUrl}/api.php?{$query}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return ($data && ($data['ok'] ?? false)) ? $data : null;
}

// ── Меню статистики (inline) ──────────────────────────────────────────────────

function statsMenu(): array {
    return [[
        ['text' => '🔄 Обновить', 'callback_data' => 'stats_refresh'],
    ]];
}

// ── Меню статистики кампании (inline) с фильтром по дате ──────────────────────

function campStatsMenu(int $campId, bool $hasFilter = false): array {
    return [
        [
            ['text' => '🔄 Обновить', 'callback_data' => "camp_refresh:{$campId}"],
            ['text' => $hasFilter ? '🗑️ Сбросить фильтр' : '📅 Фильтр по дате', 'callback_data' => "camp_filter:{$campId}"],
        ],
        [
            ['text' => '◀️ К списку кампаний', 'callback_data' => 'campaigns'],
        ],
    ];
}

// ── Формирование сообщения общей статистики ───────────────────────────────────

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
    foreach ($stats['top_geo'] as $iso => $cnt) {
        $topGeo .= $i . '. ' . $iso . ' — ' . $cnt . "\n";
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
        "💰 Профит: <b>" . number_format($stats['total_profit'], 2) . $symbol . "</b>\n\n" .
        "🏆 <b>Топ кампании:</b>\n" . $topCamp . "\n" .
        "🎯 <b>Топ цели:</b>\n" . $topGoals;
}

// ── Формирование сообщения статистики кампании с фильтром дат ─────────────────

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
    foreach ($data['top_geo'] as $iso => $cnt) {
        $topGeo .= $i . '. ' . $iso . ' — ' . $cnt . "\n";
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
        $goalsText .= "• " . htmlspecialchars($g['name']) . " — <b>{$g['conversions']}</b> конв.\n";
    }
    if (!$goalsText) $goalsText = "Нет данных\n";

    $campUrl  = htmlspecialchars($camp['url'] ?? '—');
    $panelUrl = getPanelUrl();
    $slug     = $camp['slug'] ?? '';
    $campLink = $panelUrl . '/' . $slug;

    // Профит: если 0 — пишем "Нет данных" (с жирным шрифтом), с символом валюты
    $profitValue = (float)$data['profit'];
    $profitText = $profitValue > 0 
        ? number_format($profitValue, 2) . $symbol
        : "Нет данных";

    return
        "📊 <b>Статистика кампании</b>\n" .
        "<b>" . htmlspecialchars($camp['name']) . "</b>\n\n" .
        $filterText .
        "🔗 <b>URL Кампании:</b>\n" .
        "Кампания ведет на URL:\n" .
        "<a href=\"{$campUrl}\">{$campUrl}</a>\n" .
        "🌐 <a href=\"{$campLink}\">Перейти к кампании</a>\n\n" .
        "👆 Клики: <b>{$data['total_clicks']}</b>\n" .
        "👤 Уники: <b>{$data['unique_ips']}</b>\n" .
        "🤖 Боты: <b>{$data['bots']}</b>\n\n" .
        "🖥 Desktop: <b>{$desktop}</b> ({$deskPct}%)\n" .
        "📱 Mobile: <b>{$mobile}</b> ({$mobPct}%)\n\n" .
        "🌍 <b>Топ гео:</b>\n" . $topGeo . "\n" .
        "💰 Профит: <b>{$profitText}</b>\n\n" .
        "🎯 <b>Цели:</b>\n" . $goalsText;
}

// ── Формирование списка кампаний с кликабельными названиями ───────────────────

function buildCampaignsList(array $campaigns): array {
    $msg = "📋 <b>Список активных кампаний</b>\n\n" .
           "Нажмите на название кампании для просмотра статистики:\n\n";

    $keyboard = [];
    foreach ($campaigns as $c) {
        $campaignName = htmlspecialchars($c['name']);
        $keyboard[] = [
            ['text' => '📈 ' . $campaignName, 'callback_data' => 'camp_stats:' . $c['id']],
        ];
    }

    if (empty($keyboard)) {
        return ["📋 Кампаний пока нет.", []];
    }

    return [$msg, $keyboard];
}

// ── Получаем update ───────────────────────────────────────────────────────────

$input    = json_decode(file_get_contents('php://input'), true);
$message  = $input['message']        ?? null;
$callback = $input['callback_query'] ?? null;

// ── Обработка callback кнопок ─────────────────────────────────────────────────

if ($callback) {
    $chatId     = (int)$callback['message']['chat']['id'];
    $messageId  = (int)$callback['message']['message_id'];
    $callbackId = $callback['id'];
    $data       = $callback['data'];

    answerCallback($callbackId);

    if ($data === 'change_url_tds') {
        sendMessage($chatId, 
            "✏️ Введите новый URL панели TDS:\n\n" .
            "<b>Пример:</b> <code>/edit_url https://your_domain</code>"
        );
        exit('OK');
    }

    if (str_starts_with($data, 'camp_stats:')) {
        $campId = (int)explode(':', $data)[1];
        $filter = getDateFilter($chatId, $campId);
        
        $params = ['id' => $campId];
        if ($filter) {
            $params['date_from'] = $filter['date_from'];
            $params['date_to'] = $filter['date_to'];
        }
        
        $result = apiRequest('campaign', $params);

        if (!$result) {
            editMessage($chatId, $messageId, "❌ Не удалось получить статистику кампании.", campStatsMenu($campId, false));
        } else {
            editMessage($chatId, $messageId, buildCampStatsMessage($result, $filter), campStatsMenu($campId, $filter !== null));
        }
        exit('OK');
    }

    if (str_starts_with($data, 'camp_refresh:')) {
        $campId = (int)explode(':', $data)[1];
        $filter = getDateFilter($chatId, $campId);
        
        $params = ['id' => $campId];
        if ($filter) {
            $params['date_from'] = $filter['date_from'];
            $params['date_to'] = $filter['date_to'];
        }
        
        $result = apiRequest('campaign', $params);

        if (!$result) {
            editMessage($chatId, $messageId, "❌ Не удалось получить статистику кампании.", campStatsMenu($campId, $filter !== null));
        } else {
            editMessage($chatId, $messageId, buildCampStatsMessage($result, $filter), campStatsMenu($campId, $filter !== null));
        }
        exit('OK');
    }

    if (str_starts_with($data, 'camp_filter:')) {
        $campId = (int)explode(':', $data)[1];
        sendMessage($chatId, 
            "📅 Введите диапазон дат для фильтрации:\n\n" .
            "<b>Формат:</b> YYYY-MM-DD\n\n" .
            "<b>Пример:</b>\n" .
            "<code>2024-01-01 2024-01-31</code>\n\n" .
            "Отправьте даты в формате: <b>date_from date_to</b>"
        );
        $config = loadConfig();
        if (!isset($config['temp_filter'])) $config['temp_filter'] = [];
        $config['temp_filter'][$chatId] = ['camp_id' => $campId];
        saveConfig($config);
        exit('OK');
    }

    if (str_starts_with($data, 'camp_clear_filter:')) {
        $campId = (int)explode(':', $data)[1];
        clearDateFilter($chatId, $campId);
        
        $result = apiRequest('campaign', ['id' => $campId]);

        if (!$result) {
            editMessage($chatId, $messageId, "❌ Не удалось получить статистику кампании.", campStatsMenu($campId, false));
        } else {
            editMessage($chatId, $messageId, buildCampStatsMessage($result, null), campStatsMenu($campId, false));
        }
        exit('OK');
    }

    switch ($data) {

        case 'stats':
        case 'stats_refresh':
            $stats = apiRequest('stats');
            if (!$stats) {
                editMessage($chatId, $messageId, "❌ Не удалось получить статистику.\nПроверьте URL и API ключ.", statsMenu());
                break;
            }
            editMessage($chatId, $messageId, buildStatsMessage($stats), statsMenu());
            break;

        case 'campaigns':
            $result = apiRequest('campaigns');
            if (!$result || $result['count'] === 0) {
                editMessage($chatId, $messageId, "📋 Кампаний пока нет.");
                break;
            }
            [$msg, $keyboard] = buildCampaignsList($result['campaigns']);
            editMessage($chatId, $messageId, $msg, $keyboard);
            break;
    }
    exit('OK');
}

// ── Обработка сообщений ───────────────────────────────────────────────────────

if (!$message) exit('OK');

$chatId = (int)$message['chat']['id'];
$text   = trim($message['text'] ?? '');

// ── Обработка ввода дат для фильтра ───────────────────────────────────────────

$config = loadConfig();
if (isset($config['temp_filter'][$chatId])) {
    $campId = $config['temp_filter'][$chatId]['camp_id'];
    unset($config['temp_filter'][$chatId]);
    saveConfig($config);

    $parts = preg_split('/\s+/', $text);
    
    if (count($parts) !== 2) {
        sendMessage($chatId, "❌ Неверный формат!\n\nОтправьте в виде: <code>YYYY-MM-DD YYYY-MM-DD</code>\nПример: <code>2024-01-01 2024-01-31</code>");
        exit('OK');
    }

    [$dateFrom, $dateTo] = $parts;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        sendMessage($chatId, "❌ Неверный формат даты!\n\nИспользуйте YYYY-MM-DD\nПример: <code>2024-01-01 2024-01-31</code>");
        exit('OK');
    }

    saveDateFilter($chatId, $campId, $dateFrom, $dateTo);

    $result = apiRequest('campaign', ['id' => $campId, 'date_from' => $dateFrom, 'date_to' => $dateTo]);

    if (!$result) {
        sendMessage($chatId, "❌ Не удалось получить статистику.");
        exit('OK');
    }

    $filter = ['date_from' => $dateFrom, 'date_to' => $dateTo];
    sendMessage($chatId, buildCampStatsMessage($result, $filter), campStatsMenu($campId, true));
    exit('OK');
}

// ── /set_token ────────────────────────────────────────────────────────────────

if (str_starts_with($text, '/set_token')) {
    $parts = explode(' ', $text, 2);
    $token = trim($parts[1] ?? '');

    if (empty($token)) {
        sendMessage($chatId, "❌ Укажите токен\nПример: /set_token 123456:ABC-токен");
        exit('OK');
    }

    $config              = loadConfig();
    $config['bot_token'] = $token;
    saveConfig($config);

    $panelUrl = getPanelUrl();
    if ($panelUrl) {
        $ok = registerWebhook($token, $panelUrl);
        sendReplyKeyboard($chatId, $ok
            ? "✅ Токен сохранён и webhook обновлён!"
            : "✅ Токен сохранён, но webhook не удалось обновить"
        );
    } else {
        sendMessage($chatId, "✅ Токен сохранён!\nТеперь задайте URL: /edit_url https://your_domain");
    }
    exit('OK');
}

// ── /edit_url ─────────────────────────────────────────────────────────────────

if (str_starts_with($text, '/edit_url')) {
    $parts    = explode(' ', $text, 2);
    $panelUrl = trim($parts[1] ?? '');

    if (empty($panelUrl) || !filter_var($panelUrl, FILTER_VALIDATE_URL)) {
        sendMessage($chatId, "❌ Укажите корректный URL\nПример: /edit_url https://linkstat.xyz");
        exit('OK');
    }

    $panelUrl            = rtrim($panelUrl, '/');
    $config              = loadConfig();
    $config['panel_url'] = $panelUrl;
    saveConfig($config);

    $ok = registerWebhook(getToken(), $panelUrl);
    sendReplyKeyboard($chatId, $ok
        ? "✅ URL сохранён и webhook обновлён: <code>{$panelUrl}</code>"
        : "✅ URL сохранён: <code>{$panelUrl}</code>\n⚠️ Webhook не удалось обновить"
    );
    exit('OK');
}

// ── Обработка кнопок Reply Keyboard ──────────────────────────────────────────

switch ($text) {

    case '📊 Статистика кампаний':
        $stats = apiRequest('stats');
        if (!$stats) {
            sendMessage($chatId, "❌ Не удалось получить статистику.\nПроверьте URL и API ключ.", statsMenu());
            exit('OK');
        }
        sendMessage($chatId, buildStatsMessage($stats), statsMenu());
        exit('OK');

    case '📋 Список кампаний':
        $result = apiRequest('campaigns');
        if (!$result) {
            sendMessage($chatId, "❌ Не удалось получить список кампаний.");
            exit('OK');
        }
        if ($result['count'] === 0) {
            sendMessage($chatId, "📋 Кампаний пока нет.");
            exit('OK');
        }
        [$msg, $keyboard] = buildCampaignsList($result['campaigns']);
        sendMessage($chatId, $msg, $keyboard);
        exit('OK');

    case '🔗 Текущий URL TDS':
        $panelUrl = getPanelUrl();
        $msg = $panelUrl
            ? "🔗 <b>Текущий URL TDS:</b>\n<a href=\"{$panelUrl}/login.php\">{$panelUrl}</a>"
            : "⚠️ URL не задан";
        
        $keyboard = [[
            ['text' => '✏️ Изменить URL TDS', 'callback_data' => 'change_url_tds'],
        ]];
        
        sendMessage($chatId, $msg, $keyboard);
        exit('OK');
}

// ── /start и всё остальное ────────────────────────────────────────────────────

$panelUrl  = getPanelUrl();
$urlStatus = $panelUrl ? "🔗 <code>{$panelUrl}</code>" : "⚠️ не задан";

sendReplyKeyboard($chatId,
    "👋 <b>Easy TDS Stats Bot</b>\n\n" .
    "URL панели: {$urlStatus}"
);
exit('OK');
