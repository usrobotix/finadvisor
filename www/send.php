<?php
/**
 * send.php — Form submission handler.
 *
 * Security layers (in order):
 *   1. Only POST requests are accepted.
 *   2. Honeypot field check (bots fill the hidden "website" field; humans don't).
 *   3. Per-IP rate limiting (stored in /tmp) — max RATE_LIMIT_MAX requests
 *      per RATE_LIMIT_WINDOW seconds.
 *   4. Yandex SmartCaptcha token validation via the server-side API.
 *   5. Mail is sent only when all checks pass.
 *
 * SmartCaptcha secret key resolution order:
 *   a) SMARTCAPTCHA_SECRET environment variable  (recommended for production)
 *   b) SMARTCAPTCHA_SECRET_KEY constant defined in config.php  (temporary fallback)
 *
 * To harden: set the env var, then delete config.php.
 */

/* =========================================================================
 * Configuration
 * ========================================================================= */

/**
 * Sanitize HTTP_HOST: strip port and allow only safe characters to prevent
 * header injection via a manipulated Host header.
 */
$rawHost = $_SERVER['HTTP_HOST'] ?? 'site';
$safeHost = preg_replace('/[^a-zA-Z0-9.\-]/', '', explode(':', $rawHost)[0]);
if ($safeHost === '') {
    $safeHost = 'site';
}

$tomail  = 'it@potencial-group.ru,ucentr.pro@gmail.com';
$Subject = 'Новое сообщение с сайта ' . $safeHost;
$from    = 'info@' . $safeHost;

/** Rate limit: maximum form submissions per IP within the time window. */
define('RATE_LIMIT_MAX',    5);
define('RATE_LIMIT_WINDOW', 60); // seconds

/** Yandex SmartCaptcha validation endpoint. */
define('SMARTCAPTCHA_VALIDATE_URL', 'https://smartcaptcha.yandexcloud.net/validate');

/* =========================================================================
 * Helper: emit JSON response and exit
 * ========================================================================= */
function jsonResponse(bool $ok, string $message): void
{
    header('Content-Type: application/json; charset=utf-8');
    // Tilda also checks for {"message":"OK"} — keep that key for compatibility
    echo json_encode([
        'ok'      => $ok,
        'message' => $ok ? 'OK' : $message,
        'text'    => $message,
        'results' => $ok ? ['236736:2605225'] : [],
    ]);
    exit;
}

/* =========================================================================
 * 1. Require POST
 * ========================================================================= */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    jsonResponse(false, 'Метод не поддерживается.');
}

/* =========================================================================
 * 2. Honeypot check
 *    The "website" field is hidden from real users (CSS + tabindex=-1).
 *    Any non-empty value means a bot filled it in.
 * ========================================================================= */
if (!empty($_POST['website'])) {
    // Silent success to not reveal the honeypot to bots
    jsonResponse(true, 'OK');
}

/* =========================================================================
 * 3. Per-IP rate limiting (file-based, /tmp)
 * ========================================================================= */
$clientIp   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateFile   = '/tmp/sc_rate_' . md5($clientIp);
$now        = time();
$timestamps = [];

if (file_exists($rateFile)) {
    $raw = @file_get_contents($rateFile);
    if ($raw !== false) {
        $timestamps = array_filter(
            array_map('intval', explode(',', $raw)),
            fn($t) => ($now - $t) < RATE_LIMIT_WINDOW
        );
    }
}

if (count($timestamps) >= RATE_LIMIT_MAX) {
    http_response_code(429);
    jsonResponse(false, 'Слишком много запросов. Пожалуйста, подождите немного и попробуйте снова.');
}

$timestamps[] = $now;
@file_put_contents($rateFile, implode(',', $timestamps), LOCK_EX);

/* =========================================================================
 * 4. Require captcha token
 * ========================================================================= */
$captchaToken = trim($_POST['captcha_token'] ?? '');
if ($captchaToken === '') {
    http_response_code(400);
    jsonResponse(false, 'Пожалуйста, пройдите проверку капчи.');
}

