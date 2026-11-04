<?php
/**
 * Health consent for Stage 3 health data (P3-86, FR3-59). Before the first health log the member must
 * have given health consent; without it nothing is saved. Withdrawal deletes Stage 3 health rows
 * through LLD_Members_Stage3_Erasure (already hooked) and records the date via Stage 2 consent lines.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consent checks and the short notice screens/API read before the first log.
 */
class LLD_Members_Stage3_Consent {

	/**
	 * Member-facing lines. Not clinical advice — consent UX only.
	 */
	const MESSAGES = array(
		'ask'       => 'Before you log blood pressure, glucose or other health readings, please agree to storing that information in your account. You can withdraw this at any time.',
		'needed'    => 'Health consent is needed first.',
		'refused'   => 'Nothing was saved.',
		'withdrawn' => 'Health consent withdrawn. Your health logs have been deleted.',
	);

	public static function boot() {
		// No extra hooks: create/list already refuse without consent (API health => true).
		// Withdrawal is lld_members_withdraw_health → Stage3_Erasure::withdraw_health.
	}

	/**
	 * Whether the member may store health logs right now.
	 *
	 * @param int $member_id Member number.
	 */
	public static function allows_health_log( $member_id ) {
		if ( class_exists( 'LLD_Members_Access' ) ) {
			return (bool) LLD_Members_Access::has_health_consent( (int) $member_id );
		}
		if ( class_exists( 'LLD_Members_Account' ) ) {
			return (bool) LLD_Members_Account::has_consent( (int) $member_id, 'health' );
		}
		return false;
	}

	/**
	 * What to show / return when consent is missing (FR3-59 AS-01, AS-02).
	 *
	 * @param int $member_id Member number.
	 * @return array{allowed:bool,ask:string,message:string}
	 */
	public static function before_log( $member_id ) {
		$ok = self::allows_health_log( $member_id );
		return array(
			'allowed' => $ok,
			'ask'     => self::MESSAGES['ask'],
			'message' => $ok ? '' : self::MESSAGES['needed'],
		);
	}

	/**
	 * Gate used by Health_Log / API: null when allowed, error array when not.
	 *
	 * @param int $member_id Member number.
	 * @return array{error:string,status:int}|null
	 */
	public static function gate_store( $member_id ) {
		if ( self::allows_health_log( $member_id ) ) {
			return null;
		}
		return array(
			'error'  => self::MESSAGES['needed'],
			'status' => 403,
			'ask'    => self::MESSAGES['ask'],
		);
	}
}
