<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/geo/vendor/autoload.php';
use GeoIp2\Database\Reader;

$db = getDB();

$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$segments = explode('/', $path);

$slug = $segments[0] ?? '';
$keyword = $segments[1] ?? '';

$basename = basename($path);

if ($slug === '') {
    http_response_code(404);
    echo "<h1>404 Not Found</h1><p>Nothing here</p>";
    exit;
}

$excluded = [
    'dashboard.php',
    'new_campaign.php',
    'stats.php',
    'logout.php',
    'login.php',
    'style.css',
    'favicon.ico'
];

if (!in_array($basename, $excluded)) {

    $stmt = $db->prepare("SELECT url, id, geo_filter_type, geo_filter_list, geo_redirect_urls, bot_filter, bot_redirect_urls FROM streams WHERE slug=?");
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo "<h1>404 Not Found</h1><p>Campaign not found</p>";
        exit;
    }

    $device = preg_match('/mobile|android|iphone|ipad/i', $_SERVER['HTTP_USER_AGENT']) ? 'mobile' : 'desktop';

    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ipList[0]);
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    }

    $ptr = 'UNKNOWN';
    if ($ip !== 'UNKNOWN') {
        $ptrHost = gethostbyaddr($ip);
        if ($ptrHost !== false) $ptr = $ptrHost;
    }

    $provider = 'UNKNOWN';
    try {
        $readerASN = new Reader(__DIR__ . '/geo/GeoLite2-ASN.mmdb');
        $record = $readerASN->asn($ip);
        $provider = $record->autonomousSystemOrganization ?? 'UNKNOWN';
    } catch (\GeoIp2\Exception\AddressNotFoundException $e) {
        $provider = 'UNKNOWN';
    } catch (Exception $e) {
        $provider = 'UNKNOWN';
    }

    $geo = 'UNKNOWN';
    try {
        $reader = new Reader(__DIR__ . '/geo/GeoLite2-Country.mmdb');
        $record = $reader->country($ip);
        $geo = strtoupper($record->country->isoCode ?? 'UNKNOWN');
    } catch (\GeoIp2\Exception\AddressNotFoundException $e) {
        $geo = 'UNKNOWN';
    } catch (Exception $e) {
        $geo = 'UNKNOWN';
    }

    $geoPass = true;
    if ($row['geo_filter_type'] !== 'none' && !empty($row['geo_filter_list'])) {
        $geoList = array_map('trim', explode(',', strtoupper($row['geo_filter_list'])));
        $geoPass = $row['geo_filter_type'] === 'allow' ? in_array($geo, $geoList) : !in_array($geo, $geoList);
    }

    $isBot = false;

    if ($row['bot_filter'] === 'on') {
        $botFileIP = __DIR__ . '/bots/Bots_IP.dat';
        if (file_exists($botFileIP)) {
            $lines = file($botFileIP, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (strpos($line, '/') !== false) {
                    if (cidrMatch($ip, $line)) { $isBot = true; break; }
                } else {
                    if ($ip === $line) { $isBot = true; break; }
                }
            }
        }

        if (!$isBot) {
            $botFileUA = __DIR__ . '/bots/Bots_UA.dat';
            if (file_exists($botFileUA)) {
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $lines = file($botFileUA, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    if ($ua === $line || stripos($ua, $line) !== false) {
                        $isBot = true;
                        break;
                    }
                }
            }
        }

        if (!$isBot) {
            $botFileProvider = __DIR__ . '/bots/Bots_ISP.dat';
            if (file_exists($botFileProvider)) {
                $lines = file($botFileProvider, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = strtolower(trim($line));
                    if ($line === '') continue;
                    if (strpos(strtolower($provider), $line) !== false) {
                        $isBot = true;
                        break;
                    }
                }
            }
        }

        if (!$isBot) {
            $botFilePTR = __DIR__ . '/bots/Bots_PTR.dat';
            if (file_exists($botFilePTR) && $ptr !== 'UNKNOWN') {
                $lines = file($botFilePTR, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = strtolower(trim($line));
                    if ($line === '') continue;
                    if (strpos(strtolower($ptr), $line) !== false) {
                        $isBot = true;
                        break;
                    }
                }
            }
        }
    }

    if (!empty($keyword)) {
        $keywords = array_filter(array_map('trim', explode(',', $keyword)));
    } else {
        $keywords = [];
    }

    if ($isBot) {
        $keywords[] = 'bot';
    }

    $keyword = implode(',', $keywords);

    $mskTime = (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s');
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

    $stmt2 = $db->prepare("
        INSERT INTO logs (stream_id, device, ip, geo, keyword, provider, timestamp, useragent, ptr)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt2->execute([$row['id'], $device, $ip, $geo, $keyword, $provider, $mskTime, $userAgent, $ptr]);

    session_start();

    if ($isBot) {
        $botUrls = array_map('trim', explode(',', $row['bot_redirect_urls'] ?? ''));
        if (!empty($botUrls)) {
            if (!isset($_SESSION['bot_redirect_index'][$row['id']])) $_SESSION['bot_redirect_index'][$row['id']] = 0;
            $index = $_SESSION['bot_redirect_index'][$row['id']];
            $redirectUrl = $botUrls[$index];
            $_SESSION['bot_redirect_index'][$row['id']] = ($index + 1) % count($botUrls);
            header("Location: " . $redirectUrl);
            exit;
        }
    }

    if (!$geoPass) {
        $redirectUrls = array_map('trim', explode(',', $row['geo_redirect_urls'] ?? ''));
        if (!empty($redirectUrls)) {
            if (!isset($_SESSION['geo_redirect_index'][$row['id']])) $_SESSION['geo_redirect_index'][$row['id']] = 0;
            $index = $_SESSION['geo_redirect_index'][$row['id']];
            $redirectUrl = $redirectUrls[$index];
            $_SESSION['geo_redirect_index'][$row['id']] = ($index + 1) % count($redirectUrls);
            header("Location: " . $redirectUrl);
            exit;
        } else {
            http_response_code(403);
            echo "<h1>403 Forbidden</h1><p>Access restricted by GEO filter</p>";
            exit;
        }
    }

    $urls = array_map('trim', explode(',', $row['url']));
    if (!isset($_SESSION['redirect_index'][$row['id']])) $_SESSION['redirect_index'][$row['id']] = 0;
    $index = $_SESSION['redirect_index'][$row['id']];
    $redirectUrl = $urls[$index];
    $_SESSION['redirect_index'][$row['id']] = ($index + 1) % count($urls);

    header("Location: " . $redirectUrl);
    exit;
}

function cidrMatch($ip, $cidr) {
    [$subnet, $mask] = explode('/', $cidr);
    $ipBin = inet_pton($ip);
    $subnetBin = inet_pton($subnet);
    if (!$ipBin || !$subnetBin) return false;
    $mask = intval($mask);
    $length = strlen($ipBin)*8;
    if ($mask < 0 || $mask > $length) return false;
    $bytes = intdiv($mask,8);
    $bits = $mask%8;
    if (substr($ipBin,0,$bytes)!==substr($subnetBin,0,$bytes)) return false;
    if ($bits) {
        $ipByte=ord($ipBin[$bytes]);
        $subnetByte=ord($subnetBin[$bytes]);
        $maskByte = ~((1<<(8-$bits))-1)&0xFF;
        if (($ipByte & $maskByte)!==($subnetByte & $maskByte)) return false;
    }
    return true;
}
