<?php
/**
 * The Stage 3 calculator pages (P3-57 to P3-5A screens; FR3-49 to FR3-55): six free pages under /health-tools/,
 * usable signed out, with the member's profile pre-filled, the safety matrix applied and "save this result" for
 * signed-in members with health consent (P3-5B). One page per engine:
 *
 *   /health-tools/heart-risk-check/        LLD_Members_Heart_Risk     (P3-57, FR3-49)
 *   /health-tools/blood-pressure-check/    LLD_Members_BP_Check       (P3-58, FR3-50)
 *   /health-tools/metabolic-health-check/  LLD_Members_Metabolic      (P3-59, FR3-51)
 *   /health-tools/fitness-age/             LLD_Members_Fitness_Age    (P3-5A, FR3-52)
 *   /health-tools/heart-rate-zones/        LLD_Members_HR_Zones       (P3-5A, FR3-53)
 *   /health-tools/sleep-and-stress-check/  LLD_Members_Sleep_Stress   (P3-5A, FR3-54)
 *
 * This class does the routing, the page shell, the form round trip and saving. Each tool's own page class in
 * screens/calculators/ supplies the fields, the engine inputs, the result and the saved row. Member-facing clinical
 * text comes only from each engine's signed wording (its MESSAGES, hashed in approvals/stage3/); the page adds
 * labels and layout only. The pages are not in LLD_Members_Analytics::PUBLIC_SCREENS, so Google Analytics never
 * runs on them (NFR3-16), and nothing entered is stored unless the member saves the result.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

foreach ( glob( __DIR__ . '/calculators/class-calc-page-*.php' ) ?: array() as $lld_calc_page_file ) {
	require_once $lld_calc_page_file;
}
unset( $lld_calc_page_file );

/**
 * Routing, page shell, form round trip and saving for the six calculator pages.
 */
class LLD_Members_Calculator_Pages {

	const HANDLE          = 'lld-members-stage3';
	const SCREEN          = 'calc3';
	const FEATURE         = 'calculators_new';
	const OPTION_REWRITE  = 'lld_members_calc3_rewrites';
	const REWRITE_VERSION = '1';

	/**
	 * Address => page class.
	 */
	const PAGES = array(
		'heart-risk-check'       => 'LLD_Members_Calc_Page_Heart_Risk',
		'blood-pressure-check'   => 'LLD_Members_Calc_Page_BP_Check',
		'metabolic-health-check' => 'LLD_Members_Calc_Page_Metabolic',
		'fitness-age'            => 'LLD_Members_Calc_Page_Fitness_Age',
		'heart-rate-zones'       => 'LLD_Members_Calc_Page_HR_Zones',
		'sleep-and-stress-check' => 'LLD_Members_Calc_Page_Sleep_Stress',
	);

	/**
	 * Page labels and lines that are not clinical wording (listed in the pull request for the owner).
	 */
	const UI = array(
		'check'        => 'Check',
		'again'        => 'Change my answers',
		'save'         => 'Save this result',
		'saved'        => 'Saved. It is listed under Your saved results.',
		'save_consent' => 'To save results, turn on health information in your account.',
		'sign_in'      => 'Sign in to fill in your details and save your results.',
		'error'        => 'Please check the highlighted answer.',
		'result'       => 'Your result',
		'history'      => 'Your saved results',
		'about'        => 'About this check',
		'disclaimer'   => 'Medical disclaimer',
		'all_tools'    => 'All health tools',
		'not_stored'   => 'Your answers are used to work out this result. They are not stored unless you save the result.',
	);

	public static function boot() {
		if ( ! class_exists( 'LLD_Members_Features' ) || 'off' === LLD_Members_Features::state( self::FEATURE ) ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'rewrites' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 5 );
	}

