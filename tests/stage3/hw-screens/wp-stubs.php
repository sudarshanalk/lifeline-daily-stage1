<?php
/**
 * The few WordPress functions the Healthy Weight screens call, for the source-level tests (P3-35, package P4). The
 * signed engine (P1) is loaded for real. With LLD_HW_STUBS set, stand-ins for the parallel packages P2
 * (LLD_Members_HW_Programme, LLD_Members_HW_Answers) and P3 (LLD_Members_HW_Content) answer from the fixture arrays in
 * $GLOBALS['fx'], and record every write in $GLOBALS['writes']. Not a test file itself.
 */

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'LLD_MEMBERS_FILE', '/plugin/lld-members.php' );
define( 'LLD_MEMBERS_VERSION', 'test' );

function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function home_url( $p = '' ) {
	return 'https://stg.example.test' . $p;
}
function plugins_url( $p = '' ) {
	return 'https://stg.example.test/plugin/' . $p;
}
function wp_nonce_field( $action, $name ) {
	return '<input type="hidden" name="' . $name . '" value="n-' . $action . '">';
}
function wp_verify_nonce() {
	return true;
}
function wp_kses( $html, $allowed ) {
	return strip_tags( (string) $html, '<a>' );
}
function sanitize_text_field( $s ) {
	return trim( (string) $s );
}
function sanitize_key( $s ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
function sanitize_title( $s ) {
	return preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $s ) );
}
function wp_unslash( $v ) {
	return $v;
}
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}
function lld_button( $a ) {
	return '<a class="lld-button" href="' . esc_url( isset( $a['href'] ) ? $a['href'] : '' ) . '">' . esc_html( $a['label'] ) . '</a>';
}
function lld_membership_lock( $line ) {
	return '<article class="lld-lock"><p>' . esc_html( $line ) . '</p></article>';
}
class WP_Error {
	private $c;
	private $m;
	private $d;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->c = (string) $code;
		$this->m = (string) $message;
		$this->d = $data;
	}
	public function get_error_code() {
		return $this->c;
	}
	public function get_error_message() {
		return $this->m;
	}
	public function get_error_data() {
		return $this->d;
	}
}

$stage3 = dirname( __DIR__, 3 ) . '/site/public_html/wp-content/plugins/lld-members/includes/stage3/';
foreach ( array( 'engine/class-rule-catalogue.php', 'engine/class-decision-engine.php', 'calculators/class-calorie-target.php', 'calculators/class-goal-timeline.php', 'healthy-weight/class-hw-where.php', 'healthy-weight/class-hw-festival.php', 'healthy-weight/class-hw-rules.php', 'class-goal-rules.php' ) as $f ) {
	require_once $stage3 . $f;
}
$GLOBALS['fx']     = json_decode( (string) stream_get_contents( STDIN ), true ) ?: array();
$GLOBALS['writes'] = array();

if ( getenv( 'LLD_HW_STUBS' ) ) {
	/** P2 stand-in: answers from the fixtures. */
	class LLD_Members_HW_Programme {
		public static function plan_view( $m, $today ) {
			return isset( $GLOBALS['fx']['plan_view'] ) ? $GLOBALS['fx']['plan_view'] : null;
		}
		public static function today( $m, $today ) {
			return isset( $GLOBALS['fx']['today'] ) ? $GLOBALS['fx']['today'] : null;
		}
		public static function blocks( $m, $today ) {
			return isset( $GLOBALS['fx']['blocks'] ) ? $GLOBALS['fx']['blocks'] : null;
		}
		public static function join( $m, $args ) {
			$GLOBALS['writes'][] = array( 'join', $args );
			if ( isset( $args['target_date'] ) && isset( $GLOBALS['fx']['join_refuses_date'] ) ) {
				return new WP_Error( 'lld_hw_pace', 'refused', array( 'code' => 'rate_cap' ) );
			}
			return true;
		}
		public static function choose_block( $m, $slug, $today ) {
			$GLOBALS['writes'][] = array( 'choose_block', $slug );
			return true;
		}
		public static function record_barrier( $m, $choices, $today ) {
			$GLOBALS['writes'][] = array( 'record_barrier', $choices );
			return true;
		}
		public static function carry_on( $m, $today ) {
			$GLOBALS['writes'][] = array( 'carry_on' );
			return true;
		}
	}
	/** P2 stand-in. */
	class LLD_Members_HW_Answers {
		public static function all( $m ) {
			return isset( $GLOBALS['fx']['answers'] ) ? $GLOBALS['fx']['answers'] : array();
		}
		public static function get( $m, $k ) {
			return isset( $GLOBALS['fx']['answers'][ $k ] ) ? $GLOBALS['fx']['answers'][ $k ] : null;
		}
		public static function set( $m, $k, $v ) {
			$GLOBALS['writes'][] = array( 'set', $k, $v );
			return true;
		}
		public static function questions( $m ) {
			return array();
		}
	}
	/** P3 stand-in: the approved content in the fixtures. */
	class LLD_Members_HW_Content {
		public static function question( $k ) {
			return isset( $GLOBALS['fx']['questions'][ $k ] ) ? $GLOBALS['fx']['questions'][ $k ] : null;
		}
		public static function questions() {
			return isset( $GLOBALS['fx']['questions'] ) ? $GLOBALS['fx']['questions'] : array();
		}
		public static function block( $k ) {
			return isset( $GLOBALS['fx']['blocks_content'][ $k ] ) ? $GLOBALS['fx']['blocks_content'][ $k ] : null;
		}
		public static function notes( $track ) {
			return array();
		}
		public static function activity_meta( $id ) {
			return isset( $GLOBALS['fx']['activities'][ $id ] ) ? $GLOBALS['fx']['activities'][ $id ] : null;
		}
	}
}

$screens = $stage3 . 'screens/';
foreach ( array( 'class-hw-screen-parts.php', 'class-hw-where-screen.php', 'class-hw-questions-screen.php', 'class-hw-plan-screen.php', 'class-hw-blocks-screen.php', 'class-goal-setting.php', 'class-today-screen.php', 'class-progress-page.php' ) as $f ) {
	require_once $screens . $f;
}

/** Call a private static method (the screens' own parts) for the tests. */
function lld_test_call( $class, $method, array $args ) {
	$r = new ReflectionMethod( $class, $method );
	$r->setAccessible( true );
	return $r->invokeArgs( null, $args );
}
