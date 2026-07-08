<?php
/**
 * Thin wrapper around the Eventbrite API v3.
 *
 * Uses a private API token (pasted into the settings page) sent as a Bearer
 * token. No OAuth callback flow in the POC — that can be added later for the
 * public/multi-account use case.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles authenticated requests to Eventbrite and normalises responses.
 */
class EVMR_Eventbrite {

	const API_BASE = 'https://www.eventbriteapi.com/v3';

	/**
	 * Make an authenticated GET request.
	 *
	 * @param string $path  API path beginning with a slash, e.g. '/users/me/organizations/'.
	 * @param array  $query Query args.
	 * @return array|WP_Error Decoded body on success, WP_Error on failure.
	 */
	public function get( $path, $query = array() ) {
		$token = $this->token();
		if ( '' === $token ) {
			return new WP_Error( 'evmr_no_token', __( 'No Eventbrite API token is configured.', 'event-mirror' ) );
		}

		$url = self::API_BASE . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);

		/**
		 * Filter the request args before they are sent.
		 *
		 * @param array  $args Request args.
		 * @param string $url  Full request URL.
		 */
		$args = apply_filters( 'evmr_api_request_args', $args, $url );

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = isset( $body['error_description'] ) ? $body['error_description'] : __( 'Unknown error', 'event-mirror' );
			return new WP_Error(
				'evmr_api_error',
				/* translators: 1: HTTP status code, 2: error detail from Eventbrite. */
				sprintf( __( 'Eventbrite API returned %1$d: %2$s', 'event-mirror' ), $code, $detail ),
				array( 'status' => $code )
			);
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Resolve the organization ID to pull events from.
	 *
	 * Uses the stored org_id if set, otherwise discovers the first organization
	 * the token can see and caches it back into settings.
	 *
	 * @return string|WP_Error
	 */
	public function organization_id() {
		$settings = get_option( EVMR_OPTION, array() );
		if ( ! empty( $settings['org_id'] ) ) {
			return $settings['org_id'];
		}

		$body = $this->get( '/users/me/organizations/' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( empty( $body['organizations'][0]['id'] ) ) {
			return new WP_Error( 'evmr_no_org', __( 'No Eventbrite organization found for this token.', 'event-mirror' ) );
		}

		$org_id             = $body['organizations'][0]['id'];
		$settings['org_id'] = $org_id;
		update_option( EVMR_OPTION, $settings );

		return $org_id;
	}

	/**
	 * Fetch all events for the resolved organization, following pagination.
	 *
	 * @return array|WP_Error Array of raw Eventbrite event arrays, or WP_Error.
	 */
	public function fetch_all_events() {
		$org_id = $this->organization_id();
		if ( is_wp_error( $org_id ) ) {
			return $org_id;
		}

		$events       = array();
		$continuation = null;
		$guard        = 0;

		do {
			$query = array(
				'expand' => 'venue,logo',
				'status' => 'all',
			);
			if ( $continuation ) {
				$query['continuation'] = $continuation;
			}

			$body = $this->get( "/organizations/{$org_id}/events/", $query );
			if ( is_wp_error( $body ) ) {
				return $body;
			}

			if ( ! empty( $body['events'] ) ) {
				$events = array_merge( $events, $body['events'] );
			}

			$has_more     = ! empty( $body['pagination']['has_more_items'] );
			$continuation = $has_more && ! empty( $body['pagination']['continuation'] )
				? $body['pagination']['continuation']
				: null;

			$guard++;
		} while ( $continuation && $guard < 50 );

		return $events;
	}

	/**
	 * Read the token from settings.
	 *
	 * @return string
	 */
	private function token() {
		$settings = get_option( EVMR_OPTION, array() );
		$token    = isset( $settings['token'] ) ? trim( $settings['token'] ) : '';

		/**
		 * Filter the API token (e.g. to source it from a constant or secrets store).
		 *
		 * @param string $token The configured token.
		 */
		return apply_filters( 'evmr_api_token', $token );
	}
}
