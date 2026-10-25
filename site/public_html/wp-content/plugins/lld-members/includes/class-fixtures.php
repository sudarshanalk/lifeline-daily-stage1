<?php
/**
 * Test fixtures for personalisation checks. Called only from the closed test route.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds real goals, articles and approved items so the member screens can be checked.
 */
class LLD_Members_Fixtures {

	/**
	 * @param string               $kind  Fixture name.
	 * @param string               $stamp Short unique stamp.
	 * @param array<string,mixed>  $args  Extra fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function build( $kind, $stamp, $args = array() ) {
		$stamp = sanitize_title( (string) $stamp );
		if ( '' === $stamp ) {
			return new WP_Error( 'lld_fixture', 'A stamp is required.' );
		}
		if ( 'chooser' === $kind ) {
			return self::chooser( $stamp );
		}
		if ( 'pool' === $kind ) {
			return self::pool( $stamp, isset( $args['count'] ) ? (int) $args['count'] : 8 );
		}
		if ( 'pair' === $kind ) {
			return self::pair( $stamp );
		}
		if ( 'safety' === $kind ) {
			return self::safety( $stamp );
		}
		if ( 'live' === $kind ) {
			return self::live( $stamp, isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : '' );
		}
		if ( 'gap' === $kind ) {
			return self::gap( $stamp );
		}
		if ( 'article' === $kind ) {
			return self::article( $stamp, isset( $args['tag'] ) ? sanitize_title( (string) $args['tag'] ) : '' );
		}
		if ( 'blocked' === $kind ) {
			return self::blocked( $stamp );
		}
		if ( 'actions' === $kind ) {
			return self::actions( $stamp, $args );
		}
		if ( 'days' === $kind ) {
			return self::days( $stamp );
		}
		if ( 'draft' === $kind ) {
			return self::draft( $stamp );
		}
		return new WP_Error( 'lld_fixture', 'Unknown fixture.' );
	}

	/**
	 * @param string $stamp Stamp.
	 * @return array<string,mixed>
	 */
	private static function chooser( $stamp ) {
		$visible = array();
		foreach ( array( 'Free one', 'Free two', 'Free three' ) as $name ) {
			$title = $name . ' ' . $stamp;
			$slug  = sanitize_title( $title );
			self::goal( $title, 'free goal', $slug, 0 );
			self::articles( $slug, 5, $title );
			$visible[] = $title;
		}
		$track_title = 'Track ' . $stamp;
		$track_slug  = sanitize_title( $track_title );
		$track_id    = self::post( 'lld_track', $track_title, 'Track body ' . $stamp, array() );
		self::goal( $track_title, 'track goal', $track_slug, $track_id );
		self::articles( $track_slug, 5, $track_title );
		for ( $i = 1; $i <= 7; $i++ ) {
			self::item( 'lld_activity', 'Track activity ' . $i . ' ' . $stamp, $track_slug, array( 'track_id' => $track_id ) );
		}
		$visible[] = $track_title;
		$short     = 'Short ' . $stamp;
		self::goal( $short, 'free goal', sanitize_title( $short ), 0 );
		self::articles( sanitize_title( $short ), 4, $short );
		return array(
			'visible' => $visible,
			'hidden'  => $short,
			'track'   => $track_title,
		);
	}

	/**
	 * @param string $stamp Stamp.
	 * @param int    $count How many activities.
	 * @return array<string,mixed>
	 */
	private static function pool( $stamp, $count ) {
		$title = 'Pool ' . $stamp;
		$slug  = sanitize_title( $title );
		self::goal( $title, 'free goal', $slug, 0 );
		self::articles( $slug, 5, $title );
		$items = array();
		$total = max( 1, min( 12, $count ) );
		for ( $i = 0; $i < $total; $i++ ) {
			$name = 'Idea ' . $i . ' ' . $stamp;
			$items[] = array(
				'id'    => self::item( 'lld_activity', $name, $slug, array() ),
				'title' => $name,
			);
		}
		return array(
			'goal'  => $title,
			'key'   => $slug,
			'tag'   => $slug,
			'items' => $items,
		);
	}

	/**
	 * @param string $stamp Stamp.
	 * @return array<string,mixed>
	 */
	private static function pair( $stamp ) {
		$title = 'Pair ' . $stamp;
		$slug  = sanitize_title( $title );
		self::goal( $title, 'free goal', $slug, 0 );
		self::articles( $slug, 5, $title );
		$first  = 'Similar A ' . $stamp;
		$second = 'Similar B ' . $stamp;
		return array(
			'goal' => $title,
			'key'  => $slug,
			'a'    => array( 'id' => self::item( 'lld_activity', $first, $slug, array() ), 'title' => $first ),
			'b'    => array( 'id' => self::item( 'lld_activity', $second, $slug, array() ), 'title' => $second ),
		);
	}

