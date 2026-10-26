<?php
/**
 * Just enough WordPress for the Premium class's pure parts to run in PHP CLI: the real safety matrix
 * (class-safety.php) and the real Premium class (class-premium.php), with content, consent and the
 * health profile supplied by the test as JSON on stdin.
 *
 * Input: { "items": { "<id>": snapshot }, "consent": bool, "profile": { conditions_answered, conditions } | null,
 *          "call": "<method>", "args": [...] }
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lld_test'] = json_decode( stream_get_contents( STDIN ), true );
foreach ( $GLOBALS['lld_test']['defines'] ?? array() as $name => $value ) {
	define( $name, $value );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function sanitize_title( $text ) {
	$text = strtolower( trim( (string) $text ) );
	$text = preg_replace( '/[^a-z0-9_\-]+/', '-', $text );
	return trim( (string) $text, '-' );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function get_posts( $args ) {
	$type = isset( $args['post_type'] ) ? $args['post_type'] : '';
	$out  = array();
	if ( 'lld_version' === ( $args['post_status'] ?? '' ) ) {
		// items[parent].versions[N] = { snapshot, approved_on }
		$parent = (string) (int) ( $args['post_parent'] ?? 0 );
		$number = (string) (int) ( $args['meta_value'] ?? 0 );
		$found  = $GLOBALS['lld_test']['items'][ $parent ]['versions'][ $number ] ?? null;
		if ( ! is_array( $found ) || ( $GLOBALS['lld_test']['items'][ $parent ]['type'] ?? '' ) !== $type ) {
			return array();
		}
		$id                            = 9000 + (int) $parent * 10 + (int) $number;
		$GLOBALS['lld_test_meta'][ $id ] = array(
			'_lld_approved_on' => (string) ( $found['approved_on'] ?? '' ),
			'_lld_fingerprint' => 'fp' . $number,
		);
		unset( $found['approved_on'] );
		return array( (object) array( 'ID' => $id, 'post_content' => json_encode( $found ) ) );
	}
	foreach ( $GLOBALS['lld_test']['items'] as $id => $snap ) {
		if ( ( $snap['type'] ?? '' ) === $type ) {
			$out[] = (object) array( 'ID' => (int) $id );
		}
	}
	return $out;
}

function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['lld_test_meta'][ (int) $id ][ $key ] ?? '';
}

class LLD_Members_Content {
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
	public static function has_consent( $member_id, $kind ) {
		return 'health' === $kind && ! empty( $GLOBALS['lld_test']['consent'] );
	}
}

class LLD_Members_Schema {
	public static function table( $suffix ) {
		return 'wp_lld_' . $suffix;
	}
}

class LLD_Members_Programme {
	public static function active( $member_id ) {
		return null;
	}

	public static function local_today( $member_id ) {
		return '2026-05-04';
	}
}

class LLD_Test_Wpdb {
	public $insert_id = 1;

	public function prepare( $query, ...$args ) {
		return $query;
	}

	public function get_row( $query ) {
		$profile = $GLOBALS['lld_test']['profile'] ?? null;
		if ( false !== strpos( $query, 'health_profile' ) && is_array( $profile ) ) {
			return (object) array(
				'conditions_answered' => (string) ( $profile['conditions_answered'] ?? 'not_answered' ),
				'conditions'          => json_encode( $profile['conditions'] ?? array() ),
			);
		}
		return null;
	}

	public function get_var( $query ) {
		return null;
	}

	public function insert( $table, $data, $format = null ) {
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		return 1;
	}
}

$GLOBALS['wpdb'] = new LLD_Test_Wpdb();

$plugin = dirname( __DIR__, 3 ) . '/site/public_html/wp-content/plugins/lld-members/includes';
require $plugin . '/class-safety.php';
require $plugin . '/class-premium.php';

$method = new ReflectionMethod( 'LLD_Members_Premium', (string) $GLOBALS['lld_test']['call'] );
$args = array_map(
	static function ( $arg ) {
		return is_array( $arg ) && isset( $arg['__object'] ) ? (object) $arg['__object'] : $arg;
	},
	$GLOBALS['lld_test']['args'] ?? array()
);
$result = $method->invokeArgs( null, $args );
if ( ! empty( $GLOBALS['lld_test']['raw'] ) ) {
	$result = base64_encode( (string) $result );
}
echo json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
