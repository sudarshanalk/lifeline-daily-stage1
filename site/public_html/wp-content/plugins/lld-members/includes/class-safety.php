<?php
/**
 * Safety matrix. Exclusions apply before access and before matching.
 *
 * Launch rows come from the requirements. An approved matrix version replaces that situation.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combined safety rules for one member.
 */
class LLD_Members_Safety {

	/**
	 * @return array<string,array{tags:string[],badges:bool,tracks:string[],note:string}>
	 */
	public static function launch_rules() {
		return array(
			'unknown'         => array(
				'tags'   => array( 'weight-loss', 'fasting', 'high-intensity', 'high-protein', 'high-salt', 'high-saturated-fat', 'pregnancy-unsafe-food', 'alcohol' ),
				'badges' => false,
				'tracks' => array(),
				'note'   => 'Complete your health profile for more suggestions',
			),
			'none'            => array(
				'tags'   => array(),
				'badges' => false,
				'tracks' => array(),
				'note'   => '',
			),
			'kidney'          => array(
				'tags'   => array( 'high-protein' ),
				'badges' => false,
				'tracks' => array(),
				'note'   => 'Your kidney team should set your protein target',
			),
			'pregnancy'       => array(
				'tags'   => array( 'weight-loss', 'fasting', 'high-intensity', 'alcohol', 'pregnancy-unsafe-food' ),
				'badges' => true,
				'tracks' => array( 'Healthy Weight' ),
				'note'   => 'Talk to your midwife or doctor about weight and exercise during pregnancy',
			),
			'eating-disorder' => array(
				'tags'   => array( 'weight-loss', 'fasting', 'calorie-mention', 'portion-question' ),
				'badges' => true,
				'tracks' => array( 'Healthy Weight' ),
				'note'   => 'For support with eating, talk to your doctor',
			),
			'diabetes'        => array(
				'tags'   => array( 'fasting' ),
				'badges' => false,
				'tracks' => array(),
				'note'   => 'Talk to your diabetes team before you change when you eat',
			),
			'prediabetes'     => array(
				'tags'   => array(),
				'badges' => false,
				'tracks' => array(),
				'note'   => '',
			),
			'blood-pressure'  => array(
				'tags'   => array( 'high-salt' ),
				'badges' => false,
				'tracks' => array(),
				'note'   => 'Talk to your doctor before starting intense exercise',
			),
			'cholesterol'     => array(
				'tags'   => array( 'high-saturated-fat' ),
				'badges' => false,
				'tracks' => array(),
				'note'   => '',
			),
		);
	}

