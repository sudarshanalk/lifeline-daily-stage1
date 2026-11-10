<?php
/**
 * WordPress and Stage 2 stand-ins for the Healthy Weight data tests (P3-35, package P2). Not a test file.
 *
 * The real Stage 3 classes run on top: the schema, LLD_Members_Stage3_Data (sealing), the export and erasure classes,
 * the goal journey, the food log, the recipes, the reminders and the Healthy Weight classes. Only the database and
 * the Stage 2 plugin are stood in for: Fake_Wpdb keeps tables in memory and answers the plain SQL these classes send;
 * LLD_Members_Crypto seals visibly ("ct:" + base64 of member and value) so a test can see that no plain value is stored.
 * Made-up test members only.
 */

define( 'ABSPATH', '/' );
date_default_timezone_set( 'UTC' ); // as WordPress sets it
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['lld_filters'] = array();
$GLOBALS['lld_options'] = array();
$GLOBALS['lld_mail']    = array();

function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['lld_filters'][ $hook ][] = array( $cb, $args ); return true; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { return add_filter( $hook, $cb, $prio, $args ); }
function has_filter( $hook ) { return ! empty( $GLOBALS['lld_filters'][ $hook ] ); }
function remove_all_filters( $hook ) { unset( $GLOBALS['lld_filters'][ $hook ] ); }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( isset( $GLOBALS['lld_filters'][ $hook ] ) ? $GLOBALS['lld_filters'][ $hook ] : array() as $f ) {
		$value = call_user_func_array( $f[0], array_slice( array_merge( array( $value ), $args ), 0, max( 1, $f[1] ) ) );
	}
	return $value;
}
function do_action( $hook, ...$args ) { foreach ( isset( $GLOBALS['lld_filters'][ $hook ] ) ? $GLOBALS['lld_filters'][ $hook ] : array() as $f ) { call_user_func_array( $f[0], $args ); } }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['lld_options'] ) ? $GLOBALS['lld_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['lld_options'][ $k ] = $v; return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_textarea_field( $v ) { return trim( (string) $v ); }
function sanitize_title( $v ) { return strtolower( trim( (string) $v ) ); }
function home_url( $p = '' ) { return 'https://stg.example.test' . $p; }
function is_email( $e ) { return false !== strpos( (string) $e, '@' ); }
function wp_mail( $to, $subject, $body ) { $GLOBALS['lld_mail'][] = compact( 'to', 'subject', 'body' ); return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function lld_members_now_mysql( $member_id = 0 ) { return LLD_Members_Clock::now( $member_id )->format( 'Y-m-d H:i:s' ); }
function get_posts( $args ) { return array(); }

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

/** In-memory tables answering the SQL the Stage 3 classes send. */
class Fake_Wpdb {
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $last_error = '';
	public $tables    = array();
	public $log       = array();

	public function get_charset_collate() { return ''; }
	public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[dsf]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : ( '%f' === $m[0] ? (string) (float) $v : "'" . str_replace( "'", "\\'", (string) $v ) . "'" );
			},
			$sql
		);
	}

	public function create( $name, array $cols ) {
		if ( ! isset( $this->tables[ $name ] ) ) {
			$this->tables[ $name ] = array( 'cols' => $cols, 'rows' => array(), 'auto' => 0 );
		}
	}

	public function query( $sql ) {
		$this->log[] = $sql;
		if ( preg_match( '/^CREATE TABLE IF NOT EXISTS (\S+) \((.*)\)/s', $sql, $m ) ) {
			$cols = array();
			foreach ( preg_split( '/\n/', $m[2] ) as $line ) {
				$line = trim( $line );
				if ( '' === $line || preg_match( '/^(PRIMARY|UNIQUE|KEY|FULLTEXT)\b/', $line ) ) {
					continue;
				}
				$cols[] = strtok( $line, ' ' );
			}
			$this->create( $m[1], $cols );
			return true;
		}
		if ( preg_match( '/^ALTER TABLE (\S+) ADD COLUMN (\w+)/', $sql, $m ) ) {
			$this->tables[ $m[1] ]['cols'][] = $m[2];
			return true;
		}
		if ( preg_match( '/^ALTER TABLE/', $sql ) ) {
			return true;
		}
		if ( preg_match( '/^DELETE FROM (\S+) WHERE (.*)$/s', $sql, $m ) ) {
			$n = 0;
			foreach ( $this->tables[ $m[1] ]['rows'] as $id => $row ) {
				if ( $this->match( $row, $m[2] ) ) {
					unset( $this->tables[ $m[1] ]['rows'][ $id ] );
					$n++;
				}
			}
			return $n;
		}
		if ( preg_match( '/^UPDATE (\S+) SET (\w+) = (\S+) WHERE (.*)$/s', $sql, $m ) ) {
			$n = 0;
			foreach ( $this->tables[ $m[1] ]['rows'] as $id => $row ) {
				if ( $this->match( $row, $m[4] ) ) {
					$this->tables[ $m[1] ]['rows'][ $id ][ $m[2] ] = trim( $m[3], "'" );
					$n++;
				}
			}
			return $n;
		}
		return 1;
	}

	public function get_col( $sql, $col = 0 ) {
		if ( preg_match( '/^SHOW COLUMNS FROM (\S+)/', $sql, $m ) ) {
			return isset( $this->tables[ $m[1] ] ) ? $this->tables[ $m[1] ]['cols'] : array();
		}
		if ( preg_match( '/^SHOW TABLES/', $sql ) ) {
			return array_keys( $this->tables );
		}
		return array_map( function ( $r ) { return reset( $r ); }, $this->select( $sql ) );
	}

	public function get_results( $sql, $out = null ) {
		if ( preg_match( '/^SHOW INDEX/', $sql ) ) {
			return array( 1 );
		}
		$rows = $this->select( $sql );
		return ARRAY_A === $out ? $rows : array_map( function ( $r ) { return (object) $r; }, $rows );
	}

	public function get_row( $sql, $out = null ) {
		$rows = $this->get_results( $sql, $out );
		return $rows ? $rows[0] : null;
	}

	public function get_var( $sql ) {
		$rows = $this->select( $sql );
		return $rows ? reset( $rows[0] ) : null;
	}

	public function insert( $table, array $data ) {
		$t = &$this->tables[ $table ];
		$t['auto']++;
		$row = array_fill_keys( $t['cols'], null );
		foreach ( $data as $k => $v ) {
			if ( ! in_array( $k, $t['cols'], true ) ) {
				$this->last_error = 'Unknown column ' . $k;
				$t['auto']--;
				return false;
			}
			$row[ $k ] = null === $v ? null : ( is_bool( $v ) ? (int) $v : $v );
		}
		if ( isset( $data['id'] ) ) {
			$t['auto'] = max( $t['auto'] - 1, (int) $data['id'] );
		}
		$id              = isset( $data['id'] ) ? (int) $data['id'] : $t['auto'];
		$row['id']       = $id;
		$t['rows'][ $id ] = $row;
		$this->insert_id = $id;
		return 1;
	}

	public function replace( $table, array $data ) {
		foreach ( $this->tables[ $table ]['rows'] as $id => $row ) {
			if ( isset( $data['member_id'] ) && (int) $row['member_id'] === (int) $data['member_id'] ) {
				unset( $this->tables[ $table ]['rows'][ $id ] );
			}
		}
		return $this->insert( $table, $data );
	}

	public function update( $table, array $data, array $where ) {
		$n = 0;
		foreach ( $this->tables[ $table ]['rows'] as $id => $row ) {
			$ok = true;
			foreach ( $where as $k => $v ) {
				$ok = $ok && (string) $row[ $k ] === (string) $v;
			}
			if ( $ok ) {
				foreach ( $data as $k => $v ) {
					if ( ! in_array( $k, $this->tables[ $table ]['cols'], true ) ) {
						return false;
					}
					$this->tables[ $table ]['rows'][ $id ][ $k ] = $v;
				}
				$n++;
			}
		}
		return $n;
	}

	public function delete( $table, array $where, $formats = null ) {
		$n = 0;
		foreach ( $this->tables[ $table ]['rows'] as $id => $row ) {
			$ok = true;
			foreach ( $where as $k => $v ) {
				$ok = $ok && (string) $row[ $k ] === (string) $v;
			}
			if ( $ok ) {
				unset( $this->tables[ $table ]['rows'][ $id ] );
				$n++;
			}
		}
		return $n;
	}

	private function select( $sql ) {
		$this->log[] = $sql;
		if ( ! preg_match( '/^SELECT (.+?) FROM (\S+)(?: \w+)?(?: WHERE (.+?))?(?: ORDER BY (.+?))?(?: LIMIT (\d+))?$/s', trim( $sql ), $m ) ) {
			return array();
		}
		$table = $m[2];
		if ( ! isset( $this->tables[ $table ] ) ) {
			return array();
		}
		$rows = array();
		foreach ( $this->tables[ $table ]['rows'] as $row ) {
			if ( empty( $m[3] ) || $this->match( $row, $m[3] ) ) {
				$rows[] = $row;
			}
		}
		if ( ! empty( $m[4] ) ) {
			$order = array();
			foreach ( explode( ',', $m[4] ) as $o ) {
				$p       = preg_split( '/\s+/', trim( $o ) );
				$order[] = array( preg_replace( '/^\w+\./', '', $p[0] ), isset( $p[1] ) && 'DESC' === strtoupper( $p[1] ) ? -1 : 1 );
			}
			usort(
				$rows,
				function ( $a, $b ) use ( $order ) {
					foreach ( $order as $o ) {
						$c = strcmp( (string) $a[ $o[0] ], (string) $b[ $o[0] ] );
						if ( is_numeric( $a[ $o[0] ] ) && is_numeric( $b[ $o[0] ] ) ) {
							$c = $a[ $o[0] ] <=> $b[ $o[0] ];
						}
						if ( 0 !== $c ) {
							return $c * $o[1];
						}
					}
					return 0;
				}
			);
		}
		if ( ! empty( $m[5] ) ) {
			$rows = array_slice( $rows, 0, (int) $m[5] );
		}
		$what = trim( $m[1] );
		if ( '*' === $what || preg_match( '/^\w+\.\*$/', $what ) ) {
			return $rows;
		}
		if ( preg_match( '/^MAX\((\w+)\)$/', $what, $mm ) ) {
			$vals = array_filter( array_column( $rows, $mm[1] ) );
			return array( array( 'max' => $vals ? max( $vals ) : null ) );
		}
		$cols = array_map( 'trim', explode( ',', preg_replace( '/^DISTINCT /', '', $what ) ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$x = array();
			foreach ( $cols as $c ) {
				$parts             = preg_split( '/\s+AS\s+/i', $c );
				$src               = preg_replace( '/^\w+\./', '', $parts[0] );
				$x[ end( $parts ) ] = array_key_exists( $src, $r ) ? $r[ $src ] : null;
			}
			$out[] = $x;
		}
		return $out;
	}

	private function match( array $row, $where ) {
		foreach ( preg_split( '/\s+AND\s+/i', trim( $where ) ) as $cond ) {
			$cond = trim( $cond, ' ()' );
			if ( preg_match( '/^(?:\w+\.)?(\w+) IS (NOT )?NULL$/i', $cond, $m ) ) {
				$is = null === $row[ $m[1] ];
				if ( ! empty( $m[2] ) ? $is : ! $is ) {
					return false;
				}
				continue;
			}
			if ( ! preg_match( "/^(?:\w+\.)?(\w+) (=|<>|>=|<=|>|<|LIKE) (?:'(.*)'|(-?[\d.]+))$/s", $cond, $m ) ) {
				continue;
			}
			$a = $row[ $m[1] ];
			$b = isset( $m[4] ) && '' !== $m[4] ? $m[4] : stripslashes( $m[3] );
			$c = is_numeric( $a ) && is_numeric( $b ) ? ( (float) $a <=> (float) $b ) : strcmp( (string) $a, (string) $b );
			$ok = array( '=' => 0 === $c, '<>' => 0 !== $c, '>' => $c > 0, '<' => $c < 0, '>=' => $c >= 0, '<=' => $c <= 0, 'LIKE' => true );
			if ( null === $a && '=' === $m[2] ) {
				return false;
			}
			if ( ! $ok[ $m[2] ] ) {
				return false;
			}
		}
		return true;
	}
}

