<?php
/**
 * Just enough WordPress for the Stage 3 content switch to run in PHP CLI: the real feature switches
 * (stage3/class-features.php), the real content filter (stage3/class-content-stage3.php), the real safety matrix
 * (class-safety.php) and the real Premium class (class-premium.php), with the content library, the switch state,
 * the test accounts and the signed-in member supplied by the test as JSON on stdin.
 *
 * get_posts() runs the query through `pre_get_posts` exactly as WordPress does, then honours the meta_query the
 * filter adds (NOT EXISTS _lld_stage), so the test sees what a Stage 2 class's query returns on the live site.
 *
 * Input: { "items": { "<id>": snapshot (type, title, fields, tags, stage?) }, "switch": "off|test_accounts|everyone",
 *          "test_emails": [...], "emails": { "<member id>": email }, "member": <signed-in member id>, "admin": bool,
 *          "in_admin": bool, "call": "get_posts" | "<LLD_Members_Premium method>", "args": [...] }
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['lld_test'] = json_decode( stream_get_contents( STDIN ), true );
$GLOBALS['opts']     = array();

class WP_Error {
	public $code;
	public $message;
	function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function current_user_can() { return ! empty( $GLOBALS['lld_test']['admin'] ); }
function is_admin() { return ! empty( $GLOBALS['lld_test']['in_admin'] ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function taxonomy_exists() { return false; }
function add_action() {}
function add_filter() {}
function apply_filters( $h, $v ) { return $v; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function sanitize_title( $text ) { return trim( preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( trim( (string) $text ) ) ), '-' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return (string) $url; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function lld_members_now_mysql() { return '2026-11-03 12:00:00'; }

/** The query object WordPress hands to pre_get_posts. */
class WP_Query {
	public $query_vars = array();
	function __construct( $vars ) { $this->query_vars = $vars; }
	function get( $k, $d = '' ) { return $this->query_vars[ $k ] ?? $d; }
	function set( $k, $v ) { $this->query_vars[ $k ] = $v; }
}

/** True when a meta_query (nested as WP_Meta_Query allows) has the NOT EXISTS clause on this key. */
function lld_test_excludes( $meta, $key ) {
	if ( ! is_array( $meta ) ) {
		return false;
	}
	if ( ( $meta['key'] ?? '' ) === $key && ( $meta['compare'] ?? '' ) === 'NOT EXISTS' ) {
		return true;
	}
	foreach ( $meta as $k => $v ) {
		if ( 'relation' !== $k && lld_test_excludes( $v, $key ) ) {
			return true;
		}
	}
	return false;
}

function get_posts( $args ) {
	$q = new WP_Query( $args );
	LLD_Members_Stage3_Content::hide_new_content( $q );   // as WordPress's pre_get_posts does
	$args  = $q->query_vars;
	$types = (array) ( $args['post_type'] ?? '' );
	$out   = array();
	if ( 'lld_version' === ( $args['post_status'] ?? '' ) ) {
		$parent = (string) (int) ( $args['post_parent'] ?? 0 );
		$found  = $GLOBALS['lld_test']['items'][ $parent ] ?? null;
		if ( ! is_array( $found ) || ! in_array( $found['type'] ?? '', $types, true ) ) {
			return array();
		}
		$id = 9000 + (int) $parent * 10 + 1;
		$GLOBALS['lld_test_meta'][ $id ] = array( '_lld_approved_on' => '2026-11-01', '_lld_fingerprint' => 'fp1' );
		return array( (object) array( 'ID' => $id, 'post_content' => json_encode( $found ) ) );
	}
	$hide = lld_test_excludes( $args['meta_query'] ?? null, '_lld_stage' );
	foreach ( $GLOBALS['lld_test']['items'] as $id => $snap ) {
		if ( ! in_array( $snap['type'] ?? '', $types, true ) ) {
			continue;
		}
		if ( $hide && ! empty( $snap['stage'] ) ) {
			continue;
		}
		if ( ! empty( $args['name'] ) && ( $snap['slug'] ?? '' ) !== $args['name'] ) {
			continue;
		}
		$out[] = ( $args['fields'] ?? '' ) === 'ids' ? (int) $id : (object) array( 'ID' => (int) $id, 'post_title' => (string) ( $snap['title'] ?? '' ) );
	}
	return $out;
}

