<?php
/**
 * Just enough WordPress for the Healthy Weight content loader and LLD_Members_HW_Content to run in PHP CLI (P3-35,
 * package P3). The test sends a bundle; this file runs the approved content loader's real pure check on it
 * (lld_content_check, mu-plugins/lld-tools/content-load.php), then "loads" it the way `wp lld content load` does:
 * each item becomes a library post keyed by _lld_source_key, its "@key" references become post ids
 * (lld_content_resolve) and its approved version is the item as loaded. Keys listed in `unapproved` get no approved
 * version, as an item still waiting on the approvals screen. Then it calls one LLD_Members_HW_Content method.
 *
 * Input (JSON on stdin): { "bundle": {...}, "unapproved": [keys], "table": false | {"columns": [...]}, "call": "...",
 *   "args": [...] }. Output: { "problems": [...], "ids": {key: id}, "result": ..., "rows": [festival table rows] }.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['t']    = json_decode( stream_get_contents( STDIN ), true );
$GLOBALS['opts'] = array();

class WP_Error {
	public $message;
	function __construct( $c = '', $m = '' ) { $this->message = $m; }
	function get_error_message() { return $this->message; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action() {}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function taxonomy_exists() { return true; }
function term_exists( $name ) { return in_array( $name, $GLOBALS['terms'] ?? array(), true ) ? array( 'term_id' => 1 ) : null; }
function wp_insert_term( $name ) { $GLOBALS['terms'][] = $name; return array( 'term_id' => count( $GLOBALS['terms'] ) ); }
function update_term_meta( $id, $k, $v ) { $GLOBALS['term_kinds'][ end( $GLOBALS['terms'] ) ] = $v; }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }

/** The library as loaded: post id => {item, key, approved}. */
$GLOBALS['library'] = array();

function get_posts( $args ) {
	$types = (array) ( $args['post_type'] ?? array() );
	$out   = array();
	foreach ( $GLOBALS['library'] as $id => $row ) {
		if ( ! in_array( $row['item']['type'], $types, true ) ) {
			continue;
		}
		if ( ! empty( $args['name'] ) && ( $row['item']['slug'] ?? '' ) !== $args['name'] ) {
			continue;
		}
		if ( ( $args['meta_key'] ?? '' ) === '_lld_source_key' && $row['key'] !== ( $args['meta_value'] ?? null ) ) {
			continue;
		}
		$out[] = (object) array( 'ID' => $id );
	}
	$n = (int) ( $args['posts_per_page'] ?? -1 );
	return $n > 0 ? array_slice( $out, 0, $n ) : $out;
}

function get_post_meta( $id, $key, $single = false ) {
	$row = $GLOBALS['library'][ (int) $id ] ?? null;
	if ( ! $row ) {
		return '';
	}
	if ( '_lld_source_key' === $key ) {
		return $row['key'];
	}
	if ( '_lld_stage' === $key ) {
		return isset( $row['item']['stage'] ) ? (string) $row['item']['stage'] : '';
	}
	return '';
}

class LLD_Members_Content {
	/** The approved version members are given, as the library returns it (null while waiting for approval). */
	public static function shown( $id ) {
		$row = $GLOBALS['library'][ (int) $id ] ?? null;
		if ( ! $row || ! $row['approved'] ) {
			return null;
		}
		$it = $row['item'];
		return array(
			'type'       => $it['type'],
			'title'      => $it['title'],
			'body'       => $it['body'] ?? '',
			'tags'       => $it['tags'] ?? array(),
			'fields'     => $row['fields'],
			'parent_id'  => (int) $id,
			'version_id' => 9000 + (int) $id,
		);
	}
}

class LLD_Members_Schema {
	public static function table( $suffix ) { return 'wp_lld_' . $suffix; }
}

/** The festival calendar table, when the test says package P2 has created it. */
class LLD_Test_Wpdb {
	public $rows = array();
	public function prepare( $q, ...$a ) { return vsprintf( str_replace( '%s', "'%s'", $q ), $a ); }
	private function table() { return is_array( $GLOBALS['t']['table'] ?? null ); }
	public function get_var( $q ) {
		if ( preg_match( "/^SHOW TABLES LIKE '([^']+)'/", $q, $m ) ) {
			return $this->table() ? $m[1] : null;
		}
		if ( preg_match( "/WHERE (\\w+) = '([^']*)'(?: AND starts_on = '([^']*)')?/", $q, $m ) ) {
			foreach ( $this->rows as $id => $r ) {
				if ( ( $r[ $m[1] ] ?? null ) === $m[2] && ( ! isset( $m[3] ) || ( $r['starts_on'] ?? '' ) === $m[3] ) ) {
					return $id;
				}
			}
		}
		return null;
	}
	public function get_col( $q ) { return $this->table() ? $GLOBALS['t']['table']['columns'] : array(); }
	public function get_results( $q, $out = null ) {
		$rows = array_values( $this->rows );
		usort( $rows, fn( $a, $b ) => strcmp( (string) ( $a['starts_on'] ?? '' ), (string) ( $b['starts_on'] ?? '' ) ) );
		return $rows;
	}
	public function insert( $t, $row ) { $id = count( $this->rows ) + 1; $this->rows[ $id ] = array( 'id' => $id ) + $row; return 1; }
	public function update( $t, $row, $where ) { $this->rows[ $where['id'] ] = array_merge( $this->rows[ $where['id'] ], $row ); return 1; }
}
$GLOBALS['wpdb'] = new LLD_Test_Wpdb();

$root = dirname( __DIR__, 3 ) . '/site/public_html/wp-content';
require $root . '/plugins/lld-members/includes/stage3/class-content-hw.php';
require $root . '/mu-plugins/lld-tools/content-load.php';

$bundle   = $GLOBALS['t']['bundle'];
$problems = lld_content_check( $bundle );
$ids      = array();
$n        = 100;
foreach ( $bundle['items'] as $it ) {
	$missing = array();
	$fields  = lld_content_resolve( (array) ( $it['fields'] ?? array() ), $ids, $missing );
	$id      = ++$n;
	$ids[ $it['key'] ] = $id;
	$GLOBALS['library'][ $id ] = array(
		'item'     => $it,
		'key'      => $it['key'],
		'fields'   => $fields,
		'approved' => ! in_array( $it['key'], $GLOBALS['t']['unapproved'] ?? array(), true ),
	);
}

$call   = (string) ( $GLOBALS['t']['call'] ?? '' );
$result = null;
if ( '' !== $call ) {
	$result = call_user_func_array( array( 'LLD_Members_HW_Content', $call ), $GLOBALS['t']['args'] ?? array() );
}
echo json_encode(
	array(
		'problems' => $problems,
		'ids'      => $ids,
		'result'   => $result,
		'rows'     => array_values( $GLOBALS['wpdb']->rows ),
		'terms'    => $GLOBALS['term_kinds'] ?? array(),
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