	/**
	 * @param int $member_id Member number.
	 * @return string[]
	 */
	public static function situations( $member_id ) {
		$member_id = (int) $member_id;
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			return array( 'unknown' );
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT conditions_answered, conditions FROM ' . LLD_Members_Schema::table( 'health_profile' ) . ' WHERE member_id = %d',
				$member_id
			)
		);
		if ( ! $row || 'not_answered' === (string) $row->conditions_answered ) {
			return array( 'unknown' );
		}
		if ( 'none' === (string) $row->conditions_answered ) {
			return array( 'none' );
		}
		$raw = json_decode( (string) $row->conditions, true );
		if ( ! is_array( $raw ) ) {
			return array( 'unknown' );
		}
		$map = array(
			'type-2-diabetes'     => 'diabetes',
			'high-blood-pressure' => 'blood-pressure',
			'high-cholesterol'    => 'cholesterol',
		);
		$out = array();
		foreach ( $raw as $item ) {
			$key = sanitize_key( (string) $item );
			if ( isset( $map[ $key ] ) ) {
				$key = $map[ $key ];
			}
			if ( isset( self::launch_rules()[ $key ] ) && 'unknown' !== $key && 'none' !== $key ) {
				$out[] = $key;
			}
		}
		return $out ? array_values( array_unique( $out ) ) : array( 'unknown' );
	}

	/**
	 * @param string $key Situation key.
	 * @return array{tags:string[],badges:bool,tracks:string[],note:string}
	 */
	public static function rule( $key ) {
		$launch = self::launch_rules();
		$base   = isset( $launch[ $key ] ) ? $launch[ $key ] : $launch['unknown'];
		$posts  = get_posts(
			array(
				'post_type'      => 'lld_matrix',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => 1,
				'meta_key'       => '_lld_situation',
				'meta_value'     => $key,
			)
		);
		if ( ! $posts ) {
			return $base;
		}
		$shown = LLD_Members_Content::shown( $posts[0]->ID );
		if ( ! is_array( $shown ) ) {
			return $base;
		}
		$fields = isset( $shown['fields'] ) && is_array( $shown['fields'] ) ? $shown['fields'] : array();
		return array(
			'tags'   => self::split_tags( (string) ( $fields['remove_tags'] ?? '' ) ),
			'badges' => ! empty( $fields['hide_badges'] ),
			'tracks' => self::split_labels( (string) ( $fields['tracks'] ?? '' ) ),
			'note'   => trim( (string) ( $shown['body'] ?? '' ) ),
		);
	}

	/**
	 * @param int $member_id Member number.
	 * @return array{tags:string[],badges:bool,tracks:string[],notes:string[]}
	 */
	public static function combined( $member_id ) {
		$tags   = array();
		$tracks = array();
		$notes  = array();
		$badges = false;
		foreach ( self::situations( $member_id ) as $key ) {
			$rule   = self::rule( $key );
			$tags   = array_merge( $tags, $rule['tags'] );
			$tracks = array_merge( $tracks, $rule['tracks'] );
			$badges = $badges || $rule['badges'];
			if ( '' !== $rule['note'] ) {
				$notes[] = $rule['note'];
			}
		}
		return array(
			'tags'   => array_values( array_unique( $tags ) ),
			'badges' => $badges,
			'tracks' => array_values( array_unique( $tracks ) ),
			'notes'  => array_values( array_unique( $notes ) ),
		);
	}

	/**
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $item      Snapshot, with tags and type.
	 */
	public static function allows( $member_id, $item ) {
		$rule = self::combined( $member_id );
		$tags = array();
		if ( isset( $item['tags'] ) && is_array( $item['tags'] ) ) {
			foreach ( $item['tags'] as $tag ) {
				$tags[] = sanitize_title( (string) $tag );
			}
		}
		foreach ( $tags as $tag ) {
			if ( in_array( $tag, $rule['tags'], true ) ) {
				return false;
			}
		}
		$type   = isset( $item['type'] ) ? (string) $item['type'] : '';
		$fields = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();
		$badge  = ( 'lld_badge' === $type ) && ( in_array( 'weight-loss', $tags, true ) || ! empty( $fields['weight_loss'] ) );
		if ( $rule['badges'] && $badge ) {
			return false;
		}
		return true;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $title     Track title.
	 */
	public static function track_allowed( $member_id, $title ) {
		foreach ( self::combined( $member_id )['tracks'] as $blocked ) {
			if ( 0 === strcasecmp( (string) $blocked, (string) $title ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param int $member_id Member number.
	 * @return string[]
	 */
	public static function notes( $member_id ) {
		return self::combined( $member_id )['notes'];
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function empty_line( $member_id ) {
		if ( in_array( 'unknown', self::situations( $member_id ), true ) ) {
			return 'Complete your health profile for more suggestions';
		}
		return 'Nothing suitable is available right now.';
	}

	/**
	 * @param string $text Comma-separated tags.
	 * @return string[]
	 */
	private static function split_tags( $text ) {
		$out = array();
		foreach ( preg_split( '/\s*,\s*/', $text ) as $tag ) {
			$tag = sanitize_title( (string) $tag );
			if ( '' !== $tag ) {
				$out[] = $tag;
			}
		}
		return $out;
	}

	/**
	 * @param string $text Comma-separated labels.
	 * @return string[]
	 */
	private static function split_labels( $text ) {
		$out = array();
		foreach ( preg_split( '/\s*,\s*/', $text ) as $label ) {
			$label = trim( (string) $label );
			if ( '' !== $label ) {
				$out[] = $label;
			}
		}
		return $out;
	}
}