function get_post_meta( $id, $key, $single = false ) {
	if ( '_lld_stage' === $key ) {
		$snap = $GLOBALS['lld_test']['items'][ (string) (int) $id ] ?? array();
		return ! empty( $snap['stage'] ) ? (string) $snap['stage'] : '';
	}
	return $GLOBALS['lld_test_meta'][ (int) $id ][ $key ] ?? '';
}

class LLD_Members_Content {
	public static function types() {
		return LLD_Members_Stage3_Content::TYPES;
	}
	public static function shown( $id ) {
		$items = $GLOBALS['lld_test']['items'];
		if ( ! isset( $items[ (string) (int) $id ] ) ) {
			return null;
		}
		$snap              = $items[ (string) (int) $id ];
		$snap['parent_id'] = (int) $id;
		$snap['version']   = isset( $snap['version'] ) ? (int) $snap['version'] : 1;
		return $snap;
	}
}

class LLD_Members_Account {
	public static function has_consent( $member_id, $kind ) { return 'health' === $kind; }
}
class LLD_Members_Schema {
	public static function table( $suffix ) { return 'wp_lld_' . $suffix; }
}
class LLD_Members_Programme {
	public static function active( $member_id ) { return null; }
	public static function local_today( $member_id ) { return '2026-12-04'; }
}
class LLD_Members_Access {
	public static function own_member_id() { return (int) ( $GLOBALS['lld_test']['member'] ?? 0 ); }
}

class LLD_Test_Wpdb {
	public $insert_id = 1;
	public function prepare( $query, ...$args ) { return $args ? vsprintf( str_replace( '%s', "'%s'", $query ), $args ) : $query; }
	public function get_row( $query ) { return null; }
	public function get_var( $query ) {
		if ( preg_match( '/SELECT email FROM \S+ WHERE id = (\d+)/', $query, $m ) ) {
			return $GLOBALS['lld_test']['emails'][ $m[1] ] ?? null;
		}
		return null;
	}
	public function get_results( $query ) { return array(); }
	public function insert( $table, $data, $format = null ) { return 1; }
	public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
}
$GLOBALS['wpdb'] = new LLD_Test_Wpdb();

$plugin = dirname( __DIR__, 3 ) . '/site/public_html/wp-content/plugins/lld-members/includes';
require $plugin . '/stage3/class-features.php';
require $plugin . '/stage3/class-content-stage3.php';
require $plugin . '/class-safety.php';
require $plugin . '/class-premium.php';

update_option( 'lld_test_account_emails', $GLOBALS['lld_test']['test_emails'] ?? array() );
$state = (string) ( $GLOBALS['lld_test']['switch'] ?? 'off' );
if ( 'off' !== $state ) {
	LLD_Members_Features::set( 'content_new', $state, 'everyone' === $state ? 'Owner (test)' : '' );
}

$call = (string) $GLOBALS['lld_test']['call'];
$args = $GLOBALS['lld_test']['args'] ?? array();
if ( 'get_posts' === $call ) {
	$result = array_map( static function ( $p ) { return is_object( $p ) ? array( 'id' => $p->ID, 'title' => $p->post_title ) : $p; }, get_posts( $args[0] ) );
} elseif ( 'filter_vars' === $call ) {
	$q = new WP_Query( $args[0] );
	LLD_Members_Stage3_Content::hide_new_content( $q );
	$result = $q->query_vars;
} else {
	$method = new ReflectionMethod( 'LLD_Members_Premium', $call );
	$result = $method->invokeArgs( null, $args );
}
echo json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
