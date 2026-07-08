<?php
/**
 * Event structured data (JSON-LD).
 *
 * Emits schema.org Event markup on the events archive as an ItemList, with
 * honest attribution back to Eventbrite rather than competing with it: the
 * canonical event detail lives on Eventbrite, so each Event's `url`,
 * `isBasedOn` and `offers.url` point there. Off by default; enabled from the
 * Display settings. Because this site shows outbound cards with no local
 * single-event pages, the markup rides on the listing page.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and prints Event JSON-LD for the events archive.
 */
class EVMR_Schema {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'wp_head', array( $this, 'output' ), 20 );
	}

	/**
	 * Print an ItemList of Event objects on the events archive, if enabled.
	 */
	public function output() {
		$settings = get_option( EVMR_OPTION, array() );
		if ( empty( $settings['schema'] ) ) {
			return;
		}

		// Only on the events archive or its category / tag archives — those are
		// real pages that present the events. Never on single pages (there are
		// none) or elsewhere.
		if ( ! (
			is_post_type_archive( EVMR_POST_TYPE )
			|| is_tax( EVMR_CPT::TAXONOMY )
			|| is_tax( EVMR_CPT::TAG_TAXONOMY )
		) ) {
			return;
		}

		global $wp_query;
		if ( empty( $wp_query->posts ) ) {
			return;
		}

		$items    = array();
		$position = 0;
		foreach ( $wp_query->posts as $post ) {
			$event = $this->build_event( (int) $post->ID );
			if ( $event ) {
				$position++;
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $position,
					'item'     => $event,
				);
			}
		}

		if ( empty( $items ) ) {
			return;
		}

		$data = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'itemListElement' => $items,
		);

		/**
		 * Filter the full JSON-LD data structure before it is printed.
		 *
		 * @param array $data The ItemList structure.
		 */
		$data = apply_filters( 'evmr_schema_data', $data );

		echo "\n<script type=\"application/ld+json\">"
			// JSON_HEX_TAG neutralises any "</script>" inside the data.
			. wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )
			. "</script>\n";
	}

	/**
	 * Build a schema.org Event array for one mirrored post, or null if it lacks
	 * the minimum required fields (name + start date).
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	private function build_event( $post_id ) {
		$name  = get_the_title( $post_id );
		$start = $this->iso_datetime( $post_id, 'start' );
		if ( '' === $name || '' === $start ) {
			return null;
		}

		$url    = get_post_meta( $post_id, '_evmr_url', true );
		$online = (bool) get_post_meta( $post_id, '_evmr_online', true );
		$status = get_post_meta( $post_id, '_evmr_status', true );
		$dead   = in_array( $status, array( 'canceled', 'cancelled' ), true );

		$event = array(
			'@type'               => 'Event',
			'name'                => $name,
			'startDate'           => $start,
			'eventStatus'         => $dead ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => $online
				? 'https://schema.org/OnlineEventAttendanceMode'
				: 'https://schema.org/OfflineEventAttendanceMode',
			'location'            => $this->location( $post_id, $online, $url ),
		);

		$end = $this->iso_datetime( $post_id, 'end' );
		if ( '' !== $end ) {
			$event['endDate'] = $end;
		}

		$image = get_post_meta( $post_id, '_evmr_image', true );
		if ( $image ) {
			$event['image'] = $image;
		}

		$excerpt = get_post_field( 'post_excerpt', $post_id );
		if ( $excerpt ) {
			$event['description'] = wp_strip_all_tags( $excerpt );
		}

		if ( $url ) {
			// No local landing page: the fullest detail lives on Eventbrite, and
			// this listing is derived from it — so url + isBasedOn both point there.
			$event['url']       = $url;
			$event['isBasedOn'] = $url;
			$event['offers']    = $this->offers( $post_id, $url, $dead );
		}

		return $event;
	}

	/**
	 * Build the location node (VirtualLocation for online events, else Place).
	 *
	 * @param int    $post_id Post ID.
	 * @param bool   $online  Whether the event is online.
	 * @param string $url     Eventbrite URL (used as the virtual location URL).
	 * @return array
	 */
	private function location( $post_id, $online, $url ) {
		if ( $online ) {
			$virtual = array( '@type' => 'VirtualLocation' );
			if ( $url ) {
				$virtual['url'] = $url;
			}
			return $virtual;
		}

		$place = array( '@type' => 'Place' );
		$venue = get_post_meta( $post_id, '_evmr_venue', true );
		if ( $venue ) {
			$place['name'] = $venue;
		}

		$street = trim(
			get_post_meta( $post_id, '_evmr_street', true ) . ' '
			. get_post_meta( $post_id, '_evmr_street2', true )
		);
		$address = array_filter(
			array(
				'streetAddress'   => $street,
				'addressLocality' => get_post_meta( $post_id, '_evmr_city', true ),
				'addressRegion'   => get_post_meta( $post_id, '_evmr_region', true ),
				'postalCode'      => get_post_meta( $post_id, '_evmr_postal', true ),
				'addressCountry'  => get_post_meta( $post_id, '_evmr_country', true ),
			),
			static function ( $v ) {
				return '' !== $v && null !== $v;
			}
		);
		if ( ! empty( $address ) ) {
			$address        = array( '@type' => 'PostalAddress' ) + $address;
			$place['address'] = $address;
		}

		return $place;
	}

	/**
	 * Build the Offer node pointing at the Eventbrite ticket page.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Eventbrite URL.
	 * @param bool   $dead    Whether the event is cancelled.
	 * @return array
	 */
	private function offers( $post_id, $url, $dead ) {
		$offer = array(
			'@type' => 'Offer',
			'url'   => $url,
		);

		$currency = get_post_meta( $post_id, '_evmr_currency', true );
		$price    = get_post_meta( $post_id, '_evmr_price', true );
		if ( get_post_meta( $post_id, '_evmr_is_free', true ) && $currency ) {
			$price = '0';
		}
		if ( '' !== $price && $currency ) {
			$offer['price']         = (string) $price;
			$offer['priceCurrency'] = $currency;
		}

		if ( ! $dead ) {
			$offer['availability'] = 'https://schema.org/InStock';
		}

		return $offer;
	}

	/**
	 * Produce an ISO 8601 datetime for start/end, preferring local time with a
	 * timezone offset and falling back to the stored UTC value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $which   'start' or 'end'.
	 * @return string ISO 8601 string, or '' if unknown.
	 */
	private function iso_datetime( $post_id, $which ) {
		$local = get_post_meta( $post_id, "_evmr_{$which}_local", true );
		$tz    = get_post_meta( $post_id, "_evmr_{$which}_tz", true );

		if ( $local && $tz ) {
			try {
				$dt = new DateTime( $local, new DateTimeZone( $tz ) );
				return $dt->format( 'c' );
			} catch ( Exception $e ) {
				// Fall through to the UTC value.
			}
		}

		$utc = get_post_meta( $post_id, "_evmr_{$which}_utc", true );
		return $utc ? (string) $utc : '';
	}
}
