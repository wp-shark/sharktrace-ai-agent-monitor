<?php
/**
 * Admin screen registration.
 *
 * @package AIAM
 */

declare( strict_types = 1 );

namespace AIAM\Admin;

use AIAM\Agent_Detector;
use AIAM\Event_Store;
use AIAM\Plugin;
use AIAM\Rest_Api;
use AIAM\Risk_Analyzer;

defined( 'ABSPATH' ) || exit;

/**
 * The control room screen.
 */
class Admin_Page {

	public const SLUG = 'ai-agent-monitor';

	/**
	 * Hook the admin menu.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the top-level menu entry.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'AI Agent Monitor', 'ai-agent-monitor' ),
			__( 'AI Monitor', 'ai-agent-monitor' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-visibility',
			3
		);
	}

	/**
	 * Load assets on our screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'aiam-admin',
			AIAM_URL . 'assets/css/admin.css',
			array(),
			AIAM_VERSION
		);

		wp_enqueue_script(
			'aiam-admin',
			AIAM_URL . 'assets/js/admin.js',
			array( 'wp-api-fetch' ),
			AIAM_VERSION,
			true
		);

		wp_localize_script(
			'aiam-admin',
			'AIAM',
			array(
				'root'      => esc_url_raw( rest_url( Rest_Api::NAMESPACE ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'pollMs'    => 5000,
				'retention' => Plugin::retention_days(),
				'i18n'      => array(
					'live'        => __( 'LIVE', 'ai-agent-monitor' ),
					'paused'      => __( 'PAUSED', 'ai-agent-monitor' ),
					'empty'       => __( 'No activity recorded yet. Events appear here as soon as something changes.', 'ai-agent-monitor' ),
					'loadError'   => __( 'Could not load activity.', 'ai-agent-monitor' ),
					'confirmWipe' => __( 'Delete every recorded event? This cannot be undone.', 'ai-agent-monitor' ),
					'noBefore'    => __( 'No previous value recorded.', 'ai-agent-monitor' ),
					'unattributed' => __( 'Unknown / WordPress', 'ai-agent-monitor' ),
				),
			)
		);
	}

	/**
	 * Render the dashboard.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ai-agent-monitor' ) );
		}

		$installed = Event_Store::is_installed();
		$sources   = array(
			Agent_Detector::SOURCE_AGENT   => __( 'AI agent', 'ai-agent-monitor' ),
			Agent_Detector::SOURCE_HUMAN   => __( 'Human', 'ai-agent-monitor' ),
			Agent_Detector::SOURCE_UNKNOWN => __( 'Unknown', 'ai-agent-monitor' ),
		);
		$risks     = array(
			Risk_Analyzer::HIGH   => __( 'High', 'ai-agent-monitor' ),
			Risk_Analyzer::MEDIUM => __( 'Medium', 'ai-agent-monitor' ),
			Risk_Analyzer::LOW    => __( 'Low', 'ai-agent-monitor' ),
		);
		$statuses  = array(
			'success' => __( 'Success', 'ai-agent-monitor' ),
			'failed'  => __( 'Failed', 'ai-agent-monitor' ),
			'blocked' => __( 'Blocked', 'ai-agent-monitor' ),
		);

		require AIAM_DIR . 'admin/dashboard.php';
	}
}
