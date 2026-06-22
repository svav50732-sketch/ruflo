<?php
/**
 * PinMedia — обработчик формы заявок.
 * Принимает JSON от сайта, проверяет, шлёт в Telegram и на email.
 * Ничего не сохраняет на сервере (минимизация обработки персональных данных).
 *
 * Перед запуском: скопируйте config.example.php в config.php и заполните.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']); exit;
}

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'no config']); exit;
}
require __DIR__ . '/config.php';

/* ---------- читаем данные ---------- */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { echo json_encode(['ok' => false, 'error' => 'bad data']); exit; }

$clean = function ($v, $max = 300) {
    $v = trim((string)$v);
    $v = strip_tags($v);
    return mb_substr($v, 0, $max);
};

$name     = $clean($data['name'] ?? '', 100);
$contact  = $clean($data['contact'] ?? '', 150);
$role     = $clean($data['role'] ?? '', 50);
$brand    = $clean($data['brand'] ?? '', 150);
$platform = $clean($data['platform'] ?? '', 50);
$comment  = $clean($data['comment'] ?? '', 1500);
$honeypot = trim((string)($data['website'] ?? ''));
$page     = $clean($data['page'] ?? '', 300);
$utm      = is_array($data['utm'] ?? null) ? $data['utm'] : [];

/* ---------- защита ---------- */
// ловушка для ботов: поле скрыто, человек его не заполняет
if ($honeypot !== '') { echo json_encode(['ok' => true]); exit; } // боту отвечаем «ок», но ничего не шлём

// обязательные поля
if ($name === '' || $contact === '') {
    echo json_encode(['ok' => false, 'error' => 'required']); exit;
}

// простое ограничение частоты: не чаще 1 заявки в 30 секунд с одного IP
$ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$lock = sys_get_temp_dir() . '/pm_rl_' . md5($ip);
if (file_exists($lock) && (time() - filemtime($lock)) < 30) {
    echo json_encode(['ok' => false, 'error' => 'too fast']); exit;
}
@touch($lock);

/* ---------- собираем сообщение ---------- */
$utmLine = '';
foreach (['utm_source','utm_medium','utm_campaign','utm_content','utm_term'] as $k) {
    if (!empty($utm[$k])) $utmLine .= $k . '=' . $clean($utm[$k], 100) . ' ';
}

$lines = [
    "🔔 Новая заявка с сайта PinMedia",
    "",
    "👤 Имя: {$name}",
    "📲 Контакт: {$contact}",
    "🎭 Роль: {$role}",
];
if ($brand    !== '')             $lines[] = "🏷 Бренд/блог: {$brand}";
if ($platform !== '' && $platform !== 'Не выбрано') $lines[] = "📺 Платформа: {$platform}";
if ($comment  !== '')             $lines[] = "💬 Комментарий: {$comment}";
$lines[] = "";
$lines[] = "📊 Источник: " . ($utmLine !== '' ? trim($utmLine) : 'прямой заход / без меток');
if ($page !== '') $lines[] = "🔗 Страница: {$page}";
$lines[] = "🕐 " . date('d.m.Y H:i');

$text = implode("\n", $lines);

/* ---------- отправка в Telegram ---------- */
$tgOk = false;
if (defined('TG_BOT_TOKEN') && TG_BOT_TOKEN !== '' && defined('TG_CHAT_ID') && TG_CHAT_ID !== '') {
    $url = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage';
    $payload = http_build_query(['chat_id' => TG_CHAT_ID, 'text' => $text]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $tgOk = $resp !== false && strpos((string)$resp, '"ok":true') !== false;
}

/* ---------- отправка на email ---------- */
$mailOk = false;
if (defined('MAIL_TO') && MAIL_TO !== '') {
    $subject = '=?UTF-8?B?' . base64_encode('Заявка с сайта PinMedia — ' . $name) . '?=';
    $headers = "MIME-Version: 1.0\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n" .
               "From: " . (defined('MAIL_FROM') && MAIL_FROM !== '' ? MAIL_FROM : 'site@' . ($_SERVER['SERVER_NAME'] ?? 'pinmedia.local')) . "\r\n";
    $mailOk = @mail(MAIL_TO, $subject, $text, $headers);
}

/* ---------- ответ сайту ---------- */
if ($tgOk || $mailOk) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'delivery']);
}