	/**
	 * @param string $stamp Stamp.
	 * @return array<string,mixed>
	 */
	private static function safety( $stamp ) {
		$tags  = array( 'weight-loss', 'fasting', 'high-intensity', 'high-protein', 'high-salt', 'high-saturated-fat', 'pregnancy-unsafe-food', 'alcohol', 'calorie-mention', 'portion-question' );
		$types = array( 'lld_activity', 'lld_meal', 'lld_workout', 'lld_challenge', 'lld_badge', 'lld_week' );
		$out   = array();
		foreach ( $tags as $tag ) {
			$out[ $tag ] = array();
			foreach ( $types as $type ) {
				$title = $type . ' ' . $tag . ' ' . $stamp;
				$fields = array();
				if ( 'lld_badge' === $type && 'weight-loss' === $tag ) {
					$fields['weight_loss'] = 1;
				}
				$out[ $tag ][ $type ] = array(
					'id'    => self::item( $type, $title, $tag, $fields ),
					'title' => $title,
				);
			}
		}
		return array( 'stamp' => $stamp, 'tags' => $out );
	}

	/**
	 * @param string $stamp Stamp.
	 * @param string $title Goal title.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function live( $stamp, $title ) {
		if ( '' === $title ) {
			return new WP_Error( 'lld_fixture', 'A title is required.' );
		}
		$slug     = sanitize_title( $title . '-' . $stamp );
		$track_id = self::post( 'lld_track', $title, 'Track ' . $stamp, array() );
		self::goal( $title, 'track goal', $slug, $track_id );
		self::articles( $slug, 5, $title );
		for ( $i = 1; $i <= 7; $i++ ) {
			self::item( 'lld_activity', $title . ' activity ' . $i, $slug, array( 'track_id' => $track_id ) );
		}
		return array( 'title' => $title, 'key' => sanitize_title( $title ) );
	}

	/**
	 * @param string $stamp Stamp.
	 * @return array<string,mixed>
	 */
	private static function gap( $stamp ) {
		$title = 'Gap ' . $stamp;
		self::goal( $title, 'free goal', sanitize_title( $title ), 0 );
		self::articles( sanitize_title( $title ), 2, $title );
		return array( 'title' => $title );
	}

	/**
	 * One matching activity that pregnancy excludes.
	 *
	 * @param string $stamp Stamp.
	 * @return array<string,mixed>
	 */
	private static function blocked( $stamp ) {
		$title = 'Blocked ' . $stamp;
		$slug  = sanitize_title( $title );
		self::goal( $title, 'free goal', $slug, 0 );
		self::articles( $slug, 5, $title, 'weight-loss' );
		$name = 'Excluded snack ' . $stamp;
		$id   = self::item( 'lld_activity', $name, 'weight-loss', array() );
		self::ensure_tag( $slug, 'goal' );
		wp_set_object_terms( $id, array( 'weight-loss', $slug ), 'lld_tag' );
		LLD_Members_Content::approve_now( $id );
		return array(
			'goal'  => $title,
			'key'   => $slug,
			'title' => $name,
			'id'    => $id,
		);
	}

	/**
	 * @param string $stamp Stamp.
	 * @param string $tag   Goal tag.
	 * @return array<string,mixed>
	 */
	private static function article( $stamp, $tag ) {
		$title = 'Unreviewed ' . $stamp;
		$id    = self::post( 'post', $title, 'Not reviewed ' . $stamp, array( $tag ) );
		return array( 'id' => $id, 'title' => $title );
	}

	/**
	 * @param string $title    Title.
	 * @param string $kind     free goal or track goal.
	 * @param string $slug     Tag slug.
	 * @param int    $track_id Track id.
	 */
	private static function goal( $title, $kind, $slug, $track_id ) {
		self::ensure_tag( $slug, 'goal' );
		$fields = array( 'kind' => $kind );
		if ( $track_id > 0 ) {
			$fields['track_id'] = $track_id;
		}
		return self::post( 'lld_goal', $title, $title, array( $slug ), $fields );
	}

	/**
	 * @param string $slug  Tag slug.
	 * @param int    $count How many.
	 * @param string $label Title prefix.
	 */
	private static function articles( $slug, $count, $label, $also = '' ) {
		self::ensure_tag( $slug, 'goal' );
		$tags = array( $slug );
		if ( '' !== $also ) {
			self::ensure_tag( $also, 'safety' );
			$tags[] = $also;
		}
		for ( $i = 1; $i <= $count; $i++ ) {
			$id = self::post( 'post', $label . ' article ' . $i, 'Reviewed article ' . $i, $tags );
			update_post_meta( $id, 'lld_review_status', 'approved' );
		}
	}

	/**
	 * @param string              $type   Post type.
	 * @param string              $title  Title.
	 * @param string              $tag    Tag slug.
	 * @param array<string,mixed> $fields Extra fields.
	 */
	private static function item( $type, $title, $tag, $fields ) {
		if ( '' !== $tag ) {
			self::ensure_tag( $tag, 'safety' === $tag || in_array( $tag, array( 'weight-loss', 'fasting', 'high-intensity', 'high-protein', 'high-salt', 'high-saturated-fat', 'pregnancy-unsafe-food', 'alcohol', 'calorie-mention', 'portion-question' ), true ) ? 'safety' : 'goal' );
		}
		$id = self::post( $type, $title, $title . ' body', '' === $tag ? array() : array( $tag ), $fields );
		if ( 'post' !== $type ) {
			LLD_Members_Content::approve_now( $id );
		}
		return $id;
	}

