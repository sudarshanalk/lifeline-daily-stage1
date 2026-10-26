<?php
/**
 * Shared screen components (Screen Design Specification sections 3 to 6).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page shell: header, main, member menu and footer.
 *
 * @param array $args title, main, signed_in, status.
 */
function lld_members_layout( $args ) {
	$status = isset( $args['status'] ) ? (int) $args['status'] : 200;
	status_header( $status );
	nocache_headers();
	header( 'Cache-Control: no-store' );
	$title = isset( $args['title'] ) ? (string) $args['title'] : 'Lifeline Daily';
	$main  = isset( $args['main'] ) ? (string) $args['main'] : '';
	$in    = ! empty( $args['signed_in'] );
	$css   = plugins_url( 'assets/lld-members.css', LLD_MEMBERS_FILE );
	$js    = plugins_url( 'assets/lld-members.js', LLD_MEMBERS_FILE );
	echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<meta name="robots" content="noindex">';
	if ( $in ) {
		echo '<meta name="lld-rest-nonce" content="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">';
	}
	echo '<title>' . esc_html( $title ) . '</title>';
	if ( ! empty( $args['description'] ) ) {
		echo '<meta name="description" content="' . esc_attr( (string) $args['description'] ) . '">';
	}
	echo '<link rel="stylesheet" href="' . esc_url( $css ) . '?ver=' . esc_attr( LLD_MEMBERS_VERSION ) . '">';
	echo '</head><body class="' . ( $in ? 'lld-signed-in' : 'lld-signed-out' ) . '">';
	echo '<a class="lld-skip" href="#lld-main">Skip to content</a>';
	echo lld_members_header( $in );
	echo '<main id="lld-main" class="lld-main" tabindex="-1">';
	echo '<div class="lld-banner" id="lld-offline" hidden>You\'re offline</div>';
	echo $main;
	echo '</main>';
	echo lld_members_footer();
	if ( $in ) {
		echo lld_members_menu();
	}
	echo '<div class="lld-toasts" aria-live="polite"></div>';
	echo '<script src="' . esc_url( $js ) . '?ver=' . esc_attr( LLD_MEMBERS_VERSION ) . '"></script>';
	echo '</body></html>';
}

/**
 * Signed-out header, or the desktop member links.
 *
 * @param bool $signed_in Whether a member is signed in.
 */
function lld_members_header( $signed_in ) {
	$public = array(
		'Health Tools' => home_url( '/tools/' ),
		'Articles'     => home_url( '/articles/' ),
		'Programmes'   => home_url( '/programmes/' ),
		'Pricing'      => home_url( '/pricing/' ),
		'Sign in'      => home_url( '/members/sign-in/' ),
		'Join free'    => home_url( '/members/join/' ),
	);
	$html  = '<header class="lld-header"><div class="lld-header-inner">';
	$html .= '<a class="lld-logo" href="' . esc_url( home_url( '/' ) ) . '">Lifeline Daily</a><nav aria-label="Site">';
	if ( $signed_in ) {
		$html .= lld_members_menu_links( 'lld-desktop-nav' );
	} else {
		$html .= '<ul class="lld-public-nav">';
		foreach ( $public as $label => $url ) {
			$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		$html .= '</ul>';
	}
	$html .= '</nav></div></header>';
	return $html;
}

/**
 * @param string $class CSS class.
 */
function lld_members_menu_links( $class ) {
	$items = array(
		'Today'  => home_url( '/members/home/' ),
		'Track'  => home_url( '/members/track/' ),
		'Meals'  => home_url( '/members/meals/' ),
		'Diary'  => home_url( '/members/diary/' ),
		'More'   => home_url( '/members/more/' ),
	);
	$html  = '<ul class="' . esc_attr( $class ) . '">';
	foreach ( $items as $label => $url ) {
		$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
	}
	$html .= '</ul>';
	return $html;
}

/**
 * Phone bottom bar. The same five items sit in the header on a desktop.
 */
function lld_members_menu() {
	return '<nav class="lld-tabbar" aria-label="Member">' . lld_members_menu_links( 'lld-tabbar-list' ) . '</nav>';
}

function lld_members_footer() {
	return '<footer class="lld-footer"><p>Lifeline Daily</p></footer>';
}

/**
 * @param array $args label, type, variant, busy, disabled, disabled_reason, href, name.
 */
function lld_button( $args ) {
	$label   = isset( $args['label'] ) ? (string) $args['label'] : 'Continue';
	$variant = isset( $args['variant'] ) ? (string) $args['variant'] : 'primary';
	$class   = 'lld-button lld-button-' . $variant;
	if ( ! empty( $args['href'] ) ) {
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $args['href'] ) . '">' . esc_html( $label ) . '</a>';
	}
	$type = isset( $args['type'] ) ? (string) $args['type'] : 'submit';
	$name = isset( $args['name'] ) ? ' name="' . esc_attr( $args['name'] ) . '"' : '';
	$dis  = ! empty( $args['disabled'] ) ? ' disabled' : '';
	$busy = ! empty( $args['busy'] ) ? ' aria-busy="true"' : '';
	$text = ! empty( $args['busy'] ) ? 'Saving…' : $label;
	$html = '<button class="' . esc_attr( $class ) . '" type="' . esc_attr( $type ) . '"' . $name . $dis . $busy . '>' . esc_html( $text ) . '</button>';
	if ( ! empty( $args['disabled'] ) && ! empty( $args['disabled_reason'] ) ) {
		$html .= '<p class="lld-hint">' . esc_html( $args['disabled_reason'] ) . '</p>';
	}
	return $html;
}