$GLOBALS['wpdb'] = new Fake_Wpdb();

class LLD_Members_Schema {
	public static function table( $s ) { return 'wp_lld_' . $s; }
}

/** Visible sealing: the member number is the bound data, so another member's row never opens. */
class LLD_Members_Crypto {
	public static function encrypt( $plain, $aad ) { return 'ct:' . base64_encode( $aad . '|' . $plain ); }
	public static function decrypt( $token, $aad ) {
		$raw = base64_decode( substr( (string) $token, 3 ) );
		if ( 0 !== strpos( $raw, $aad . '|' ) ) {
			throw new Exception( 'wrong member' );
		}
		return substr( $raw, strlen( $aad ) + 1 );
	}
}

class LLD_Members_Clock {
	public static $now = '2026-11-12 09:00:00';
	public static function now( $member_id = 0 ) { return new DateTimeImmutable( self::$now, new DateTimeZone( 'UTC' ) ); }
}

class LLD_Members_Access {
	public static $consent = array();
	public static $member  = 0;
	public static function has_health_consent( $m ) { return ! empty( self::$consent[ (int) $m ] ); }
	public static function current_member_id() { return self::$member; }
	public static function access_state( $m ) { return LLD_Members_Billing::has_premium( $m ) ? 'premium' : 'free'; }
}

