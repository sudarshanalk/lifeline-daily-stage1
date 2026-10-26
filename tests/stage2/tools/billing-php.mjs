// Runs one LLD_Members_Billing method in PHP CLI with no WordPress, for checks that need no database:
// webhook secrets, Stripe key refusal and the notice signature. constants: { LLD_ENV: 'staging', ... }.
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const includes = resolve(dirname(fileURLToPath(import.meta.url)), '../../../site/public_html/wp-content/plugins/lld-members/includes');
const path = (name) => JSON.stringify(resolve(includes, name).split(String.fromCharCode(92)).join('/'));

export function billing(method, args = [], constants = {}) {
  const defines = Object.entries(constants).map(([name, value]) => `define(${JSON.stringify(name)}, ${JSON.stringify(value)});`).join(' ');
  const code = [
    "define('ABSPATH', '/');",
    defines,
    'class WP_Error { public $code; public $message; public function __construct( $code = "", $message = "" ) { $this->code = $code; $this->message = $message; } }',
    'function is_wp_error( $thing ) { return $thing instanceof WP_Error; }',
    `require ${path('helpers.php')};`,
    `require ${path('class-billing.php')};`,
    '$args = json_decode( stream_get_contents( STDIN ), true );',
    `$method = new ReflectionMethod( 'LLD_Members_Billing', ${JSON.stringify(method)} );`,
    '$method->setAccessible( true );',
    '$out = $method->invokeArgs( null, $args );',
    'echo json_encode( $out instanceof WP_Error ? array( "error" => $out->code ) : $out );',
  ].join(' ');
  return JSON.parse(execFileSync('php', ['-r', code], { input: JSON.stringify(args), encoding: 'utf8' }));
}
