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
	 * At most one active track enrolment per member.
	 *
	 * @param int $member_id Member number.
	 */
	public static function one_active_enrolment( $member_id ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'enrolment' );
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE member_id = %d AND status = %s", $member_id, 'active' ) );
		if ( $found > 0 ) {
			return new WP_Error( 'lld_enrolment', 'This member already has an active track.' );
		}
		return true;
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
	 * At most one active workout plan per member.
	 *
	 * @param int $member_id Member number.
	 */
	public static function one_active_workout( $member_id ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'workout' );
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE member_id = %d AND active = 1", $member_id ) );
		if ( $found > 0 ) {
			return new WP_Error( 'lld_workout', 'This member already has an active workout plan.' );
		}
		return true;
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
