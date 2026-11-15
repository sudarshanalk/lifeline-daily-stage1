<?php
/**
 * Healthy Weight focus blocks (P3-35 · FR3-104, R-HW-BLOCK-01 to -03): after week 8, the next four weeks. The block
 * suggested for the member comes first with "Suggested for you, because …" (T-HW-BLOCK-01) and the member can choose
 * any other. A member who has reached the goal is offered Keep It Off (TC-FR3-104-AS-05). Shown at
 * /members/goal/?hw=blocks from LLD_Members_HW_Programme::blocks() (P2); block titles come from the approved content
 * (LLD_Members_HW_Content::block), and a block whose content is not loaded is left out.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Focus block choice.
 */
class LLD_Members_Hw_Blocks_Screen {

	/**
	 * The blocks the member can choose (P2 blocks()), normalised.
	 *
	 * Accepted: {suggested: suggest_block() (block, id, text, reason, keep_it_off), choices: list of {slug, id, title}
	 * or slugs, keep_it_off: bool, due: bool}.
	 *
	 * @param int    $member_id Member number.
	 * @param string $today     Y-m-d.
	 * @return array|null {suggested: slug|null, because: text, keep_it_off: bool, due: bool, choices: list of
	 *                    {slug, id, title}}
	 */
	public static function data( $member_id, $today ) {
		$c = 'LLD_Members_Hw_Screen_Parts';
		$b = $c::call( $c::PROGRAMME, 'blocks', array( (int) $member_id, (string) $today ) );
		if ( ! is_array( $b ) || is_wp_error( $b ) ) {
			return null;
		}
		$sug     = isset( $b['suggested'] ) && is_array( $b['suggested'] ) ? $b['suggested'] : array();
		$slug    = isset( $sug['block'] ) && is_string( $sug['block'] ) ? $sug['block'] : null;
		$because = isset( $sug['text'] ) && is_string( $sug['text'] ) ? $sug['text'] : '';
		if ( '' === $because && isset( $sug['reason'] ) ) {
			$phrase  = $c::text_of( $sug['reason'] );
			$because = '' !== $phrase ? $c::tpl( 'hw.T-HW-BLOCK-01', array( 'reason' => $phrase ) ) : '';
		}
		$ids     = class_exists( 'LLD_Members_HW_Rules' ) ? LLD_Members_HW_Rules::BLOCKS : array();
		$choices = array();
		foreach ( isset( $b['choices'] ) ? (array) $b['choices'] : array_keys( $ids ) as $item ) {
			$s  = is_array( $item ) ? ( isset( $item['slug'] ) ? (string) $item['slug'] : '' ) : (string) $item;
			$id = is_array( $item ) && isset( $item['id'] ) ? (string) $item['id'] : ( isset( $ids[ $s ] ) ? $ids[ $s ] : '' );
			$t  = is_array( $item ) && isset( $item['title'] ) && is_string( $item['title'] ) ? $item['title'] : ( '' !== $id ? $c::block_text( $id, 'title' ) : '' );
			if ( '' === $s || '' === $t ) {
				continue; // a block whose approved content is not loaded is left out
			}
			$choices[ $s ] = array(
				'slug'  => $s,
				'id'    => $id,
				'title' => $t,
			);
		}
		if ( null !== $slug && isset( $choices[ $slug ] ) ) {
			$choices = array( $slug => $choices[ $slug ] ) + $choices; // the suggestion first
		} else {
			$slug    = null;
			$because = '';
		}
		return array(
			'suggested'   => $slug,
			'because'     => $because,
			'keep_it_off' => ! empty( $b['keep_it_off'] ) || ! empty( $sug['keep_it_off'] ),
			'due'         => ! empty( $b['due'] ),
			'choices'     => array_values( $choices ),
		);
	}

