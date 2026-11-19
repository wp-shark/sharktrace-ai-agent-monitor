<?php
/**
 * Attribution: who or what caused the current request.
 *
 * @package STAIAM
 */

declare( strict_types = 1 );

namespace STAIAM;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the execution context of the current request.
 *
 * Hard rule: an event is only ever labelled `agent` when there is a positive
 * signal (an Abilities API execution, or an explicit agent/MCP client header).
 * Everything else stays `unknown` — never guess.
 */
class Agent_Detector {

	public const SOURCE_AGENT   = 'agent';
	public const SOURCE_HUMAN   = 'human';
	public const SOURCE_UNKNOWN = 'unknown';

	public const TRANSPORT_ABILITIES = 'abilities';
	public const TRANSPORT_REST      = 'rest';
	public const TRANSPORT_CLI       = 'wp-cli';
	public const TRANSPORT_CRON      = 'cron';
	public const TRANSPORT_ADMIN     = 'admin';
	public const TRANSPORT_AJAX      = 'ajax';
	public const TRANSPORT_WEB       = 'web';

	/**
	 * Ability currently being executed, if any.
	 *
	 * @var string
	 */
	private static $current_ability = '';

	/**
	 * User-agent fragments that identify a known AI client.
	 *
	 * Matching only upgrades the *client* label; it never alone promotes an
	 * event to `agent` unless it arrives over an automation transport.
	 *
	 * @var array<string, string>
	 */
	private const KNOWN_CLIENTS = array(
		'claude'      => 'Claude',
		'anthropic'   => 'Claude',
		'chatgpt'     => 'ChatGPT',
		'openai'      => 'OpenAI',
		'gpt-'        => 'OpenAI',
		'gemini'      => 'Gemini',
		'googleother' => 'Gemini',
		'copilot'     => 'Copilot',
		'mcp'         => 'MCP Client',
		'modelcontext' => 'MCP Client',
	);

	/**
	 * Mark the start of an ability execution so nested writes inherit attribution.
	 */
	public static function enter_ability( string $ability ): void {
		self::$current_ability = $ability;
	}

	/**
	 * Clear the ability context once execution finished.
	 */
	public static function exit_ability(): void {
		self::$current_ability = '';
	}

	/**
	 * The ability being executed right now, or an empty string.
	 */
	public static function current_ability(): string {
		return self::$current_ability;
	}

	/**
	 * Build the attribution context for the current request.
	 *
	 * @return array{source: string, transport: string, agent_name: string, ability: string, user_id: int}
	 */
	public static function context(): array {
		$ability   = self::$current_ability;
		$transport = self::transport();
		$client    = self::client_hint();

		if ( '' !== $ability ) {
			// An ability executed: this is machine-invoked by definition.
			$source = self::SOURCE_AGENT;
		} elseif ( '' !== $client && in_array( $transport, array( self::TRANSPORT_REST, self::TRANSPORT_CLI ), true ) ) {
			// A named AI client over an automation transport.
			$source = self::SOURCE_AGENT;
		} elseif ( self::TRANSPORT_ADMIN === $transport || self::TRANSPORT_AJAX === $transport ) {
			$source = self::SOURCE_HUMAN;
		} else {
			$source = self::SOURCE_UNKNOWN;
		}

		$agent_name = '';
		if ( self::SOURCE_AGENT === $source ) {
			$agent_name = self::agent_name( $client, $ability );
		}

		return array(
			'source'     => $source,
			'transport'  => $transport,
			'agent_name' => $agent_name,
			'ability'    => $ability,
			'user_id'    => get_current_user_id(),
		);
	}

	/**
	 * Which entry point handled this request.
	 */
	public static function transport(): string {
		if ( '' !== self::$current_ability ) {
			return self::TRANSPORT_ABILITIES;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return self::TRANSPORT_CLI;
		}

		if ( wp_doing_cron() ) {
			return self::TRANSPORT_CRON;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::TRANSPORT_REST;
		}

		if ( wp_doing_ajax() ) {
			return self::TRANSPORT_AJAX;
		}

		if ( is_admin() ) {
			return self::TRANSPORT_ADMIN;
		}

		return self::TRANSPORT_WEB;
	}

	/**
	 * Explicit client identity from request headers, falling back to the user agent.
	 *
	 * Returns an empty string when nothing identifies the caller.
	 */
	public static function client_hint(): string {
		$headers = array( 'HTTP_X_AGENT_NAME', 'HTTP_X_MCP_CLIENT', 'HTTP_X_AI_CLIENT' );

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$value = sanitize_text_field( wp_unslash( (string) $_SERVER[ $header ] ) );

				if ( '' !== $value ) {
					return mb_substr( $value, 0, 100 );
				}
			}
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) )
			: '';

		if ( '' === $user_agent ) {
			return '';
		}

		foreach ( self::KNOWN_CLIENTS as $needle => $label ) {
			if ( false !== strpos( $user_agent, $needle ) ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * Best available name for the acting agent.
	 *
	 * Falls back to a transport-derived label rather than inventing a persona.
	 */
	private static function agent_name( string $client, string $ability ): string {
		if ( '' !== $client ) {
			return $client;
		}

		if ( '' !== $ability ) {
			return __( 'Unnamed agent', 'sharktrace-ai-agent-monitor' );
		}

		return __( 'Unidentified client', 'sharktrace-ai-agent-monitor' );
	}
}
