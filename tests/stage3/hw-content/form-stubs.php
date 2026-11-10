<?php
/**
 * Just enough WordPress to run LLD_Members_Content's field form (box) and its save (save_fields) in PHP CLI, for the
 * P3-35 reload-only types. Input (JSON on stdin): { "type": post type, "fields": stored _lld_fields, "posted": $_POST }.
 * Output: { "box": the form's HTML, "saved": the _lld_fields written (null when nothing was written), "tags": the tags
 * written (null when none) }.
 */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['t']     = json_decode( stream_get_contents( STDIN ), true );
$GLOBALS['saved'] = null;
$GLOBALS['tagsw'] = null;
$_POST            = (array) ( $GLOBALS['t']['posted'] ?? array() );

class WP_Error {}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_nonce_field() { echo '<input type="hidden" name="lld_content_nonce">'; }
function wp_verify_nonce( $n, $a ) { return 'ok' === $n && 'lld_content_save' === $a; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function get_post_type( $id ) { return $GLOBALS['t']['type']; }
function get_post_meta( $id, $k, $single = false ) { return '_lld_fields' === $k ? $GLOBALS['t']['fields'] : ''; }
function update_post_meta( $id, $k, $v ) { if ( '_lld_fields' === $k ) { $GLOBALS['saved'] = $v; } }
function wp_get_object_terms() { return array( 'healthy-weight' ); }
function wp_set_object_terms( $id, $tags ) { $GLOBALS['tagsw'] = $tags; }
function get_posts() { return array(); }
function selected() { return ''; }
function checked() { return ''; }

require dirname( __DIR__, 3 ) . '/site/public_html/wp-content/plugins/lld-members/includes/class-content.php';

$post = (object) array( 'ID' => 5, 'post_type' => $GLOBALS['t']['type'] );
ob_start();
LLD_Members_Content::box( $post );
$box  = ob_get_clean();
$save = new ReflectionMethod( 'LLD_Members_Content', 'save_fields' );
$save->setAccessible( true );
$save->invoke( null, 5 );
echo json_encode( array( 'box' => $box, 'saved' => $GLOBALS['saved'], 'tags' => $GLOBALS['tagsw'] ) );
