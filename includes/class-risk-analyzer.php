<?php
/**
 * Risk classification for collected events.
 *
 * @package STAIAM
 */

declare( strict_types = 1 );

namespace STAIAM;

defined( 'ABSPATH' ) || exit;

/**
 * Maps an action to a risk level.
 *
 * Phase 1 is observability only — nothing here blocks anything yet.
 */
class Risk_Analyzer {

	public const LOW    = 'low';
	public const MEDIUM = 'medium';
	public const HIGH   = 'high';

	/**
	 * Actions that change site integrity, access, or code.
	 *
	 * @var array<int, string>
	 */
	private const HIGH_RISK = array(
		'delete_post',
		'delete_user',
		'create_user',
		'change_user_role',
		'activate_plugin',
		'deactivate_plugin',
		'install_plugin',
		'update_plugin',
		'update_core_option',
		'wp_cli',
		'execute_php',
	);

	/**
	 * Actions that change content or configuration but are reversible.
	 *
	 * @var array<int, string>
	 */
	private const MEDIUM_RISK = array(
		'create_post',
		'update_post',
		'trash_post',
		'update_post_meta',
		'upload_media',
		'create_term',
		'update_option',
		'update_ability',
	);

	/**
	 * Options whose change is always high risk regardless of action naming.
	 *
	 * @var array<int, string>
	 */
	private const CRITICAL_OPTIONS = array(
		'siteurl',
		'home',
		'admin_email',
		'users_can_register',
		'default_role',
		'active_plugins',
		'template',
		'stylesheet',
	);

	/**
	 * Classify an event.
	 *
	 * @param string               $action  Normalised action slug.
	 * @param array<string, mixed> $context Extra signals (object_type, object_id, ability...).
	 */
	public static function level( string $action, array $context = array() ): string {
		if ( in_array( $action, self::HIGH_RISK, true ) ) {
			$level = self::HIGH;
		} elseif ( in_array( $action, self::MEDIUM_RISK, true ) ) {
			$level = self::MEDIUM;
		} else {
			$level = self::LOW;
		}

		if ( 'option' === ( $context['object_type'] ?? '' ) && self::is_critical_option( (string) ( $context['object_id'] ?? '' ) ) ) {
			$level = self::HIGH;
		}

		/**
		 * Filters the risk level assigned to an event.
		 *
		 * @param string               $level   One of low|medium|high.
		 * @param string               $action  Normalised action slug.
		 * @param array<string, mixed> $context Event context.
		 */
		return (string) apply_filters( 'staiam_risk_level', $level, $action, $context );
	}

	/**
	 * Whether an option name is on the critical list.
	 */
	public static function is_critical_option( string $option ): bool {
		return in_array( $option, self::CRITICAL_OPTIONS, true );
	}

	/**
	 * Options worth logging at all. Everything else is too noisy for Phase 1.
	 *
	 * @return array<int, string>
	 */
	public static function watched_options(): array {
		$watched = array_merge(
			self::CRITICAL_OPTIONS,
			array( 'blogname', 'blogdescription', 'permalink_structure', 'blog_public', 'timezone_string', 'posts_per_page' )
		);

		/**
		 * Filters which option names are recorded when updated.
		 *
		 * @param array<int, string> $watched Option names.
		 */
		return (array) apply_filters( 'staiam_watched_options', $watched );
	}
}
