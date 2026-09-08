<?php
/**
 * Session grouping for related events.
 *
 * @package AIAM
 */

declare( strict_types = 1 );

namespace AIAM;

defined( 'ABSPATH' ) || exit;

/**
 * Groups the events of one agent task under a single session id.
 *
 * Phase 1 scope: one PHP request is one session, unless the caller supplies a
 * stable session header (MCP clients that keep a conversation open can send it).
 */
class Session_Manager {

	private static string $session_id = '';

	/**
	 * Session id for the current request. Stable for the whole request.
	 */
	public static function current(): string {
		if ( '' !== self::$session_id ) {
			return self::$session_id;
		}

		$supplied = '';

		foreach ( array( 'HTTP_X_AGENT_SESSION', 'HTTP_MCP_SESSION_ID', 'HTTP_X_SESSION_ID' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$supplied = sanitize_text_field( wp_unslash( (string) $_SERVER[ $header ] ) );
				break;
			}
		}

		self::$session_id = '' !== $supplied
			? substr( md5( $supplied ), 0, 32 )
			: substr( md5( uniqid( 'aiam', true ) ), 0, 32 );

		return self::$session_id;
	}

	/**
	 * All events belonging to one session, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function events( string $session_id, int $limit = 200 ): array {
		$events = Event_Store::query(
			array(
				'session_id' => $session_id,
				'limit'      => $limit,
			)
		);

		return array_reverse( $events );
	}
}
