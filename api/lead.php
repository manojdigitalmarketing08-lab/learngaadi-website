<?php
/**
 * Lead capture endpoint.
 * 1. Saves the lead to MySQL.
 * 2. Answers the browser straight away (so the form never waits on WhatsApp).
 * 3. Then sends the lead to the owner's WhatsApp through the Meta WhatsApp Cloud API.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    error_log('lead.php: config.php missing');
    respond(500, ['ok' => false, 'error' => 'Server is not configured.']);
}
$config = require $configFile;

// Fall back to plain string functions if the mbstring extension is off.
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $len = null): string { return substr($s, $start, $len); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s): int { return strlen($s); }
}

/** Trim, strip control characters and cap length. */
function clean($value, int $max): string {
    $value = is_string($value) ? trim($value) : '';
    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
    return mb_substr($value, 0, $max);
}

// Honeypot: bots fill this, humans never see it. Pretend success so bots learn nothing.
if (!empty($_POST['website'])) {
    respond(200, ['ok' => true]);
}

$name  = clean($_POST['name'] ?? '', 80);
$phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
$phone = preg_replace('/^(91|0)(?=\d{10}$)/', '', $phone);

if (mb_strlen($name) < 2) {
    respond(422, ['ok' => false, 'error' => 'Please enter your name.']);
}
if (!preg_match('/^[6-9]\d{9}$/', $phone)) {
    respond(422, ['ok' => false, 'error' => 'Please enter a valid 10-digit mobile number.']);
}
// Obvious placeholder numbers (9999999999, 9876543210, ...) are never real leads.
if (preg_match('/^(\d)\1{9}$/', $phone) || in_array($phone, ['9876543210', '1234567890'], true)) {
    respond(422, ['ok' => false, 'error' => 'Please enter your real mobile number.']);
}

$lead = [
    'name'         => $name,
    'phone'        => $phone,
    'service'      => clean($_POST['service'] ?? '', 80),
    'page_url'     => clean($_POST['page_url'] ?? '', 500),
    'page_path'    => clean($_POST['page_path'] ?? '', 255),
    'page_title'   => clean($_POST['page_title'] ?? '', 255),
    'source'       => strtolower(clean($_POST['source'] ?? '', 100)) ?: 'direct',
    'medium'       => strtolower(clean($_POST['medium'] ?? '', 100)),
    'campaign'     => clean($_POST['campaign'] ?? '', 150),
    'referrer'     => clean($_POST['referrer'] ?? '', 500),
    'landing_page' => clean($_POST['landing_page'] ?? '', 500),
    'click_id'     => clean($_POST['click_id'] ?? '', 255),
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent'   => clean($_SERVER['HTTP_USER_AGENT'] ?? '', 500),
];

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']),
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    // Basic abuse control: max 5 submissions per IP per 10 minutes.
    $rate = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)');
    $rate->execute([$lead['ip_address']]);
    if ((int) $rate->fetchColumn() >= 5) {
        respond(429, ['ok' => false, 'error' => 'Too many requests. Please call us on +91 8296161555.']);
    }

    $cols = implode(', ', array_keys($lead));
    $marks = implode(', ', array_fill(0, count($lead), '?'));
    $pdo->prepare("INSERT INTO leads ($cols) VALUES ($marks)")->execute(array_values($lead));
    $leadId = (int) $pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('lead.php: ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'Could not save your details. Please call +91 8296161555.']);
}

// Lead is safely stored. Answer the visitor now; the WhatsApp alert runs after the response is sent.
$payload = json_encode(['ok' => true, 'id' => $leadId]);
http_response_code(200);
header('Content-Length: ' . strlen($payload));
header('Connection: close');
echo $payload;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
} else {
    while (ob_get_level() > 0) { ob_end_flush(); }
    flush();
}
ignore_user_abort(true);

/**
 * Send one WhatsApp message through the Meta Cloud API.
 * Returns [true, ''] on success or [false, 'reason'] on failure.
 */
function whatsapp_send(array $config, string $to, array $lead, int $leadId): array {
    $version = $config['wa_api_version'] ?? 'v21.0';
    $base = rtrim($config['wa_api_base'] ?? 'https://graph.facebook.com', '/');
    $url = "{$base}/{$version}/{$config['wa_phone_number_id']}/messages";

    $source  = $lead['source'] . ($lead['medium'] !== '' ? ' / ' . $lead['medium'] : '');
    $service = $lead['service'] !== '' ? $lead['service'] : 'Not selected';
    $page    = $lead['page_path'] !== '' ? $lead['page_path'] : '/';

    if (!empty($config['wa_template'])) {
        // Approved template: works at any time (required for business-initiated messages).
        // Body variables in order: {{1}} name, {{2}} phone, {{3}} service, {{4}} page, {{5}} source, {{6}} lead id
        $vars = [$lead['name'], $lead['phone'], $service, $page, $source, '#' . $leadId];
        $body = [
            'messaging_product' => 'whatsapp',
            'to'       => $to,
            'type'     => 'template',
            'template' => [
                'name'       => $config['wa_template'],
                'language'   => ['code' => $config['wa_template_lang'] ?? 'en'],
                'components' => [[
                    'type'       => 'body',
                    'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => (string) $v], $vars),
                ]],
            ],
        ];
    } else {
        // Plain text: only delivered if the owner messaged the business number in the last 24 hours.
        $text = "New website lead #{$leadId}\n"
              . "Name: {$lead['name']}\n"
              . "Phone: {$lead['phone']}\n"
              . "Service: {$service}\n"
              . "Page: {$page}\n"
              . "Source: {$source}";
        $body = [
            'messaging_product' => 'whatsapp',
            'to'   => $to,
            'type' => 'text',
            'text' => ['body' => $text],
        ];
    }

    $json = json_encode($body);
    $headers = [
        'Authorization: Bearer ' . $config['wa_access_token'],
        'Content-Type: application/json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $json,
            'timeout' => 10, 'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        $err = $resp === false ? 'request failed' : '';
    }

    if ($code >= 200 && $code < 300) {
        return [true, ''];
    }
    $decoded = is_string($resp) ? json_decode($resp, true) : null;
    $detail  = $decoded['error']['message'] ?? '';
    return [false, trim("HTTP {$code} {$err} {$detail}")];
}

