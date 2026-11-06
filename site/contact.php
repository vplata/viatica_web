<?php
// Demo request form handler for viatica.pro.
// Sends through the server's local mail, so no password is stored here.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const SENDER    = 'info@viatica.pro';
const RECIPIENT = 'info@viatica.pro';
const RATE_MAX  = 5;      // requests per IP
const RATE_WIN  = 3600;   // per hour

function reply(int $code, string $status): void {
    http_response_code($code);
    echo json_encode(['status' => $status]);
    exit;
}
function field(string $name, int $max): string {
    $v = trim((string)($_POST[$name] ?? ''));
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    return mb_substr($v, 0, $max);
}
function oneLine(string $v): string {
    return trim(preg_replace('/[\r\n\t]+/', ' ', $v));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, 'method');

// Bots fill the hidden field; tell them it worked and drop the message.
if (field('website', 200) !== '') reply(200, 'sent');

// Simple per-IP rate limit
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = sys_get_temp_dir() . '/viatica_rate_' . hash('sha256', $ip);
$now = time();
$hits = [];
if (is_file($rateFile)) {
    $hits = array_filter(
        array_map('intval', explode(',', (string)file_get_contents($rateFile))),
        fn($t) => $t > $now - RATE_WIN
    );
}
if (count($hits) >= RATE_MAX) reply(429, 'rate');
$hits[] = $now;
@file_put_contents($rateFile, implode(',', $hits), LOCK_EX);

$name       = oneLine(field('name', 120));
$company    = oneLine(field('company', 160));
$email      = oneLine(field('email', 200));
$travellers = oneLine(field('travellers', 20));
$message    = field('message', 4000);
$lang       = field('lang', 2) === 'en' ? 'en' : 'es';

if ($name === '' || $company === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    reply(422, 'invalid');
}

$subject = 'Solicitud de demo: ' . $company;
$body = "Nueva solicitud de demo desde viatica.pro\n\n"
      . "Nombre: $name\n"
      . "Empresa: $company\n"
      . "Correo: $email\n"
      . "Personas que viajan: " . ($travellers !== '' ? $travellers : '-') . "\n"
      . "Idioma del sitio: $lang\n\n"
      . "¿Cómo lo manejan hoy?\n" . ($message !== '' ? $message : '-') . "\n";

$headers = [
    'From' => 'Viatica <' . SENDER . '>',
    'Reply-To' => $email,
    'MIME-Version' => '1.0',
    'Content-Type' => 'text/plain; charset=UTF-8',
    'Content-Transfer-Encoding' => '8bit',
];
$encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$ok = @mail(RECIPIENT, $encSubject, $body, $headers, '-f' . SENDER);
if (!$ok) {
    // Some hosts reject the envelope-sender parameter; retry without it.
    $ok = @mail(RECIPIENT, $encSubject, $body, $headers);
}
if ($ok) reply(200, 'sent');
$err = error_get_last();
http_response_code(500);
echo json_encode(['status' => 'mail', 'detail' => $err ? mb_substr($err['message'], 0, 200) : 'mail() returned false']);
exit;
