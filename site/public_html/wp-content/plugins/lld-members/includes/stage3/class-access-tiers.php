<?php
/**
 * Free and Premium access (P3-61; FR3-63, FR3-68).
 *
 * The one place that says what a Free member may use. Every Stage 3 screen and route asks allows() before it
 * opens a Premium feature and shows upgrade_prompt() when the answer is no, so a Free member never meets an
 * error (TC-FR3-63-AS-04). Safety features are never paid (FR3-63, FR3-69): they are always allowed.
 *
 * Downgrade or pause (FR3-68) deletes nothing and changes no stored row: the limits below are worked out each
 * time from the member's access, so upgrading again brings everything back as it was (TC-FR3-68-AS-03).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access rules read by Stage 3 screens, routes and background jobs.
 */
class LLD_Members_Access_Tiers {

	/** Days of history a Free member sees on screen: today and the 13 days before it (FR3-63). */
	const FREE_HISTORY_DAYS = 14;

	/** Reminders that keep running for a Free member (FR3-63: one daily reminder; FR3-68: the first one). */
	const FREE_REMINDERS = 1;

	/**
	 * Premium-only features, each with the short explanation a Free member sees (FR3-63 list).
	 */
	const PREMIUM = array(
		'guided_plan'        => 'The full guided plan, with weekly adjustments, is part of Premium.',
		'track_weeks'        => 'Weeks 2 to 4 and every level of each track are part of Premium.',
		'full_history'       => 'Free shows the last 14 days. Premium shows your full history.',
		'trends'             => 'Long-term trends and insights are part of Premium.',
		'calculator_history' => 'Saved calculator history and comparisons are part of Premium.',
		'barcode'            => 'Barcode scanning is part of Premium.',
		'own_targets'        => 'Setting your own targets is part of Premium.',
		'custom_foods'       => 'Adding your own foods is part of Premium.',
		'copy_meals'         => 'Copying meals between days is part of Premium.',
		'recipes'            => 'Recipes in your food diary are part of Premium.',
		'weekly_summary'     => 'The weekly food summary is part of Premium.',
		'meal_plans'         => 'Meal plans are part of Premium.',
		'workout_plans'      => 'Workout plans are part of Premium.',
		'challenges'         => 'Monthly challenges are part of Premium.',
		'more_reminders'     => 'Free includes one reminder. Premium has as many as you like.',
	);

	/**
	 * Features that are never paid for, whatever the plan (FR3-63, TC-FR3-63-AS-06).
	 */
	const SAFETY = array( 'urgent_care', 'safety_warnings', 'safety_matrix', 'emergency_numbers' );

	/** Members' API types that a Free member may read but not add to (FR3-68: read-only, not deleted). */
	const READ_ONLY_TYPES = array(
		'custom-foods' => 'custom_foods',
		'recipes'      => 'recipes',
	);

	/** Members' API types that are Premium for every method, reading too (FR3-63: barcode scanning, P3-44). */
	const PREMIUM_TYPES = array(
		'food-barcode' => 'barcode',
	);

	public static function boot() {
		add_filter( 'lld_members_stage3_api_refuse', array( __CLASS__, 'api_refuse' ), 20, 4 );
		add_filter( 'lld_members_stage3_api_rows', array( __CLASS__, 'api_rows' ), 20, 3 );
	}

	/**
	 * Whether the Free and Premium split applies. Off, Stage 3 features are not limited by plan.
	 *
	 * @param int $member_id Member number.
	 */
	public static function on( $member_id ) {
		return LLD_Members_Features::enabled( 'access_tiers', (int) $member_id );
	}

	/**
	 * Premium now: paid, in a cancelled paid period or the failed-payment grace period, and not paused (FR3-65).
	 *
	 * @param int $member_id Member number.
	 */
	public static function premium( $member_id ) {
		return LLD_Members_Billing::has_premium( (int) $member_id );
	}

	/**
	 * May this member use the feature? Unknown names are Free features; safety is always allowed.
	 *
	 * @param int    $member_id Member number.
	 * @param string $feature   A key of PREMIUM, SAFETY or any Free feature.
	 */
	public static function allows( $member_id, $feature ) {
		return self::decide( (string) $feature, self::on( $member_id ), self::on( $member_id ) && self::premium( $member_id ) );
	}

