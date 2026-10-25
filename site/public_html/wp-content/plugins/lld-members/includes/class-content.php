<?php
/**
 * Content library and approvals (Area B, B16, T2).
 *
 * Members only ever see an approved version. An edit stays hidden until it is approved again.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owner-edited library, approval queue and the member reading page.
 */
class LLD_Members_Content {

	/**
	 * Fields queued for the post that is about to be saved.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $next_fields = null;

	/**
	 * True while a version row is being stored.
	 *
	 * @var bool
	 */
	private static $capturing = false;

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'capture' ), 30, 2 );
		add_action( 'save_post_post', array( __CLASS__, 'save_article_tags' ) );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'classic' ), 10, 2 );
		add_filter( 'use_block_editor_for_post', array( __CLASS__, 'classic_post' ), 10, 2 );
		add_filter( 'user_can_richedit', array( __CLASS__, 'plain_editor' ) );
		add_filter( 'pre_insert_term', array( __CLASS__, 'refuse_banned_term' ), 10, 2 );
		add_action( 'lld_tag_add_form_fields', array( __CLASS__, 'tag_kind_add' ) );
		add_action( 'lld_tag_edit_form_fields', array( __CLASS__, 'tag_kind_edit' ) );
		add_action( 'created_lld_tag', array( __CLASS__, 'save_tag_kind' ) );
		add_action( 'edited_lld_tag', array( __CLASS__, 'save_tag_kind' ) );
	}

	/**
	 * @return string[]
	 */
	public static function types() {
		return array(
			'lld_goal',
			'lld_track',
			'lld_week',
			'lld_lesson',
			'lld_activity',
			'lld_challenge',
			'lld_recipe',
			'lld_meal',
			'lld_workout',
			'lld_rule',
			'lld_badge',
			'lld_matrix',
			'lld_wording',
			'lld_qa',
		);
	}

	public static function register() {
		register_post_status(
			'lld_version',
			array(
				'label'                     => 'Version',
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
		$labels = array(
			'lld_goal'      => 'Goals',
			'lld_track'     => 'Tracks',
			'lld_week'      => 'Weeks',
			'lld_lesson'    => 'Lessons',
			'lld_activity'  => 'Activities',
			'lld_challenge' => 'Challenges',
			'lld_recipe'    => 'Recipes',
			'lld_meal'      => 'Meal plans',
			'lld_workout'   => 'Workout plans',
			'lld_rule'      => 'Suggestion rules',
			'lld_badge'     => 'Badges',
			'lld_matrix'    => 'Safety matrix',
			'lld_wording'   => 'Screening wording',
			'lld_qa'        => 'Live Q&A',
		);
		foreach ( $labels as $type => $plural ) {
			$hidden = in_array( $type, array( 'lld_matrix', 'lld_qa' ), true );
			register_post_type(
				$type,
				array(
					'labels'              => array(
						'name'          => $plural,
						'singular_name' => $plural,
						'add_new_item'  => 'Add ' . strtolower( $plural ),
						'edit_item'     => 'Edit ' . strtolower( $plural ),
					),
					'public'              => false,
					'show_ui'             => true,
					'show_in_menu'        => $hidden ? false : 'lld-approvals',
					'show_in_rest'        => false,
					'exclude_from_search' => true,
					'publicly_queryable'  => false,
					'rewrite'             => false,
					'supports'            => 'lld_recipe' === $type ? array( 'title', 'editor', 'thumbnail' ) : array( 'title', 'editor' ),
					'capability_type'     => 'post',
				)
			);
		}
		register_taxonomy(
			'lld_tag',
			array_merge( self::types(), array( 'post' ) ),
			array(
				'labels'            => array(
					'name'          => 'Tags',
					'singular_name' => 'Tag',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_menu'      => true,
				'meta_box_cb'       => false,
				'hierarchical'      => false,
				'rewrite'           => false,
			)
		);
		self::seed_tags();
	}

	/**
	 * @param bool   $use  Whether the block editor would be used.
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function classic( $use, $type ) {
		if ( in_array( $type, self::types(), true ) ) {
			return false;
		}
		return $use;
	}

	/**
	 * @param bool    $use  Whether the block editor would be used.
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function classic_post( $use, $post ) {
		if ( $post instanceof WP_Post && in_array( $post->post_type, self::types(), true ) ) {
			return false;
		}
		return $use;
	}

	/**
	 * @param bool $can Whether the visual editor is on.
	 * @return bool
	 */
	public static function plain_editor( $can ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->post_type ) && in_array( $screen->post_type, self::types(), true ) ) {
			return false;
		}
		return $can;
	}

	/**
	 * @param string|WP_Error $term     Term name.
	 * @param string          $taxonomy Taxonomy.
	 * @return string|WP_Error
	 */
	public static function refuse_banned_term( $term, $taxonomy ) {
		if ( 'lld_tag' === $taxonomy && is_string( $term ) && self::banned_name( $term ) ) {
			return new WP_Error( 'lld_banned', 'Breath-holding is not allowed.' );
		}
		return $term;
	}

	public static function menu() {
		add_menu_page( 'Content', 'Content', 'manage_options', 'lld-approvals', array( __CLASS__, 'approvals_page' ), 'dashicons-yes', 26 );
		add_submenu_page( 'lld-approvals', 'Approvals', 'Approvals', 'manage_options', 'lld-approvals', array( __CLASS__, 'approvals_page' ) );
		add_submenu_page( 'lld-approvals', 'Safety matrix', 'Safety matrix', 'manage_options', 'lld-matrix', array( __CLASS__, 'matrix_page' ) );
		add_submenu_page( 'lld-approvals', 'Live Q&A', 'Live Q&A', 'manage_options', 'lld-qa', array( __CLASS__, 'qa_page' ) );
	}

	public static function boxes() {
		foreach ( self::types() as $type ) {
			add_meta_box( 'lld_fields', 'Details', array( __CLASS__, 'box' ), $type, 'normal', 'high' );
		}
		add_meta_box( 'lld_article_tags', 'Tags', array( __CLASS__, 'article_box' ), 'post', 'side' );
	}

	/**
	 * @param WP_Post $post Post.
	 */
	public static function box( $post ) {
		wp_nonce_field( 'lld_content_save', 'lld_content_nonce' );
		$fields = get_post_meta( $post->ID, '_lld_fields', true );
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}
		echo '<p><label><input type="checkbox" name="lld_ai" id="lld_ai" value="1"> AI-drafted</label></p>';
		$tags = wp_get_object_terms( $post->ID, 'lld_tag', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $tags ) ) {
			$tags = array();
		}
		echo '<p><label for="lld_tags">Tags</label><br><input class="widefat" id="lld_tags" name="lld_tags" value="' . esc_attr( implode( ', ', $tags ) ) . '">';
		echo '<span class="description">Comma-separated. Breath-holding is refused. Lessons are limited to 1,000 words.</span></p>';
		foreach ( self::definitions( $post->post_type ) as $field ) {
			self::render_field( $field, $fields );
		}
		if ( 'lld_goal' === $post->post_type ) {
			echo '<p>' . esc_html( self::goal_line( $post->ID ) ) . '</p>';
		}
		echo '<p class="description">Members see the last approved version only. Saving here does not publish it to members.</p>';
	}

	/**
	 * @param WP_Post $post Post.
	 */
	public static function article_box( $post ) {
		wp_nonce_field( 'lld_article_tags', 'lld_article_nonce' );
		$tags = wp_get_object_terms( $post->ID, 'lld_tag', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $tags ) ) {
			$tags = array();
		}
		$premium = (int) get_post_meta( $post->ID, '_lld_premium', true );
		echo '<p><label for="lld_tags">Tags</label><br><input class="widefat" id="lld_tags" name="lld_tags" value="' . esc_attr( implode( ', ', $tags ) ) . '"></p>';
		echo '<p><label><input type="checkbox" name="lld_premium" value="1" ' . checked( 1, $premium, false ) . '> Premium only</label></p>';
	}

	/**
	 * @param int $post_id Post id.
	 */
	public static function save_article_tags( $post_id ) {
		if ( ! isset( $_POST['lld_article_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_article_nonce'] ) ), 'lld_article_tags' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw  = isset( $_POST['lld_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_tags'] ) ) : '';
		$tags = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
		foreach ( $tags as $tag ) {
			if ( self::banned_name( $tag ) ) {
				self::remember( 'Breath-holding is not allowed.' );
				return;
			}
		}
		wp_set_object_terms( $post_id, $tags, 'lld_tag' );
		update_post_meta( $post_id, '_lld_premium', empty( $_POST['lld_premium'] ) ? 0 : 1 );
	}

	/**
	 * @param int          $post_id Post id.
	 * @param WP_Post|null $post    Post.
	 */
	public static function capture( $post_id, $post ) {
		if ( self::$capturing || ! $post instanceof WP_Post ) {
			return;
		}
		if ( 'lld_version' === $post->post_status || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::types(), true ) ) {
			return;
		}
		$posted = self::posted_tags();
		if ( is_array( $posted ) ) {
			foreach ( $posted as $tag ) {
				if ( self::banned_name( $tag ) ) {
					self::remember( 'Breath-holding is not allowed.' );
					return;
				}
			}
		}
		if ( is_array( self::$next_fields ) ) {
			update_post_meta( $post_id, '_lld_fields', self::$next_fields );
			if ( isset( self::$next_fields['situation'] ) ) {
				update_post_meta( $post_id, '_lld_situation', (string) self::$next_fields['situation'] );
			}
			self::$next_fields = null;
		} else {
			self::save_fields( $post_id );
		}
		$fresh = get_post( $post_id );
		if ( self::blocked( $fresh ) ) {
			return;
		}
		self::store_version( $post_id );
	}

	/**
	 * Latest approved snapshot, or null when members must not see the item.
	 *
	 * @param int $parent_id Working copy id.
	 * @return array<string,mixed>|null
	 */
	public static function shown( $parent_id ) {
		$post = get_post( $parent_id );
		if ( ! $post || 'lld_version' === $post->post_status || ! in_array( $post->post_type, self::types(), true ) ) {
			return null;
		}
		$best = null;
		foreach ( self::versions( $parent_id ) as $version ) {
			$on = get_post_meta( $version->ID, '_lld_approved_on', true );
			if ( is_string( $on ) && '' !== $on ) {
				$best = $version;
			}
		}
		if ( ! $best ) {
			return null;
		}
		return self::read_version( $best );
	}

	/**
	 * @return array{problems:string[],shown:array<int,array<string,mixed>>,waiting:array<int,array<string,mixed>>}
	 */
	public static function audit() {
		$problems = array();
		$shown    = array();
		$waiting  = array();
		$posts    = get_posts(
			array(
				'post_type'      => self::types(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
			)
		);
		foreach ( $posts as $post ) {
			$live = self::shown( $post->ID );
			if ( $live ) {
				if ( '' === (string) $live['approved_on'] || '' === (string) $live['fingerprint'] ) {
					$problems[] = $post->ID . ' is shown without an approval record';
				}
				$shown[] = array(
					'id'          => (int) $post->ID,
					'fingerprint' => (string) $live['fingerprint'],
					'approved_on' => (string) $live['approved_on'],
				);
			}
			foreach ( self::versions( $post->ID ) as $version ) {
				$on = (string) get_post_meta( $version->ID, '_lld_approved_on', true );
				if ( '' === $on ) {
					$waiting[] = array(
						'id'          => (int) $post->ID,
						'fingerprint' => (string) get_post_meta( $version->ID, '_lld_fingerprint', true ),
					);
				}
			}
		}
		return array(
			'problems' => $problems,
			'shown'    => $shown,
			'waiting'  => $waiting,
		);
	}

	public static function screen() {
		if ( LLD_Members_Access::current_member_id() < 1 ) {
			wp_safe_redirect( home_url( '/members/sign-in/' ) );
			exit;
		}
		$item = (int) get_query_var( 'lld_item' );
		if ( $item > 0 ) {
			self::one( $item );
			return;
		}
		self::catalog();
	}

	public static function notices() {
		self::echo_notice();
	}

	public static function approvals_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::decide();
		echo '<div class="wrap">';
		echo '<h1>Approve content</h1>';
		self::echo_notice();
		echo '<p>Members keep seeing the last approved version until you approve the new one. WordPress Publish does not show an item to members.</p>';
		$rows = self::pending();
		if ( ! $rows ) {
			echo '<p>No content is waiting for approval.</p>';
			if ( get_option( 'lld_members_reviewer' ) ) {
				echo '<p>Qualifications recorded</p>';
			}
			echo '</div>';
			return;
		}
		echo '<form method="post">';
		wp_nonce_field( 'lld_approve', 'lld_approve_nonce' );
		if ( ! get_option( 'lld_members_reviewer' ) ) {
			echo '<p><label for="lld_qualifications">Clinical qualifications<br>';
			echo '<textarea name="lld_qualifications" id="lld_qualifications" rows="3" class="large-text" required></textarea></label></p>';
			echo '<p class="description">Recorded once, with the first content approval.</p>';
		} else {
			echo '<p>Qualifications recorded</p>';
		}
		echo '<table class="widefat lld-approvals"><thead><tr><th>Item</th><th>What changed</th><th>Wording</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $version ) {
			$snap  = self::read_version( $version );
			$old   = self::shown( (int) $version->post_parent );
			$flags = self::wording_flags( (string) ( $snap['title'] ?? '' ) . ' ' . (string) ( $snap['body'] ?? '' ) );
			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $snap['title'] ?? '' ) ) . '</strong>';
			echo '<br>Version ' . (int) ( $snap['version'] ?? 0 );
			echo '<br>AI-drafted: ' . ( ! empty( $snap['ai'] ) ? 'yes' : 'no' );
			echo '<br><code>' . esc_html( (string) ( $snap['fingerprint'] ?? '' ) ) . '</code></td>';
			echo '<td><p class="lld-was">' . esc_html( $old ? (string) $old['body'] : 'Nothing approved yet' ) . '</p>';
			echo '<p class="lld-now">' . esc_html( (string) ( $snap['body'] ?? '' ) ) . '</p></td>';
			echo '<td>';
			if ( $flags ) {
				echo '<ul class="lld-wording-flags">';
				foreach ( $flags as $flag ) {
					echo '<li>' . esc_html( $flag ) . '</li>';
				}
				echo '</ul>';
				echo '<label><input type="checkbox" name="lld_wording_reviewed_' . (int) $version->ID . '" value="1"> I have reviewed the flagged wording</label>';
			} else {
				echo '<p>No flagged phrases.</p>';
			}
			echo '</td><td>';
			echo '<button class="button button-primary" name="lld_approve" value="' . (int) $version->ID . '">Approve</button> ';
			echo '<button class="button" name="lld_send_back" value="' . (int) $version->ID . '">Send back</button>';
			echo '</td></tr>';
		}
		echo '</tbody></table></form></div>';
	}

	public static function matrix_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_matrix_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_matrix_nonce'] ) ), 'lld_matrix' ) ) {
			self::save_matrix();
			self::remember( 'Safety matrix saved. Approve the new version before members are affected.' );
		}
		echo '<div class="wrap"><h1>Safety matrix</h1>';
		self::echo_notice();
		echo '<p>Changes go through the approvals queue. Members are not affected until you approve them.</p>';
		echo '<form method="post">';
		wp_nonce_field( 'lld_matrix', 'lld_matrix_nonce' );
		echo '<table class="widefat"><thead><tr><th>Situation</th><th>Remove content tagged</th><th>Hide weight-loss badges</th><th>Tracks not offered</th><th>Note shown</th></tr></thead><tbody>';
		foreach ( self::situations() as $key => $label ) {
			$row = self::matrix_row( $key );
			echo '<tr><td>' . esc_html( $label ) . '</td>';
			echo '<td><input name="remove[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) ( $row['remove_tags'] ?? '' ) ) . '"></td>';
			echo '<td><input type="checkbox" name="hide[' . esc_attr( $key ) . ']" value="1" ' . checked( 1, (int) ( $row['hide_badges'] ?? 0 ), false ) . '></td>';
			echo '<td><input name="tracks[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) ( $row['tracks'] ?? '' ) ) . '"></td>';
			echo '<td><textarea name="note[' . esc_attr( $key ) . ']" rows="2">' . esc_textarea( (string) ( $row['note'] ?? '' ) ) . '</textarea></td></tr>';
		}
		echo '</tbody></table><p><button class="button button-primary">Save matrix</button></p></form></div>';
	}

	public static function qa_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_qa_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_qa_nonce'] ) ), 'lld_qa' ) ) {
			self::save_qa();
			self::remember( 'Live Q&A saved. Approve it before members see it.' );
		}
		echo '<div class="wrap"><h1>Live Q&A</h1>';
		self::echo_notice();
		echo '<form method="post">';
		wp_nonce_field( 'lld_qa', 'lld_qa_nonce' );
		echo '<table class="widefat"><thead><tr><th>Date</th><th>Time</th><th>Link</th></tr></thead><tbody>';
		$sessions = self::sessions();
		$sessions[] = array( 'date' => '', 'time' => '', 'link' => '' );
		foreach ( $sessions as $index => $session ) {
			echo '<tr>';
			echo '<td><input type="date" name="qa_date[' . (int) $index . ']" value="' . esc_attr( (string) $session['date'] ) . '"></td>';
			echo '<td><input type="time" name="qa_time[' . (int) $index . ']" value="' . esc_attr( (string) $session['time'] ) . '"></td>';
			echo '<td><input type="url" class="widefat" name="qa_link[' . (int) $index . ']" value="' . esc_attr( (string) $session['link'] ) . '"></td>';
			echo '</tr>';
		}
		echo '</tbody></table><p><button class="button button-primary">Save sessions</button></p></form></div>';
	}

	public static function tag_kind_add() {
		echo '<div class="form-field"><label for="lld_kind">Kind</label><select name="lld_kind" id="lld_kind">';
		foreach ( array( 'goal', 'person', 'safety' ) as $kind ) {
			echo '<option value="' . esc_attr( $kind ) . '">' . esc_html( $kind ) . '</option>';
		}
		echo '</select><p>Goal and track, person, or safety.</p></div>';
	}

	/**
	 * @param WP_Term $term Term.
	 */
	public static function tag_kind_edit( $term ) {
		$current = (string) get_term_meta( $term->term_id, 'lld_kind', true );
		echo '<tr class="form-field"><th scope="row"><label for="lld_kind">Kind</label></th><td><select name="lld_kind" id="lld_kind">';
		foreach ( array( 'goal', 'person', 'safety' ) as $kind ) {
			echo '<option value="' . esc_attr( $kind ) . '" ' . selected( $current, $kind, false ) . '>' . esc_html( $kind ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	/**
	 * @param int $term_id Term id.
	 */
	public static function save_tag_kind( $term_id ) {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['lld_kind'] ) ) {
			return;
		}
		$kind = sanitize_key( wp_unslash( $_POST['lld_kind'] ) );
		if ( ! in_array( $kind, array( 'goal', 'person', 'safety' ), true ) ) {
			return;
		}
		update_term_meta( $term_id, 'lld_kind', $kind );
	}

	/**
	 * @param string $text Phrase source.
	 * @return string[]
	 */
	public static function wording_flags( $text ) {
		preg_match_all( '/\b(cures|treats)\b/i', (string) $text, $found );
		$list = array();
		foreach ( $found[1] as $word ) {
			$list[] = strtolower( (string) $word );
		}
		return array_values( array_unique( $list ) );
	}

	/**
	 * @param string $name Tag name.
	 */
	public static function banned_name( $name ) {
		$name = strtolower( (string) $name );
		$name = preg_replace( '/[^a-z]+/', '', $name );
		return 'breathholding' === $name;
	}

	/**
	 * @param int $parent_id Working copy.
	 * @return WP_Post[]
	 */
	private static function versions( $parent_id ) {
		$post = get_post( $parent_id );
		if ( ! $post ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => $post->post_type,
				'post_status'    => 'lld_version',
				'post_parent'    => $parent_id,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * @param WP_Post $version Version row.
	 * @return array<string,mixed>
	 */
	private static function read_version( $version ) {
		$snap = json_decode( (string) $version->post_content, true );
		if ( ! is_array( $snap ) ) {
			$snap = array();
		}
		$snap['fingerprint'] = (string) get_post_meta( $version->ID, '_lld_fingerprint', true );
		$snap['version']     = (int) get_post_meta( $version->ID, '_lld_version', true );
		$snap['approved_on'] = (string) get_post_meta( $version->ID, '_lld_approved_on', true );
		$snap['approved_by'] = (int) get_post_meta( $version->ID, '_lld_approved_by', true );
		$snap['ai']          = (int) get_post_meta( $version->ID, '_lld_ai', true );
		$snap['version_id']  = (int) $version->ID;
		$snap['parent_id']   = (int) $version->post_parent;
		return $snap;
	}

	/**
	 * @param int $post_id Working copy.
	 */
	private static function store_version( $post_id ) {
		$body     = self::canonical( $post_id );
		$encoded  = wp_json_encode( $body );
		$fp       = substr( hash( 'sha256', (string) $encoded ), 0, 16 );
		$existing = self::versions( $post_id );
		$last     = $existing ? $existing[ count( $existing ) - 1 ] : null;
		if ( $last && (string) get_post_meta( $last->ID, '_lld_fingerprint', true ) === $fp ) {
			return;
		}
		$n                    = $last ? ( (int) get_post_meta( $last->ID, '_lld_version', true ) + 1 ) : 1;
		$ai                   = ( self::nonce_ok() && ! empty( $_POST['lld_ai'] ) ) ? 1 : 0;
		self::$capturing      = true;
		$version_id           = wp_insert_post(
			array(
				'post_type'    => get_post_type( $post_id ),
				'post_status'  => 'lld_version',
				'post_parent'  => $post_id,
				'post_title'   => get_the_title( $post_id ),
				'post_content' => wp_slash( (string) $encoded ),
				'post_name'    => 'lldv-' . $post_id . '-' . $n,
			)
		);
		self::$capturing = false;
		if ( is_wp_error( $version_id ) || ! $version_id ) {
			return;
		}
		update_post_meta( $version_id, '_lld_fingerprint', $fp );
		update_post_meta( $version_id, '_lld_version', $n );
		update_post_meta( $version_id, '_lld_ai', $ai );
	}

	/**
	 * @param int $post_id Working copy.
	 * @return array<string,mixed>
	 */
	private static function canonical( $post_id ) {
		$post   = get_post( $post_id );
		$fields = get_post_meta( $post_id, '_lld_fields', true );
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}
		ksort( $fields );
		$tags = wp_get_object_terms( $post_id, 'lld_tag', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $tags ) ) {
			$tags = array();
		}
		$tags = array_map( 'strval', $tags );
		sort( $tags );
		return array(
			'type'   => $post ? $post->post_type : '',
			'title'  => $post ? $post->post_title : '',
			'body'   => $post ? $post->post_content : '',
			'tags'   => $tags,
			'fields' => $fields,
			'links'  => self::link_fingerprints( $fields ),
		);
	}

	/**
	 * @param array<string,mixed> $fields Saved fields.
	 * @return string[]
	 */
	private static function link_fingerprints( $fields ) {
		$out = array();
		foreach ( $fields as $key => $value ) {
			$ids = array();
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( is_numeric( $item ) ) {
						$ids[] = (int) $item;
					}
				}
			} elseif ( is_numeric( $value ) && is_string( $key ) && str_ends_with( $key, '_id' ) ) {
				$ids[] = (int) $value;
			}
			foreach ( $ids as $id ) {
				if ( $id > 0 ) {
					$out[] = self::latest_fingerprint( $id );
				}
			}
		}
		return $out;
	}

	/**
	 * @param int $post_id Linked item.
	 */
	private static function latest_fingerprint( $post_id ) {
		$versions = self::versions( $post_id );
		if ( ! $versions ) {
			return '';
		}
		$last = $versions[ count( $versions ) - 1 ];
		return (string) get_post_meta( $last->ID, '_lld_fingerprint', true );
	}

	/**
	 * @param WP_Post|null $post Post.
	 */
	private static function blocked( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return true;
		}
		$assigned = wp_get_object_terms( $post->ID, 'lld_tag', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $assigned ) ) {
			foreach ( $assigned as $name ) {
				if ( self::banned_name( $name ) ) {
					self::remember( 'Breath-holding is not allowed.' );
					return true;
				}
			}
		}
		if ( 'lld_lesson' === $post->post_type && str_word_count( wp_strip_all_tags( $post->post_content ) ) > 1000 ) {
			self::remember( 'Lessons are limited to 1,000 words.' );
			return true;
		}
		return false;
	}

	/**
	 * @param int $post_id Working copy.
	 */
	private static function save_fields( $post_id ) {
		if ( ! self::nonce_ok() ) {
			return;
		}
		$posted = isset( $_POST['lld_fields'] ) && is_array( $_POST['lld_fields'] ) ? wp_unslash( $_POST['lld_fields'] ) : array();
		$clean  = array();
		foreach ( self::definitions( (string) get_post_type( $post_id ) ) as $field ) {
			$key  = $field[0];
			$kind = $field[2];
			$raw  = isset( $posted[ $key ] ) ? $posted[ $key ] : '';
			if ( 'check' === $kind ) {
				$clean[ $key ] = empty( $posted[ $key ] ) ? 0 : 1;
				continue;
			}
			if ( 'number' === $kind || 'post' === $kind ) {
				$clean[ $key ] = absint( $raw );
				continue;
			}
			if ( 'ingredients' === $kind ) {
				$clean[ $key ] = self::ingredients_from_text( is_string( $raw ) ? $raw : '' );
				continue;
			}
			if ( 'textarea' === $kind ) {
				$clean[ $key ] = sanitize_textarea_field( is_string( $raw ) ? $raw : '' );
				continue;
			}
			$clean[ $key ] = sanitize_text_field( is_string( $raw ) ? $raw : '' );
		}
		update_post_meta( $post_id, '_lld_fields', $clean );
		$tags = self::posted_tags();
		if ( is_array( $tags ) ) {
			wp_set_object_terms( $post_id, $tags, 'lld_tag' );
		}
	}

	/**
	 * @return string[]|null Null when this save did not include the tag field.
	 */
	private static function posted_tags() {
		if ( ! self::nonce_ok() || ! isset( $_POST['lld_tags'] ) ) {
			return null;
		}
		$raw = sanitize_text_field( wp_unslash( $_POST['lld_tags'] ) );
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	private static function nonce_ok() {
		return isset( $_POST['lld_content_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_content_nonce'] ) ), 'lld_content_save' );
	}

	private static function decide() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		if ( ! isset( $_POST['lld_approve_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_approve_nonce'] ) ), 'lld_approve' ) ) {
			return;
		}
		$approve = isset( $_POST['lld_approve'] ) ? absint( $_POST['lld_approve'] ) : 0;
		$back    = isset( $_POST['lld_send_back'] ) ? absint( $_POST['lld_send_back'] ) : 0;
		$id      = $approve ? $approve : $back;
		if ( $id < 1 ) {
			return;
		}
		$version = get_post( $id );
		if ( ! $version || 'lld_version' !== $version->post_status ) {
			return;
		}
		if ( $back ) {
			update_post_meta( $id, '_lld_sent_back', 1 );
			self::remember( 'Sent back. Members still see the last approved version.' );
			return;
		}
		$reasons = self::approval_block( $version );
		if ( $reasons ) {
			self::remember( implode( ' ', $reasons ) );
			return;
		}
		if ( ! get_option( 'lld_members_reviewer' ) ) {
			$qualifications = isset( $_POST['lld_qualifications'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lld_qualifications'] ) ) : '';
			update_option(
				'lld_members_reviewer',
				array(
					'qualifications' => $qualifications,
					'user_id'        => get_current_user_id(),
					'on'             => gmdate( 'Y-m-d' ),
				),
				false
			);
		}
		$snap  = json_decode( (string) $version->post_content, true );
		$text  = is_array( $snap ) ? ( (string) ( $snap['title'] ?? '' ) . ' ' . (string) ( $snap['body'] ?? '' ) ) : '';
		$flags = self::wording_flags( $text );
		if ( $flags ) {
			update_post_meta( $id, '_lld_wording_reviewed', 1 );
			update_post_meta( $id, '_lld_flags', $flags );
		}
		update_post_meta( $id, '_lld_approved_by', get_current_user_id() );
		update_post_meta( $id, '_lld_approved_on', gmdate( 'Y-m-d' ) );
		delete_post_meta( $id, '_lld_sent_back' );
		self::remember( 'Approved. Members can now see this version.' );
	}

	/**
	 * @param WP_Post $version Version row.
	 * @return string[]
	 */
	private static function approval_block( $version ) {
		$snap    = json_decode( (string) $version->post_content, true );
		if ( ! is_array( $snap ) ) {
			$snap = array();
		}
		$tags    = isset( $snap['tags'] ) && is_array( $snap['tags'] ) ? $snap['tags'] : array();
		$tags    = array_map( 'strtolower', array_map( 'strval', $tags ) );
		$text    = strtolower( (string) ( $snap['title'] ?? '' ) . ' ' . (string) ( $snap['body'] ?? '' ) );
		$track   = '';
		$track_id = isset( $snap['fields']['track_id'] ) ? (int) $snap['fields']['track_id'] : 0;
		if ( $track_id > 0 ) {
			$track = strtolower( get_the_title( $track_id ) );
		}
		$reasons = array();
		$stress  = self::tag_hit( $tags, array( 'stress', 'mood', 'sleep-stress' ) ) || false !== strpos( $track, 'sleep' ) || false !== strpos( $track, 'stress' );
		$sleep   = self::tag_hit( $tags, array( 'sleep', 'sleep-stress' ) ) || false !== strpos( $track, 'sleep' );
		$sugar   = self::tag_hit( $tags, array( 'blood-sugar' ) ) || false !== strpos( $track, 'blood sugar' );
		if ( $stress && false === strpos( $text, 'findahelpline.com' ) ) {
			$reasons[] = 'Add an urgent-help link (findahelpline.com) before approving.';
		}
		if ( $sleep && false === strpos( $text, 'loud snoring with daytime sleepiness' ) ) {
			$reasons[] = 'Sleep content must advise seeing a doctor about loud snoring with daytime sleepiness.';
		}
		if ( $sugar && false === strpos( $text, 'diabetes medication' ) ) {
			$reasons[] = 'Blood Sugar Balance content must tell anyone on diabetes medication to talk to their doctor first.';
		}
		$flags = self::wording_flags( (string) ( $snap['title'] ?? '' ) . ' ' . (string) ( $snap['body'] ?? '' ) );
		if ( $flags && empty( $_POST[ 'lld_wording_reviewed_' . $version->ID ] ) ) {
			$reasons[] = 'Review the flagged wording before approving.';
		}
		$recorded = get_option( 'lld_members_reviewer' );
		$entered  = isset( $_POST['lld_qualifications'] ) ? trim( (string) wp_unslash( $_POST['lld_qualifications'] ) ) : '';
		if ( ! $recorded && '' === $entered ) {
			$reasons[] = 'Record your clinical qualifications with this first approval.';
		}
		return $reasons;
	}

	/**
	 * @param string[] $tags    Tag slugs.
	 * @param string[] $needles Wanted slugs.
	 */
	private static function tag_hit( $tags, $needles ) {
		foreach ( $tags as $tag ) {
			if ( in_array( $tag, $needles, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return WP_Post[]
	 */
	private static function pending() {
		$rows = get_posts(
			array(
				'post_type'      => self::types(),
				'post_status'    => 'lld_version',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$on   = (string) get_post_meta( $row->ID, '_lld_approved_on', true );
			$back = (string) get_post_meta( $row->ID, '_lld_sent_back', true );
			if ( '' === $on && '' === $back ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param int $item_id Working copy id.
	 */
	private static function one( $item_id ) {
		$shown = self::shown( $item_id );
		if ( ! $shown ) {
			lld_members_layout(
				array(
					'title'     => "This page isn't available",
					'main'      => lld_state_not_allowed(),
					'signed_in' => true,
					'status'    => 404,
				)
			);
			return;
		}
		$main  = '<h1>' . esc_html( (string) $shown['title'] ) . '</h1>';
		$main .= '<div class="lld-content">' . wp_kses_post( wpautop( (string) $shown['body'] ) ) . '</div>';
		lld_members_layout(
			array(
				'title'     => (string) $shown['title'],
				'main'      => $main,
				'signed_in' => true,
			)
		);
	}

	private static function catalog() {
		$posts = get_posts(
			array(
				'post_type'      => self::types(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$main  = '<h1>Library</h1><ul class="lld-library">';
		foreach ( $posts as $post ) {
			$shown = self::shown( $post->ID );
			if ( ! $shown ) {
				continue;
			}
			$main .= '<li><a href="' . esc_url( home_url( '/members/library/' . $post->ID . '/' ) ) . '">' . esc_html( (string) $shown['title'] ) . '</a></li>';
		}
		$main .= '</ul>';
		lld_members_layout(
			array(
				'title'     => 'Library',
				'main'      => $main,
				'signed_in' => true,
			)
		);
	}

	/**
	 * @param int $goal_id Goal id.
	 */
	private static function goal_line( $goal_id ) {
		$fields     = get_post_meta( $goal_id, '_lld_fields', true );
		$kind       = is_array( $fields ) ? (string) ( $fields['kind'] ?? '' ) : '';
		$track      = is_array( $fields ) ? (int) ( $fields['track_id'] ?? 0 ) : 0;
		$articles   = self::count_reviewed_articles( $goal_id );
		$activities = self::count_approved_activities( $track );
		$ok         = $articles >= 5 && ( 'track goal' !== $kind || $activities >= 7 );
		return 'Shown to members: ' . ( $ok ? 'yes' : 'no' ) . '. Reviewed articles: ' . $articles . '. Approved activities: ' . $activities . '.';
	}

	/**
	 * @param int $goal_id Goal id.
	 */
	private static function count_reviewed_articles( $goal_id ) {
		$tags = wp_get_object_terms( $goal_id, 'lld_tag', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $tags ) || ! $tags ) {
			return 0;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'lld_tag',
						'field'    => 'term_id',
						'terms'    => $tags,
					),
				),
				'meta_query'     => array(
					array(
						'key'   => 'lld_review_status',
						'value' => 'approved',
					),
				),
			)
		);
		return count( $posts );
	}

	/**
	 * @param int $track_id Track id. Zero counts none.
	 */
	private static function count_approved_activities( $track_id ) {
		if ( $track_id < 1 ) {
			return 0;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'lld_activity',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
			)
		);
		$n = 0;
		foreach ( $posts as $post ) {
			$shown = self::shown( $post->ID );
			if ( $shown && (int) ( $shown['fields']['track_id'] ?? 0 ) === $track_id ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * @param array{0:string,1:string,2:string,3?:mixed} $field  Definition.
	 * @param array<string,mixed>                        $stored Stored fields.
	 */
	private static function render_field( $field, $stored ) {
		$key   = $field[0];
		$label = $field[1];
		$kind  = $field[2];
		$value = self::field_value( $stored, $key, $kind );
		$id    = 'lld_fields_' . $key;
		echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br>';
		if ( 'textarea' === $kind || 'ingredients' === $kind ) {
			echo '<textarea class="widefat" rows="4" id="' . esc_attr( $id ) . '" name="lld_fields[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea>';
		} elseif ( 'select' === $kind ) {
			echo '<select id="' . esc_attr( $id ) . '" name="lld_fields[' . esc_attr( $key ) . ']">';
			$choices = isset( $field[3] ) && is_array( $field[3] ) ? $field[3] : array();
			echo '<option value="">—</option>';
			foreach ( $choices as $choice ) {
				echo '<option value="' . esc_attr( (string) $choice ) . '" ' . selected( $value, (string) $choice, false ) . '>' . esc_html( (string) $choice ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'post' === $kind ) {
			$type  = isset( $field[3] ) ? (string) $field[3] : '';
			$posts = $type ? get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'post_parent'    => 0,
					'posts_per_page' => 100,
				)
			) : array();
			echo '<select id="' . esc_attr( $id ) . '" name="lld_fields[' . esc_attr( $key ) . ']">';
			echo '<option value="0">—</option>';
			foreach ( $posts as $item ) {
				echo '<option value="' . (int) $item->ID . '" ' . selected( (string) (int) $item->ID, $value, false ) . '>' . esc_html( $item->post_title ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'check' === $kind ) {
			echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="lld_fields[' . esc_attr( $key ) . ']" value="1" ' . checked( '1', $value, false ) . '>';
		} else {
			$type = 'number' === $kind ? 'number' : 'text';
			echo '<input class="widefat" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="lld_fields[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		}
		echo '</p>';
	}

	/**
	 * @param array<string,mixed> $stored Stored fields.
	 * @param string              $key    Field key.
	 * @param string              $kind   Field kind.
	 */
	private static function field_value( $stored, $key, $kind ) {
		$value = isset( $stored[ $key ] ) ? $stored[ $key ] : '';
		if ( 'ingredients' === $kind && is_array( $value ) ) {
			$lines = array();
			foreach ( $value as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$lines[] = (string) ( $row['name'] ?? '' ) . ' | ' . (string) ( $row['amount'] ?? '' ) . ' | ' . (string) ( $row['unit'] ?? '' );
			}
			return implode( "\n", $lines );
		}
		if ( is_array( $value ) ) {
			return '';
		}
		return (string) $value;
	}

	/**
	 * @param string $text Owner's ingredient lines.
	 * @return array<int,array{name:string,amount:string,unit:string}>
	 */
	private static function ingredients_from_text( $text ) {
		$rows = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$parts  = array_map( 'trim', explode( '|', $line ) );
			$rows[] = array(
				'name'   => sanitize_text_field( $parts[0] ?? '' ),
				'amount' => sanitize_text_field( $parts[1] ?? '' ),
				'unit'   => sanitize_text_field( $parts[2] ?? '' ),
			);
		}
		return $rows;
	}

	/**
	 * @param string $type Post type.
	 * @return array<int,array{0:string,1:string,2:string,3?:mixed}>
	 */
	private static function definitions( $type ) {
		$all = array(
			'lld_goal'      => array(
				array( 'kind', 'Kind', 'select', array( 'track goal', 'free goal' ) ),
				array( 'track_id', 'Linked track', 'post', 'lld_track' ),
				array( 'sort_order', 'Order', 'number' ),
			),
			'lld_track'     => array(
				array( 'sales_page', 'Sales page', 'text' ),
				array( 'starter_guide', 'Starter guide', 'text' ),
				array( 'measures', 'Check-in measures', 'text' ),
				array( 'status', 'Status', 'select', array( 'draft', 'waitlist', 'live' ) ),
			),
			'lld_week'      => array(
				array( 'track_id', 'Track', 'post', 'lld_track' ),
				array( 'week_number', 'Week number', 'number' ),
			),
			'lld_lesson'    => array(
				array( 'track_id', 'Track', 'post', 'lld_track' ),
				array( 'week_number', 'Week number', 'number' ),
				array( 'video_link', 'Video link', 'text' ),
			),
			'lld_activity'  => array(
				array( 'belongs', 'Belongs to', 'select', array( 'track', 'challenge', 'library' ) ),
				array( 'track_id', 'Track', 'post', 'lld_track' ),
				array( 'challenge_id', 'Challenge', 'post', 'lld_challenge' ),
				array( 'week_number', 'Week number', 'number' ),
				array( 'day_number', 'Day number', 'number' ),
				array( 'link', 'Link', 'text' ),
				array( 'difficulty', 'Difficulty', 'select', array( 'easier', 'standard', 'stretch' ) ),
				array( 'in_library', 'In the action library', 'check' ),
			),
			'lld_challenge' => array(
				array( 'month', 'Month', 'text' ),
				array( 'badge_id', 'Finish badge', 'post', 'lld_badge' ),
				array( 'activity_ids', 'Activity ids', 'text' ),
			),
			'lld_recipe'    => array(
				array( 'servings', 'Servings', 'number' ),
				array( 'meal_type', 'Meal type', 'select', array( 'breakfast', 'lunch', 'dinner', 'snack' ) ),
				array( 'diet', 'Diet suitability', 'text' ),
				array( 'tracks', 'Track suitability', 'text' ),
				array( 'ingredients', 'Ingredients (name | amount | unit)', 'ingredients' ),
			),
			'lld_meal'      => array(
				array( 'track_id', 'Track', 'post', 'lld_track' ),
				array( 'diet', 'Diet type', 'text' ),
				array( 'week_number', 'Week number', 'number' ),
				array( 'meals', 'Days and meals', 'textarea' ),
				array( 'swaps', 'Swap options', 'textarea' ),
			),
			'lld_workout'   => array(
				array( 'level', 'Level', 'select', array( 'beginner', 'intermediate', 'active' ) ),
				array( 'place', 'Version', 'select', array( 'home', 'equipment' ) ),
				array( 'week_number', 'Week number', 'number' ),
				array( 'safety', 'Safety advice', 'textarea' ),
				array( 'sessions', 'Sessions', 'textarea' ),
			),
			'lld_rule'      => array(
				array( 'when_text', 'When', 'text' ),
				array( 'person_tags', 'Person tags', 'text' ),
				array( 'suggest', 'Suggest', 'textarea' ),
				array( 'reason', 'Reason shown', 'text' ),
				array( 'priority', 'Priority', 'number' ),
			),
			'lld_badge'     => array(
				array( 'picture', 'Picture', 'text' ),
				array( 'rule', 'Rule', 'textarea' ),
				array( 'weight_loss', 'Is a weight-loss badge', 'check' ),
			),
			'lld_wording'   => array(
				array( 'kind', 'Kind', 'select', array( 'screening-self-harm', 'screening-panic', 'screening-mood', 'reflection-1', 'reflection-2', 'reflection-3' ) ),
			),
			'lld_matrix'    => array(
				array( 'situation', 'Situation', 'text' ),
				array( 'remove_tags', 'Remove content tagged', 'text' ),
				array( 'hide_badges', 'Hide weight-loss badges', 'check' ),
				array( 'tracks', 'Tracks not offered', 'text' ),
			),
			'lld_qa'        => array(
				array( 'session_date', 'Date', 'text' ),
				array( 'session_time', 'Time', 'text' ),
				array( 'session_link', 'Link', 'text' ),
			),
		);
		return isset( $all[ $type ] ) ? $all[ $type ] : array();
	}

	/**
	 * @return array<string,string>
	 */
	private static function situations() {
		return array(
			'unknown'         => 'Unknown: no health consent, or no profile',
			'none'            => 'No conditions',
			'kidney'          => 'Kidney disease',
			'pregnancy'       => 'Pregnancy or breastfeeding',
			'eating-disorder' => 'Eating disorder, now or in the past',
			'diabetes'        => 'Type 2 diabetes',
			'prediabetes'     => 'Prediabetes',
			'blood-pressure'  => 'High blood pressure',
			'cholesterol'     => 'High cholesterol',
		);
	}

	/**
	 * @param string $key Situation key.
	 * @return array<string,mixed>
	 */
	private static function matrix_row( $key ) {
		$found = get_posts(
			array(
				'post_type'      => 'lld_matrix',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => 1,
				'meta_key'       => '_lld_situation',
				'meta_value'     => $key,
			)
		);
		if ( ! $found ) {
			return array();
		}
		$fields = get_post_meta( $found[0]->ID, '_lld_fields', true );
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}
		$fields['note'] = $found[0]->post_content;
		return $fields;
	}

	private static function save_matrix() {
		$notes   = isset( $_POST['note'] ) && is_array( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : array();
		$remove  = isset( $_POST['remove'] ) && is_array( $_POST['remove'] ) ? wp_unslash( $_POST['remove'] ) : array();
		$tracks  = isset( $_POST['tracks'] ) && is_array( $_POST['tracks'] ) ? wp_unslash( $_POST['tracks'] ) : array();
		$hide    = isset( $_POST['hide'] ) && is_array( $_POST['hide'] ) ? wp_unslash( $_POST['hide'] ) : array();
		foreach ( self::situations() as $key => $label ) {
			$note  = sanitize_textarea_field( isset( $notes[ $key ] ) ? (string) $notes[ $key ] : '' );
			$tags  = sanitize_text_field( isset( $remove[ $key ] ) ? (string) $remove[ $key ] : '' );
			$away  = sanitize_text_field( isset( $tracks[ $key ] ) ? (string) $tracks[ $key ] : '' );
			$badges = empty( $hide[ $key ] ) ? 0 : 1;
			if ( '' === $note && '' === $tags && '' === $away && ! $badges ) {
				continue;
			}
			$fields = array(
				'situation'   => $key,
				'remove_tags' => $tags,
				'hide_badges' => $badges,
				'tracks'      => $away,
			);
			$found = get_posts(
				array(
					'post_type'      => 'lld_matrix',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'post_parent'    => 0,
					'posts_per_page' => 1,
					'meta_key'       => '_lld_situation',
					'meta_value'     => $key,
				)
			);
			self::$next_fields = $fields;
			if ( $found ) {
				wp_update_post(
					array(
						'ID'           => $found[0]->ID,
						'post_title'   => $label,
						'post_content' => $note,
					)
				);
			} else {
				wp_insert_post(
					array(
						'post_type'    => 'lld_matrix',
						'post_status'  => 'publish',
						'post_title'   => $label,
						'post_content' => $note,
					)
				);
			}
			self::$next_fields = null;
		}
	}

	/**
	 * @return array<int,array{date:string,time:string,link:string}>
	 */
	private static function sessions() {
		$posts = get_posts(
			array(
				'post_type'      => 'lld_qa',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$fields = get_post_meta( $post->ID, '_lld_fields', true );
			if ( ! is_array( $fields ) ) {
				$fields = array();
			}
			$out[] = array(
				'date' => (string) ( $fields['session_date'] ?? '' ),
				'time' => (string) ( $fields['session_time'] ?? '' ),
				'link' => (string) ( $fields['session_link'] ?? '' ),
			);
		}
		return $out;
	}

	private static function save_qa() {
		$dates = isset( $_POST['qa_date'] ) && is_array( $_POST['qa_date'] ) ? wp_unslash( $_POST['qa_date'] ) : array();
		$times = isset( $_POST['qa_time'] ) && is_array( $_POST['qa_time'] ) ? wp_unslash( $_POST['qa_time'] ) : array();
		$links = isset( $_POST['qa_link'] ) && is_array( $_POST['qa_link'] ) ? wp_unslash( $_POST['qa_link'] ) : array();
		$posts = get_posts(
			array(
				'post_type'      => 'lld_qa',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $dates as $index => $date ) {
			$fields = array(
				'session_date' => sanitize_text_field( (string) $date ),
				'session_time' => sanitize_text_field( isset( $times[ $index ] ) ? (string) $times[ $index ] : '' ),
				'session_link' => esc_url_raw( isset( $links[ $index ] ) ? (string) $links[ $index ] : '' ),
			);
			if ( '' === $fields['session_date'] && '' === $fields['session_time'] && '' === $fields['session_link'] ) {
				continue;
			}
			self::$next_fields = $fields;
			if ( isset( $posts[ $index ] ) ) {
				wp_update_post(
					array(
						'ID'         => $posts[ $index ]->ID,
						'post_title' => 'Live Q&A ' . $fields['session_date'],
					)
				);
			} else {
				wp_insert_post(
					array(
						'post_type'   => 'lld_qa',
						'post_status' => 'publish',
						'post_title'  => 'Live Q&A ' . $fields['session_date'],
					)
				);
			}
			self::$next_fields = null;
		}
	}

	private static function seed_tags() {
		if ( get_option( 'lld_tag_seed' ) ) {
			return;
		}
		$groups = array(
			'goal'   => array( 'healthy-weight', 'blood-sugar', 'fitness', 'sleep-stress', 'heart', 'gut', 'ageing', 'energy' ),
			'person' => array( 'over-50', 'vegetarian', 'vegan', 'beginner' ),
			'safety' => array( 'weight-loss', 'fasting', 'high-intensity', 'high-protein', 'high-salt', 'high-saturated-fat', 'pregnancy-unsafe-food', 'alcohol', 'calorie-mention', 'portion-question' ),
		);
		foreach ( $groups as $kind => $names ) {
			foreach ( $names as $name ) {
				$term = term_exists( $name, 'lld_tag' );
				if ( ! $term ) {
					$term = wp_insert_term( $name, 'lld_tag', array( 'slug' => $name ) );
				}
				if ( is_wp_error( $term ) ) {
					continue;
				}
				$id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
				update_term_meta( $id, 'lld_kind', $kind );
			}
		}
		update_option( 'lld_tag_seed', '1', true );
	}

	/**
	 * @param string $text Notice.
	 */
	private static function remember( $text ) {
		set_transient( 'lld_content_notice_' . get_current_user_id(), $text, 60 );
	}

	private static function echo_notice() {
		$key    = 'lld_content_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_string( $notice ) || '' === $notice ) {
			return;
		}
		delete_transient( $key );
		$ok = 0 === strpos( $notice, 'Approved' ) || 0 === strpos( $notice, 'Sent back' );
		echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $notice ) . '</p></div>';
	}
}