/* =========================================================================
 * 4a. Resolve SmartCaptcha secret key
 *
 *  --- TEMPORARY FALLBACK (remove once env var is set) ---
 *  If the environment variable is absent, try loading config.php.
 *  To remove: set SMARTCAPTCHA_SECRET env var, then delete config.php.
 * ========================================================================= */

// Check multiple env sources for compatibility with different PHP SAPIs
// (e.g. PHP-FPM may not expose getenv() but does populate $_SERVER/$_ENV)
$secretKey = getenv('SMARTCAPTCHA_SECRET')
    ?: ($_SERVER['SMARTCAPTCHA_SECRET'] ?? '')
    ?: ($_ENV['SMARTCAPTCHA_SECRET']    ?? '');

if ($secretKey === '') {
    // Temporary fallback: read from config.php if present
    $configPath = __DIR__ . '/config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
        $secretKey = defined('SMARTCAPTCHA_SECRET_KEY') ? SMARTCAPTCHA_SECRET_KEY : '';
    }
}

if ($secretKey === '' || $secretKey === 'REPLACE_WITH_YOUR_SMARTCAPTCHA_SECRET_KEY') {
    // Secret not configured — refuse to process to avoid bypassing captcha
    http_response_code(500);
    jsonResponse(false, 'Сервис временно недоступен. Пожалуйста, попробуйте позже.');
}

/* =========================================================================
 * 4b. Validate captcha token with Yandex SmartCaptcha API
 * ========================================================================= */
$validateUrl = SMARTCAPTCHA_VALIDATE_URL
    . '?secret='  . urlencode($secretKey)
    . '&token='   . urlencode($captchaToken)
    . '&ip='      . urlencode($clientIp);

$ctx = stream_context_create([
    'http' => [
        'timeout'        => 5,
        'ignore_errors'  => true,
    ],
]);
$apiResponse = @file_get_contents($validateUrl, false, $ctx);

if ($apiResponse === false) {
    http_response_code(502);
    jsonResponse(false, 'Не удалось проверить капчу. Пожалуйста, попробуйте ещё раз.');
}

$apiData = json_decode($apiResponse, true);
if (!is_array($apiData) || ($apiData['status'] ?? '') !== 'ok') {
    http_response_code(403);
    jsonResponse(false, 'Проверка капчи не пройдена. Пожалуйста, попробуйте ещё раз.');
}

/* =========================================================================
 * 5. Build and send the email
 * ========================================================================= */
$msg = '';

if (!empty($_POST['CourseName']))             $msg .= 'Форма: '    . htmlspecialchars($_POST['CourseName'])            . "\r\n<BR>";
if (!empty(trim($_POST['Name']  ?? '')))      $msg .= 'Имя: '      . htmlspecialchars(trim($_POST['Name']))            . "\r\n<BR>";
if (!empty(trim($_POST['Email'] ?? '')))      $msg .= 'Почта: '    . htmlspecialchars(trim($_POST['Email']))           . "\r\n<BR>";
if (!empty(trim($_POST['tildaspec-referer'] ?? ''))) $msg .= 'С сайта: ' . htmlspecialchars(trim($_POST['tildaspec-referer'])) . "\r\n<BR>";
if (!empty(trim($_POST['Phone'] ?? '')))      $msg .= 'Телефон: '  . htmlspecialchars(trim($_POST['Phone']))           . "\r\n<BR>";

$msg .= '==================' . "\r\n<BR>";

$header  = "Content-type: text/html; charset=utf-8\r\n";
$header .= "From: {$from}\r\n";
$header .= 'X-Mailer: PHP v' . phpversion() . "\r\n";

$SubjectEncoded = '=?UTF-8?B?' . base64_encode($Subject) . '?=';
$msg = wordwrap($msg, 70, "\r\n");

if (strlen($msg) > 9) {
    if (!mail($tomail, $SubjectEncoded, $msg, $header, '-f' . $from)) {
        http_response_code(500);
        jsonResponse(false, 'Не удалось отправить сообщение. Пожалуйста, попробуйте позже.');
    }
}

jsonResponse(true, 'Ваша заявка принята! Мы свяжемся с вами в ближайшее время.');
