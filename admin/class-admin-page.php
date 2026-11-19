<?php
/**
 * Admin screen registration.
 *
 * @package STAIAM
 */

declare( strict_types = 1 );

namespace STAIAM\Admin;

use STAIAM\Agent_Detector;
use STAIAM\Event_Store;
use STAIAM\Plugin;
use STAIAM\Rest_Api;
use STAIAM\Risk_Analyzer;

defined( 'ABSPATH' ) || exit;

/**
 * The control room screen.
 */
class Admin_Page {

	public const SLUG = 'sharktrace-ai-agent-monitor';

	/**
	 * Hook suffix handed back by add_management_page().
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Hook the admin menu.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Register the screen under Tools, where site utilities belong.
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_management_page(
			__( 'SharkTrace AI Agent Monitor', 'sharktrace-ai-agent-monitor' ),
			__( 'SharkTrace AI Agent Monitor', 'sharktrace-ai-agent-monitor' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Load assets on our screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'staiam-admin',
			STAIAM_URL . 'assets/css/admin.css',
			array(),
			STAIAM_VERSION
		);

		wp_enqueue_script(
			'staiam-admin',
			STAIAM_URL . 'assets/js/admin.js',
			array( 'wp-api-fetch' ),
			STAIAM_VERSION,
			true
		);

		wp_localize_script(
			'staiam-admin',
			'STAIAM',
			array(
				'root'      => esc_url_raw( rest_url( Rest_Api::NAMESPACE ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'pollMs'    => 5000,
				'retention' => Plugin::retention_days(),
				'i18n'      => array(
					'live'        => __( 'LIVE', 'sharktrace-ai-agent-monitor' ),
					'paused'      => __( 'PAUSED', 'sharktrace-ai-agent-monitor' ),
					'empty'       => __( 'No activity recorded yet. Events appear here as soon as something changes.', 'sharktrace-ai-agent-monitor' ),
					'loadError'   => __( 'Could not load activity.', 'sharktrace-ai-agent-monitor' ),
					'confirmWipe' => __( 'Delete every recorded event? This cannot be undone.', 'sharktrace-ai-agent-monitor' ),
					'noBefore'    => __( 'No previous value recorded.', 'sharktrace-ai-agent-monitor' ),
					'unattributed' => __( 'Unknown / WordPress', 'sharktrace-ai-agent-monitor' ),
				),
			)
		);
	}

	/**
	 * Render the dashboard.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'sharktrace-ai-agent-monitor' ) );
		}

		$installed = Event_Store::is_installed();
		$sources   = array(
			Agent_Detector::SOURCE_AGENT   => __( 'AI agent', 'sharktrace-ai-agent-monitor' ),
			Agent_Detector::SOURCE_HUMAN   => __( 'Human', 'sharktrace-ai-agent-monitor' ),
			Agent_Detector::SOURCE_UNKNOWN => __( 'Unknown', 'sharktrace-ai-agent-monitor' ),
		);
		$risks     = array(
			Risk_Analyzer::HIGH   => __( 'High', 'sharktrace-ai-agent-monitor' ),
			Risk_Analyzer::MEDIUM => __( 'Medium', 'sharktrace-ai-agent-monitor' ),
			Risk_Analyzer::LOW    => __( 'Low', 'sharktrace-ai-agent-monitor' ),
		);
		$statuses  = array(
			'success' => __( 'Success', 'sharktrace-ai-agent-monitor' ),
			'failed'  => __( 'Failed', 'sharktrace-ai-agent-monitor' ),
			'blocked' => __( 'Blocked', 'sharktrace-ai-agent-monitor' ),
		);

		require STAIAM_DIR . 'admin/dashboard.php';
	}
}