/**
 * @param array $args id, label, hint, error, type, name, value.
 */
function lld_field( $args ) {
	$id    = (string) $args['id'];
	$type  = isset( $args['type'] ) ? (string) $args['type'] : 'text';
	$name  = isset( $args['name'] ) ? (string) $args['name'] : $id;
	$value = isset( $args['value'] ) ? (string) $args['value'] : '';
	$html  = '<div class="lld-field">';
	$html .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $args['label'] ) . '</label>';
	$html .= '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '">';
	if ( ! empty( $args['hint'] ) ) {
		$html .= '<p class="lld-hint" id="' . esc_attr( $id ) . '-hint">' . esc_html( $args['hint'] ) . '</p>';
	}
	if ( ! empty( $args['error'] ) ) {
		$html .= '<p class="lld-error" id="' . esc_attr( $id ) . '-error">' . esc_html( $args['error'] ) . '</p>';
	}
	$html .= '</div>';
	return $html;
}

/**
 * Password box with a show/hide control.
 *
 * @param array $args id, label, name, error.
 */
function lld_password_field( $args ) {
	$id   = (string) $args['id'];
	$name = isset( $args['name'] ) ? (string) $args['name'] : $id;
	$html = '<div class="lld-field lld-password">';
	$html .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $args['label'] ) . '</label>';
	$html .= '<div class="lld-password-row"><input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="password" autocomplete="current-password">';
	$html .= '<button type="button" class="lld-eye" aria-controls="' . esc_attr( $id ) . '" aria-pressed="false" data-lld-show="Show password" data-lld-hide="Hide password">Show password</button></div>';
	if ( ! empty( $args['hint'] ) ) {
		$html .= '<p class="lld-hint">' . esc_html( $args['hint'] ) . '</p>';
	}
	if ( ! empty( $args['error'] ) ) {
		$html .= '<p class="lld-error">' . esc_html( $args['error'] ) . '</p>';
	}
	$html .= '</div>';
	return $html;
}

/**
 * @param string $title   Card title.
 * @param string $line    Short line.
 * @param string $action  Action HTML.
 */
function lld_card( $title, $line, $action ) {
	return '<article class="lld-card"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $line ) . '</p>' . $action . '</article>';
}

/**
 * @param array $args label, title, reason, reviewer, date, action_label, action_href.
 */
function lld_suggestion_card( $args ) {
	$html  = '<article class="lld-card lld-suggestion">';
	$html .= '<p class="lld-kicker">' . esc_html( $args['label'] ) . '</p>';
	$html .= '<h2>' . esc_html( $args['title'] ) . '</h2>';
	$html .= '<p>' . esc_html( $args['reason'] ) . '</p>';
	$html .= '<p class="lld-hint">Reviewed by ' . esc_html( $args['reviewer'] ) . ' on ' . esc_html( $args['date'] ) . '</p>';
	$html .= '<div class="lld-row">' . lld_button( array( 'label' => $args['action_label'], 'href' => $args['action_href'], 'type' => 'button' ) );
	$html .= lld_button( array( 'label' => 'Not for me', 'variant' => 'secondary', 'type' => 'button' ) ) . '</div></article>';
	return $html;
}

