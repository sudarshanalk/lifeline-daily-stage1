<?php
/**
 * Stage 3 content behind the content_new switch (P3-73 to P3-77; FR3-19 to FR3-23; NFR3-06).
 *
 * The approved Stage 3 drafts (Heart Health, Keep It Off, the two level 2 tracks, the 2027 monthly challenges, the
 * Heart Health safety check and the goal, plan, check-in, insight and safety wording) are loaded into the same
 * content library as the Stage 2 items, each with the post meta `_lld_stage` = 3 that the bundle's "stage" field
 * sets. This class is the one place that keeps them from members: every library query the Stage 2 classes run
 * (goal choice, the programmes page, the action library, the monthly challenge, wording look-ups) goes through
 * WordPress's `pre_get_posts`, where a `NOT EXISTS _lld_stage` clause is added unless the content_new switch
 * allows the member. With the switch off, every query returns exactly what it returned before the Stage 3 items
 * were loaded, so Stage 2 members see no new track, goal, challenge or wording (Build Method Stage 3 §1 rule 5).
 *
 * An item asked for by its number (`/members/library/<id>/`, `/members/read/<id>/`, with or without `?download=1`)
 * is read with get_post(), not WP_Query, so `pre_get_posts` never runs for it: `template_redirect` (before the Stage 2
 * screens dispatch) answers the same "This page isn't available" 404 the library gives an unapproved item when the
 * item is Stage 3 and the switch does not allow the member.
 *
 * The owner's admin screens (the library, the approvals queue) and the content loader (`lld_all_stages` query
 * var) always see every stage.
 *
 * The one path not covered: LLD_Members_Personal::safe_titles_limited() reads lld_meal and lld_recipe rows with
 * its own SQL. No Stage 3 bundle carries a meal or a recipe (P3-73 to P3-77 add tracks, challenges and wording), so
 * nothing leaks; when a Stage 3 bundle first carries one, that query gains the same exclusion (a declared Stage 2
 * change, lead review).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hides `_lld_stage` content from members the content_new switch does not allow.
 */
class LLD_Members_Stage3_Content {

	const FEATURE  = 'content_new';
	const META     = '_lld_stage';
	const ALL_VAR  = 'lld_all_stages';
	const TAG_SEED = 'lld_stage3_tag_seed';

	/**
	 * Goal tags the Stage 3 tracks use (the track slug is a goal tag, as in Stage 2). Seeded with the managed
	 * tags so the loader accepts the bundle; a tag is never shown to members on its own.
	 */
	const GOAL_TAGS = array( 'heart-health', 'keep-it-off', 'healthy-weight-2', 'blood-sugar-2' );

	/**
	 * Content types the library holds, when the Stage 2 class is not loaded (tests).
	 */
	const TYPES = array(
		'lld_goal',
		'lld_track',
		'lld_week',
		'lld_lesson',
		'lld_activity',
		'lld_challenge',
		'lld_recipe',
		'lld_meal',
		'lld_workout',
		'lld_rule',
		'lld_badge',
		'lld_matrix',
		'lld_wording',
		'lld_qa',
	);

	/**
	 * Answer of hidden() for this request, once worked out.
	 *
	 * @var bool|null
	 */
	private static $hidden = null;

	public static function boot() {
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_new_content' ) );
		// Priority 5: before LLD_Members_Screens::dispatch (10) renders an item by its number.
		add_action( 'template_redirect', array( __CLASS__, 'guard_item' ), 5 );
		add_action( 'init', array( __CLASS__, 'seed_tags' ), 20 );
	}

	/**
	 * `template_redirect`: a request for a Stage 3 item by number, from a signed-in member the switch does not allow,
	 * gets the library's 404 page. A visitor is left to the Stage 2 screen, which sends them to sign in as before.
	 */
	public static function guard_item() {
		$item = (int) get_query_var( 'lld_item' );
		if ( $item < 1 || ! class_exists( 'LLD_Members_Access' ) || LLD_Members_Access::current_member_id() < 1 ) {
			return;
		}
		if ( ! self::item_hidden( $item ) ) {
			return;
		}
		lld_members_layout(
			array(
				'title'     => "This page isn't available",
				'main'      => lld_state_not_allowed(),
				'signed_in' => true,
				'status'    => 404,
			)
		);
		exit;
	}

	/**
	 * True when this item is Stage 3 content the switch does not allow the member to see.
	 *
	 * @param int $item_id   Content item (working copy) number.
	 * @param int $member_id Member number; 0 for the signed-in member.
	 */
	public static function item_hidden( $item_id, $member_id = 0 ) {
		if ( '3' !== (string) get_post_meta( (int) $item_id, self::META, true ) ) {
			return false;
		}
		return self::hidden( $member_id );
	}