$waReady    = !empty($config['wa_phone_number_id']) && !empty($config['wa_access_token']);
$recipients = array_filter((array) ($config['wa_notify_numbers'] ?? []));

if ($waReady && $recipients) {
    $failures = [];
    foreach ($recipients as $to) {
        [$sent, $reason] = whatsapp_send($config, preg_replace('/\D/', '', (string) $to), $lead, $leadId);
        if (!$sent) {
            $failures[] = "{$to}: {$reason}";
            error_log("lead.php: WhatsApp send failed for lead #{$leadId} -> {$to}: {$reason}");
        }
    }
    // Record the outcome so missed alerts can be found later (skipped quietly if the columns are not added yet).
    try {
        $pdo->prepare('UPDATE leads SET wa_status = ?, wa_error = ? WHERE id = ?')
            ->execute([$failures ? 'failed' : 'sent', mb_substr(implode(' | ', $failures), 0, 500), $leadId]);
    } catch (Throwable $e) {
        // wa_status / wa_error columns not created yet; the lead itself is already saved.
    }
} else {
    error_log("lead.php: WhatsApp alert skipped for lead #{$leadId} (wa_* settings missing in config.php)");
}

/**
 * Append the lead as a row in Google Sheets through the Apps Script web app (see google-sheet.gs).
 */
function sheet_append(array $config, array $lead, int $leadId): array {
    $url = $config['sheet_webhook_url'] ?? '';
    if ($url === '' || $url === 'CHANGE_ME') {
        return [false, 'sheet_webhook_url not set'];
    }
    $body = json_encode(['secret' => $config['sheet_secret'] ?? '', 'id' => $leadId, 'lead' => $lead]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,   // Apps Script answers with a redirect
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $ok = $code >= 200 && $code < 300 && is_string($resp) && (json_decode($resp, true)['ok'] ?? false);
    return $ok ? [true, ''] : [false, trim("HTTP {$code} {$err} " . substr((string) $resp, 0, 150))];
}

/**
 * Send the lead alert by SMTP (Hostinger: smtp.hostinger.com, port 465 SSL or 587 STARTTLS).
 */
function smtp_send(array $config, string $subject, string $text): array {
    $host = $config['smtp_host'] ?? '';
    $user = $config['smtp_user'] ?? '';
    $pass = $config['smtp_pass'] ?? '';
    $to   = array_filter((array) ($config['mail_to'] ?? []));
    if ($host === '' || $user === '' || $pass === '' || $pass === 'CHANGE_ME' || !$to) {
        return [false, 'smtp_* / mail_to not set'];
    }
    $port   = (int) ($config['smtp_port'] ?? 465);
    $secure = $port === 465;
    $from   = $config['mail_from'] ?? $user;

    $fp = @stream_socket_client(($secure ? 'ssl://' : 'tcp://') . "{$host}:{$port}", $en, $es, 15);
    if (!$fp) {
        return [false, "connect failed: {$es}"];
    }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') { break; }
        }
        return $out;
    };
    $cmd = function (string $c, string $expect) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        $r = $read();
        if (strpos($r, $expect) !== 0) { throw new RuntimeException(trim($r)); }
        return $r;
    };

    try {
        if (strpos($read(), '220') !== 0) { throw new RuntimeException('no SMTP greeting'); }
        $cmd('EHLO learngaadi.in', '250');
        if (!$secure) {
            $cmd('STARTTLS', '220');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS failed');
            }
            $cmd('EHLO learngaadi.in', '250');
        }
        $cmd('AUTH LOGIN', '334');
        $cmd(base64_encode($user), '334');
        $cmd(base64_encode($pass), '235');
        $cmd("MAIL FROM:<{$from}>", '250');
        foreach ($to as $addr) { $cmd("RCPT TO:<{$addr}>", '25'); }
        $cmd('DATA', '354');
        $headers = [
            'From: Learn Gaadi Leads <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($text));
        $cmd($data . "\r\n.", '250');
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return [true, ''];
    } catch (Throwable $e) {
        @fclose($fp);
        return [false, $e->getMessage()];
    }
}

// Google Sheet row
[$sheetOk, $sheetErr] = sheet_append($config, $lead, $leadId);
if (!$sheetOk) {
    error_log("lead.php: Google Sheet append failed for lead #{$leadId}: {$sheetErr}");
}

// Email alert
$mailText = "New website lead #{$leadId}\n\n"
    . "Name: {$lead['name']}\nPhone: {$lead['phone']}\nService: {$lead['service']}\n"
    . "Page: {$lead['page_url']}\nSource: {$lead['source']}"
    . ($lead['medium'] !== '' ? " / {$lead['medium']}" : '')
    . ($lead['campaign'] !== '' ? "\nCampaign: {$lead['campaign']}" : '')
    . "\nReferrer: {$lead['referrer']}\nTime: " . date('d M Y, h:i A') . "\n";
[$mailOk, $mailErr] = smtp_send($config, "New lead: {$lead['name']} ({$lead['service']})", $mailText);
if (!$mailOk) {
    error_log("lead.php: email alert failed for lead #{$leadId}: {$mailErr}");
}