/**
 * @param array $args week, day, title, instructions, href.
 */
function lld_activity_card( $args ) {
	$html  = '<article class="lld-card"><p class="lld-kicker">Week ' . esc_html( (string) $args['week'] ) . ', day ' . esc_html( (string) $args['day'] ) . '</p>';
	$html .= '<h2>' . esc_html( $args['title'] ) . '</h2><p>' . esc_html( $args['instructions'] ) . '</p>';
	$html .= '<p><a href="' . esc_url( $args['href'] ) . '">Read the activity</a></p><div class="lld-row">';
	$html .= lld_button( array( 'label' => 'Done', 'type' => 'button' ) );
	$html .= lld_button( array( 'label' => 'Skip', 'variant' => 'secondary', 'type' => 'button' ) );
	$html .= '</div></article>';
	return $html;
}

/**
 * @param int $done  Count done.
 * @param int $total Count available.
 */
function lld_progress( $done, $total ) {
	$safe_total = max( 1, (int) $total );
	$pct        = (int) round( ( (int) $done / $safe_total ) * 100 );
	$html       = '<div class="lld-progress"><p>' . esc_html( (string) (int) $done ) . ' of ' . esc_html( (string) (int) $total ) . ' done</p>';
	$html      .= '<div class="lld-progress-track" role="progressbar" aria-valuenow="' . esc_attr( (string) (int) $done ) . '" aria-valuemax="' . esc_attr( (string) (int) $total ) . '">';
	$html      .= '<span style="width:' . esc_attr( (string) $pct ) . '%"></span></div></div>';
	return $html;
}

/**
 * @param string $summary One-line summary.
 * @param array  $rows    List of label => value.
 */
function lld_chart( $summary, $rows ) {
	$html  = '<figure class="lld-chart"><figcaption>' . esc_html( $summary ) . '</figcaption>';
	$html .= '<div class="lld-bars" aria-hidden="true">';
	foreach ( $rows as $label => $value ) {
		$html .= '<div class="lld-bar"><span style="height:' . esc_attr( (string) (int) $value ) . '%"></span><b>' . esc_html( (string) $label ) . '</b></div>';
	}
	$html .= '</div><button type="button" class="lld-text-button" data-lld-table-toggle aria-expanded="false">Show as table</button>';
	$html .= '<table class="lld-chart-table" hidden><caption>' . esc_html( $summary ) . '</caption><thead><tr><th>Week</th><th>Value</th></tr></thead><tbody>';
	foreach ( $rows as $label => $value ) {
		$html .= '<tr><td>' . esc_html( (string) $label ) . '</td><td>' . esc_html( (string) $value ) . '</td></tr>';
	}
	$html .= '</tbody></table></figure>';
	return $html;
}

/**
 * @param string $name Badge name.
 */
function lld_badge( $name ) {
	return '<p class="lld-badge"><span aria-hidden="true">★</span> ' . esc_html( $name ) . '</p>';
}

/**
 * @param string $heading Heading.
 * @param string $text    Message.
 * @param string $action  Action HTML.
 */
function lld_safety_banner( $heading, $text, $action ) {
	return '<div class="lld-safety" role="alert"><h2>' . esc_html( $heading ) . '</h2><p>' . esc_html( $text ) . '</p>' . $action . '</div>';
}

/**
 * @param string $text Note.
 */
function lld_caution( $text ) {
	return '<p class="lld-caution">' . esc_html( $text ) . '</p>';
}

/**
 * @param string $text Tip.
 */
function lld_info( $text ) {
	return '<p class="lld-info">' . esc_html( $text ) . '</p>';
}

/**
 * Premium lock. Never used for content the safety matrix excludes.
 *
 * @param string $line What Premium adds.
 */