class LLD_Members_Account {
	public static function has_consent( $m, $type ) { return 'health' === $type && LLD_Members_Access::has_health_consent( $m ); }
	public static function save_health( $member_id, array $fields ) {
		global $wpdb;
		$t   = LLD_Members_Schema::table( 'health_profile' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE member_id = %d", $member_id ), ARRAY_A );
		$row = $row ? $row : array( 'member_id' => $member_id );
		unset( $row['id'], $fields['kind'], $fields['confirmed'] );
		return $wpdb->replace( $t, array_merge( $row, $fields ) ) ? true : new WP_Error( 'x', 'x' );
	}
}

class LLD_Members_Billing {
	public static $premium = array();
	public static function has_premium( $m ) { return ! empty( self::$premium[ (int) $m ] ); }
}

class LLD_Members_Features {
	public static $off = array();
	public static function enabled( $f, $m = 0 ) { return ! in_array( $f, self::$off, true ); }
	public static function state( $f ) { return in_array( $f, self::$off, true ) ? 'off' : 'everyone'; }
}

class LLD_Members_Access_Tiers {
	const FREE_REMINDERS = 1;
	public static function allows( $m, $f ) { return LLD_Members_Billing::has_premium( $m ); }
	public static function running_reminders( array $rows, $all ) {
		$ids = array();
		foreach ( $rows as $r ) { if ( ! empty( $r['active'] ) && empty( $r['deleted_at'] ) ) { $ids[] = (int) $r['id']; } }
		sort( $ids );
		return $all ? $ids : array_slice( $ids, 0, 1 );
	}
}

class LLD_Members_Stage3_Safety {
	public static $situations = array();
	public static function situations( $m, $known = array() ) { return isset( self::$situations[ (int) $m ] ) ? self::$situations[ (int) $m ] : array( 'none' ); }
	public static function allows_rule( $s, $r ) { return true; }
	public static function warning_level( $type, $values, $flags ) { return array( 'level' => 'none', 'message' => null ); }
}

class LLD_Members_Safety {
	public static $blocked = array();
	public static function allows( $m, $item ) { return ! in_array( (int) ( $item['parent_id'] ?? 0 ), self::$blocked, true ); }
}

class LLD_Members_Insights_Runner {
	public static function daily_value( $type, array $list ) { $x = end( $list ); return $x['v1']; }
	public static function on_reading_saved( $m, $r = array() ) {}
	public static function run_member( $m, $t = null ) {}
	public static function low_mood_active( $m ) { return false; }
}

/**
 * The Stage 2 programme, standing in with the 56 approved level 1 and 2 activities of the fixture (track 11 is level 1,
 * track 12 level 2; parent ids 1000 + programme day) and one enrolment per member.
 */
class LLD_Members_Programme {
	const DAYS = 28;
	public static $enrolment = array(); // member => {id, track_key, status, first}
	public static $started   = array();
	public static $today     = '2026-11-12';
	public static $acts      = array();
	public static function local_today( $m ) { return self::$today; }
	public static function timezone( $m ) { return new DateTimeZone( 'UTC' ); }
	public static function track_exists( $k ) { return in_array( $k, array( 'healthy-weight', 'healthy-weight-2', 'keep-it-off' ), true ); }
	public static function track_allowed( $m, $k ) { return true; }
	public static function track_id( $k ) { return array( 'healthy-weight' => 11, 'healthy-weight-2' => 12, 'keep-it-off' => 13 )[ $k ] ?? 0; }
	public static function activities( $track, $day, $member, $safe = true ) {
		$out = array();
		foreach ( self::$acts as $a ) {
			$level = $a['week'] > 4 ? 12 : 11;
			$d     = ( ( $a['week'] - 1 ) % 4 ) * 7 + $a['day'];
			if ( $level === (int) $track && $d === (int) $day ) {
				$out[] = array( 'parent_id' => isset( $a['pid'] ) ? $a['pid'] : ( 12 === $level ? 2000 : 1000 ) + $d, 'title' => $a['id'], 'tags' => $a['tags'], 'fields' => array( 'track_id' => $level, 'day_number' => $d ) );
			}
		}
		return $out;
	}
	public static function current( $m ) { return isset( self::$enrolment[ $m ] ) ? (object) self::$enrolment[ $m ] : null; }
	public static function periods( $id ) {
		foreach ( self::$enrolment as $e ) { if ( (int) $e['id'] === (int) $id ) { return array( array( 'id' => 1, 'first' => $e['first'], 'last' => '' ) ); } }
		return array();
	}
	public static function days_until( $periods, $date ) {
		if ( ! $periods || $date < $periods[0]['first'] ) { return 0; }
		return min( 28, (int) ( ( strtotime( $date ) - strtotime( $periods[0]['first'] ) ) / 86400 ) + 1 );
	}
	public static function day_date( $periods, $day ) { return $periods ? gmdate( 'Y-m-d', strtotime( $periods[0]['first'] . ' +' . ( $day - 1 ) . ' days' ) ) : ''; }
	public static function start( $m, $key, $when = 'today' ) {
		self::$started[]       = array( $m, $key );
		self::$enrolment[ $m ] = array( 'id' => 100 + $m * 10 + count( self::$started ), 'track_key' => $key, 'status' => 'active', 'first' => 'tomorrow' === $when ? gmdate( 'Y-m-d', strtotime( self::$today . ' +1 day' ) ) : self::$today );
		return true;
	}
	public static function completion( $m, $row, $week ) { return array( 'done' => 0, 'available' => 7, 'pct' => null ); }
}
