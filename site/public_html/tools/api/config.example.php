<?php
// Example only. Real values come from environment variables; never commit a filled config.php.
return [
  'transport'       => getenv('LLD_MAIL_TRANSPORT') ?: 'smtp',          // 'smtp' | 'log' (log = dev only, persists mail to outbox/)
  'smtp'            => ['host'=>getenv('LLD_SMTP_HOST') ?: '', 'port'=>(int)(getenv('LLD_SMTP_PORT') ?: 587), 'secure'=>'tls',
                        'username'=>getenv('LLD_SMTP_USER') ?: '', 'password'=>getenv('LLD_SMTP_PASS') ?: '', 'auth'=>true],
  'from_email'      => getenv('LLD_FROM_EMAIL') ?: 'noreply@lifelinedaily.com',
  'from_name'       => 'Lifeline Daily',
  'reply_to'        => '',
  'bcc_owner'       => '',                                               // must stay empty: health results go to the visitor only (DEC-21)
  'allowed_origins' => ['https://lifelinedaily.com', 'https://www.lifelinedaily.com'],
  'ratelimit'       => ['enabled'=>true, 'max'=>5, 'window_sec'=>600], // DEC-16, per IP per endpoint
  'max_body_bytes'  => 8192,                                            // FR1-14: JSON bodies over 8 KB are refused
  // DEC-35: the hosting CDN edge is a trusted proxy; the real client IP is read from its forwarded header only when the
  // request arrives from one of the listed proxy addresses (comma-separated env). Empty list = REMOTE_ADDR is the client.
  'client_ip'       => ['header' => getenv('LLD_CLIENT_IP_HEADER') ?: 'X-Forwarded-For',
                        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', getenv('LLD_TRUSTED_PROXIES') ?: ''))))],
  'log_salt'        => getenv('LLD_LOG_SALT') ?: '',                    // DEC-20: secret behind the daily ip_hash salt (random per install when empty)
  'status_path'     => __DIR__.'/../status.json',                      // FR1-15: the server's tools/status.json switch (DEC-10)
  'outbox_dir'      => __DIR__.'/outbox',
  'state_dir'       => __DIR__.'/state',
];
