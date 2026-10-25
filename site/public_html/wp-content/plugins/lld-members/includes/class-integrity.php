<?php
/**
 * Integrity rules from Data Model T1. Called before a record is written.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses records that would break a T1 rule.
 */
class LLD_Members_Integrity {

	/**
	 * Age is 18 or over.
	 *
	 * @param int $age Age in years.
	 */
	public static function age( $age ) {
		if ( (int) $age < 18 ) {
			return new WP_Error( 'lld_age', 'Lifeline Daily is for adults aged 18 and over.' );
		}
		return true;
	}

	/**
	 * Insert an active enrolment. The unique active-member key makes a second one fail.
	 *
	 * @param int    $member_id Member number.
	 * @param string $track_key Track key.
	 * @return int|WP_Error New row id.
	 */
	public static function insert_active_enrolment( $member_id, $track_key ) {
		global $wpdb;
		$ok = $wpdb->insert(
			LLD_Members_Schema::table( 'enrolment' ),
			array(
				'member_id' => (int) $member_id,
				'track_key' => $track_key,
				'status'    => 'active',
			),
			array( '%d', '%s', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'lld_enrolment', 'This member already has an active track.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * "None declared" cannot be combined with a condition.
	 *
	 * @param string $answered   not_answered, none_declared or conditions_declared.
	 * @param array  $conditions Condition keys.
	 */
	public static function conditions( $answered, $conditions ) {
		$conditions = array_values( array_filter( (array) $conditions ) );
		if ( 'none_declared' === $answered && $conditions ) {
			return new WP_Error( 'lld_profile', '"None declared" cannot be combined with a condition.' );
		}
		if ( 'conditions_declared' === $answered && ! $conditions ) {
			return new WP_Error( 'lld_profile', 'Add the conditions, or choose none declared.' );
		}
		return true;
	}

	/**
	 * Insert an active workout. The unique active-member key makes a second one fail.
	 *
	 * @param int    $member_id Member number.
	 * @param string $level     Level.
	 * @return int|WP_Error New row id.
	 */
	public static function insert_active_workout( $member_id, $level ) {
		global $wpdb;
		$ok = $wpdb->insert(
			LLD_Members_Schema::table( 'workout' ),
			array(
				'member_id'  => (int) $member_id,
				'level'      => $level,
				'version_key'=> 'test',
				'started_on' => gmdate( 'Y-m-d' ),
				'active'     => 1,
			),
			array( '%d', '%s', '%s', '%s', '%d' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'lld_workout', 'This member already has an active workout plan.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Add one goal. Order is 1, 2 or 3, and the database rejects a duplicate goal or order.
	 *
	 * @param int    $member_id Member number.
	 * @param string $goal_key  Goal key.
	 * @param int    $order     1, 2 or 3.
	 * @return int|WP_Error
	 */
	public static function insert_goal( $member_id, $goal_key, $order ) {
		$order = (int) $order;
		if ( $order < 1 || $order > 3 ) {
			return new WP_Error( 'lld_goal', 'Goal order is 1, 2 or 3.' );
		}
		global $wpdb;
		$ok = $wpdb->insert(
			LLD_Members_Schema::table( 'goal_choice' ),
			array(
				'member_id'  => (int) $member_id,
				'goal_key'   => $goal_key,
				'sort_order' => $order,
				'chosen_on'  => gmdate( 'Y-m-d' ),
			),
			array( '%d', '%s', '%d', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'lld_goal', 'That goal or order is already used.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a check-in. Unique keys enforce one per enrolment week and one per month.
	 *
	 * @param array $row Column values.
	 * @return int|WP_Error
	 */
	public static function insert_check_in( $row ) {
		global $wpdb;
		$ok = $wpdb->insert( LLD_Members_Schema::table( 'check_in' ), $row );
		if ( false === $ok ) {
			return new WP_Error( 'lld_check_in', 'That check-in already exists.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Open an enrolment period. The database rejects an overlapping period for that member.
	 *
	 * @param int    $enrolment_id Enrolment id.
	 * @param string $first_on     First local date.
	 * @param string $last_on      Last local date, or empty while the period is open.
	 * @return int|WP_Error
	 */
	public static function insert_period( $enrolment_id, $first_on, $last_on = '' ) {
		global $wpdb;
		$ok = $wpdb->insert(
			LLD_Members_Schema::table( 'enrolment_period' ),
			array(
				'enrolment_id' => (int) $enrolment_id,
				'first_on'     => $first_on,
				'last_on'      => '' === $last_on ? null : $last_on,
			),
			array( '%d', '%s', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'lld_period', 'Active periods cannot overlap.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * A qualified referral creates exactly two rewards, in one transaction.
	 *
	 * @param int $referral_id Referral id.
	 * @param int $referrer    Referrer member id.
	 * @param int $friend      Friend member id.
	 * @return true|WP_Error
	 */
	public static function create_referral_rewards( $referral_id, $referrer, $friend ) {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$pair = array(
			array( (int) $referrer, 'referrer-' . (int) $referral_id ),
			array( (int) $friend, 'friend-' . (int) $referral_id ),
		);
		foreach ( $pair as $item ) {
			$ok = $wpdb->insert(
				LLD_Members_Schema::table( 'reward' ),
				array(
					'referral_id'          => (int) $referral_id,
					'recipient_member_id'  => $item[0],
					'form'                 => 'credit',
					'operation_key'        => $item[1],
					'status'               => 'pending',
				)
			);
			if ( false === $ok ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'lld_reward', 'A referral has exactly two rewards.' );
			}
		}
		$wpdb->query( 'COMMIT' );
		return true;
	}

	/**
	 * Run every T1 rule against temporary rows and remove those rows.
	 *
	 * @return array<string,bool>
	 */
	public static function exercise() {
		global $wpdb;
		$member = 900001;
		$results = array(
			'age'        => is_wp_error( self::age( 17 ) ) && true === self::age( 40 ),
			'conditions' => is_wp_error( self::conditions( 'none_declared', array( 'pregnancy' ) ) ) && true === self::conditions( 'none_declared', array() ),
			'plan'       => is_wp_error( self::plan_actions( array( 'a' ) ) ) && true === self::plan_actions( array( 'a', 'b', 'c' ) ),
		);
		$first = self::insert_goal( $member, 'sleep', 1 );
		$again = self::insert_goal( $member, 'sleep', 2 );
		$fourth = self::insert_goal( $member, 'weight', 4 );
		$results['goals'] = ! is_wp_error( $first ) && is_wp_error( $again ) && is_wp_error( $fourth );
		$wpdb->delete( LLD_Members_Schema::table( 'goal_choice' ), array( 'member_id' => $member ), array( '%d' ) );

		$enrol = self::insert_active_enrolment( $member, 'sleep' );
		$second = self::insert_active_enrolment( $member, 'weight' );
		$results['one_active_enrolment'] = ! is_wp_error( $enrol ) && is_wp_error( $second );
		$work = self::insert_active_workout( $member, 'beginner' );
		$work2 = self::insert_active_workout( $member, 'next' );
		$results['one_active_workout'] = ! is_wp_error( $work ) && is_wp_error( $work2 );

		$week = array(
			'member_id'    => $member,
			'enrolment_id' => (int) $enrol,
			'week_number'  => 1,
			'local_date'   => gmdate( 'Y-m-d' ),
		);
		$check = self::insert_check_in( $week );
		$check2 = self::insert_check_in( $week );
		$results['check_in_week'] = ! is_wp_error( $check ) && is_wp_error( $check2 );
		$month = array(
			'member_id'  => $member,
			'month_key'  => gmdate( 'Y-m' ),
			'local_date' => gmdate( 'Y-m-d' ),
		);
		$m1 = self::insert_check_in( $month );
		$m2 = self::insert_check_in( $month );
		$results['check_in_month'] = ! is_wp_error( $m1 ) && is_wp_error( $m2 );

		$period = self::insert_period( (int) $enrol, '2026-01-01', '2026-01-28' );
		$overlap = self::insert_period( (int) $enrol, '2026-01-10', '2026-02-01' );
		$results['periods'] = ! is_wp_error( $period ) && is_wp_error( $overlap );

		$consent = LLD_Members_Schema::table( 'consent' );
		$wpdb->insert(
			$consent,
			array(
				'member_id'    => $member,
				'consent_type' => 'health',
				'change_kind'  => 'given',
				'occurred_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$consent_id = (int) $wpdb->insert_id;
		$edited = $wpdb->update( $consent, array( 'change_kind' => 'withdrawn' ), array( 'id' => $consent_id ) );
		$results['consent'] = false === $edited;

		$ref = $wpdb->insert(
			LLD_Members_Schema::table( 'referral' ),
			array(
				'referrer_member_id' => $member,
				'code'               => 't1',
				'friend_member_id'   => 900002,
				'status'             => 'qualified',
			)
		);
		$referral_id = (int) $wpdb->insert_id;
		$pair = self::create_referral_rewards( $referral_id, $member, 900002 );
		$third = $wpdb->insert(
			LLD_Members_Schema::table( 'reward' ),
			array(
				'referral_id'         => $referral_id,
				'recipient_member_id' => $member,
				'form'                => 'credit',
				'operation_key'       => 'extra-' . $referral_id,
				'status'              => 'pending',
			)
		);
		$dup_friend = $wpdb->insert(
			LLD_Members_Schema::table( 'referral' ),
			array(
				'referrer_member_id' => $member,
				'code'               => 't2',
				'friend_member_id'   => 900002,
				'status'             => 'qualified',
			)
		);
		$results['rewards'] = false !== $ref && true === $pair && false === $third && false === $dup_friend;

		$wpdb->delete( LLD_Members_Schema::table( 'reward' ), array( 'referral_id' => $referral_id ), array( '%d' ) );
		$wpdb->delete( LLD_Members_Schema::table( 'referral' ), array( 'id' => $referral_id ), array( '%d' ) );
		$wpdb->delete( LLD_Members_Schema::table( 'check_in' ), array( 'member_id' => $member ), array( '%d' ) );
		$wpdb->delete( LLD_Members_Schema::table( 'enrolment_period' ), array( 'enrolment_id' => (int) $enrol ), array( '%d' ) );
		$wpdb->delete( LLD_Members_Schema::table( 'workout' ), array( 'member_id' => $member ), array( '%d' ) );
		$wpdb->delete( LLD_Members_Schema::table( 'enrolment' ), array( 'member_id' => $member ), array( '%d' ) );
		return $results;
	}

	/**
	 * A weekly plan has 3 to 5 actions.
	 *
	 * @param array $actions Action version keys.
	 */
	public static function plan_actions( $actions ) {
		$count = count( (array) $actions );
		if ( $count < 3 || $count > 5 ) {
			return new WP_Error( 'lld_plan', 'A weekly plan has 3 to 5 actions.' );
		}
		return true;
	}
}