	/**
	 * @param string              $type   Post type.
	 * @param string              $title  Title.
	 * @param string              $body   Body.
	 * @param string[]            $tags   Tag slugs.
	 * @param array<string,mixed> $fields Meta fields.
	 */
	private static function post( $type, $title, $body, $tags, $fields = array() ) {
		$id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $body,
			)
		);
		$id = (int) $id;
		if ( $fields ) {
			update_post_meta( $id, '_lld_fields', $fields );
		}
		if ( $tags ) {
			wp_set_object_terms( $id, $tags, 'lld_tag' );
		}
		return $id;
	}

	/**
	 * @param string              $stamp Stamp.
	 * @param array<string,mixed> $args  Mode: full, thin or empty.
	 * @return array<string,mixed>
	 */
	private static function actions( $stamp, $args ) {
		$mode  = isset( $args['mode'] ) ? sanitize_key( (string) $args['mode'] ) : 'full';
		$slug  = 'act-' . $stamp;
		$track = self::track_post( 'Actions ' . $stamp, $slug );
		$items = array();
		if ( 'empty' === $mode ) {
			for ( $i = 1; $i <= 3; $i++ ) {
				$title   = 'Loss ' . $i . ' ' . $stamp;
				$items[] = array(
					'id'    => self::item( 'lld_activity', $title, 'weight-loss', self::action_fields( $track, 0, 'standard', true ) ),
					'title' => $title,
				);
			}
		} elseif ( 'thin' === $mode ) {
			for ( $i = 1; $i <= 2; $i++ ) {
				$title   = 'Thin ' . $i . ' ' . $stamp;
				$items[] = array(
					'id'    => self::item( 'lld_activity', $title, '', self::action_fields( $track, 0, 'standard', true ) ),
					'title' => $title,
				);
			}
		} else {
			foreach ( array( 'easier' => 4, 'standard' => 4, 'stretch' => 2 ) as $difficulty => $count ) {
				for ( $i = 1; $i <= $count; $i++ ) {
					$title   = ucfirst( $difficulty ) . ' ' . $i . ' ' . $stamp;
					$items[] = array(
						'id'    => self::item( 'lld_activity', $title, '', self::action_fields( $track, 0, $difficulty, true ) ),
						'title' => $title,
					);
				}
			}
		}
		return array(
			'track' => $slug,
			'items' => $items,
		);
	}

	/**
	 * @param string $stamp Stamp.
	 * @return array<string,mixed>
	 */
	private static function days( $stamp ) {
		$slug  = 'day-' . $stamp;
		$track = self::track_post( 'Days ' . $stamp, $slug );
		$items = array();
		for ( $day = 1; $day <= 8; $day++ ) {
			$title   = 'Day ' . $day . ' ' . $stamp;
			$items[] = array(
				'id'    => self::item( 'lld_activity', $title, '', self::action_fields( $track, $day, 'standard', false ) ),
				'title' => $title,
				'body'  => $title . ' body',
				'day'   => $day,
			);
		}
		return array(
			'track' => $slug,
			'items' => $items,
		);
	}

	/**
	 * @param string $stamp Stamp.
	 * @return array<string,mixed>
	 */
	private static function draft( $stamp ) {
		$title = 'Unapproved ' . $stamp;
		return array(
			'id'    => self::post( 'lld_activity', $title, 'Hidden ' . $stamp, array() ),
			'title' => $title,
		);
	}

	/**
	 * @param string $title Title.
	 * @param string $slug  Track slug.
	 */
	private static function track_post( $title, $slug ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'lld_track',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $title,
			)
		);
		LLD_Members_Content::approve_now( (int) $id );
		return (int) $id;
	}

	/**
	 * @param int    $track      Track id.
	 * @param int    $day        Programme day, or 0 for the library.
	 * @param string $difficulty Easier, standard or stretch.
	 * @param bool   $library    Included in the weekly plan.
	 * @return array<string,mixed>
	 */
	private static function action_fields( $track, $day, $difficulty, $library ) {
		return array(
			'belongs'     => $library ? 'library' : 'track',
			'track_id'    => $track,
			'day_number'  => $day,
			'week_number' => $day > 0 ? (int) ceil( $day / 7 ) : 1,
			'difficulty'  => $difficulty,
			'in_library'  => $library ? 1 : 0,
		);
	}

	/**
	 * @param string $slug Tag slug.
	 * @param string $kind Tag kind.
	 */
	private static function ensure_tag( $slug, $kind ) {
		$term = term_exists( $slug, 'lld_tag' );
		if ( ! $term ) {
			$term = wp_insert_term( $slug, 'lld_tag', array( 'slug' => $slug ) );
		}
		if ( is_wp_error( $term ) ) {
			return;
		}
		$id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
		if ( $id && '' === (string) get_term_meta( $id, 'lld_kind', true ) ) {
			update_term_meta( $id, 'lld_kind', $kind );
		}
	}
}
