<?php
/**
 * Registers the evmr_event custom post type, its taxonomy, and meta schema.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data model: a mirrored Eventbrite event is stored as one evmr_event post.
 * The Eventbrite event ID lives in the _evmr_eb_id meta and is the sync key.
 */
class EVMR_CPT {

	const TAXONOMY     = 'evmr_category';
	const TAG_TAXONOMY = 'evmr_tag';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_description_box' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_cleanup_box' ) );
		add_action( 'save_post_' . EVMR_POST_TYPE, array( $this, 'save_cleanup_meta' ) );
		add_action( 'template_redirect', array( $this, 'block_single_views' ) );
	}

	/**
	 * Events are shown only as cards and in the archive/listings page — there are
	 * no individual event pages. The posts still exist (they power the cards, the
	 * calendar and the archive), but their auto-generated single-view URLs return
	 * a 404 instead of rendering a page. The /events/ archive is unaffected.
	 */
	public function block_single_views() {
		if ( is_singular( EVMR_POST_TYPE ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Meta box: let the user exclude this event from Auto Clean-Up before it ages out.
	 */
	public function add_cleanup_box() {
		add_meta_box(
			'evmr_cleanup_exclude',
			__( 'Auto Clean-Up', 'event-mirror' ),
			array( $this, 'render_cleanup_box' ),
			EVMR_POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the exclude checkbox.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_cleanup_box( $post ) {
		$val = get_post_meta( $post->ID, '_evmr_exclude_cleanup', true );
		wp_nonce_field( 'evmr_cleanup_exclude', 'evmr_cleanup_nonce' );
		echo '<label style="display:flex;gap:.5rem;align-items:flex-start;">';
		printf( '<input type="checkbox" name="evmr_exclude_cleanup" value="1" %s />', checked( $val, '1', false ) );
		echo '<span>' . esc_html__( 'Exclude from Auto Clean-Up — keep this event permanently and never auto-remove it, even after it ends.', 'event-mirror' ) . '</span>';
		echo '</label>';
	}

	/**
	 * Save the exclude checkbox and keep the Auto Clean-Up registry in sync.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_cleanup_meta( $post_id ) {
		if ( ! isset( $_POST['evmr_cleanup_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['evmr_cleanup_nonce'] ) ), 'evmr_cleanup_exclude' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$excluded = ! empty( $_POST['evmr_exclude_cleanup'] );
		if ( $excluded ) {
			update_post_meta( $post_id, '_evmr_exclude_cleanup', '1' );
		} else {
			delete_post_meta( $post_id, '_evmr_exclude_cleanup' );
		}

		// Mirror the choice into the Auto Clean-Up registry if this event is tracked.
		$eb_id = get_post_meta( $post_id, '_evmr_eb_id', true );
		if ( $eb_id ) {
			$reg = get_option( 'evmr_cleanup', array() );
			if ( isset( $reg[ $eb_id ] ) ) {
				$reg[ $eb_id ]['excluded'] = $excluded;
				update_option( 'evmr_cleanup', $reg, false );
			}
		}
	}

	/**
	 * Register the read-only description meta box.
	 */
	public function add_description_box() {
		add_meta_box(
			'evmr_description',
			__( 'Event description (read-only)', 'event-mirror' ),
			array( $this, 'render_description_box' ),
			EVMR_POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the mirrored description, read-only, with guidance to edit on Eventbrite.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_description_box( $post ) {
		$eb_id      = get_post_meta( $post->ID, '_evmr_eb_id', true );
		$manage_url = $eb_id
			? trailingslashit( EVMR_Settings::eventbrite_manage_url() ) . rawurlencode( $eb_id )
			: EVMR_Settings::eventbrite_manage_url();

		echo '<p class="description" style="margin-bottom:10px;">';
		echo esc_html__( 'This description is read-only and pulls from Eventbrite. To make changes, visit the Eventbrite event manager page.', 'event-mirror' );
		echo '</p>';

		printf(
			'<p><a class="button button-secondary" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_url( $manage_url ),
			esc_html__( 'Edit on Eventbrite', 'event-mirror' )
		);

		$content = $post->post_content;
		echo '<div style="border:1px solid #dcdcde;border-radius:4px;padding:12px;background:#f6f7f7;max-height:340px;overflow:auto;">';
		if ( '' !== trim( (string) $content ) ) {
			echo wp_kses_post( wpautop( $content ) );
		} else {
			echo '<em>' . esc_html__( '(No description mirrored yet.)', 'event-mirror' ) . '</em>';
		}
		echo '</div>';
	}

	/**
	 * Register the post type and taxonomy.
	 */
	public function register() {
		$labels = array(
			'name'               => __( 'Events', 'event-mirror' ),
			'singular_name'      => __( 'Event', 'event-mirror' ),
			'menu_name'          => __( 'Event Mirror', 'event-mirror' ),
			'add_new_item'       => __( 'Add New Event', 'event-mirror' ),
			'edit_item'          => __( 'Edit Event', 'event-mirror' ),
			'view_item'          => __( 'View Event', 'event-mirror' ),
			'search_items'       => __( 'Search Events', 'event-mirror' ),
			'not_found'          => __( 'No events found', 'event-mirror' ),
			'all_items'          => __( 'All Events', 'event-mirror' ),
		);

		$args = array(
			'labels'        => $labels,
			'public'        => true,
			'show_in_rest'  => true,
			'has_archive'   => true,
			'menu_icon'     => 'dashicons-calendar-alt',
			// Editor intentionally omitted: the description is mirrored from
			// Eventbrite and shown read-only (see add_description_box()).
			'supports'      => array( 'title', 'thumbnail', 'excerpt' ),
			'rewrite'       => array( 'slug' => 'events' ),
			'taxonomies'    => array( self::TAXONOMY, self::TAG_TAXONOMY ),
		);

		/**
		 * Filter the post type registration args (pro can adjust capabilities, etc.).
		 *
		 * @param array $args Registration args.
		 */
		$args = apply_filters( 'evmr_post_type_args', $args );

		register_post_type( EVMR_POST_TYPE, $args );

		register_taxonomy(
			self::TAXONOMY,
			EVMR_POST_TYPE,
			array(
				'label'        => __( 'Event Categories', 'event-mirror' ),
				'public'       => true,
				'hierarchical' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'event-category' ),
			)
		);

		register_taxonomy(
			self::TAG_TAXONOMY,
			EVMR_POST_TYPE,
			array(
				'label'        => __( 'Event Tags', 'event-mirror' ),
				'public'       => true,
				'hierarchical' => false,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'event-tag' ),
			)
		);
	}

	/**
	 * Register meta fields so they are exposed to REST and properly sanitised.
	 */
	public function register_meta() {
		foreach ( self::meta_schema() as $key => $args ) {
			register_post_meta(
				EVMR_POST_TYPE,
				$key,
				array(
					'type'              => $args['type'],
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $args['sanitize'],
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * The canonical meta schema for a mirrored event.
	 *
	 * @return array Map of meta_key => [type, sanitize].
	 */
	public static function meta_schema() {
		$schema = array(
			'_evmr_eb_id'     => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			'_evmr_start_utc' => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			'_evmr_end_utc'   => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			'_evmr_url'       => array(
				'type'     => 'string',
				'sanitize' => 'esc_url_raw',
			),
			'_evmr_venue'     => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			'_evmr_online'    => array(
				'type'     => 'boolean',
				'sanitize' => 'rest_sanitize_boolean',
			),
			'_evmr_status'    => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			'_evmr_image'     => array(
				'type'     => 'string',
				'sanitize' => 'esc_url_raw',
			),
		);

		/**
		 * Filter the meta schema (pro can add fields such as price, capacity, etc.).
		 *
		 * @param array $schema Meta schema.
		 */
		return apply_filters( 'evmr_meta_schema', $schema );
	}
}
