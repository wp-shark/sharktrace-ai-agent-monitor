<?php
/**
 * REST endpoints backing the dashboard.
 *
 * @package STAIAM
 */

declare( strict_types = 1 );

namespace STAIAM;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only activity API for administrators.
 */
class Rest_Api {

	public const NAMESPACE = 'sharktrace-ai-agent-monitor/v1';

	/**
	 * Hook route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declare every route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_events' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $this->collection_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_events' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/events/(?P<id>[0-9]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_event' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sessions/(?P<session_id>[A-Za-z0-9]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_session' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => array(
					'session_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Capability required to read monitoring data.
	 */
	public function can_view(): bool {
		/**
		 * Filters the capability required to read monitoring data.
		 *
		 * @param string $capability Capability name.
		 */
		return current_user_can( (string) apply_filters( 'staiam_view_capability', 'manage_options' ) );
	}

	/**
	 * Capability required to modify monitoring data.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /events
	 */
	public function get_events( WP_REST_Request $request ): WP_REST_Response {
		$events = Event_Store::query(
			array(
				'search'     => (string) $request->get_param( 'search' ),
				'source'     => (string) $request->get_param( 'source' ),
				'status'     => (string) $request->get_param( 'status' ),
				'risk_level' => (string) $request->get_param( 'risk_level' ),
				'action'     => (string) $request->get_param( 'action_name' ),
				'session_id' => (string) $request->get_param( 'session_id' ),
				'since_id'   => (int) $request->get_param( 'since_id' ),
				'limit'      => (int) ( $request->get_param( 'per_page' ) ?: 50 ),
				'offset'     => (int) $request->get_param( 'offset' ),
			)
		);

		return rest_ensure_response(
			array(
				'events' => array_map( array( $this, 'prepare_event' ), $events ),
			)
		);
	}

	/**
	 * GET /events/<id>
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_event( WP_REST_Request $request ) {
		$event = Event_Store::get( (int) $request['id'] );

		if ( ! $event ) {
			return new WP_Error(
				'staiam_event_not_found',
				__( 'Event not found.', 'sharktrace-ai-agent-monitor' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->prepare_event( $event ) );
	}

	/**
	 * GET /stats
	 */
	public function get_stats(): WP_REST_Response {
		return rest_ensure_response( Event_Store::stats() );
	}

	/**
	 * GET /sessions/<session_id>
	 */
	public function get_session( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (string) $request['session_id'];
		$events     = Session_Manager::events( $session_id );

		return rest_ensure_response(
			array(
				'session_id' => $session_id,
				'events'     => array_map( array( $this, 'prepare_event' ), $events ),
			)
		);
	}

	/**
	 * DELETE /events
	 */
	public function delete_events(): WP_REST_Response {
		Event_Store::truncate();

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Shape one event for the dashboard.
	 *
	 * @param array<string, mixed> $event Stored event.
	 * @return array<string, mixed>
	 */
	private function prepare_event( array $event ): array {
		$user = $event['user_id'] ? get_userdata( (int) $event['user_id'] ) : null;

		$event['user_login'] = $user ? $user->user_login : '';
		$event['time_local'] = get_date_from_gmt( (string) $event['created_at'], 'H:i:s' );
		$event['date_local'] = get_date_from_gmt( (string) $event['created_at'], get_option( 'date_format' ) . ' H:i:s' );

		return $event;
	}

	/**
	 * Query args for the collection endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function collection_args(): array {
		return array(
			'search'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'source'      => array(
				'type' => 'string',
				'enum' => array( '', Agent_Detector::SOURCE_AGENT, Agent_Detector::SOURCE_HUMAN, Agent_Detector::SOURCE_UNKNOWN ),
			),
			'status'      => array(
				'type' => 'string',
				'enum' => array( '', 'success', 'failed', 'blocked' ),
			),
			'risk_level'  => array(
				'type' => 'string',
				'enum' => array( '', Risk_Analyzer::LOW, Risk_Analyzer::MEDIUM, Risk_Analyzer::HIGH ),
			),
			'action_name' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'session_id'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'since_id'    => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'per_page'    => array(
				'type'              => 'integer',
				'default'           => 50,
				'minimum'           => 1,
				'maximum'           => 200,
				'sanitize_callback' => 'absint',
			),
			'offset'      => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
		);
	}
}