	/**
	 * The rule itself, without WordPress, for tests.
	 *
	 * @param string $feature Feature name.
	 * @param bool   $tiers   Whether the split applies.
	 * @param bool   $premium Premium now.
	 */
	public static function decide( $feature, $tiers, $premium ) {
		if ( in_array( $feature, self::SAFETY, true ) || ! isset( self::PREMIUM[ $feature ] ) || ! $tiers ) {
			return true;
		}
		return (bool) $premium;
	}

	/**
	 * The first date shown on screen, or '' for every date. Downloads ignore this (TC-FR3-63-AS-02).
	 *
	 * @param int    $member_id Member number.
	 * @param string $today     Member's local date, Y-m-d.
	 */
	public static function history_from( $member_id, $today ) {
		return self::history_start( $today, self::allows( $member_id, 'full_history' ) );
	}

	/**
	 * @param string $today Local date, Y-m-d.
	 * @param bool   $full  Full history allowed.
	 */
	public static function history_start( $today, $full ) {
		if ( $full || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $today ) ) {
			return '';
		}
		return gmdate( 'Y-m-d', strtotime( $today . ' 00:00:00 UTC' ) - ( self::FREE_HISTORY_DAYS - 1 ) * 86400 );
	}

	/**
	 * Keep only the rows on or after $from. Rows stay stored; only what is shown changes.
	 *
	 * @param array<int,array<string,mixed>> $rows  Rows.
	 * @param string                         $field Date or date-time field.
	 * @param string                         $from  First date shown, or '' for all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function limit_rows( array $rows, $field, $from ) {
		if ( '' === $from ) {
			return $rows;
		}
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $field, $from ) {
					return substr( (string) ( $row[ $field ] ?? '' ), 0, 10 ) >= $from;
				}
			)
		);
	}

	/**
	 * FR3-68: saved calculator history is kept, but a Free member sees only the latest result of each tool.
	 *
	 * @param array<int,array<string,mixed>> $rows Calculator results, any order.
	 * @return array<int,array<string,mixed>>
	 */
	public static function latest_results( array $rows ) {
		$latest = array();
		foreach ( $rows as $row ) {
			$tool = (string) ( $row['tool'] ?? $row['calculator'] ?? '' );
			$when = (string) ( $row['created_at'] ?? '' );
			if ( ! isset( $latest[ $tool ] ) || $when > (string) ( $latest[ $tool ]['created_at'] ?? '' ) || ( $when === (string) ( $latest[ $tool ]['created_at'] ?? '' ) && (int) ( $row['id'] ?? 0 ) > (int) ( $latest[ $tool ]['id'] ?? 0 ) ) ) {
				$latest[ $tool ] = $row;
			}
		}
		return array_values( $latest );
	}

	/**
	 * FR3-68: which reminders run. Premium: every active one. Free: only the first (oldest) active one; the others
	 * are paused, not changed or deleted, and run again after an upgrade (TC-FR3-68-AS-01, AS-03).
	 *
	 * @param array<int,array<string,mixed>> $reminders Reminder rows with id, active and deleted_at.
	 * @param bool                           $all       Every reminder allowed (Premium, or the split is off).
	 * @return int[] Ids that run.
	 */
	public static function running_reminders( array $reminders, $all ) {
		$ids = array();
		foreach ( $reminders as $row ) {
			if ( ! empty( $row['active'] ) && empty( $row['deleted_at'] ) ) {
				$ids[] = (int) $row['id'];
			}
		}
		sort( $ids );
		return $all ? $ids : array_slice( $ids, 0, self::FREE_REMINDERS );
	}

	/**
	 * @param int                            $member_id Member number.
	 * @param array<int,array<string,mixed>> $reminders That member's reminder rows.
	 * @return int[]
	 */
	public static function reminders_for( $member_id, array $reminders ) {
		return self::running_reminders( $reminders, self::allows( $member_id, 'more_reminders' ) );
	}

	/**
	 * A short explanation and an upgrade button, shown where a Free member reaches a Premium feature (AS-04).
	 *
	 * @param string $feature Key of PREMIUM.
	 */
	public static function upgrade_prompt( $feature ) {
		$why = isset( self::PREMIUM[ $feature ] ) ? self::PREMIUM[ $feature ] : 'This is part of Premium.';
		return '<div class="lld-card lld-upgrade" data-lld-upgrade="' . esc_attr( $feature ) . '"><p>' . esc_html( $why ) . '</p>'
			. lld_button(
				array(
					'label' => 'See Premium',
					'href'  => home_url( '/pricing/' ),
				)
			) . '</div>';
	}

	/**
	 * The same answer for the members' API and the app: plan, pause and what is open.
	 *
	 * @param int $member_id Member number.
	 * @return array<string,mixed>
	 */
	public static function summary( $member_id ) {
		$features = array();
		foreach ( array_keys( self::PREMIUM ) as $feature ) {
			$features[ $feature ] = self::allows( $member_id, $feature );
		}
		$full = self::allows( $member_id, 'full_history' );
		return array(
			'plan'         => self::premium( $member_id ) ? 'premium' : 'free',
			'paused'       => class_exists( 'LLD_Members_Pause' ) && LLD_Members_Pause::paused_now( $member_id ),
			'history_days' => $full ? null : self::FREE_HISTORY_DAYS,
			'reminders'    => self::allows( $member_id, 'more_reminders' ) ? null : self::FREE_REMINDERS,
			'features'     => $features,
			'upgrade_url'  => home_url( '/pricing/' ),
		);
	}

	/**
	 * Members' API: a Free member may read their custom foods and recipes but not add or change them (FR3-68).
	 * The refusal carries the explanation and the upgrade link, so the screen shows a prompt, not an error.
	 *
	 * @param WP_REST_Response|null $refusal   An earlier refusal (safety comes first).
	 * @param string                $type      API type.
	 * @param int                   $member_id Member number.
	 * @param WP_REST_Request|null  $request   The request; by default the one the members' API is checking. Its
	 *                                         method, after any _method or override header, is what the route
	 *                                         runs; the server's own method may differ.
	 */
	public static function api_refuse( $refusal, $type, $member_id, $request = null ) {
		if ( null === $refusal && isset( self::PREMIUM_TYPES[ $type ] ) && ! self::allows( $member_id, self::PREMIUM_TYPES[ $type ] ) ) {
			return self::upgrade_refusal( self::PREMIUM_TYPES[ $type ] );
		}
		if ( null !== $refusal || ! isset( self::READ_ONLY_TYPES[ $type ] ) ) {
			return $refusal;
		}
		if ( null === $request && class_exists( 'LLD_Members_Stage3_API' ) ) {
			$request = LLD_Members_Stage3_API::$request;
		}
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_method' ) ) {
			// Without the request the method cannot be trusted: treat it as a change.
			$method = 'POST';
		} else {
			$method = strtoupper( (string) $request->get_method() );
		}
		if ( in_array( $method, array( 'GET', 'HEAD', 'DELETE' ), true ) ) {
			// Reading is open, and deleting your own item is always allowed.
			return $refusal;
		}
		$feature = self::READ_ONLY_TYPES[ $type ];
		if ( self::allows( $member_id, $feature ) ) {
			return $refusal;
		}
		return self::upgrade_refusal( $feature );
	}

	/**
	 * The 403 a screen turns into an upgrade prompt: the explanation, the feature and the upgrade link.
	 *
	 * @param string $feature Key of PREMIUM.
	 */
	private static function upgrade_refusal( $feature ) {
		return new WP_REST_Response(
			array(
				'message'     => self::PREMIUM[ $feature ],
				'upgrade'     => true,
				'feature'     => $feature,
				'upgrade_url' => home_url( '/pricing/' ),
			),
			403
		);
	}

	/**
	 * Members' API: what a Free member sees on screen. Health readings show 14 days; calculator results show only
	 * the latest of each tool. The food log applies its own 14 days in LLD_Members_Food_Log.
	 *
	 * @param array<int,array<string,mixed>> $rows      Rows.
	 * @param string                         $type      API type.
	 * @param int                            $member_id Member number.
	 */
	public static function api_rows( $rows, $type, $member_id ) {
		if ( ! is_array( $rows ) || ! self::on( $member_id ) ) {
			return $rows;
		}
		if ( 'readings' === $type ) {
			return self::limit_rows( $rows, 'taken_at', self::history_from( $member_id, LLD_Members_Programme::local_today( $member_id ) ) );
		}
		if ( 'calculator-results' === $type && ! self::allows( $member_id, 'calculator_history' ) ) {
			// The list route sends the P3-5B view (rows, latest_only, upgrade_text …), which already shows Free the
			// latest only: limit its rows, never flatten the view itself.
			if ( isset( $rows['rows'] ) && is_array( $rows['rows'] ) ) {
				$rows['rows'] = self::latest_results( $rows['rows'] );
				return $rows;
			}
			return self::latest_results( $rows );
		}
		return $rows;
	}
}