function lld_membership_lock( $line ) {
	$html  = '<article class="lld-lock"><p class="lld-kicker">Part of Premium</p>';
	$html .= '<p class="lld-lock-preview">A short preview of this Premium item.</p>';
	$html .= '<p>' . esc_html( $line ) . '</p><p class="lld-price">US$59.99 a year</p>';
	$html .= lld_button( array( 'label' => 'Upgrade', 'href' => home_url( '/members/checkout/' ) ) );
	$html .= '</article>';
	return $html;
}

/**
 * Explains a personalised feature that needs health consent. No pressure to switch it on.
 */
function lld_health_consent_notice() {
	$html  = '<article class="lld-consent-note"><h2>Health consent needed</h2>';
	$html .= '<p>This uses your goals and health profile, which we keep only while health consent is on.</p>';
	$html .= '<p><a href="' . esc_url( home_url( '/members/settings/' ) ) . '">Review health consent</a></p>';
	$html .= '<p>Without it you can still read general guides.</p></article>';
	return $html;
}

/**
 * Safety exclusion. No preview and no upgrade.
 *
 * @param string $reason    Short reason.
 * @param string $note      Matrix note.
 * @param string $alt_href  Safe alternative.
 * @param string $alt_label Link text that stands alone.
 */
function lld_not_suitable( $reason, $note, $alt_href, $alt_label ) {
	$html  = '<article class="lld-unsuitable"><h2>Not suitable for you</h2>';
	$html .= '<p>' . esc_html( $reason ) . '</p>';
	if ( '' !== $note ) {
		$html .= '<p>' . esc_html( $note ) . '</p>';
	}
	$html .= '<p><a href="' . esc_url( $alt_href ) . '">' . esc_html( $alt_label ) . '</a></p></article>';
	return $html;
}

/**
 * @param string $line   Friendly line.
 * @param string $action Action HTML.
 */
function lld_empty( $line, $action ) {
	return '<div class="lld-empty"><p>' . esc_html( $line ) . '</p>' . $action . '</div>';
}

/**
 * Large steps for the member's actual route.
 *
 * @param string[] $steps    Step names.
 * @param int      $current  Zero-based current step.
 */
function lld_steps( $steps, $current ) {
	$html = '<ol class="lld-steps">';
	foreach ( $steps as $i => $step ) {
		$class = $i === (int) $current ? ' class="is-current"' : '';
		$html .= '<li' . $class . '>' . esc_html( $step ) . '</li>';
	}
	$html .= '</ol>';
	return $html;
}

/**
 * @param string $name    Group name.
 * @param array  $options Value => label.
 */
function lld_options( $name, $options ) {
	$html = '<fieldset class="lld-options"><legend class="lld-sr">' . esc_html( $name ) . '</legend>';
	foreach ( $options as $value => $label ) {
		$id    = $name . '-' . $value;
		$html .= '<label class="lld-option" for="' . esc_attr( $id ) . '"><input type="radio" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( (string) $value ) . '"> ' . esc_html( $label ) . '</label>';
	}
	$html .= '</fieldset>';
	return $html;
}

function lld_timer() {
	return '<div class="lld-timer" data-lld-timer><p class="lld-timer-count" aria-live="polite">0:30</p>'
		. '<div class="lld-row"><button type="button" class="lld-button" data-lld-timer-start>Start</button>'
		. '<button type="button" class="lld-button lld-button-secondary" data-lld-timer-pause>Pause</button></div>'
		. '<p class="lld-hint">Sound is off.</p></div>';
}

/**
 * @param string $id    State id.
 * @param string $title Heading.
 */
function lld_state_loading( $title ) {
	return '<div class="lld-loading" aria-busy="true"><p class="lld-sr">Loading ' . esc_html( $title ) . '</p><span></span><span></span><span></span></div>';
}

function lld_state_saved() {
	return '<p class="lld-toast" role="status">Saved <button type="button" class="lld-text-button">Undo</button></p>';
}

function lld_state_save_failed() {
	return '<div class="lld-fail" role="alert"><p>We couldn\'t save that. Check your connection and try again.</p>'
		. lld_button( array( 'label' => 'Try again', 'type' => 'button' ) ) . '</div>';
}

function lld_state_offline() {
	return '<p class="lld-banner">You\'re offline</p>';
}

function lld_state_not_allowed( $level = 'h1' ) {
	$tag = 'h2' === $level ? 'h2' : 'h1';
	return '<' . $tag . '>This page isn\'t available</' . $tag . '>';
}
