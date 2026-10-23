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
  'ratelimit'       => ['enabled'=>true, 'max'=>5, 'window_sec'=>600], // DEC-16
  'outbox_dir'      => __DIR__.'/outbox',
  'state_dir'       => __DIR__.'/state',
];
