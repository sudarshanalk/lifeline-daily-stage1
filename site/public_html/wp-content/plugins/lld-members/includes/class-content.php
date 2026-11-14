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
			// P3-35 (Owner Question 160, additions only): Healthy Weight mindset notes and questions.
			'lld_note',
			'lld_question',
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
			'lld_note'      => 'Mindset notes',
			'lld_question'  => 'Programme questions',
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
		if ( self::reload_only( $post->post_type ) ) {
			echo '<p class="description">' . esc_html( self::RELOAD_ONLY_NOTE ) . '</p>';
			return;
		}
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
		$unknown = self::unknown_tags( $tags );
		if ( $unknown ) {
			self::remember( 'Not saved: ' . implode( ', ', $unknown ) . ' is not in the tag list. Add it on the Tags screen first, so a tag is never spelt two ways.' );
			return;
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
			$unknown = self::unknown_tags( $posted );
			if ( $unknown ) {
				self::remember( 'Not saved: ' . implode( ', ', $unknown ) . ' is not in the tag list. Add it on the Tags screen first, so a tag is never spelt two ways.' );
				return;
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
		if ( ! $post || in_array( $post->post_status, array( 'lld_version', 'trash', 'auto-draft' ), true ) || ! in_array( $post->post_type, self::types(), true ) ) {
			return null;
		}
		$best = null;
		foreach ( self::versions( $parent_id ) as $version ) {
			if ( '' === self::record_problem( $version ) ) {
				$best = $version;
			}
		}
		if ( ! $best ) {
			return null;
		}
		return self::read_version( $best );
	}

	/**
	 * One approved version, as members saw it (T2 rule 3), or null when its approval record cannot be trusted.
	 * Added for S2-C5: weekly plans and reviews show the action version they stored.
	 *
	 * @param int $version_id Version row.
	 * @return array<string,mixed>|null
	 */
	public static function shown_version( $version_id ) {
		$version = get_post( (int) $version_id );
		if ( ! $version || 'lld_version' !== $version->post_status || '' !== self::record_problem( $version ) ) {
			return null;
		}
		return self::read_version( $version );
	}

	/**
	 * Why a version's approval record cannot be trusted, or an empty string when it can.
	 * An unapproved version returns "not approved".
	 *
	 * @param WP_Post $version Version row.
	 */
	private static function record_problem( $version ) {
		$on = (string) get_post_meta( $version->ID, '_lld_approved_on', true );
		if ( '' === $on ) {
			return 'not approved';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $on ) ) {
			return 'the approval date is not a date';
		}
		if ( (int) get_post_meta( $version->ID, '_lld_approved_by', true ) < 1 ) {
			return 'the approval does not name who approved it';
		}
		$fingerprint = (string) get_post_meta( $version->ID, '_lld_fingerprint', true );
		if ( '' === $fingerprint ) {
			return 'the version has no fingerprint';
		}
		if ( ! hash_equals( $fingerprint, substr( hash( 'sha256', (string) $version->post_content ), 0, 16 ) ) ) {
			return 'the approved text does not match its fingerprint';
		}
		$stored = (string) get_post_meta( $version->ID, '_lld_stored_hash', true );
		if ( '' !== $stored && ! hash_equals( $stored, hash( 'sha256', (string) $version->post_content ) ) ) {
			return 'the approved text was changed after approval';
		}
		return '';
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
			$approved = null;
			foreach ( self::versions( $post->ID ) as $version ) {
				$on = (string) get_post_meta( $version->ID, '_lld_approved_on', true );
				if ( '' === $on ) {
					$waiting[] = array(
						'id'          => (int) $post->ID,
						'fingerprint' => (string) get_post_meta( $version->ID, '_lld_fingerprint', true ),
					);
					continue;
				}
				$approved = $version;
			}
			if ( $approved ) {
				$problem = self::record_problem( $approved );
				if ( '' !== $problem ) {
					$problems[] = $post->ID . ': version ' . (int) get_post_meta( $approved->ID, '_lld_version', true ) . ' is marked approved, but ' . $problem . '. Members are not shown it.';
				}
			}
			$live = self::shown( $post->ID );
			if ( $live ) {
				$shown[] = array(
					'id'          => (int) $post->ID,
					'version'     => (int) $live['version'],
					'fingerprint' => (string) $live['fingerprint'],
					'approved_on' => (string) $live['approved_on'],
					'approved_by' => (int) $live['approved_by'],
				);
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
			echo '<td>' . self::changes_html( $old, $snap ) . '</td>';
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
			$result = self::save_matrix();
			if ( $result['refused'] ) {
				self::remember( 'Not saved: ' . implode( ' ', $result['refused'] ) . ' Tick "Confirm removal" on that row to save it.' );
			} elseif ( $result['saved'] ) {
				self::remember( 'Safety matrix saved. Approve the new version before members are affected.' );
			} else {
				self::remember( 'Nothing changed.' );
			}
		}
		echo '<div class="wrap"><h1>Safety matrix</h1>';
		self::echo_notice();
		echo '<p>Changes go through the approvals queue. Members are not affected until you approve them.</p>';
		echo '<p>Each row starts from the launch rules in requirements section 8.2. Removing a launch protection needs "Confirm removal" on that row, and the removal is shown again when you approve it.</p>';
		echo '<form method="post">';
		wp_nonce_field( 'lld_matrix', 'lld_matrix_nonce' );
		echo '<table class="widefat lld-matrix"><thead><tr><th>Situation</th><th>Remove content tagged</th><th>Hide weight-loss badges</th><th>Tracks not offered</th><th>Note shown</th><th>Helpline link</th><th>Before exercise</th><th>On high-intensity content</th><th>Confirm removal</th></tr></thead><tbody>';
		$launch = LLD_Members_Safety::launch_rules();
		foreach ( self::situations() as $key => $label ) {
			$row  = self::matrix_row( $key );
			$rule = $row['rule'];
			$base = $launch[ $key ];
			echo '<tr data-lld-situation="' . esc_attr( $key ) . '"><td>' . esc_html( $label );
			if ( $row['waiting'] ) {
				echo '<br><em>A change is waiting for approval.</em>';
			}
			echo '<br><small>Launch: ' . esc_html( self::describe_rule( $base ) ) . '</small></td>';
			echo '<td><input name="remove[' . esc_attr( $key ) . ']" value="' . esc_attr( implode( ', ', $rule['tags'] ) ) . '"></td>';
			echo '<td><input type="checkbox" name="hide[' . esc_attr( $key ) . ']" value="1" ' . checked( true, (bool) $rule['badges'], false ) . '></td>';
			echo '<td><input name="tracks[' . esc_attr( $key ) . ']" value="' . esc_attr( implode( ', ', $rule['tracks'] ) ) . '"></td>';
			echo '<td><textarea name="note[' . esc_attr( $key ) . ']" rows="2">' . esc_textarea( $rule['note'] ) . '</textarea></td>';
			echo '<td><input type="url" name="help[' . esc_attr( $key ) . ']" value="' . esc_attr( $rule['help_link'] ) . '"></td>';
			echo '<td><textarea name="exercise[' . esc_attr( $key ) . ']" rows="2">' . esc_textarea( $rule['exercise_note'] ) . '</textarea></td>';
			echo '<td><textarea name="intense[' . esc_attr( $key ) . ']" rows="2">' . esc_textarea( $rule['intense_note'] ) . '</textarea></td>';
			echo '<td><label><input type="checkbox" name="confirm[' . esc_attr( $key ) . ']" value="1"> Confirm removal</label></td></tr>';
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
		$list = array();
		foreach ( self::flagged_phrases() as $phrase ) {
			if ( preg_match( '/\b' . str_replace( ' ', '\s+', preg_quote( $phrase, '/' ) ) . '\b/i', (string) $text ) ) {
				$list[] = $phrase;
			}
		}
		return $list;
	}

	/**
	 * Phrases that read as diagnosis, treatment or a promise of a cure. The list supports the owner's review;
	 * it does not replace it (NFR2-07-AS-03).
	 *
	 * @return string[]
	 */
	public static function flagged_phrases() {
		return array( 'cure', 'cures', 'cured', 'curing', 'treats', 'treatment for', 'heals', 'reverses', 'reversing', 'diagnose', 'diagnosis', 'prescription', 'guaranteed', 'miracle', 'detox', 'clinically proven', 'stop taking', 'instead of medication' );
	}

	/**
	 * Tags typed on an item that are not in the managed list (B2). Saving them would create a new term.
	 *
	 * @param string[] $tags Typed tags.
	 * @return string[]
	 */
	public static function unknown_tags( $tags ) {
		$out = array();
		foreach ( (array) $tags as $tag ) {
			$tag = trim( (string) $tag );
			if ( '' === $tag ) {
				continue;
			}
			if ( ! term_exists( sanitize_title( $tag ), 'lld_tag' ) && ! term_exists( $tag, 'lld_tag' ) ) {
				$out[] = $tag;
			}
		}
		return $out;
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
		global $wpdb;
		if ( (string) get_post_field( 'post_content', $version_id, 'raw' ) !== (string) $encoded ) {
			// Content filters (kses, link rel) must not change a fingerprinted snapshot.
			$wpdb->update( $wpdb->posts, array( 'post_content' => (string) $encoded ), array( 'ID' => (int) $version_id ), array( '%s' ), array( '%d' ) );
			clean_post_cache( (int) $version_id );
		}
		update_post_meta( $version_id, '_lld_fingerprint', $fp );
		update_post_meta( $version_id, '_lld_stored_hash', hash( 'sha256', (string) get_post_field( 'post_content', $version_id, 'raw' ) ) );
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
	 * Fingerprints of the linked items' approved versions, the versions members are actually given.
	 *
	 * @param array<string,mixed> $fields Saved fields.
	 * @return string[] "id:fingerprint", or "id:" while a linked item has no approved version.
	 */
	private static function link_fingerprints( $fields ) {
		$out = array();
		foreach ( self::linked_ids( $fields ) as $id ) {
			$shown = self::shown( $id );
			$out[] = $id . ':' . ( is_array( $shown ) ? (string) $shown['fingerprint'] : '' );
		}
		return $out;
	}

	/**
	 * Items a working copy links to: *_id fields, activity_ids, and recipe ids inside meals and swaps.
	 *
	 * @param array<string,mixed> $fields Saved fields.
	 * @return int[]
	 */
	private static function linked_ids( $fields ) {
		$ids = array();
		foreach ( $fields as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, array( 'meals', 'swaps' ), true ) && is_array( $value ) ) {
				$value = (string) wp_json_encode( $value );
			}
			if ( is_array( $value ) ) {
				// A list of ids. Nested arrays (ingredient rows and their amounts) are not links.
				foreach ( $value as $item ) {
					if ( is_numeric( $item ) ) {
						$ids[] = (int) $item;
					}
				}
			} elseif ( str_ends_with( $key, '_id' ) && is_numeric( $value ) ) {
				$ids[] = (int) $value;
			} elseif ( 'activity_ids' === $key ) {
				foreach ( preg_split( '/\s*,\s*/', (string) $value ) as $part ) {
					if ( is_numeric( $part ) ) {
						$ids[] = (int) $part;
					}
				}
			} elseif ( in_array( $key, array( 'meals', 'swaps' ), true ) ) {
				$decoded = json_decode( (string) $value, true );
				if ( is_array( $decoded ) ) {
					array_walk_recursive(
						$decoded,
						static function ( $item ) use ( &$ids ) {
							if ( is_numeric( $item ) ) {
								$ids[] = (int) $item;
							}
						}
					);
				}
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * A linked item has a new approved version: every item linking to it gets a new version, which needs
	 * a fresh approval (B16). Members keep the parent's last approved version until then.
	 *
	 * @param int $item_id Linked item that changed.
	 */
	private static function reversion_parents( $item_id ) {
		global $wpdb;
		$item_id = (int) $item_id;
		// Only working copies hold _lld_fields. The LIKE narrows the scan; linked_ids() decides.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_lld_fields' AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( (string) $item_id ) . '%'
			)
		);
		foreach ( (array) $rows as $row ) {
			$parent = (int) $row->post_id;
			$post   = get_post( $parent );
			if ( $parent === $item_id || ! $post || ! in_array( $post->post_type, self::types(), true ) || in_array( $post->post_status, array( 'lld_version', 'trash', 'auto-draft', 'inherit' ), true ) ) {
				continue;
			}
			$fields = maybe_unserialize( $row->meta_value );
			if ( is_array( $fields ) && in_array( $item_id, self::linked_ids( $fields ), true ) ) {
				self::store_version( $parent );
			}
		}
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
	 * P3-35 (Owner Question 160, additions only): the Healthy Weight mindset notes and programme questions carry fields
	 * this form cannot show (choices, rules, the note's track and day), so they are not edited here: their fields and
	 * tags are kept exactly as the approved content loader stored them.
	 */
	const RELOAD_ONLY_NOTE = 'This item is changed only by reloading the owner-approved content (wp lld content load). Its fields and tags cannot be edited here, and saving keeps them as loaded.';

	/**
	 * True for a type whose fields change only by reloading approved content (P3-35).
	 *
	 * @param string $type Post type.
	 */
	public static function reload_only( $type ) {
		return in_array( (string) $type, array( 'lld_note', 'lld_question' ), true );
	}

	/**
	 * @param int $post_id Working copy.
	 */
	private static function save_fields( $post_id ) {
		if ( ! self::nonce_ok() ) {
			return;
		}
		if ( self::reload_only( (string) get_post_type( $post_id ) ) ) {
			return; // P3-35: fields and tags kept as loaded; they change only by reloading approved content
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
		self::reversion_parents( (int) $version->post_parent );
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
	 * What changed between the approved version and the one waiting: text, tags, fields and linked items.
	 *
	 * @param array<string,mixed>|null $old  Approved snapshot, or null.
	 * @param array<string,mixed>      $snap Waiting snapshot.
	 */
	private static function changes_html( $old, $snap ) {
		$type  = (string) ( $snap['type'] ?? '' );
		$html  = '<p class="lld-was">' . esc_html( $old ? (string) $old['body'] : 'Nothing approved yet' ) . '</p>';
		$html .= '<p class="lld-now">' . esc_html( (string) ( $snap['body'] ?? '' ) ) . '</p>';
		$lines = array();
		$was   = is_array( $old ) ? $old : array( 'title' => '', 'tags' => array(), 'fields' => array(), 'links' => array() );
		if ( (string) ( $was['title'] ?? '' ) !== (string) ( $snap['title'] ?? '' ) ) {
			$lines[] = 'Title: "' . (string) ( $was['title'] ?? '' ) . '" to "' . (string) ( $snap['title'] ?? '' ) . '"';
		}
		$old_tags = isset( $was['tags'] ) && is_array( $was['tags'] ) ? array_map( 'strval', $was['tags'] ) : array();
		$new_tags = isset( $snap['tags'] ) && is_array( $snap['tags'] ) ? array_map( 'strval', $snap['tags'] ) : array();
		$added    = array_diff( $new_tags, $old_tags );
		$removed  = array_diff( $old_tags, $new_tags );
		if ( $added ) {
			$lines[] = 'Tags added: ' . implode( ', ', $added );
		}
		if ( $removed ) {
			$lines[] = 'Tags removed: ' . implode( ', ', $removed );
		}
		$old_fields = isset( $was['fields'] ) && is_array( $was['fields'] ) ? $was['fields'] : array();
		$new_fields = isset( $snap['fields'] ) && is_array( $snap['fields'] ) ? $snap['fields'] : array();
		$labels     = array();
		foreach ( self::definitions( $type ) as $field ) {
			$labels[ $field[0] ] = $field[1];
		}
		foreach ( array_unique( array_merge( array_keys( $old_fields ), array_keys( $new_fields ) ) ) as $key ) {
			$before = self::field_text( $old_fields[ $key ] ?? '' );
			$after  = self::field_text( $new_fields[ $key ] ?? '' );
			if ( $before !== $after ) {
				$lines[] = ( $labels[ $key ] ?? (string) $key ) . ': "' . $before . '" to "' . $after . '"';
			}
		}
		if ( wp_json_encode( $was['links'] ?? array() ) !== wp_json_encode( $snap['links'] ?? array() ) && is_array( $old ) ) {
			$lines[] = 'A linked recipe or activity has changed.';
		}
		if ( 'lld_matrix' === $type ) {
			$key   = (string) ( $new_fields['situation'] ?? '' );
			$drops = LLD_Members_Safety::dropped( $key, LLD_Members_Safety::from_fields( $new_fields, (string) ( $snap['body'] ?? '' ) ) );
			if ( $drops ) {
				$html .= '<p class="lld-matrix-drops"><strong>Removes launch protections: ' . esc_html( implode( ', ', $drops ) ) . '</strong></p>';
			}
		}
		if ( $lines ) {
			$html .= '<ul class="lld-changes">';
			foreach ( $lines as $line ) {
				$html .= '<li>' . esc_html( $line ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html;
	}

	/**
	 * @param mixed $value Field value.
	 */
	private static function field_text( $value ) {
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		return (string) $value;
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
		$out    = array();
		$latest = array();
		foreach ( $rows as $row ) {
			$parent = (int) $row->post_parent;
			if ( ! isset( $latest[ $parent ] ) ) {
				$shown             = self::shown( $parent );
				$latest[ $parent ] = $shown ? (int) $shown['version_id'] : 0;
			}
			// A version older than the one members see has been superseded; it is not offered for approval.
			if ( (int) $row->ID < $latest[ $parent ] ) {
				continue;
			}
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
		$shown = in_array( get_post_type( $item_id ), self::not_in_library(), true ) ? null : self::shown( $item_id );
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
		$member_id = LLD_Members_Access::current_member_id();
		if ( class_exists( 'LLD_Members_Safety' ) && ! LLD_Members_Safety::allows( $member_id, $shown ) ) {
			lld_members_layout(
				array(
					'title'     => 'Not suitable for you',
					'main'      => LLD_Members_Safety::not_suitable( $member_id, 'This is not suitable for you.', home_url( '/members/home/' ), 'Back to today' ),
					'signed_in' => true,
				)
			);
			return;
		}
		if ( class_exists( 'LLD_Members_Programme' ) && ! LLD_Members_Programme::item_open( $member_id, $shown ) ) { // S2-C5: day 8 onwards by any route.
			LLD_Members_Programme::item_locked();
			return;
		}
		$cautions = class_exists( 'LLD_Members_Safety' ) ? LLD_Members_Safety::cautions( $member_id, $shown ) : array();
		if ( class_exists( 'LLD_Members_Personal' ) ) {
			LLD_Members_Personal::remember_open( $member_id, 'c' . $item_id );
		}
		if ( isset( $_GET['download'] ) ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo (string) $shown['title'] . "\n" . ( $cautions ? implode( "\n", $cautions ) . "\n" : '' ) . (string) $shown['body'];
			exit;
		}
		$main = '<h1>' . esc_html( (string) $shown['title'] ) . '</h1>';
		foreach ( $cautions as $caution ) {
			$main .= lld_caution( $caution );
		}
		$main .= '<div class="lld-content">' . wp_kses_post( wpautop( (string) $shown['body'] ) ) . '</div>';
		lld_members_layout(
			array(
				'title'     => (string) $shown['title'],
				'main'      => $main,
				'signed_in' => true,
			)
		);
	}

	/**
	 * Owner records that are not member-facing content items.
	 *
	 * @return string[]
	 */
	private static function not_in_library() {
		return array( 'lld_matrix', 'lld_wording', 'lld_rule', 'lld_qa' );
	}

	/**
	 * Member Library (P3-36 A8 / FR3-111): search, type chips, one card per title, approved items only.
	 */
	private static function catalog() {
		$member_id = LLD_Members_Access::current_member_id();
		// Owner records stay out of the Library (FR2-08 AS-04); library_cards only lists these types.
		$types  = array_values( array_diff( self::types(), self::not_in_library() ) );
		$cards  = self::library_cards( $member_id, $types );
		$filters = self::library_filters();
		$groups  = array();
		foreach ( $cards as $card ) {
			$groups[ $card['type'] ][] = $card;
		}

		wp_enqueue_style( 'lld-members-forms', plugins_url( 'assets/stage3-forms.css', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION );
		wp_enqueue_script( 'lld-members-forms', plugins_url( 'assets/stage3-forms.js', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION, true );

		$main  = '<div class="lld-library-page" data-lld-library>';
		$main .= '<h1>Library</h1>';
		$main .= '<div class="lld-library-tools">';
		$main .= '<p class="lld-field"><label for="lld_library_search">Search</label>';
		$main .= '<input id="lld_library_search" type="search" data-lld-library-search autocomplete="off"></p>';
		$main .= '<div class="lld-library-chips" role="group" aria-label="Filter by type">';
		$main .= '<button type="button" class="lld-chip-filter is-active" data-lld-library-filter="" aria-pressed="true">All</button>';
		foreach ( $filters as $type => $label ) {
			$main .= '<button type="button" class="lld-chip-filter" data-lld-library-filter="' . esc_attr( $type ) . '" aria-pressed="false">' . esc_html( $label ) . '</button>';
		}
		$main .= '</div>';
		// Filled by the page script when a search or filter matches nothing, and read out by screen readers.
		$main .= '<p class="lld-library-status" role="status" aria-live="polite" data-lld-library-status data-lld-library-none="No matching items."></p>';
		$main .= '</div>';

		$type_labels = self::type_labels();
		foreach ( $type_labels as $type => $label ) {
			if ( empty( $groups[ $type ] ) ) {
				continue;
			}
			$main .= '<section class="lld-library-group" data-lld-library-group="' . esc_attr( $type ) . '">';
			$main .= '<h2>' . esc_html( $label ) . '</h2>';
			$main .= '<ul class="lld-library-cards">';
			foreach ( $groups[ $type ] as $card ) {
				$tracks = $card['tracks'] ? implode( ', ', $card['tracks'] ) : '';
				$main  .= '<li class="lld-library-card" data-lld-library-card data-lld-type="' . esc_attr( $type ) . '" data-lld-title="' . esc_attr( self::library_key( $card['title'] ) ) . '">';
				$main  .= '<a href="' . esc_url( home_url( '/members/library/' . (int) $card['id'] . '/' ) ) . '">';
				$main  .= '<span class="lld-library-card-title">' . esc_html( $card['title'] ) . '</span>';
				$main  .= '<span class="lld-library-card-meta">' . esc_html( $label );
				if ( '' !== $card['length'] ) {
					$main .= ' · ' . esc_html( $card['length'] );
				}
				$main .= '</span>';
				if ( '' !== $tracks ) {
					$main .= '<span class="lld-library-card-tracks">' . esc_html( $tracks ) . '</span>';
				}
				$main .= '</a></li>';
			}
			$main .= '</ul></section>';
		}
		$main .= '</div>';

		ob_start();
		lld_members_layout(
			array(
				'title'     => 'Library',
				'main'      => $main,
				'signed_in' => true,
			)
		);
		$page = (string) ob_get_clean();
		ob_start();
		wp_print_styles( array( 'lld-members-forms' ) );
		$styles = (string) ob_get_clean();
		ob_start();
		wp_print_scripts( array( 'lld-members-forms' ) );
		$scripts = (string) ob_get_clean();
		$head    = strpos( $page, '</head>' );
		if ( false !== $head ) {
			$page = substr_replace( $page, $styles, $head, 0 );
		}
		$foot = strrpos( $page, '</body>' );
		if ( false !== $foot ) {
			$page = substr_replace( $page, $scripts, $foot, 0 );
		}
		echo $page; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Filter chip types shown on the Library (FR3-111). Labels match the existing content type names.
	 *
	 * @return array<string,string>
	 */
	private static function library_filters() {
		return array(
			'lld_recipe'    => 'Recipes',
			'lld_workout'   => 'Workout plans',
			'lld_challenge' => 'Challenges',
			'lld_lesson'    => 'Lessons',
		);
	}

	/**
	 * Plural labels for every content type that can appear in the Library.
	 *
	 * @return array<string,string>
	 */
	private static function type_labels() {
		return array(
			'lld_recipe'    => 'Recipes',
			'lld_workout'   => 'Workout plans',
			'lld_challenge' => 'Challenges',
			'lld_lesson'    => 'Lessons',
			'lld_activity'  => 'Activities',
			'lld_meal'      => 'Meal plans',
			'lld_badge'     => 'Badges',
			'lld_note'      => 'Mindset notes',
			'lld_question'  => 'Programme questions',
			'lld_goal'      => 'Goals',
			'lld_track'     => 'Tracks',
			'lld_week'      => 'Weeks',
		);
	}

	/**
	 * Approved, safe library cards: one per title within a type, with track names merged.
	 *
	 * @param int      $member_id Member number.
	 * @param string[] $types     Post types already filtered by not_in_library().
	 * @return array<int,array{id:int,type:string,title:string,length:string,tracks:string[]}>
	 */
	private static function library_cards( $member_id, array $types ) {
		$posts = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$active = self::library_active_track_id( $member_id );
		$names  = array();
		$items  = array();
		foreach ( $posts as $post ) {
			$shown = self::shown( $post->ID );
			if ( ! $shown ) {
				continue;
			}
			if ( class_exists( 'LLD_Members_Safety' ) && ! LLD_Members_Safety::allows( $member_id, $shown ) ) {
				continue;
			}
			// Only the approved title is ever shown (FR3-111): an item without one is left out, never named from its working copy.
			$title = isset( $shown['title'] ) ? trim( (string) $shown['title'] ) : '';
			if ( '' === $title ) {
				continue;
			}
			$type     = (string) $post->post_type;
			$fields   = isset( $shown['fields'] ) && is_array( $shown['fields'] ) ? $shown['fields'] : array();
			$track_id = (int) ( $fields['track_id'] ?? 0 );
			if ( $track_id > 0 && ! isset( $names[ $track_id ] ) ) {
				$track              = self::shown( $track_id );
				$names[ $track_id ] = $track && isset( $track['title'] ) ? trim( (string) $track['title'] ) : '';
			}
			$items[] = array(
				'id'       => (int) $post->ID,
				'type'     => $type,
				'title'    => $title,
				'length'   => self::library_length( $type, $shown ),
				'track_id' => $track_id,
				'track'    => $track_id > 0 ? $names[ $track_id ] : '',
			);
		}
		return self::library_merge( $items, $active );
	}

	/**
	 * One card per title within a type, naming every track the title is used on (FR3-111 AS-03). When several
	 * items share a title the card opens the one on the member's active track, otherwise the first.
	 *
	 * @param array<int,array<string,mixed>> $items           id, type, title, length, track_id and track (approved name, or '').
	 * @param int                            $active_track_id The member's active track, or 0.
	 * @return array<int,array{id:int,type:string,title:string,length:string,tracks:string[]}>
	 */
	public static function library_merge( array $items, $active_track_id = 0 ) {
		$by_key = array();
		foreach ( $items as $item ) {
			$key = (string) $item['type'] . "\0" . self::library_key( (string) $item['title'] );
			if ( ! isset( $by_key[ $key ] ) ) {
				$by_key[ $key ] = array(
					'id'     => (int) $item['id'],
					'type'   => (string) $item['type'],
					'title'  => (string) $item['title'],
					'length' => (string) $item['length'],
					'tracks' => array(),
				);
			}
			$track = (string) ( $item['track'] ?? '' );
			if ( '' !== $track && ! in_array( $track, $by_key[ $key ]['tracks'], true ) ) {
				$by_key[ $key ]['tracks'][] = $track;
			}
			if ( $active_track_id > 0 && (int) ( $item['track_id'] ?? 0 ) === (int) $active_track_id ) {
				$by_key[ $key ]['id']     = (int) $item['id'];
				$by_key[ $key ]['length'] = (string) $item['length'];
			}
		}
		return array_values( $by_key );
	}

	/**
	 * A title in lower case, for matching titles and for the search box.
	 *
	 * @param string $title Title.
	 */
	public static function library_key( $title ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $title, 'UTF-8' ) : strtolower( (string) $title );
	}

	/**
	 * The member's active track, looked up once for the whole list.
	 *
	 * @param int $member_id Member number.
	 */
	private static function library_active_track_id( $member_id ) {
		if ( ! class_exists( 'LLD_Members_Programme' ) ) {
			return 0;
		}
		$row = LLD_Members_Programme::active( $member_id );
		return $row ? (int) LLD_Members_Programme::track_id( (string) $row->track_key ) : 0;
	}

	/**
	 * Length or size line when the approved item already has one. Never invent a number.
	 *
	 * @param string              $type  Post type.
	 * @param array<string,mixed> $shown Approved snapshot.
	 */
	private static function library_length( $type, array $shown ) {
		$fields = isset( $shown['fields'] ) && is_array( $shown['fields'] ) ? $shown['fields'] : array();
		if ( 'lld_recipe' === $type && '' !== trim( (string) ( $fields['servings'] ?? '' ) ) ) {
			$n = (string) $fields['servings'];
			return $n . ( '1' === $n ? ' serving' : ' servings' );
		}
		if ( 'lld_workout' === $type && '' !== trim( (string) ( $fields['level'] ?? '' ) ) ) {
			return ucfirst( (string) $fields['level'] );
		}
		if ( 'lld_lesson' === $type ) {
			$words = str_word_count( wp_strip_all_tags( (string) ( $shown['body'] ?? '' ) ) );
			return $words > 0 ? $words . ' words' : '';
		}
		if ( 'lld_activity' === $type && '' !== trim( (string) ( $fields['difficulty'] ?? '' ) ) ) {
			return ucfirst( (string) $fields['difficulty'] );
		}
		return '';
	}

	/**
	 * @param int $goal_id Goal id.
	 */
	private static function goal_line( $goal_id ) {
		$snap = self::shown( $goal_id );
		if ( ! $snap ) {
			$ready = self::goal_ready( self::canonical( $goal_id ) );
			return 'Shown to members: no, it is waiting for approval. Reviewed articles: ' . $ready['articles'] . '. Approved activities: ' . $ready['activities'] . '.';
		}
		$ready = self::goal_ready( $snap );
		return 'Shown to members: ' . ( $ready['ok'] ? 'yes' : 'no' ) . '. Reviewed articles: ' . $ready['articles'] . '. Approved activities: ' . $ready['activities'] . '.';
	}

	/**
	 * @param array<string,mixed> $snap Goal snapshot (approved version, or the working copy for the owner's line).
	 * @return array{ok:bool,articles:int,activities:int,kind:string,track_id:int}
	 */
	private static function goal_ready( $snap ) {
		$fields     = isset( $snap['fields'] ) && is_array( $snap['fields'] ) ? $snap['fields'] : array();
		$tags       = isset( $snap['tags'] ) && is_array( $snap['tags'] ) ? array_map( 'strval', $snap['tags'] ) : array();
		$kind       = (string) ( $fields['kind'] ?? '' );
		$track      = (int) ( $fields['track_id'] ?? 0 );
		$articles   = self::count_reviewed_articles( $tags );
		$activities = self::count_approved_activities( $track );
		return array(
			'ok'         => $articles >= 5 && ( 'track goal' !== $kind || $activities >= 7 ),
			'articles'   => $articles,
			'activities' => $activities,
			'kind'       => $kind,
			'track_id'   => $track,
		);
	}

	/**
	 * Approved goal snapshots, in title order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function approved_goals() {
		$posts = get_posts(
			array(
				'post_type'      => 'lld_goal',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$snap = self::shown( $post->ID );
			if ( is_array( $snap ) && '' !== trim( (string) ( $snap['title'] ?? '' ) ) ) {
				$out[] = $snap;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['title'], (string) $b['title'] );
			}
		);
		return $out;
	}

	/**
	 * True when at least one item of this type has an approved version members may see. Screens use it to tell
	 * "nothing has been approved yet" apart from "the safety rules leave nothing for you" (section 3.3 empty state).
	 *
	 * @param string $type Content type, for example lld_meal.
	 */
	public static function has_approved( $type ) {
		static $seen = array();
		if ( isset( $seen[ $type ] ) ) {
			return $seen[ $type ];
		}
		$ids = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$seen[ $type ] = false;
		foreach ( $ids as $id ) {
			if ( null !== self::shown( (int) $id ) ) {
				$seen[ $type ] = true;
				break;
			}
		}
		return $seen[ $type ];
	}

	/**
	 * Goals a member is allowed to choose: approved, with enough content, and not a track the safety matrix
	 * keeps from this member.
	 *
	 * @param int $member_id Member number, or 0 for every member.
	 * @return array<int,array{id:int,title:string,key:string,kind:string}>
	 */
	public static function member_goals( $member_id = 0 ) {
		$member_id = (int) $member_id;
		$out       = array();
		foreach ( self::approved_goals() as $snap ) {
			$ready = self::goal_ready( $snap );
			if ( ! $ready['ok'] ) {
				continue;
			}
			$title = (string) $snap['title'];
			if ( $member_id > 0 && class_exists( 'LLD_Members_Safety' ) ) {
				if ( ! LLD_Members_Safety::track_allowed( $member_id, $title ) ) {
					continue;
				}
				if ( $ready['track_id'] > 0 ) {
					$track = self::shown( $ready['track_id'] );
					$name  = is_array( $track ) ? (string) $track['title'] : (string) get_the_title( $ready['track_id'] );
					if ( '' !== $name && ! LLD_Members_Safety::track_allowed( $member_id, $name ) ) {
						continue;
					}
				}
			}
			$out[] = array(
				'id'    => (int) $snap['parent_id'],
				'title' => $title,
				'key'   => sanitize_title( $title ),
				'kind'  => $ready['kind'],
				'line'  => wp_trim_words( wp_strip_all_tags( (string) ( $snap['body'] ?? '' ) ), 16, '...' ),
			);
		}
		return $out;
	}

	/**
	 * Approved goal titles that are still short of the member threshold.
	 *
	 * @return string[]
	 */
	public static function goal_gaps() {
		$out = array();
		foreach ( self::approved_goals() as $snap ) {
			if ( ! self::goal_ready( $snap )['ok'] ) {
				$out[] = (string) $snap['title'];
			}
		}
		return $out;
	}

	/**
	 * Store the current fields and mark that version approved.
	 *
	 * @param int $post_id Working copy.
	 */
	public static function approve_now( $post_id ) {
		self::store_version( (int) $post_id );
		$versions = self::versions( (int) $post_id );
		if ( ! $versions ) {
			return 0;
		}
		$last = $versions[ count( $versions ) - 1 ];
		update_post_meta( $last->ID, '_lld_approved_on', gmdate( 'Y-m-d' ) );
		update_post_meta( $last->ID, '_lld_approved_by', get_current_user_id() ? (int) get_current_user_id() : 1 );
		self::reversion_parents( (int) $post_id );
		return (int) $last->ID;
	}

	/**
	 * @param string[] $slugs Goal tag slugs from the approved goal.
	 */
	private static function count_reviewed_articles( $slugs ) {
		$slugs = array_values( array_filter( array_map( 'sanitize_title', (array) $slugs ) ) );
		if ( ! $slugs ) {
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
						'field'    => 'slug',
						'terms'    => $slugs,
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
				array( 'help_link', 'Helpline link', 'text' ),
				array( 'exercise_note', 'Before exercise', 'textarea' ),
				array( 'intense_note', 'On high-intensity content', 'textarea' ),
				array( 'confirmed_drops', 'Launch protections the owner confirmed removing', 'text' ),
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
	 * The row as the owner last saved it, prefilled from the launch rules.
	 *
	 * @param string $key Situation key.
	 * @return array{rule:array<string,mixed>,waiting:bool,id:int}
	 */
	private static function matrix_row( $key ) {
		$found = self::matrix_post( $key );
		if ( ! $found ) {
			return array(
				'rule'    => LLD_Members_Safety::launch_rules()[ $key ],
				'waiting' => false,
				'id'      => 0,
			);
		}
		$fields = get_post_meta( $found->ID, '_lld_fields', true );
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}
		$waiting = false;
		foreach ( self::versions( $found->ID ) as $version ) {
			$waiting = '' === (string) get_post_meta( $version->ID, '_lld_approved_on', true ) && '' === (string) get_post_meta( $version->ID, '_lld_sent_back', true );
		}
		return array(
			'rule'    => LLD_Members_Safety::effective( $key, $fields, (string) $found->post_content ),
			'waiting' => $waiting,
			'id'      => (int) $found->ID,
		);
	}

	/**
	 * @param string $key Situation key.
	 * @return WP_Post|null
	 */
	private static function matrix_post( $key ) {
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
		return $found ? $found[0] : null;
	}

	/**
	 * Save changed rows. A row that removes a launch protection is refused unless its removal is confirmed.
	 *
	 * @return array{saved:int,refused:string[]}
	 */
	private static function save_matrix() {
		$posted = array();
		foreach ( array( 'note', 'remove', 'tracks', 'hide', 'help', 'exercise', 'intense', 'confirm' ) as $name ) {
			$posted[ $name ] = isset( $_POST[ $name ] ) && is_array( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : array();
		}
		$saved   = 0;
		$refused = array();
		foreach ( self::situations() as $key => $label ) {
			$row = array(
				'tags'          => LLD_Members_Safety::split_tags( sanitize_text_field( (string) ( $posted['remove'][ $key ] ?? '' ) ) ),
				'badges'        => ! empty( $posted['hide'][ $key ] ),
				'tracks'        => LLD_Members_Safety::split_labels( sanitize_text_field( (string) ( $posted['tracks'][ $key ] ?? '' ) ) ),
				'note'          => trim( sanitize_textarea_field( (string) ( $posted['note'][ $key ] ?? '' ) ) ),
				'help_link'     => esc_url_raw( trim( (string) ( $posted['help'][ $key ] ?? '' ) ), array( 'https' ) ),
				'exercise_note' => trim( sanitize_textarea_field( (string) ( $posted['exercise'][ $key ] ?? '' ) ) ),
				'intense_note'  => trim( sanitize_textarea_field( (string) ( $posted['intense'][ $key ] ?? '' ) ) ),
			);
			if ( self::same_rule( $row, self::matrix_row( $key )['rule'] ) ) {
				continue;
			}
			$drops = LLD_Members_Safety::dropped( $key, $row );
			if ( $drops && empty( $posted['confirm'][ $key ] ) ) {
				$refused[] = $label . ' would remove launch protections (' . implode( ', ', $drops ) . ').';
				continue;
			}
			$fields            = array(
				'situation'       => $key,
				'remove_tags'     => implode( ', ', $row['tags'] ),
				'hide_badges'     => $row['badges'] ? 1 : 0,
				'tracks'          => implode( ', ', $row['tracks'] ),
				'help_link'       => $row['help_link'],
				'exercise_note'   => $row['exercise_note'],
				'intense_note'    => $row['intense_note'],
				'confirmed_drops' => implode( ', ', $drops ),
			);
			$found             = self::matrix_post( $key );
			self::$next_fields = $fields;
			if ( $found ) {
				wp_update_post(
					array(
						'ID'           => $found->ID,
						'post_title'   => $label,
						'post_content' => $row['note'],
					)
				);
			} else {
				wp_insert_post(
					array(
						'post_type'    => 'lld_matrix',
						'post_status'  => 'publish',
						'post_title'   => $label,
						'post_content' => $row['note'],
					)
				);
			}
			self::$next_fields = null;
			++$saved;
		}
		return array(
			'saved'   => $saved,
			'refused' => $refused,
		);
	}

	/**
	 * @param array<string,mixed> $a Rule.
	 * @param array<string,mixed> $b Rule.
	 */
	private static function same_rule( $a, $b ) {
		$norm = static function ( $rule ) {
			$tags   = array_map( 'strval', (array) $rule['tags'] );
			$tracks = array_map( 'strtolower', array_map( 'strval', (array) $rule['tracks'] ) );
			sort( $tags );
			sort( $tracks );
			$out = array( $tags, (bool) $rule['badges'], $tracks );
			foreach ( LLD_Members_Safety::text_fields() as $field ) {
				$out[] = trim( (string) ( $rule[ $field ] ?? '' ) );
			}
			return $out;
		};
		return $norm( $a ) === $norm( $b );
	}

	/**
	 * @param array<string,mixed> $rule Rule.
	 */
	private static function describe_rule( $rule ) {
		$parts   = array();
		$parts[] = $rule['tags'] ? 'remove ' . implode( ', ', $rule['tags'] ) : 'remove nothing';
		if ( $rule['badges'] ) {
			$parts[] = 'hide weight-loss badges';
		}
		if ( $rule['tracks'] ) {
			$parts[] = 'not offered: ' . implode( ', ', $rule['tracks'] );
		}
		foreach ( LLD_Members_Safety::text_fields() as $field ) {
			if ( '' !== (string) $rule[ $field ] ) {
				$parts[] = str_replace( '_', ' ', $field ) . ': "' . $rule[ $field ] . '"';
			}
		}
		return implode( '; ', $parts );
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
		if ( '2' === (string) get_option( 'lld_tag_seed' ) ) {
			return;
		}
		$groups = array(
			// stress, mood and sleep trigger the NFR2-07 approval checks, so they are part of the managed list.
			'goal'   => array( 'healthy-weight', 'blood-sugar', 'fitness', 'sleep-stress', 'heart', 'gut', 'ageing', 'energy', 'stress', 'mood', 'sleep' ),
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
		update_option( 'lld_tag_seed', '2', true );
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
