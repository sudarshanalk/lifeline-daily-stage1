<?php
/* ============================================================================
 * render.php  —  pure template rendering (no I/O, no sending)
 * render(array $payload): array  → ['subject'=>..., 'body'=>...]
 * Chooses the template key from the result state, builds derived blocks, and
 * substitutes {{tokens}}. Mirrors the tool's on-screen priority order.
 * ==========================================================================*/

function render_key_for(array $p): string {
  if (!empty($p['emergency']))   return 'emergency';
  if (!empty($p['symptomatic'])) return 'symptomatic';
  $band = strtolower(trim((string)($p['riskBand'] ?? '')));
  $map = ['low'=>'low','moderate'=>'moderate','high'=>'high','very high'=>'veryhigh'];
  return $map[$band] ?? 'moderate';
}

function render_crosschecks(array $p): string {
  // Only present when the user entered a waist measurement.
  if (!isset($p['whtr']) || $p['whtr'] === null) return '';
  $whtr = htmlspecialchars((string)$p['whtr'], ENT_QUOTES);
  $rows = '<li>Waist-to-height ratio: <strong>'.$whtr.'</strong></li>';
  if (isset($p['idrsScore']) && $p['idrsScore'] !== null) {
    $score = htmlspecialchars((string)$p['idrsScore'], ENT_QUOTES);
    $tier  = htmlspecialchars(ucfirst((string)($p['idrsTier'] ?? '')), ENT_QUOTES);
    $rows .= '<li>IDRS cross-check: <strong>'.$score.'</strong>'.($tier ? ' ('.$tier.')' : '').'</li>';
  }
  return '<p style="margin:16px 0 6px"><strong>Cross-checks</strong></p><ul>'.$rows.'</ul>';
}

function render_fill(string $text, array $vars): string {
  return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($vars) {
    return array_key_exists($m[1], $vars) ? $vars[$m[1]] : '';
  }, $text);
}

function render(array $payload): array {
  $tpl    = require __DIR__ . '/templates.php';
  $key    = render_key_for($payload);
  $block  = $tpl[$key] ?? $tpl['moderate'];
  $shared = $tpl['_shared'] ?? ['disclaimer' => ''];

  $first = trim((string)($payload['firstName'] ?? ''));
  $vars = [
    'firstName'   => $first !== '' ? htmlspecialchars($first, ENT_QUOTES) : 'there',
    'riskPercent' => isset($payload['riskPercent']) ? htmlspecialchars((string)$payload['riskPercent'], ENT_QUOTES) : '',
    'riskBand'    => htmlspecialchars((string)($payload['riskBand'] ?? ''), ENT_QUOTES),
    'bmi'         => isset($payload['bmi']) ? htmlspecialchars((string)$payload['bmi'], ENT_QUOTES) : '',
    'crossChecks' => render_crosschecks($payload),
    'disclaimer'  => htmlspecialchars($shared['disclaimer'] ?? '', ENT_QUOTES),
    'year'        => date('Y'),
  ];

  $subject = render_fill($block['subject'], $vars);
  $inner   = render_fill($block['body'], $vars);

  // Branded HTML shell (charcoal header, green accent) — matches the tool.
  $body =
    '<div style="font-family:Arial,Helvetica,sans-serif;color:#1c1c1c;max-width:560px;margin:0 auto">'.
      '<div style="background:#1c1c1c;padding:20px;text-align:center;border-radius:12px 12px 0 0">'.
        '<div style="color:#fff;font-size:20px;font-weight:700">Lifeline Daily</div>'.
        '<div style="color:#3fd07f;font-size:13px;margin-top:4px">Start Small, Change Big, Live Healthier</div>'.
      '</div>'.
      '<div style="border:1px solid #e4eae6;border-top:0;border-radius:0 0 12px 12px;padding:22px;font-size:15px;line-height:1.55">'.
        $inner .
        '<hr style="border:0;border-top:1px solid #e4eae6;margin:20px 0">'.
        '<p style="font-size:12px;color:#63706a;margin:0">'.$vars['disclaimer'].'</p>'.
        '<p style="font-size:12px;color:#9aa39d;margin:10px 0 0">© '.$vars['year'].' Lifeline Daily</p>'.
      '</div>'.
    '</div>';

  return ['subject' => $subject, 'body' => $body];
}
