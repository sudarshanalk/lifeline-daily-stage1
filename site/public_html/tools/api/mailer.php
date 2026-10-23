<?php
/* ============================================================================
 * mailer.php  —  the ONLY file that touches PHPMailer
 * sendMail($to,$subject,$body,$cfg): array  → ['ok'=>bool,'info'=>string]
 * With transport 'log' it writes the message to /api/outbox instead of sending,
 * so you can verify the whole pipeline before SMTP credentials are in place.
 * ==========================================================================*/

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function sendMail(string $to, string $subject, string $body, ?array $cfg = null): array {
  if ($cfg === null) $cfg = require __DIR__ . '/config.php';

  // ---- LOG transport: write to outbox, don't send (safe testing) ----------
  if (($cfg['transport'] ?? 'log') === 'log') {
    $dir = $cfg['outbox_dir'] ?? (__DIR__ . '/outbox');
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . date('Ymd-His') . '-' . substr(md5($to . microtime()), 0, 6) . '.html';
    $head = "<!-- TO: {$to}\n     SUBJECT: {$subject}\n     TIME: " . date('c') . " -->\n";
    $ok = @file_put_contents($file, $head . $body) !== false;
    return ['ok' => $ok, 'info' => $ok ? ('logged:' . basename($file)) : 'log-write-failed'];
  }

  // ---- SMTP transport: send for real --------------------------------------
  require_once __DIR__ . '/PHPMailer/Exception.php';
  require_once __DIR__ . '/PHPMailer/PHPMailer.php';
  require_once __DIR__ . '/PHPMailer/SMTP.php';

  $mail = new PHPMailer(true);
  try {
    $s = $cfg['smtp'];
    $mail->isSMTP();
    $mail->Host       = $s['host'];
    $mail->Port       = (int)$s['port'];
    $mail->SMTPAuth   = !empty($s['auth']);
    $mail->Username   = $s['username'];
    $mail->Password   = $s['password'];
    $mail->SMTPSecure = $s['secure'] === 'tls'
                        ? PHPMailer::ENCRYPTION_STARTTLS
                        : PHPMailer::ENCRYPTION_SMTPS;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = (int)($s['timeout'] ?? 10);   // FR1-14: a dead SMTP host fails fast → 502, never a hung request

    $mail->setFrom($cfg['from_email'], $cfg['from_name'] ?? 'Lifeline Daily');
    $mail->addAddress($to);
    if (!empty($cfg['reply_to'])) $mail->addReplyTo($cfg['reply_to']);
    if (!empty($cfg['bcc_owner'])) $mail->addBCC($cfg['bcc_owner']);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $body;
    $mail->AltBody = trim(preg_replace('/\s+/', ' ', strip_tags($body)));

    $mail->send();
    return ['ok' => true, 'info' => 'sent'];
  } catch (Exception $e) {
    // DEC-20: the transport error is logged without any address or payload field
    error_log('LLD mailer error: ' . preg_replace('/\S+@\S+/', '[address]', (string)$mail->ErrorInfo));
    return ['ok' => false, 'info' => 'smtp-error'];
  }
}
