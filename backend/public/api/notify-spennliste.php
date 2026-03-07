<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/http.php';
require_once __DIR__ . '/../../lib/auth.php';

function sanitize_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

function sanitize_subject_piece(string $value): string
{
    $clean = sanitize_header_value($value);
    return $clean === '' ? 'Project' : $clean;
}

function default_mail_from_email(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host);
    $host = preg_replace('/[^a-z0-9.\-]/', '', (string) $host);

    if ($host === '' || strpos($host, '.') === false) {
        return 'no-reply@example.com';
    }

    return 'no-reply@' . $host;
}

apply_cors();
require_method('POST');

$auth = require_auth();
$modules = (array) ($auth['modules'] ?? []);
if (empty($modules['stressing'])) {
    json_error(403, 'Stressing access required');
}

$input = json_input();
if ($input === []) {
    $input = $_POST;
}

$projectId = trim((string) ($input['projectId'] ?? ''));
$projectName = trim((string) ($input['projectName'] ?? 'Project'));
$recipientEmail = strtolower(trim((string) ($input['recipientEmail'] ?? '')));
$shareUrl = trim((string) ($input['shareUrl'] ?? ''));

if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
    json_error(422, 'Valid recipientEmail is required');
}
if ($shareUrl === '' || !filter_var($shareUrl, FILTER_VALIDATE_URL)) {
    json_error(422, 'Valid shareUrl is required');
}
$scheme = strtolower((string) parse_url($shareUrl, PHP_URL_SCHEME));
if (!in_array($scheme, ['http', 'https'], true)) {
    json_error(422, 'shareUrl must use http or https');
}

$cfg = app_config();
$fromEmailCfg = strtolower(trim((string) ($cfg['mail_from_email'] ?? '')));
$fromEmail = filter_var($fromEmailCfg, FILTER_VALIDATE_EMAIL)
    ? $fromEmailCfg
    : default_mail_from_email();

$fromName = sanitize_header_value((string) ($cfg['mail_from_name'] ?? 'Etterspenning.no'));
if ($fromName === '') {
    $fromName = 'Etterspenning.no';
}

$senderEmail = strtolower(trim((string) ($auth['email'] ?? '')));
$replyToCfg = strtolower(trim((string) ($cfg['mail_reply_to'] ?? '')));
$replyTo = filter_var($replyToCfg, FILTER_VALIDATE_EMAIL)
    ? $replyToCfg
    : (filter_var($senderEmail, FILTER_VALIDATE_EMAIL) ? $senderEmail : $fromEmail);

$senderName = sanitize_header_value((string) ($auth['name'] ?? $senderEmail ?: 'Project manager'));
$subject = 'Spennliste - ' . sanitize_subject_piece($projectName);
$body = implode("\n", [
    'Hei,',
    '',
    "Du er satt som ansvarlig for stressing pa site for prosjekt '" . sanitize_subject_piece($projectName) . "'.",
    '',
    'Aapne spennlisten her:',
    $shareUrl,
    '',
    'Logg inn med firma-konto for tilgang.',
    '',
    'Hilsen',
    $senderName
]);

$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'From: ' . $fromName . ' <' . $fromEmail . '>',
    'Reply-To: ' . $replyTo,
];

$sent = @mail($recipientEmail, $subject, $body, implode("\r\n", $headers));
if (!$sent) {
    json_error(500, 'Could not send email notification. Check mail/SMTP configuration on server.');
}

audit_log(
    (int) ($auth['userId'] ?? 0),
    'stressing.spennliste.notify',
    'project',
    $projectId !== '' ? $projectId : null,
    [
        'projectName' => $projectName,
        'recipientEmail' => $recipientEmail,
        'shareUrl' => $shareUrl,
        'senderEmail' => $senderEmail,
    ]
);

json_response(200, [
    'ok' => true,
    'sent' => true,
    'recipientEmail' => $recipientEmail,
    'sentAt' => gmdate('c'),
]);

