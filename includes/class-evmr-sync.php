<?php
/**
 * The sync engine — the core "mirror".
 *
 * Pulls events from Eventbrite, matches them to existing evmr_event posts by the
 * stored Eventbrite ID, then creates, updates, or removes posts so WordPress
 * mirrors Eventbrite exactly.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reconciles Eventbrite events with local evmr_event posts.
 */
class EVMR_Sync {

	/**
	 * @var EVMR_Eventbrite
	 */
	private $api;

	/**
	 * @var EVMR_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param EVMR_Eventbrite $api    API client.
	 * @param EVMR_Logger     $logger Logger.
	 */
	public function __construct( EVMR_Eventbrite $api, EVMR_Logger $logger ) {
		$this->api    = $api;
		$this->logger = $logger;
	}

	/**
	 * Run a full sync.
	 *
	 * @return array|WP_Error Summary counts on success, WP_Error on failure.
	 */
	public function run() {
		do_action( 'evmr_sync_start' );

		$remote = $this->api->fetch_all_events();
		if ( is_wp_error( $remote ) ) {
			$this->logger->error( $remote->get_error_message() );
			update_option( 'evmr_last_error', $remote->get_error_message(), false );
			return $remote;
		}

		$existing = $this->existing_map();
		$cutoff   = $this->cleanup_cutoff();              // 0 = "Keep all".
		$registry = get_option( 'evmr_cleanup', array() ); // eb_id => [title,date,excluded].
		$seen     = array();
		$created  = 0;
		$updated  = 0;
		$cleaned  = 0;

		foreach ( $remote as $event ) {
			if ( empty( $event['id'] ) ) {
				continue;
			}
			$eb_id   = (string) $event['id'];
			$post_id = isset( $existing[ $eb_id ] ) ? $existing[ $eb_id ] : 0;

			$end_ts = $this->event_end_ts( $event );
			$is_old = ( $cutoff && $end_ts && $end_ts < $cutoff );

			if ( $is_old ) {
				// Record/refresh this event in the Auto Clean-Up registry,
				// preserving any existing "excluded" choice the user made.
				// Excluded if the user pre-marked the event, or chose to keep it
				// from the Auto Clean-Up list.
				$meta_excluded      = $post_id ? (bool) get_post_meta( $post_id, '_evmr_exclude_cleanup', true ) : false;
				$registry_excluded  = isset( $registry[ $eb_id ]['excluded'] ) ? (bool) $registry[ $eb_id ]['excluded'] : false;
				$excluded           = $meta_excluded || $registry_excluded;
				$registry[ $eb_id ] = array(
					'title'    => isset( $event['name']['text'] ) ? $event['name']['text'] : '',
					'date'     => $this->event_date_label( $event ),
					'excluded' => $excluded,
				);

				$seen[ $eb_id ] = true; // Handled here; keep out of the removed-from-Eventbrite sweep.

				if ( $excluded ) {
					// Kept permanently — import/update like any current event.
					$result = $this->upsert_event( $event, $post_id );
					if ( ! is_wp_error( $result ) ) {
						$post_id ? $updated++ : $created++;
					}
				} else {
					// Auto-removed — ensure no local post, and do not re-import.
					if ( $post_id ) {
						$this->remove_event( $post_id );
					}
					$cleaned++;
				}
				continue;
			}

			// Within the window (or "Keep all"): normal create/update.
			$seen[ $eb_id ] = true;
			$result         = $this->upsert_event( $event, $post_id );

			if ( is_wp_error( $result ) ) {
				$this->logger->warning(
					/* translators: %s: Eventbrite event ID. */
					sprintf( __( 'Failed to mirror event %s', 'event-mirror' ), $eb_id ),
					array( 'error' => $result->get_error_message() )
				);
				continue;
			}

			if ( $post_id ) {
				$updated++;
			} else {
				$created++;
			}
		}

		update_option( 'evmr_cleanup', $registry, false );

		// Anything local that is no longer in Eventbrite at all gets removed.
		$removed = 0;
		foreach ( $existing as $eb_id => $post_id ) {
			if ( ! isset( $seen[ $eb_id ] ) ) {
				$this->remove_event( $post_id );
				$removed++;
			}
		}

		$summary = array(
			'created' => $created,
			'updated' => $updated,
			'removed' => $removed,
			'cleaned' => $cleaned,
			'total'   => count( $remote ),
		);

		update_option( 'evmr_last_sync', current_time( 'mysql' ), false );
		delete_option( 'evmr_last_error' );
		$this->logger->success(
			/* translators: 1: created, 2: updated, 3: removed, 4: cleaned by Auto Clean-Up. */
			sprintf( __( 'Sync complete: %1$d created, %2$d updated, %3$d removed, %4$d cleaned up.', 'event-mirror' ), $created, $updated, $removed, $cleaned ),
			$summary
		);

		do_action( 'evmr_sync_complete', $summary );

		return $summary;
	}

	/**
	 * Resolve the Auto Clean-Up cutoff as a Unix timestamp.
	 *
	 * @return int Timestamp; events ending before this are "old". 0 = keep all.
	 */
	private function cleanup_cutoff() {
		$settings = get_option( EVMR_OPTION, array() );
		$prune    = isset( $settings['prune'] ) ? $settings['prune'] : '';
		$map      = array(
			'1day'    => DAY_IN_SECONDS,
			'1week'   => WEEK_IN_SECONDS,
			'1month'  => MONTH_IN_SECONDS,
			'3months' => 3 * MONTH_IN_SECONDS,
		);
		if ( ! isset( $map[ $prune ] ) ) {
			return 0;
		}
		return time() - $map[ $prune ];
	}

