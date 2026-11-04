<?php
/**
 * Shareable progress summary (P3-85, FR3-57). Health numbers only when the member ticks the box.
 * Deleting the share (soft) stops the public link.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build, present and resolve shared summaries.
 */
class LLD_Members_Shares {

	/**
	 * Fixed template fragments. No health numbers unless include_health is true and values are passed in.
	 */
	const MESSAGES = array(
		'title'            => 'My Lifeline Daily progress',
		'days_active'      => 'Days active: {n}',
		'challenges'       => 'Challenges finished: {n}',
		'workouts'         => 'Workouts logged: {n}',
		'weight_change'    => 'Weight change: {change}',
		'no_health'        => 'Health numbers are not included.',
		'gone'             => 'This shared summary is no longer available.',
		'hidden.weight'    => 'Not available.',
	);

	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_public' ) );
	}

	/**
	 * Public GET /lld-members/v1/share/{token} — no auth; soft-deleted shares 404.
	 */
	public static function register_public() {
		register_rest_route(
			'lld-members/v1',
			'/share/(?P<token>[a-f0-9]{16,64})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'public_get' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function public_get( $request ) {
		$token = (string) $request['token'];
		$view  = self::resolve( $token );
		if ( null === $view ) {
			return new WP_REST_Response( array( 'message' => self::MESSAGES['gone'] ), 404 );
		}
		return new WP_REST_Response( $view, 200 );
	}

	/**
	 * Build the content blob stored on create (frozen at make time).
	 *
	 * @param array{days_active?:int,challenges?:int,workouts?:int,weight_change?:string|null} $stats Counts.
	 * @param bool                                                                              $include_health Tick.
	 * @param array<string,bool>                                                                $flags Safety.
	 * @return array{include_health:bool,lines:string[],text:string,has_health_numbers:bool}
	 */
	public static function build( array $stats, $include_health, array $flags = array() ) {
		$include_health = (bool) $include_health;
		if ( ! empty( $flags['eating_disorder'] ) || ! empty( $flags['hide_weight'] ) ) {
			$include_health = false;
		}
		$lines   = array( self::MESSAGES['title'] );
		$lines[] = str_replace( '{n}', (string) (int) ( $stats['days_active'] ?? 0 ), self::MESSAGES['days_active'] );
		$lines[] = str_replace( '{n}', (string) (int) ( $stats['challenges'] ?? 0 ), self::MESSAGES['challenges'] );
		$lines[] = str_replace( '{n}', (string) (int) ( $stats['workouts'] ?? 0 ), self::MESSAGES['workouts'] );
		$has     = false;
		if ( $include_health && isset( $stats['weight_change'] ) && null !== $stats['weight_change'] && '' !== (string) $stats['weight_change'] ) {
			$lines[] = str_replace( '{change}', (string) $stats['weight_change'], self::MESSAGES['weight_change'] );
			$has     = true;
		} else {
			$lines[] = self::MESSAGES['no_health'];
		}
		return array(
			'include_health'      => $include_health && $has,
			'lines'               => $lines,
			'text'                => implode( "\n", $lines ),
			'has_health_numbers'  => $has,
		);
	}

	/**
	 * True when the text has no weight / BP / glucose style numbers (default summary).
	 *
	 * @param string $text Summary text.
	 */
	public static function text_has_health_numbers( $text ) {
		if ( preg_match( '/\b(\d+(\.\d+)?)\s*(kg|lb|mmHg|mmol|BMI)\b/i', $text ) ) {
			return true;
		}
		if ( preg_match( '/\b(systolic|diastolic|glucose|blood pressure)\b/i', $text ) ) {
			return true;
		}
		if ( preg_match( '/Weight change:/i', $text ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Prepare a create payload: compute content from member stats when the client did not send it.
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $params    Request body.
	 * @param array<string,bool>  $flags     Safety flags.
	 * @return array<string,mixed>|array{error:string,status?:int}
	 */
	public static function for_save( $member_id, array $params, array $flags = array() ) {
		$include = ! empty( $params['include_health'] );
		if ( ! empty( $flags['eating_disorder'] ) && $include ) {
			return array( 'error' => self::MESSAGES['hidden.weight'], 'status' => 403 );
		}
		if ( empty( $params['content'] ) ) {
			$built             = self::build( self::stats_for( $member_id ), $include, $flags );
			$params['content'] = $built['text'];
		}
		$params['include_health'] = $include ? 1 : 0;
		return $params;
	}

	/**
	 * Resolve a public token to the frozen content, or null when missing/deleted.
	 *
	 * @param string $token Share token.
	 * @return array<string,mixed>|null
	 */
	public static function resolve( $token ) {
		global $wpdb;
		$token = (string) $token;
		if ( strlen( $token ) < 16 ) {
			return null;
		}
		$table = LLD_Members_Stage3_Schema::table( 'shares' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) || ! empty( $row['deleted_at'] ) ) {
			return null;
		}
		return array(
			'token'          => $token,
			'include_health' => (int) $row['include_health'],
			'content'        => (string) $row['content'],
			'created_at'     => (string) $row['created_at'],
		);
	}

	/**
	 * Collect non-health activity counts for the member (Stage 2 result / challenge tables).
	 *
	 * @param int $member_id Member number.
	 * @return array{days_active:int,challenges:int,workouts:int,weight_change:?string}
	 */
	public static function stats_for( $member_id ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$out       = array(
			'days_active'    => 0,
			'challenges'     => 0,
			'workouts'       => 0,
			'weight_change'  => null,
		);
		if ( $member_id < 1 ) {
			return $out;
		}
		$result = LLD_Members_Schema::table( 'result' );
		$out['days_active'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT local_date) FROM {$result} WHERE member_id = %d", $member_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out['workouts']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$result} WHERE member_id = %d AND context_type = %s", $member_id, 'workout' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$challenge = LLD_Members_Schema::table( 'challenge' );
		$out['challenges'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$challenge} WHERE member_id = %d AND finished_on IS NOT NULL", $member_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// weight_change stays null here; screens that tick include_health pass a pre-computed change into build().
		// Default shares stay free of health numbers (FR3-57 AS-01).
		return $out;
	}
}
