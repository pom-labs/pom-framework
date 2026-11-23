<?php

namespace POMFramework\Mcp;

use WP\MCP\Transport\Infrastructure\SessionManager;
use WP_REST_Request;
use WP_REST_Response;

/** Bind a human confirmation to an originating tool request, session and credential. */
final class Elicitation {
	private const PREFIX = 'pom_mcp_review_reply_';
	private Reviews $reviews;
	private ?WP_REST_Request $request = null;
	private array $actor = array();
	private array $client = array();
	private ?array $offer = null;

	public function __construct( Reviews $reviews ) {
		$this->reviews = $reviews;
	}

	public function begin( WP_REST_Request $request, array $actor ): void {
		$this->request = $request;
		$this->actor = $actor;
		$this->offer = null;
		$this->client = array();
		$session = (string) $request->get_header( 'mcp-session-id' );
		if ( $session !== '' && strlen( $session ) <= 128 && $this->reviews->authorizes( $actor ) ) {
			$stored = SessionManager::get_session( $actor['user_id'], $session );
			$this->client = is_array( $stored ) ? ( $stored['client_params'] ?? array() ) : array();
		}
	}

	/** Handle the response before the adapter acknowledges otherwise unhandled JSON-RPC replies. */
	public function respond( WP_REST_Request $request, array $actor ): ?WP_REST_Response {
		$payload = $request->get_json_params();
		if ( $request->get_method() !== 'POST' || isset( $payload['method'] ) || ! is_string( $payload['id'] ?? null ) || strpos( $payload['id'], 'pom-review-' ) !== 0 ) { return null; }
		$previous = get_current_user_id();
		wp_set_current_user( $actor['user_id'] );
		try {
			$this->begin( $request, $actor );
			return $this->receive( $payload );
		} finally {
			$this->request = null;
			$this->actor = array();
			$this->client = array();
			wp_set_current_user( $previous );
		}
	}

	public function offer( array $review ): void {
		if ( ! $this->request || ! $this->client || ! $review['approval_required'] || $review['review_mode'] === 'web' || $this->offer !== null ) { return; }
		$payload = $this->request->get_json_params();
		$elicitation = $this->client['capabilities']['elicitation'] ?? null;
		if ( ( $payload['method'] ?? '' ) !== 'tools/call' || ! isset( $payload['id'] ) || ! is_array( $elicitation )
			|| ( $elicitation !== array() && ! array_key_exists( 'form', $elicitation ) )
			|| strcmp( $this->client['protocolVersion'] ?? '', '2025-06-18' ) < 0
			|| stripos( (string) $this->request->get_header( 'accept' ), 'text/event-stream' ) === false ) { return; }
		$message = "Review POM MCP changes\nSite: " . $review['site_url'] . "\nOperation: " . $review['title'] . "\nExpires: " . $review['expires_at'] . "\nWordPress review: " . $review['review_url']
			. "\nApproval allows your assistant to queue the exact change.\n\n" . wp_json_encode( $review['operations'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( strlen( $message ) <= 128 * 1024 ) {
			$this->offer = array( 'review_id' => $review['review_id'], 'expires' => strtotime( $review['expires_at'] ), 'message' => $message );
		}
	}

	private function slot(): string {
		return self::PREFIX . hash_hmac( 'sha256', wp_json_encode( array( get_current_blog_id(), $this->actor['user_id'], $this->actor['grant_fingerprint'],
			$this->request->get_header( 'mcp-session-id' ) ) ), wp_salt( 'auth' ) );
	}

	private function receive( array $payload ): WP_REST_Response {
		if ( ! $this->client || ( $payload['jsonrpc'] ?? '' ) !== '2.0' || array_key_exists( 'result', $payload ) === array_key_exists( 'error', $payload )
			|| strlen( (string) $this->request->get_body() ) > 8192 ) { return new WP_REST_Response( null, 400 ); }
		$slot = $this->slot();
		$store = new ReviewStore();
		[ $record, $raw ] = $this->read_slot( $slot );
		if ( ! $record || $record['id'] !== $payload['id'] || $record['expires'] < time() || $record['action'] !== null ) { return new WP_REST_Response( null, 409 ); }
		$reply = $payload['result'] ?? null;
		$action = is_array( $reply ) ? ( $reply['action'] ?? '' ) : ( isset( $payload['error'] ) ? 'cancel' : '' );
		if ( ! in_array( $action, array( 'accept', 'decline', 'cancel' ), true ) || ( $action === 'accept'
			&& ( ! is_bool( $reply['content']['confirm'] ?? null ) || array_keys( $reply['content'] ) !== array( 'confirm' ) ) ) ) { return new WP_REST_Response( null, 400 ); }
		$record['action'] = $action === 'decline' ? 'rejected' : ( $action === 'accept' && $reply['content']['confirm'] ? 'approved' : 'cancel' );
		if ( ! $store->replace( $slot, $raw, $record ) ) { return new WP_REST_Response( null, 409 ); }
		if ( $record['action'] === 'cancel' ) { return new WP_REST_Response( null, 202 ); }
		$result = $this->reviews->client_decide( $record['review_id'], $this->actor, $record['action'] );
		return is_wp_error( $result ) ? rest_convert_error_to_response( $result ) : new WP_REST_Response( null, 202 );
	}

	private function read_slot( string $slot ): array {
		global $wpdb;
		if ( function_exists( 'pom_pomstandard_force_database_reads_to_writer' ) ) { pom_pomstandard_force_database_reads_to_writer(); }
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $slot ) );
		$record = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		return array( is_array( $record ) ? $record : null, $raw );
	}

	/** Release the request after sending the form. A later POST records the human response. */
	public function serve( bool $served, $response, WP_REST_Request $request ): bool {
		if ( $served || $request !== $this->request || ! $this->offer || ! $response instanceof WP_REST_Response
			|| $response->get_status() !== 200 || headers_sent() ) { return $served; }
		$offer = $this->offer;
		$this->offer = null;
		$slot = $this->slot();
		$id = 'pom-review-' . wp_generate_uuid4();
		$record = array( 'id' => $id, 'review_id' => $offer['review_id'], 'expires' => $offer['expires'], 'action' => null );
		[ $old, $raw ] = $this->read_slot( $slot );
		if ( $old ) {
			if ( $old['expires'] >= time() && ( $old['action'] === null || $old['review_id'] === $record['review_id'] ) ) { return $served; }
			if ( ! ( new ReviewStore() )->replace( $slot, $raw, $record ) ) { return $served; }
		} elseif ( ! add_option( $slot, $record, '', false ) ) { return $served; }
		$rpc = array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => 'elicitation/create', 'params' => array(
			'message' => $offer['message'],
			'requestedSchema' => array( 'type' => 'object', 'properties' => array( 'confirm' => array( 'type' => 'boolean', 'title' => 'Approve this exact change', 'default' => false ) ), 'required' => array( 'confirm' ) ),
		) );
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Accel-Buffering: no' );
		foreach ( array( $rpc, $response->get_data() ) as $message ) {
			echo "event: message\ndata: " . wp_json_encode( $message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\n";
		}
		return true;
	}

	public static function cleanup(): void {
		Storage::prune_options( self::PREFIX, 'pom_mcp_review_reply_cursor' );
	}
}