	/**
	 * The six addresses, flushed once per rewrite version (as LLD_Members_Goal_Journey does).
	 */
	public static function rewrites() {
		add_rewrite_rule( '^health-tools/(' . implode( '|', array_keys( self::PAGES ) ) . ')/?$', 'index.php?lld_screen=' . self::SCREEN . '&lld_track=$matches[1]', 'top' );
		if ( get_option( self::OPTION_REWRITE ) !== self::REWRITE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION_REWRITE, self::REWRITE_VERSION, true );
		}
	}

	public static function maybe_render() {
		if ( self::SCREEN !== get_query_var( 'lld_screen' ) ) {
			return;
		}
		if ( get_option( 'lld_members_closed' ) && ! current_user_can( 'manage_options' ) ) {
			return; // the Stage 2 maintenance screen
		}
		$slug = (string) get_query_var( 'lld_track' );
		if ( ! isset( self::PAGES[ $slug ] ) || ! class_exists( self::PAGES[ $slug ] ) ) {
			return;
		}
		$member_id = class_exists( 'LLD_Members_Access' ) ? (int) LLD_Members_Access::current_member_id() : 0;
		if ( ! LLD_Members_Features::enabled( self::FEATURE, $member_id ) ) {
			return; // switched off for this visitor or member: the address does not exist (NFR3-06)
		}
		self::render( $slug, $member_id );
		exit;
	}

	/**
	 * The member's facts for pre-filling, or null for a visitor.
	 *
	 * @param int $member_id Member number.
	 */
	public static function context( $member_id ) {
		$facts = ( $member_id > 0 && class_exists( 'LLD_Members_Goal_Journey' ) ) ? LLD_Members_Goal_Journey::facts( $member_id ) : null;
		return array(
			'member'   => (int) $member_id,
			'facts'    => $facts,
			'health'   => is_array( $facts ) && ! empty( $facts['health'] ),
			'imperial' => is_array( $facts ) && ! empty( $facts['imperial'] ),
			'premium'  => is_array( $facts ) && ! empty( $facts['premium'] ),
		);
	}

	/**
	 * GET shows the form (pre-filled for a member); POST "check" runs the engine and shows the result under the
	 * answers; POST "save" runs it again from the same answers and saves it (the page never trusts a sent result).
	 *
	 * @param string $slug      Page address.
	 * @param int    $member_id Member number, or 0.
	 */
	public static function render( $slug, $member_id ) {
		$page   = self::PAGES[ $slug ];
		$ctx    = self::context( $member_id );
		$values = $page::defaults( $ctx );
		$result = null;
		$error  = '';
		$notice = '';
		$post   = 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' );
		if ( $post && isset( $_POST['lld_calc3_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_calc3_nonce'] ) ), 'lld_calc3_' . $slug ) ) {
			$values = array_merge( $values, self::posted() );
			$inputs = $page::inputs( $values, $ctx );
			$result = LLD_Members_Stage3_API::run_page_calculator( $page::ENGINE, $inputs, $member_id );
			if ( isset( $result['error'] ) ) {
				$error  = $page::error_text( (string) $result['error'] );
				$result = null;
			} elseif ( isset( $_POST['lld_calc3_action'] ) && 'save' === $_POST['lld_calc3_action'] ) {
				$notice = self::save( $page, $result, $inputs, $ctx );
			}
		}

		$html  = '<div class="lld-stage3-calc" data-lld-calculator="' . esc_attr( $slug ) . '">';
		$html .= '<p class="lld-kicker"><a href="' . esc_url( home_url( '/health-tools/' ) ) . '">' . esc_html( self::UI['all_tools'] ) . '</a></p>';
		$html .= '<h1>' . esc_html( $page::TITLE ) . '</h1>';
		if ( '' !== $page::INTRO ) {
			$html .= '<p class="lld-calc-intro">' . esc_html( $page::INTRO ) . '</p>';
		}
		if ( $member_id < 1 ) {
			$html .= '<p class="lld-hint"><a href="' . esc_url( home_url( '/members/sign-in/?redirect_to=' . rawurlencode( home_url( '/health-tools/' . $slug . '/' ) ) ) ) . '">' . esc_html( self::UI['sign_in'] ) . '</a></p>';
		}
		if ( null !== $result ) {
			$html .= '<section class="lld-card lld-calc-result" aria-labelledby="lld-calc-result-h" data-lld-result="shown" tabindex="-1" id="lld-calc-result">';
			$html .= '<h2 id="lld-calc-result-h">' . esc_html( self::UI['result'] ) . '</h2>';
			$html .= $page::result( $result, $ctx );
			$html .= self::save_block( $slug, $page, $result, $values, $ctx, $notice );
			$html .= '</section>';
			$html .= self::history_block( $page, $ctx );
		}
		$html .= '<form method="post" class="lld-form lld-card lld-form-card lld-calc-form" action="' . esc_url( home_url( '/health-tools/' . $slug . '/' ) ) . '#lld-calc-result" novalidate>';
		$html .= wp_nonce_field( 'lld_calc3_' . $slug, 'lld_calc3_nonce', true, false );
		if ( '' !== $error ) {
			$html .= '<div class="lld-error-box" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		$html .= $page::form( $values, $ctx );
		$html .= '<p class="lld-hint">' . esc_html( self::UI['not_stored'] ) . '</p>';
		$html .= '<button type="submit" class="lld-button lld-button-primary" name="lld_calc3_action" value="check">' . esc_html( null === $result ? self::UI['check'] : self::UI['again'] ) . '</button>';
		$html .= '</form>';
		$html .= '<section class="lld-card lld-calc-about" aria-labelledby="lld-calc-about-h"><h2 id="lld-calc-about-h">' . esc_html( self::UI['about'] ) . '</h2>' . $page::about() . '<p><a href="' . esc_url( home_url( '/medical-disclaimer/' ) ) . '">' . esc_html( self::UI['disclaimer'] ) . '</a></p></section>';
		$html .= '</div>';
		self::output( $page::TITLE, $html, $member_id > 0 );
	}

	/**
	 * The answers posted in lld_c[...], each a short plain string.
	 *
	 * @return array<string,string>
	 */
	public static function posted() {
		$out = array();
		if ( isset( $_POST['lld_c'] ) && is_array( $_POST['lld_c'] ) ) {
			foreach ( wp_unslash( $_POST['lld_c'] ) as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
				if ( is_scalar( $value ) && preg_match( '/^[a-zA-Z0-9_]{1,40}$/', (string) $key ) ) {
					$out[ (string) $key ] = substr( sanitize_text_field( (string) $value ), 0, 120 );
				}
			}
		}
		return $out;
	}

	/**
	 * Save the result for a member with health consent, through the members' API (P3-5B).
	 *
	 * @param string $page   Page class.
	 * @param array  $result Engine result.
	 * @param array  $inputs Engine inputs.
	 * @param array  $ctx    Context.
	 * @return string Notice.
	 */
	private static function save( $page, array $result, array $inputs, array $ctx ) {
		if ( $ctx['member'] < 1 || ! $ctx['health'] ) {
			return '';
		}
		$payload = $page::saved( $result, $inputs );
		if ( ! is_array( $payload ) ) {
			return '';
		}
		$done = LLD_Members_Stage3_API::save_page_result( $payload );
		return true === $done ? 'saved' : (string) $done;
	}

	/**
	 * "Save this result" for a member with health consent and a savable result; the consent line otherwise.
	 *
	 * @param string $slug   Page address.
	 * @param string $page   Page class.
	 * @param array  $result Engine result.
	 * @param array  $values The answers.
	 * @param array  $ctx    Context.
	 * @param string $notice '', 'saved' or a refusal.
	 */
	private static function save_block( $slug, $page, array $result, array $values, array $ctx, $notice ) {
		if ( $ctx['member'] < 1 || ! is_array( $page::saved( $result, $page::inputs( $values, $ctx ) ) ) ) {
			return '';
		}
		if ( 'saved' === $notice ) {
			return '<p class="lld-notice-box lld-state-saved" role="status" data-lld-saved="yes">' . esc_html( self::UI['saved'] ) . '</p>';
		}
		if ( ! $ctx['health'] ) {
			return '<p class="lld-hint"><a href="' . esc_url( home_url( '/members/settings/?section=privacy' ) ) . '">' . esc_html( self::UI['save_consent'] ) . '</a></p>';
		}
		$html = '';
		if ( '' !== $notice ) {
			$html .= '<div class="lld-error-box" role="alert"><p>' . esc_html( $notice ) . '</p></div>';
		}
		$html .= '<form method="post" class="lld-calc-save" action="' . esc_url( home_url( '/health-tools/' . $slug . '/' ) ) . '#lld-calc-result">';
		$html .= wp_nonce_field( 'lld_calc3_' . $slug, 'lld_calc3_nonce', true, false );
		foreach ( $values as $key => $value ) {
			$html .= '<input type="hidden" name="lld_c[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '">';
		}
		$html .= '<button type="submit" class="lld-button lld-button-secondary" name="lld_calc3_action" value="save">' . esc_html( self::UI['save'] ) . '</button></form>';
		return $html;
	}

	/**
	 * The member's saved results for this calculator: Free the latest with the upgrade line, Premium the history and
	 * the change (P3-5B wording, from LLD_Members_Saved_Results).
	 *
	 * @param string $page Page class.
	 * @param array  $ctx  Context.
	 */
	private static function history_block( $page, array $ctx ) {
		if ( $ctx['member'] < 1 || ! $ctx['health'] || '' === $page::SAVED ) {
			return '';
		}
		$view = LLD_Members_Stage3_API::page_saved_results( $page::SAVED );
		if ( ! is_array( $view ) || empty( $view['rows'] ) || ! empty( $view['hidden'] ) ) {
			return '';
		}
		$html = '<section class="lld-card lld-calc-history" aria-labelledby="lld-calc-history-h" data-lld-history="' . ( $ctx['premium'] ? 'premium' : 'free' ) . '"><h2 id="lld-calc-history-h">' . esc_html( self::UI['history'] ) . '</h2><ul class="lld-calc-history-list">';
		foreach ( $view['rows'] as $row ) {
			$row   = (array) $row;
			$date  = isset( $row['saved_at'] ) ? substr( (string) $row['saved_at'], 0, 10 ) : '';
			$shown = isset( $row['shown'] ) ? (string) $row['shown'] : ( (string) ( $row['headline'] ?? '' ) . ( isset( $row['unit'] ) && '' !== (string) $row['unit'] ? ' ' . (string) $row['unit'] : '' ) );
			$html .= '<li><span class="lld-muted">' . esc_html( $date ) . '</span> ' . esc_html( $shown ) . ( ! empty( $row['band'] ) ? ' · ' . esc_html( (string) $row['band'] ) : '' ) . '</li>';
		}
		$html .= '</ul>';
		if ( ! empty( $view['comparison']['wording'] ) ) {
			$html .= '<p class="lld-stat" data-lld-change>' . esc_html( (string) $view['comparison']['wording'] ) . '</p>';
		}
		if ( ! empty( $view['upgrade_text'] ) ) {
			$html .= '<p class="lld-hint" data-lld-upgrade><a href="' . esc_url( home_url( '/pricing/' ) ) . '">' . esc_html( (string) $view['upgrade_text'] ) . '</a></p>';
		}
		return $html . '</section>';
	}

	/* ---------- helpers for the page classes ---------- */

	/**
	 * Signed text with its {placeholders} filled from the engine's fill and escaped. A key with no signed text
	 * gives '' (never unsigned words).
	 *
	 * @param array  $wording Engine wording map.
	 * @param string $key     Key.
	 * @param array  $fill    Placeholder values.
	 */
	public static function text( array $wording, $key, array $fill = array() ) {
		if ( ! isset( $wording[ $key ] ) || ! is_string( $wording[ $key ] ) || '' === $wording[ $key ] ) {
			return '';
		}
		$text = $wording[ $key ];
		foreach ( $fill as $name => $value ) {
			if ( null !== $value && is_scalar( $value ) ) {
				$text = str_replace( '{' . $name . '}', (string) $value, $text );
			}
		}
		return esc_html( $text );
	}

	/**
	 * A paragraph of signed text, or '' when there is none.
	 *
	 * @param array  $wording Engine wording map.
	 * @param string $key     Key.
	 * @param array  $fill    Placeholder values.
	 * @param string $class   CSS class.
	 */
	public static function para( array $wording, $key, array $fill = array(), $class = '' ) {
		$text = self::text( $wording, $key, $fill );
		return '' === $text ? '' : '<p' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' data-lld-key="' . esc_attr( $key ) . '">' . $text . '</p>';
	}

	/**
	 * A safety line: urgent keys as the never-dismissible alert, same-day and caution keys as a caution box.
	 *
	 * @param string $text   Escaped text.
	 * @param bool   $urgent Urgent (alert) or caution.
	 * @param string $key    Wording key, for tests.
	 */
	public static function safety( $text, $urgent, $key ) {
		if ( '' === $text ) {
			return '';
		}
		return $urgent
			? '<div class="lld-safety lld-calc-urgent" role="alert" data-lld-key="' . esc_attr( $key ) . '"><p>' . $text . '</p></div>'
			: '<div class="lld-caution lld-calc-caution" role="status" data-lld-key="' . esc_attr( $key ) . '"><p>' . $text . '</p></div>';
	}

	/**
	 * A safety-layer line by its key (bp-check.urgent.alert, bp-check.pregnancy.urgent, bp-check.pregnancy.same-day,
	 * glucose urgent keys …), as the frozen text: the first line of a several-line alert as its heading, and
	 * "[When to seek urgent care]" as the link to the Stage 1 safety page. Urgent keys are the never-dismissible
	 * alert; the same-day line is a caution.
	 *
	 * @param string $key  Wording key.
	 * @param string $text Raw signed text.
	 */
	public static function safety_key( $key, $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}
		$urgent = false !== strpos( $key, 'urgent' );
		$lines  = preg_split( '/\r\n|\n/', $text );
		$html   = '';
		foreach ( $lines as $i => $line ) {
			$line = esc_html( $line );
			$line = str_replace( '[When to seek urgent care]', '<a href="' . esc_url( home_url( '/safety/' ) ) . '">When to seek urgent care</a>', $line );
			$html .= ( 0 === $i && count( $lines ) > 1 ) ? '<p class="lld-calc-urgent-head"><strong>' . $line . '</strong></p>' : '<p>' . $line . '</p>';
		}
		return $urgent
			? '<div class="lld-safety lld-calc-urgent" role="alert" data-lld-key="' . esc_attr( $key ) . '">' . $html . '</div>'
			: '<div class="lld-caution lld-calc-caution" role="status" data-lld-key="' . esc_attr( $key ) . '">' . $html . '</div>';
	}

	/**
	 * A number field.
	 *
	 * @param string $name  Answer name.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param array  $opt   min, max, step, hint, unit, required.
	 */
	public static function number( $name, $label, $value, array $opt = array() ) {
		$id    = 'lld-c-' . $name;
		$attrs = '';
		foreach ( array( 'min', 'max', 'step' ) as $a ) {
			if ( isset( $opt[ $a ] ) ) {
				$attrs .= ' ' . $a . '="' . esc_attr( (string) $opt[ $a ] ) . '"';
			}
		}
		$hint  = isset( $opt['hint'] ) && '' !== $opt['hint'] ? '<p class="lld-hint" id="' . esc_attr( $id ) . '-hint">' . esc_html( $opt['hint'] ) . '</p>' : '';
		$html  = '<div class="lld-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( isset( $opt['unit'] ) ? ' <span class="lld-muted">(' . esc_html( $opt['unit'] ) . ')</span>' : '' ) . '</label>';
		$html .= '<input id="' . esc_attr( $id ) . '" name="lld_c[' . esc_attr( $name ) . ']" type="number" inputmode="decimal" value="' . esc_attr( (string) $value ) . '"' . $attrs . ( '' !== $hint ? ' aria-describedby="' . esc_attr( $id ) . '-hint"' : '' ) . '>';
		return $html . $hint . '</div>';
	}

	/**
	 * A one-of choice as radio buttons (44 px targets in stage3.css).
	 *
	 * @param string $name    Answer name.
	 * @param string $legend  Question.
	 * @param array  $choices value => label.
	 * @param string $value   Chosen value.
	 * @param string $hint    Optional hint.
	 */
	public static function choice( $name, $legend, array $choices, $value, $hint = '' ) {
		$html = '<fieldset class="lld-field lld-calc-choice"><legend>' . esc_html( $legend ) . '</legend>';
		if ( '' !== $hint ) {
			$html .= '<p class="lld-hint">' . esc_html( $hint ) . '</p>';
		}
		$html .= '<div class="lld-calc-options">';
		foreach ( $choices as $v => $label ) {
			$id    = 'lld-c-' . $name . '-' . preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $v ) );
			$html .= '<label class="lld-calc-option" for="' . esc_attr( $id ) . '"><input type="radio" id="' . esc_attr( $id ) . '" name="lld_c[' . esc_attr( $name ) . ']" value="' . esc_attr( (string) $v ) . '"' . ( (string) $v === (string) $value ? ' checked' : '' ) . '> <span>' . esc_html( $label ) . '</span></label>';
		}
		return $html . '</div></fieldset>';
	}

	/**
	 * A drop-down list.
	 *
	 * @param string $name    Answer name.
	 * @param string $label   Label.
	 * @param array  $choices value => label.
	 * @param string $value   Chosen value.
	 */
	public static function select( $name, $label, array $choices, $value ) {
		$id   = 'lld-c-' . $name;
		$html = '<div class="lld-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" name="lld_c[' . esc_attr( $name ) . ']">';
		foreach ( $choices as $v => $text ) {
			$html .= '<option value="' . esc_attr( (string) $v ) . '"' . ( (string) $v === (string) $value ? ' selected' : '' ) . '>' . esc_html( $text ) . '</option>';
		}
		return $html . '</select></div>';
	}

	/**
	 * The plain "check the {field}" message for an engine error.
	 *
	 * @param string $field Engine field.
	 * @param array  $names field => label shown.
	 */
	public static function field_error( $field, array $names ) {
		return isset( $names[ $field ] ) ? 'Please check ' . $names[ $field ] . '.' : self::UI['error'];
	}

	/**
	 * A number from an answer, or null when blank.
	 *
	 * @param array  $values Answers.
	 * @param string $key    Answer name.
	 */
	public static function num( array $values, $key ) {
		if ( ! isset( $values[ $key ] ) || '' === trim( (string) $values[ $key ] ) || ! is_numeric( str_replace( ',', '.', (string) $values[ $key ] ) ) ) {
			return null;
		}
		return (float) str_replace( ',', '.', (string) $values[ $key ] );
	}

	/**
	 * Print the page with the Stage 3 stylesheet (the Stage 2 layout has no wp_head).
	 *
	 * @param string $title     Title.
	 * @param string $main      Body.
	 * @param bool   $signed_in Signed in.
	 */
	private static function output( $title, $main, $signed_in ) {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/stage3.css', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/stage3.js', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION, true );
		ob_start();
		lld_members_layout( array( 'title' => $title, 'main' => $main, 'signed_in' => $signed_in, 'description' => $title ) );
		$page = (string) ob_get_clean();
		ob_start();
		wp_print_styles( array( self::HANDLE ) );
		$styles = (string) ob_get_clean();
		$head   = strpos( $page, '</head>' );
		if ( false !== $head ) {
			$page = substr_replace( $page, $styles, $head, 0 );
		}
		ob_start();
		wp_print_scripts( array( self::HANDLE ) );
		$scripts = (string) ob_get_clean();
		$end     = strrpos( $page, '</body>' );
		if ( false !== $end ) {
			$page = substr_replace( $page, $scripts, $end, 0 );
		}
		echo $page; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}
}
