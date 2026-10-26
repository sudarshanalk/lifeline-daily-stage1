<?php
/**
 * Safety matrix (requirements section 8.2, data model B13). Exclusions apply before access and before matching.
 *
 * Launch rows come from the requirements. An approved matrix version can add protections. It can only remove a
 * launch protection when the owner confirmed that removal on the matrix screen, and the confirmation is part of
 * the approved version.
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
	 * Rows in matrix order. The order decides "the first applicable message" for more than one condition.
	 *
	 * @return array<string,array{tags:string[],badges:bool,tracks:string[],note:string,help_link:string,exercise_note:string,intense_note:string}>
	 */
	public static function launch_rules() {
		return array(
			'unknown'         => self::row(
				array( 'weight-loss', 'fasting', 'high-intensity', 'high-protein', 'high-salt', 'high-saturated-fat', 'pregnancy-unsafe-food', 'alcohol' ),
				false,
				array(),
				'Complete your health profile for more suggestions'
			),
			'none'            => self::row( array(), false, array(), '' ),
			'kidney'          => self::row( array( 'high-protein' ), false, array(), 'Your kidney team should set your protein target' ),
			'pregnancy'       => self::row(
				array( 'weight-loss', 'fasting', 'high-intensity', 'alcohol', 'pregnancy-unsafe-food' ),
				true,
				array( 'Healthy Weight' ),
				'Talk to your midwife or doctor about weight and exercise during pregnancy'
			),
			'eating-disorder' => self::row(
				array( 'weight-loss', 'fasting', 'calorie-mention', 'portion-question' ),
				true,
				array( 'Healthy Weight' ),
				'For support with eating, talk to your doctor',
				array( 'help_link' => 'https://findahelpline.com' )
			),
			'diabetes'        => self::row(
				array( 'fasting' ),
				false,
				array(),
				'Talk to your diabetes team',
				array( 'exercise_note' => 'If you take insulin or medicine that can cause low blood sugar, check with your doctor' )
			),
			'prediabetes'     => self::row( array(), false, array(), '' ),
			'blood-pressure'  => self::row(
				array( 'high-salt' ),
				false,
				array(),
				'Talk to your doctor before starting intense exercise',
				array( 'intense_note' => 'Check with your doctor first' )
			),
			'cholesterol'     => self::row( array( 'high-saturated-fat' ), false, array(), '' ),
		);
	}

	/**
	 * Text fields a matrix row can carry besides its tags, badges and tracks.
	 *
	 * @return string[]
	 */
	public static function text_fields() {
		return array( 'note', 'help_link', 'exercise_note', 'intense_note' );
	}

	/**
	 * @param int $member_id Member number.
	 * @return string[] Situation keys in matrix order.
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
		$found = array();
		foreach ( $raw as $item ) {
			$key = sanitize_key( (string) $item );
			if ( isset( $map[ $key ] ) ) {
				$key = $map[ $key ];
			}
			$found[ $key ] = true;
		}
		$out = array();
		foreach ( array_keys( self::launch_rules() ) as $key ) {
			if ( 'unknown' !== $key && 'none' !== $key && isset( $found[ $key ] ) ) {
				$out[] = $key;
			}
		}
		return $out ? $out : array( 'unknown' );
	}

	/**
	 * The rule in force for one situation: the approved matrix row on top of the launch row.
	 *
	 * @param string $key Situation key.
	 * @return array{tags:string[],badges:bool,tracks:string[],note:string,help_link:string,exercise_note:string,intense_note:string}
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
		if ( isset( $fields['situation'] ) && (string) $fields['situation'] !== (string) $key ) {
			return $base;
		}
		return self::merge( $base, self::from_fields( $fields, (string) ( $shown['body'] ?? '' ) ) );
	}

	/**
	 * Read a stored matrix row (snapshot or working copy fields) into rule shape.
	 *
	 * @param array<string,mixed> $fields Stored fields.
	 * @param string              $note   Note text (the item body).
	 * @return array{tags:string[],badges:bool,tracks:string[],note:string,help_link:string,exercise_note:string,intense_note:string,drops:string[]}
	 */
	public static function from_fields( $fields, $note ) {
		$out           = self::row(
			self::split_tags( (string) ( $fields['remove_tags'] ?? '' ) ),
			! empty( $fields['hide_badges'] ),
			self::split_labels( (string) ( $fields['tracks'] ?? '' ) ),
			trim( (string) $note ),
			array(
				'help_link'     => trim( (string) ( $fields['help_link'] ?? '' ) ),
				'exercise_note' => trim( (string) ( $fields['exercise_note'] ?? '' ) ),
				'intense_note'  => trim( (string) ( $fields['intense_note'] ?? '' ) ),
			)
		);
		$out['drops'] = self::split_labels( (string) ( $fields['confirmed_drops'] ?? '' ) );
		return $out;
	}

	/**
	 * The rule a stored row would give members once approved: the row on top of the launch row.
	 *
	 * @param string              $key    Situation key.
	 * @param array<string,mixed> $fields Stored fields.
	 * @param string              $note   Note text.
	 * @return array{tags:string[],badges:bool,tracks:string[],note:string,help_link:string,exercise_note:string,intense_note:string}
	 */
	public static function effective( $key, $fields, $note ) {
		$launch = self::launch_rules();
		$base   = isset( $launch[ $key ] ) ? $launch[ $key ] : $launch['unknown'];
		return self::merge( $base, self::from_fields( $fields, $note ) );
	}

	/**
	 * Launch protections that a row leaves out, as "tag:x", "badges", "track:X" or a text field name.
	 *
	 * @param string              $key Situation key.
	 * @param array<string,mixed> $row Row in rule shape.
	 * @return string[]
	 */
	public static function dropped( $key, $row ) {
		$launch = self::launch_rules();
		if ( ! isset( $launch[ $key ] ) ) {
			return array();
		}
		$base = $launch[ $key ];
		$out  = array();
		foreach ( $base['tags'] as $tag ) {
			if ( ! in_array( $tag, (array) $row['tags'], true ) ) {
				$out[] = 'tag:' . $tag;
			}
		}
		if ( $base['badges'] && empty( $row['badges'] ) ) {
			$out[] = 'badges';
		}
		foreach ( $base['tracks'] as $track ) {
			if ( ! self::has_label( (array) $row['tracks'], $track ) ) {
				$out[] = 'track:' . $track;
			}
		}
		foreach ( self::text_fields() as $field ) {
			if ( '' !== $base[ $field ] && '' === trim( (string) ( $row[ $field ] ?? '' ) ) ) {
				$out[] = $field;
			}
		}
		return $out;
	}

	/**
	 * @param int $member_id Member number.
	 * @return array{tags:string[],badges:bool,tracks:string[],notes:string[],keys:string[],exercise_notes:string[],intense_notes:string[]}
	 */
	public static function combined( $member_id ) {
		$tags     = array();
		$tracks   = array();
		$notes    = array();
		$exercise = array();
		$intense  = array();
		$badges   = false;
		$keys     = self::situations( $member_id );
		foreach ( $keys as $key ) {
			$rule   = self::rule( $key );
			$tags   = array_merge( $tags, $rule['tags'] );
			$tracks = array_merge( $tracks, $rule['tracks'] );
			$badges = $badges || $rule['badges'];
			if ( '' !== $rule['note'] ) {
				$notes[] = $rule['note'];
			}
			if ( '' !== $rule['exercise_note'] ) {
				$exercise[] = $rule['exercise_note'];
			}
			if ( '' !== $rule['intense_note'] ) {
				$intense[] = $rule['intense_note'];
			}
		}
		return array(
			'tags'           => array_values( array_unique( $tags ) ),
			'badges'         => $badges,
			'tracks'         => array_values( array_unique( $tracks ) ),
			'notes'          => array_values( array_unique( $notes ) ),
			'keys'           => $keys,
			'exercise_notes' => array_values( array_unique( $exercise ) ),
			'intense_notes'  => array_values( array_unique( $intense ) ),
		);
	}

	/**
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $item      Snapshot, with tags and type.
	 */
	public static function allows( $member_id, $item ) {
		$rule = self::combined( $member_id );
		$tags = self::item_tags( $item );
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
	 * Caution notes shown with an allowed item: the diabetes note before exercise and the high blood pressure
	 * note on high-intensity content.
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $item      Snapshot, with tags and type.
	 * @return string[]
	 */
	public static function cautions( $member_id, $item ) {
		$rule = self::combined( $member_id );
		$tags = self::item_tags( $item );
		$type = isset( $item['type'] ) ? (string) $item['type'] : '';
		$out  = array();
		if ( in_array( 'high-intensity', $tags, true ) ) {
			$out = array_merge( $out, $rule['intense_notes'] );
		}
		if ( 'lld_workout' === $type || array_intersect( array( 'fitness', 'high-intensity' ), $tags ) ) {
			$out = array_merge( $out, $rule['exercise_notes'] );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $item      Snapshot.
	 */
	public static function cautions_html( $member_id, $item ) {
		$html = '';
		foreach ( self::cautions( $member_id, $item ) as $note ) {
			$html .= lld_caution( $note );
		}
		return $html;
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
	 * Every applicable note, each followed by its helpline link when the row has one.
	 *
	 * @param int $member_id Member number.
	 */
	public static function notes_html( $member_id ) {
		$html = '';
		$seen = array();
		foreach ( self::situations( $member_id ) as $key ) {
			$rule = self::rule( $key );
			if ( '' === $rule['note'] || isset( $seen[ $rule['note'] ] ) ) {
				continue;
			}
			$seen[ $rule['note'] ] = true;
			$html                 .= '<p>' . esc_html( $rule['note'] ) . '</p>' . self::help_link_html( $rule['help_link'] );
		}
		return $html;
	}

	/**
	 * The "not suitable for you" design with the matrix notes. No preview and no upgrade.
	 *
	 * @param int    $member_id Member number.
	 * @param string $reason    Short reason.
	 * @param string $alt_href  Safe alternative.
	 * @param string $alt_label Link text.
	 */
	public static function not_suitable( $member_id, $reason, $alt_href, $alt_label ) {
		return lld_not_suitable( $reason, '', $alt_href, $alt_label, self::notes_html( $member_id ) );
	}

	/**
	 * The empty-state line: the first applicable row's message, in matrix order.
	 *
	 * @param int $member_id Member number.
	 */
	public static function empty_line( $member_id ) {
		$rule = self::first_noted( $member_id );
		return $rule ? $rule['note'] : 'Nothing suitable is available right now.';
	}

	/**
	 * The empty-state line with its helpline link, if the row has one.
	 *
	 * @param int $member_id Member number.
	 */
	public static function empty_html( $member_id ) {
		$rule = self::first_noted( $member_id );
		if ( ! $rule ) {
			return '<p>' . esc_html( self::empty_line( $member_id ) ) . '</p>';
		}
		return '<p>' . esc_html( $rule['note'] ) . '</p>' . self::help_link_html( $rule['help_link'] );
	}

	/**
	 * @param int $member_id Member number.
	 * @return array<string,mixed>|null
	 */
	private static function first_noted( $member_id ) {
		foreach ( self::situations( $member_id ) as $key ) {
			$rule = self::rule( $key );
			if ( '' !== $rule['note'] ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * @param string $href Helpline address.
	 */
	private static function help_link_html( $href ) {
		$href = esc_url( (string) $href, array( 'https' ) );
		if ( '' === $href ) {
			return '';
		}
		return '<p class="lld-help-link"><a href="' . $href . '">Find support in your country</a></p>';
	}

	/**
	 * @param array<string,mixed> $base Launch row.
	 * @param array<string,mixed> $row  Approved row, with confirmed drops.
	 * @return array{tags:string[],badges:bool,tracks:string[],note:string,help_link:string,exercise_note:string,intense_note:string}
	 */
	private static function merge( $base, $row ) {
		$drops = isset( $row['drops'] ) && is_array( $row['drops'] ) ? $row['drops'] : array();
		$tags  = $row['tags'];
		foreach ( $base['tags'] as $tag ) {
			if ( ! in_array( 'tag:' . $tag, $drops, true ) ) {
				$tags[] = $tag;
			}
		}
		$tracks = $row['tracks'];
		foreach ( $base['tracks'] as $track ) {
			if ( ! self::has_label( $drops, 'track:' . $track ) && ! self::has_label( $tracks, $track ) ) {
				$tracks[] = $track;
			}
		}
		$extra = array();
		foreach ( self::text_fields() as $field ) {
			$value = (string) $row[ $field ];
			if ( '' === $value && ! in_array( $field, $drops, true ) ) {
				$value = (string) $base[ $field ];
			}
			$extra[ $field ] = $value;
		}
		return self::row(
			array_values( array_unique( $tags ) ),
			$row['badges'] || ( $base['badges'] && ! in_array( 'badges', $drops, true ) ),
			array_values( $tracks ),
			$extra['note'],
			$extra
		);
	}

	/**
	 * @param string[]            $tags   Excluded tags.
	 * @param bool                $badges Hide weight-loss badges.
	 * @param string[]            $tracks Tracks not offered.
	 * @param string              $note   Note shown.
	 * @param array<string,string> $extra Help link, exercise note, intense note.
	 * @return array{tags:string[],badges:bool,tracks:string[],note:string,help_link:string,exercise_note:string,intense_note:string}
	 */
	private static function row( $tags, $badges, $tracks, $note, $extra = array() ) {
		return array(
			'tags'          => $tags,
			'badges'        => (bool) $badges,
			'tracks'        => $tracks,
			'note'          => (string) $note,
			'help_link'     => (string) ( $extra['help_link'] ?? '' ),
			'exercise_note' => (string) ( $extra['exercise_note'] ?? '' ),
			'intense_note'  => (string) ( $extra['intense_note'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $item Snapshot.
	 * @return string[]
	 */
	private static function item_tags( $item ) {
		$tags = array();
		if ( isset( $item['tags'] ) && is_array( $item['tags'] ) ) {
			foreach ( $item['tags'] as $tag ) {
				$tags[] = sanitize_title( (string) $tag );
			}
		}
		return $tags;
	}

	/**
	 * @param string[] $list  Labels.
	 * @param string   $label Wanted label.
	 */
	private static function has_label( $list, $label ) {
		foreach ( $list as $item ) {
			if ( 0 === strcasecmp( (string) $item, (string) $label ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $text Comma-separated tags.
	 * @return string[]
	 */
	public static function split_tags( $text ) {
		$out = array();
		foreach ( preg_split( '/\s*,\s*/', (string) $text ) as $tag ) {
			$tag = sanitize_title( (string) $tag );
			if ( '' !== $tag ) {
				$out[] = $tag;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param string $text Comma-separated labels.
	 * @return string[]
	 */
	public static function split_labels( $text ) {
		$out = array();
		foreach ( preg_split( '/\s*,\s*/', (string) $text ) as $label ) {
			$label = trim( (string) $label );
			if ( '' !== $label ) {
				$out[] = $label;
			}
		}
		return $out;
	}
}
