php
<?php
/**
 * Общая конфигурация приложения.
 * ВАЖНО: этот файл должен подключаться до любого HTML-вывода.
 *
 * Это пример конфигурации.
 * Реальные ключи, пароли и адреса должны храниться только в config.php.
 */

/**
 * Пример настроек Bitrix24-приложения.
 * Реальные CLIENT_ID и CLIENT_SECRET указываются только в config.php.
 */
define('CLIENT_ID', 'CHANGE_ME_CLIENT_ID');
define('CLIENT_SECRET', 'CHANGE_ME_CLIENT_SECRET');

/**
 * Пример адреса приложения.
 *
 * Для реальной среды в config.php указывается фактический APP_URL:
 * DEV   - адрес DEV-приложения;
 * STAGE - адрес STAGE-приложения;
 * PROD  - адрес production-сервера компании.
 */
define('APP_URL', 'http://localhost:8091');

/**
 * Пример настроек базы данных.
 * Реальный пароль указывается только в config.php.
 */
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'finance_b24_dev');
define('DB_USER', 'finance_prod');
define('DB_PASS', 'CHANGE_ME_DB_PASSWORD');

define('CURRENCY', 'сом');

// Рабочий часовой пояс компании: Душанбе (UTC+5).
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Dushanbe');
date_default_timezone_set(APP_TIMEZONE);

// Включать true только на время диагностики. На рабочем сайте оставлять false.
defined('APP_DEBUG') || define('APP_DEBUG', false);

// Пользователи, которые всегда считаются руководителями приложения.
// Для BIMA добавлен User ID 13, чтобы настройки открывались даже при сбое user.current в iframe.
defined('APP_MANAGER_USER_IDS') || define('APP_MANAGER_USER_IDS', '13');

// Если Bitrix24/браузер не передал user_id внутри iframe, для страницы настроек
// используем резервный ID руководителя. Укажите 0, чтобы отключить этот режим.
defined('APP_FALLBACK_MANAGER_USER_ID') || define('APP_FALLBACK_MANAGER_USER_ID', 0);

// Быстрый режим для iframe/мобильного Bitrix24.
// Не вызываем REST profile/user.admin на каждом открытии страницы.
defined('APP_STRICT_B24_VERIFY') || define('APP_STRICT_B24_VERIFY', false);
defined('APP_ASYNC_NAVIGATION') || define('APP_ASYNC_NAVIGATION', true);
defined('APP_DEFAULT_PORTAL') || define('APP_DEFAULT_PORTAL', 'bima.bitrix24.ru');

// Определяем, открыт ли проект по HTTPS.
// Через Cloudflare Tunnel браузер видит HTTPS, а локально PHP получает X-Forwarded-Proto=https.
$isHttps = false;

if (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
) {
    $isHttps = true;
}

// Логи приложения.
$__logDir = __DIR__ . '/logs';

if (!is_dir($__logDir)) {
    @mkdir($__logDir, 0755, true);
}

ini_set('log_errors', '1');
ini_set('error_log', $__logDir . '/app.log');
ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(APP_DEBUG ? E_ALL : (E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED));

// Разрешаем открытие внутри iframe Bitrix24.
if (!headers_sent()) {
    header_remove('X-Frame-Options');

    header(
        'Content-Security-Policy: frame-ancestors ' .
        'https://bima.bitrix24.ru ' .
        'https://*.bitrix24.ru ' .
        'https://*.bitrix24.com ' .
        'https://*.bitrix24.eu ' .
        'https://*.bitrix24.kz ' .
        'https://*.bitrix24.by ' .
        'https://*.bitrix24.de ' .
        'https://mobile.bitrix24.com;'
    );

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Chrome/Edge открывают приложение как сторонний iframe внутри Bitrix24.
// На HTTPS используем SameSite=None + Secure.
// На локальном HTTP используем SameSite=Lax без Secure.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('FINANCE_B24SESSID');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => $isHttps ? 'None' : 'Lax',
        ]);
    } else {
        ini_set('session.cookie_secure', $isHttps ? '1' : '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', $isHttps ? 'None' : 'Lax');
    }

    session_start();
}

function app_now_sql() {
    return date('Y-m-d H:i:s');
}

function app_today_sql() {
    return date('Y-m-d');
}

function app_log($message) {
    if (is_array($message) || is_object($message)) {
        $message = json_encode(
            $message,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    error_log('[finance-b24] ' . $message);
}

function app_is_ajax_request() {
    return isset($_SERVER['HTTP_ACCEPT'])
        && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
}

function app_error_page($title, $message, $details = '') {
    http_response_code(500);

    if (app_is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'ok' => false,
            'error' => $message,
            'details' => APP_DEBUG ? $details : null,
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    echo '<!DOCTYPE html>';
    echo '<html lang="ru">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Ошибка приложения</title>';
    echo '<style>
        body {
            font-family: Arial, sans-serif;
            background: #f6f8fb;
            margin: 0;
            padding: 30px;
            color: #1f2937;
        }
        .box {
            max-width: 760px;
            margin: 40px auto;
            background: #fff;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 8px 30px rgba(15,23,42,.08);
        }
        h1 {
            font-size: 22px;
            margin: 0 0 12px;
        }
        .msg {
            font-size: 15px;
            line-height: 1.55;
        }
        .details {
            white-space: pre-wrap;
            background: #111827;
            color: #d1d5db;
            border-radius: 10px;
            padding: 14px;
            margin-top: 14px;
            overflow: auto;
            font-size: 12px;
        }
    </style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="box">';
    echo '<h1>' . htmlspecialchars($title) . '</h1>';
    echo '<div class="msg">' . htmlspecialchars($message) . '</div>';

    if (APP_DEBUG && $details) {
        echo '<div class="details">' . htmlspecialchars($details) . '</div>';
    }

    echo '</div>';
    echo '</body>';
    echo '</html>';

    exit;
}

set_exception_handler(function($e) {
    app_log(
        'Unhandled exception: ' .
        $e->getMessage() .
        "\n" .
        $e->getTraceAsString()
    );

    app_error_page(
        'Ошибка приложения',
        'Произошла внутренняя ошибка. Детали записаны в logs/app.log.',
        $e->getMessage()
    );
});

register_shutdown_function(function() {
    $e = error_get_last();

    if ($e && in_array($e['type'], [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR
    ])) {
        app_log(
            'Fatal error: ' .
            $e['message'] .
            ' in ' .
            $e['file'] .
            ':' .
            $e['line']
        );
    }
});