	/**
	 * Get an event's end time as a Unix timestamp (falls back to start).
	 *
	 * @param array $event Raw Eventbrite event.
	 * @return int Timestamp, or 0 if unknown.
	 */
	private function event_end_ts( $event ) {
		$end = '';
		if ( ! empty( $event['end']['utc'] ) ) {
			$end = $event['end']['utc'];
		} elseif ( ! empty( $event['start']['utc'] ) ) {
			$end = $event['start']['utc'];
		}
		return $end ? (int) strtotime( $end ) : 0;
	}

	/**
	 * Produce a human-readable date label for the clean-up list.
	 *
	 * @param array $event Raw Eventbrite event.
	 * @return string
	 */
	private function event_date_label( $event ) {
		if ( empty( $event['start']['utc'] ) ) {
			return '';
		}
		$local = get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $event['start']['utc'] ) );
		return mysql2date( get_option( 'date_format' ), $local );
	}

	/**
	 * Build a map of [ eventbrite_id => post_id ] for existing mirrored posts.
	 *
	 * @return array
	 */
	private function existing_map() {
		$map   = array();
		$query = new WP_Query(
			array(
				'post_type'      => EVMR_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $post_id ) {
			$eb_id = get_post_meta( $post_id, '_evmr_eb_id', true );
			if ( $eb_id ) {
				$map[ (string) $eb_id ] = $post_id;
			}
		}

		return $map;
	}

	/**
	 * Create or update a single event post from a raw Eventbrite event.
	 *
	 * @param array $event   Raw Eventbrite event.
	 * @param int   $post_id Existing post ID, or 0 to create.
	 * @return int|WP_Error Post ID on success.
	 */
	private function upsert_event( $event, $post_id = 0 ) {
		$fields = $this->map_fields( $event );

		$postarr = array(
			'post_type'    => EVMR_POST_TYPE,
			'post_title'   => $fields['title'],
			'post_content' => $fields['content'],
			'post_excerpt' => $fields['excerpt'],
			'post_status'  => 'publish',
		);

		if ( $post_id ) {
			$postarr['ID'] = $post_id;
		}

		/**
		 * Filter the post array before insert/update.
		 *
		 * @param array $postarr WP post array.
		 * @param array $event   Raw Eventbrite event.
		 */
		$postarr = apply_filters( 'evmr_event_postarr', $postarr, $event );

		$result = $post_id ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$post_id = (int) $result;

		foreach ( $fields['meta'] as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		/**
		 * Fires after a single event has been mirrored.
		 *
		 * @param int   $post_id The local post ID.
		 * @param array $event   Raw Eventbrite event.
		 */
		do_action( 'evmr_event_synced', $post_id, $event );

		return $post_id;
	}

	/**
	 * Map a raw Eventbrite event to title/content/excerpt/meta.
	 *
	 * @param array $event Raw Eventbrite event.
	 * @return array
	 */
	private function map_fields( $event ) {
		$venue_name = '';
		if ( ! empty( $event['venue']['name'] ) ) {
			$venue_name = $event['venue']['name'];
		} elseif ( ! empty( $event['venue']['address']['localized_address_display'] ) ) {
			$venue_name = $event['venue']['address']['localized_address_display'];
		}

		$fields = array(
			'title'   => isset( $event['name']['text'] ) ? $event['name']['text'] : __( '(untitled event)', 'event-mirror' ),
			'content' => isset( $event['description']['html'] ) ? $event['description']['html'] : '',
			'excerpt' => isset( $event['summary'] ) ? $event['summary'] : '',
			'meta'    => array(
				'_evmr_eb_id'     => (string) $event['id'],
				'_evmr_start_utc' => isset( $event['start']['utc'] ) ? $event['start']['utc'] : '',
				'_evmr_end_utc'   => isset( $event['end']['utc'] ) ? $event['end']['utc'] : '',
				'_evmr_url'       => isset( $event['url'] ) ? $event['url'] : '',
				'_evmr_venue'     => $venue_name,
				'_evmr_online'    => ! empty( $event['online_event'] ) ? 1 : 0,
				'_evmr_status'    => isset( $event['status'] ) ? $event['status'] : '',
				'_evmr_image'     => isset( $event['logo']['original']['url'] )
					? $event['logo']['original']['url']
					: ( isset( $event['logo']['url'] ) ? $event['logo']['url'] : '' ),
			),
		);

		/**
		 * Filter the mapped fields before they are saved (pro can map extra data).
		 *
		 * @param array $fields Mapped fields.
		 * @param array $event  Raw Eventbrite event.
		 */
		return apply_filters( 'evmr_mapped_fields', $fields, $event );
	}

	/**
	 * Remove a local post that no longer exists in Eventbrite.
	 *
	 * Trashes by default (reversible). Set the filter to true to hard-delete.
	 *
	 * @param int $post_id Post ID.
	 */
	private function remove_event( $post_id ) {
		/**
		 * Filter whether to permanently delete (true) or trash (false) removed events.
		 *
		 * @param bool $force   Whether to bypass the trash.
		 * @param int  $post_id The post ID.
		 */
		$force = apply_filters( 'evmr_force_delete', false, $post_id );
		wp_delete_post( $post_id, (bool) $force );

		do_action( 'evmr_event_removed', $post_id );
	}
}
