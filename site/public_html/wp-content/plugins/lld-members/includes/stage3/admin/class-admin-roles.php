<?php
/**
 * Owner and Support admin roles (P3-87; FR3-71; data model: Admin role).
 *
 * The Owner is the site administrator (manage_options). A Support helper has the lld_support role: they can find
 * members, see account details, resend account emails, add notes, and pause or resume a membership. Every action
 * checks the role on the server (can()), not only by hiding buttons. Only the Owner adds or removes helpers.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Roles, capabilities and the Owner's helpers list.
 */
class LLD_Members_Admin_Roles {

	const ROLE = 'lld_support';

	/** The one capability that opens the panel; both roles have it. */
	const PANEL = 'lld_admin_panel';

	/** What Support may do (FR3-71). Everything else is the Owner's. */
	const SUPPORT_ACTIONS = array( 'view', 'search', 'note', 'resend', 'pause', 'resume' );

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'ensure_role' ), 5 );
		add_filter( 'user_has_cap', array( __CLASS__, 'owner_caps' ), 10, 4 );
	}

	/**
	 * The Support role, created once and kept to its capabilities.
	 */
	public static function ensure_role() {
		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, 'Lifeline Daily Support', array( 'read' => true, self::PANEL => true ) );
			return;
		}
		if ( empty( $role->capabilities[ self::PANEL ] ) ) {
			$role->add_cap( self::PANEL );
		}
	}

	/**
	 * The Owner (manage_options) holds the panel capability too.
	 *
	 * @param array<string,bool> $allcaps Capabilities.
	 * @param string[]           $caps    Required.
	 * @param array              $args    Arguments.
	 * @param WP_User            $user    User.
	 * @return array<string,bool>
	 */
	public static function owner_caps( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args, $user );
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::PANEL ] = true;
		}
		return $allcaps;
	}

	/**
	 * True for the Owner.
	 *
	 * @param int $user_id User; 0 for the current one.
	 */
	public static function is_owner( $user_id = 0 ) {
		return $user_id > 0 ? user_can( (int) $user_id, 'manage_options' ) : current_user_can( 'manage_options' );
	}

	/**
	 * True for a Support helper who is not also the Owner.
	 *
	 * @param int|WP_User $user User or id; 0 for the current one.
	 */
	public static function is_support( $user = 0 ) {
		$user = $user instanceof WP_User ? $user : ( $user ? get_userdata( (int) $user ) : wp_get_current_user() );
		if ( ! $user || empty( $user->ID ) ) {
			return false;
		}
		return in_array( self::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' );
	}

	/**
	 * True for either admin role.
	 *
	 * @param int|WP_User $user User or id.
	 */
	public static function is_admin_user( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		return $user && ( user_can( $user, 'manage_options' ) || in_array( self::ROLE, (array) $user->roles, true ) );
	}

	/**
	 * May the current admin do this member action? Checked on the server for every request (FR3-71 AS-02).
	 *
	 * @param string $action view, search, note, resend, pause, resume, or any Owner action.
	 */
	public static function can( $action ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return current_user_can( self::PANEL ) && in_array( $action, self::SUPPORT_ACTIONS, true );
	}

	/**
	 * Support helpers, newest first.
	 *
	 * @return WP_User[]
	 */
	public static function helpers() {
		return get_users(
			array(
				'role'    => self::ROLE,
				'orderby' => 'registered',
				'order'   => 'DESC',
			)
		);
	}

	/**
	 * Add a helper: a new account of their own, or an existing non-admin account given the role (FR3-71 AS-03).
	 * The helper sets their own password from the emailed link. The Owner is alerted (NFR3-21).
	 *
	 * @param string $email  Helper's email.
	 * @param string $name   Helper's name.
	 * @param string $reason Why.
	 * @return int|WP_Error User id.
	 */
	public static function add_helper( $email, $name, $reason ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'lld_admin_role', 'Only the Owner can add a Support helper.' );
		}
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'lld_admin_helper', 'Enter the helper\'s email address.' );
		}
		$user = get_user_by( 'email', $email );
		if ( $user && user_can( $user, 'manage_options' ) ) {
			return new WP_Error( 'lld_admin_helper', 'That account is already the Owner.' );
		}
		global $wpdb;
		if ( $user && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user->ID ) ) ) {
			// One person per account, and a member's account is theirs: a helper gets an account of their own.
			return new WP_Error( 'lld_admin_helper', 'That email belongs to a member. Each helper needs their own separate account and email.' );
		}
		if ( $user ) {
			$user->set_role( self::ROLE );
			$user_id = (int) $user->ID;
		} else {
			$login   = sanitize_user( 'support-' . strtolower( strtok( $email, '@' ) ) . '-' . wp_rand( 100, 999 ), true );
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'display_name' => '' !== trim( (string) $name ) ? sanitize_text_field( (string) $name ) : $email,
					'first_name'   => sanitize_text_field( (string) $name ),
					'user_pass'    => wp_generate_password( 32, true, true ),
					'role'         => self::ROLE,
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			wp_new_user_notification( (int) $user_id, null, 'user' );
		}
		LLD_Members_Admin_Log::add( 'helper_add', array( 'target' => 'helper #' . (int) $user_id, 'before' => 'no role', 'after' => 'Support', 'reason' => $reason ) );
		LLD_Members_Admin_Security::alert_owner( 'A Support helper was added', 'A Support helper (account #' . (int) $user_id . ') was added to the Lifeline Daily admin panel by ' . wp_get_current_user()->display_name . '. If you did not expect this, remove the helper from Lifeline Daily > Helpers.' );
		return (int) $user_id;
	}

	/**
	 * Remove a helper's role; their account then has no admin access. Their devices go; log entries stay.
	 *
	 * @param int    $user_id Helper.
	 * @param string $reason  Why.
	 * @return true|WP_Error
	 */
	public static function remove_helper( $user_id, $reason ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'lld_admin_role', 'Only the Owner can remove a Support helper.' );
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			return new WP_Error( 'lld_admin_helper', 'That account is not a Support helper.' );
		}
		$user->remove_role( self::ROLE );
		WP_Session_Tokens::get_instance( (int) $user_id )->destroy_all();
		global $wpdb;
		$wpdb->delete( LLD_Members_Stage3_Schema::table( 'admin_devices' ), array( 'admin_user_id' => (int) $user_id ), array( '%d' ) );
		LLD_Members_Admin_Log::add( 'helper_remove', array( 'target' => 'helper #' . (int) $user_id, 'before' => 'Support', 'after' => 'no role', 'reason' => $reason ) );
		LLD_Members_Admin_Security::alert_owner( 'A Support helper was removed', 'A Support helper (account #' . (int) $user_id . ') was removed from the Lifeline Daily admin panel by ' . wp_get_current_user()->display_name . '.' );
		return true;
	}
}