	/**
	 * True when Stage 3 content must stay hidden from the member of this request.
	 *
	 * @param int $member_id Member number; 0 for the signed-in member.
	 */
	public static function hidden( $member_id = 0 ) {
		if ( $member_id > 0 ) {
			return ! LLD_Members_Features::enabled( self::FEATURE, (int) $member_id );
		}
		if ( null === self::$hidden ) {
			self::$hidden = ! LLD_Members_Features::enabled( self::FEATURE, 0 );
		}
		return self::$hidden;
	}

	/**
	 * Forget the cached answer (after a switch or sign-in change in the same request; tests).
	 */
	public static function reset() {
		self::$hidden = null;
	}

	/**
	 * `pre_get_posts`: add the exclusion to a library query when it applies.
	 *
	 * @param WP_Query $query The query about to run.
	 */
	public static function hide_new_content( $query ) {
		if ( ! is_object( $query ) || ! isset( $query->query_vars ) || ! is_array( $query->query_vars ) ) {
			return;
		}
		if ( ! self::applies( $query->query_vars, is_admin() ) ) {
			return;
		}
		if ( ! self::hidden() ) {
			return;
		}
		$query->query_vars = self::filter_vars( $query->query_vars );
	}

	/**
	 * Whether a query is a member-facing library query (pure; no database).
	 *
	 * @param array<string,mixed> $vars     Query vars.
	 * @param bool                $in_admin True on the owner's admin screens.
	 */
	public static function applies( array $vars, $in_admin ) {
		if ( $in_admin || ! empty( $vars[ self::ALL_VAR ] ) ) {
			return false;
		}
		$types = isset( $vars['post_type'] ) ? (array) $vars['post_type'] : array();
		if ( ! $types || in_array( 'any', $types, true ) ) {
			return false;
		}
		$library = class_exists( 'LLD_Members_Content' ) ? LLD_Members_Content::types() : self::TYPES;
		foreach ( $types as $type ) {
			if ( ! in_array( (string) $type, $library, true ) ) {
				return false;
			}
		}
		// Version rows are reached through their working copy, which is already filtered.
		$status = isset( $vars['post_status'] ) ? (array) $vars['post_status'] : array();
		if ( array( 'lld_version' ) === array_values( $status ) ) {
			return false;
		}
		return true;
	}

	/**
	 * The query vars with the Stage 3 exclusion (pure). Any existing meta_key/meta_value or meta_query is kept.
	 *
	 * @param array<string,mixed> $vars Query vars.
	 * @return array<string,mixed>
	 */
	public static function filter_vars( array $vars ) {
		$clause = array(
			'key'     => self::META,
			'compare' => 'NOT EXISTS',
		);
		$meta   = isset( $vars['meta_query'] ) && is_array( $vars['meta_query'] ) ? $vars['meta_query'] : array();
		if ( $meta ) {
			$vars['meta_query'] = array(
				'relation' => 'AND',
				$meta,
				$clause,
			);
		} else {
			$vars['meta_query'] = array( $clause );
		}
		return $vars;
	}

	/**
	 * Apply the exclusion to a list of posts in PHP (for code that lists posts it already holds; tests).
	 *
	 * @param array<int,WP_Post|int> $posts     Posts or ids.
	 * @param int                    $member_id Member number; 0 for the signed-in member.
	 * @return array<int,WP_Post|int>
	 */
	public static function visible( array $posts, $member_id = 0 ) {
		if ( ! self::hidden( $member_id ) ) {
			return $posts;
		}
		return array_values(
			array_filter(
				$posts,
				static function ( $post ) {
					$id = is_object( $post ) ? (int) $post->ID : (int) $post;
					return '' === (string) get_post_meta( $id, self::META, true );
				}
			)
		);
	}

	/**
	 * The Stage 3 goal tags exist with kind "goal", so the loader accepts the bundle. Runs once.
	 */
	public static function seed_tags() {
		if ( '1' === (string) get_option( self::TAG_SEED ) || ! taxonomy_exists( 'lld_tag' ) ) {
			return;
		}
		foreach ( self::GOAL_TAGS as $name ) {
			$term = term_exists( $name, 'lld_tag' );
			if ( ! $term ) {
				$term = wp_insert_term( $name, 'lld_tag', array( 'slug' => $name ) );
			}
			if ( is_wp_error( $term ) ) {
				return;
			}
			$id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
			if ( '' === (string) get_term_meta( $id, 'lld_kind', true ) ) {
				update_term_meta( $id, 'lld_kind', 'goal' );
			}
		}
		update_option( self::TAG_SEED, '1', true );
	}
}