	/**
	 * The screen.
	 *
	 * @param array  $data  data().
	 * @param array  $facts facts().
	 * @param string $error Error text.
	 */
	public static function html( array $data, array $facts, $error = '' ) {
		$c    = 'LLD_Members_Hw_Screen_Parts';
		$html = '<div class="lld-stage3-goal lld-hw" data-lld-goal-step="blocks" data-lld-suggested="' . esc_attr( (string) $data['suggested'] ) . '"><h1>' . esc_html( $c::LABELS['blocks_title'] ) . '</h1>';
		if ( '' !== $error ) {
			$html .= '<div class="lld-error-box" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		$keep = $data['keep_it_off'] ? self::keep_it_off_html( $facts ) : '';
		if ( '' !== $keep ) {
			$html .= '<section class="lld-card" data-lld-section="hw-keep-it-off">' . $keep . '</section>';
		}
		if ( ! $data['choices'] ) {
			return $html . '<p><a href="' . esc_url( home_url( '/members/home/' ) ) . '">' . esc_html( $c::LABELS['back'] ) . '</a></p></div>';
		}
		$html .= '<form method="post" class="lld-form lld-card lld-form-card" action="' . esc_url( home_url( '/members/goal/?hw=blocks' ) ) . '">' . wp_nonce_field( 'lld_hw_block', 'lld_hw_block_nonce', true, false );
		$html .= '<fieldset class="lld-options"><legend class="lld-sr">' . esc_html( $c::LABELS['blocks_title'] ) . '</legend>';
		foreach ( $data['choices'] as $b ) {
			$is   = $b['slug'] === $data['suggested'];
			$hint = $is && '' !== $data['because'] ? 'lld-hw-block-why' : '';
			$html .= '<div class="lld-option" data-lld-block="' . esc_attr( $b['slug'] ) . '"><label><input type="radio" name="lld_hw_block" value="' . esc_attr( $b['slug'] ) . '"' . ( $is ? ' checked' : '' ) . ( '' !== $hint ? ' aria-describedby="' . $hint . '"' : '' ) . ' required> ' . esc_html( $b['title'] );
			if ( $is ) {
				$html .= ' <span class="lld-pill" data-lld-suggested>' . esc_html( $c::LABELS['suggested'] ) . '</span>';
			}
			$html .= '</label>';
			if ( '' !== $hint ) {
				$html .= '<small id="' . $hint . '" data-lld-because>' . esc_html( $data['because'] ) . '</small>';
			}
			$html .= '</div>';
		}
		$html .= '</fieldset><div class="lld-row"><button class="lld-button" type="submit" name="lld_action" value="hw_block">' . esc_html( $c::LABELS['blocks_start'] ) . '</button></div></form></div>';
		return $html;
	}

	/**
	 * Keep It Off for a member who has reached the goal: the signed goal line and its button, posted to the Goal reached
	 * choices on Today (FR3-15).
	 *
	 * @param array $facts facts().
	 */
	public static function keep_it_off_html( array $facts ) {
		$c      = 'LLD_Members_Hw_Screen_Parts';
		$labels = $c::buttons( 'hw.T-HW-MOT-02.goal' );
		if ( ! $labels ) {
			return '';
		}
		$html  = '<form method="post" class="lld-inline-form" action="' . esc_url( home_url( '/members/home/' ) ) . '" data-lld-keep-it-off>' . wp_nonce_field( 'lld_reached', 'lld_reached_nonce', true, false );
		$html .= '<button class="lld-button" type="submit" name="lld_next" value="keep">' . esc_html( $labels[0] ) . '</button></form>';
		return $html;
	}

	/**
	 * Save the chosen block through P2.
	 *
	 * @param int    $member_id Member number.
	 * @param array  $data      data().
	 * @param string $today     Y-m-d.
	 * @return true|WP_Error|null Null when the choice is not one of the blocks.
	 */
	public static function save( $member_id, array $data, $today ) {
		$slug = isset( $_POST['lld_hw_block'] ) ? sanitize_title( wp_unslash( $_POST['lld_hw_block'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by the caller.
		if ( ! in_array( $slug, array_column( $data['choices'], 'slug' ), true ) ) {
			return null;
		}
		$got = LLD_Members_Hw_Screen_Parts::call( LLD_Members_Hw_Screen_Parts::PROGRAMME, 'choose_block', array( (int) $member_id, $slug, (string) $today ), false );
		return is_wp_error( $got ) ? $got : true;
	}
}
