<?php
/**
 * Event storage: schema, writes and queries.
 *
 * @package STAIAM
 */

declare( strict_types = 1 );

namespace STAIAM;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the wp_staiam_events table.
 */
class Event_Store {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'staiam_db_version';

	/**
	 * Fully qualified table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'staiam_events';
	}

	/**
	 * Create or upgrade the events table. Runs on activation and on version bumps.
	 */
	public static function install(): void {
		global $wpdb;

		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::migrate_legacy_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace sensitive: two spaces after PRIMARY KEY, one field per line.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_uuid char(36) NOT NULL,
			session_id char(32) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT 'unknown',
			transport varchar(20) NOT NULL DEFAULT '',
			agent_name varchar(100) NOT NULL DEFAULT '',
			ability varchar(191) NOT NULL DEFAULT '',
			action varchar(64) NOT NULL DEFAULT '',
			object_type varchar(32) NOT NULL DEFAULT '',
			object_id varchar(64) NOT NULL DEFAULT '',
			label text NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'success',
			risk_level varchar(8) NOT NULL DEFAULT 'low',
			before_data longtext NULL,
			after_data longtext NULL,
			error text NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_uuid (event_uuid),
			KEY session_id (session_id),
			KEY created_at (created_at),
			KEY risk_level (risk_level),
			KEY status (status),
			KEY source (source),
			KEY action (action)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Carry data over from the pre-rename table, when the plugin was called AI Agent Monitor.
	 *
	 * Only renames when the old table is there and the new one is not, so a fresh
	 * install and a re-run both do nothing.
	 */
	private static function migrate_legacy_table(): void {
		global $wpdb;

		$legacy = $wpdb->prefix . 'ai_agent_events';
		$table  = self::table();

		$has_legacy = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $legacy ) )
		);

		if ( ! $has_legacy ) {
			return;
		}

		$has_current = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		if ( $has_current ) {
			return;
		}

		$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $legacy, $table ) );

		$retention = get_option( 'aiam_retention_days' );

		if ( false !== $retention && false === get_option( Plugin::RETENTION_OPTION, false ) ) {
			update_option( Plugin::RETENTION_OPTION, $retention, false );
		}

		delete_option( 'aiam_db_version' );
		delete_option( 'aiam_retention_days' );

		$timestamp = wp_next_scheduled( 'aiam_daily_cleanup' );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'aiam_daily_cleanup' );
		}
	}

	/**
	 * Whether the events table exists. Cheap guard for sites activated before install ran.
	 */
	public static function is_installed(): bool {
		global $wpdb;

		static $exists = null;

		if ( null === $exists ) {
			// The table prefix contains underscores, which are LIKE wildcards.
			$exists = (bool) $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) )
			);
		}

		return $exists;
	}

	/**
	 * Persist one event.
	 *
	 * @param array<string, mixed> $event Raw event data; unknown keys are dropped.
	 * @return int Inserted row id, or 0 on failure.
	 */
	public static function insert( array $event ): int {
		global $wpdb;

		if ( ! self::is_installed() ) {
			return 0;
		}

		$row = array(
			'event_uuid'  => wp_generate_uuid4(),
			'session_id'  => (string) ( $event['session_id'] ?? '' ),
			'source'      => (string) ( $event['source'] ?? Agent_Detector::SOURCE_UNKNOWN ),
			'transport'   => (string) ( $event['transport'] ?? '' ),
			'agent_name'  => (string) ( $event['agent_name'] ?? '' ),
			'ability'     => (string) ( $event['ability'] ?? '' ),
			'action'      => (string) ( $event['action'] ?? '' ),
			'object_type' => (string) ( $event['object_type'] ?? '' ),
			'object_id'   => (string) ( $event['object_id'] ?? '' ),
			'label'       => (string) ( $event['label'] ?? '' ),
			'status'      => (string) ( $event['status'] ?? 'success' ),
			'risk_level'  => (string) ( $event['risk_level'] ?? Risk_Analyzer::LOW ),
			'before_data' => self::encode( $event['before_data'] ?? null ),
			'after_data'  => self::encode( $event['after_data'] ?? null ),
			'error'       => isset( $event['error'] ) ? (string) $event['error'] : null,
			'user_id'     => (int) ( $event['user_id'] ?? 0 ),
			'duration_ms' => (int) ( $event['duration_ms'] ?? 0 ),
			'created_at'  => (string) ( $event['created_at'] ?? current_time( 'mysql', true ) ),
		);

		$inserted = $wpdb->insert( self::table(), $row );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch events, newest first.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		if ( ! self::is_installed() ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'search'     => '',
				'source'     => '',
				'status'     => '',
				'risk_level' => '',
				'action'     => '',
				'session_id' => '',
				'since_id'   => 0,
				'limit'      => 50,
				'offset'     => 0,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'source', 'status', 'risk_level', 'action', 'session_id' ) as $column ) {
			if ( '' !== $args[ $column ] ) {
				$where[]  = "{$column} = %s";
				$params[] = $args[ $column ];
			}
		}

		if ( $args['since_id'] > 0 ) {
			$where[]  = 'id > %d';
			$params[] = (int) $args['since_id'];
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '( label LIKE %s OR ability LIKE %s OR agent_name LIKE %s OR action LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$params[] = max( 1, min( 500, (int) $args['limit'] ) );
		$params[] = max( 0, (int) $args['offset'] );

		// The table name is bound through the %i identifier placeholder, so it leads the argument list.
		array_unshift( $params, self::table() );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The query is assembled from literal fragments only; every value, including the table name, is bound by $wpdb->prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d',
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array_map( array( self::class, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * Single event by id.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		if ( ! self::is_installed() ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Dashboard counters for the last 24 hours.
	 *
	 * @return array<string, mixed>
	 */
	public static function stats(): array {
		global $wpdb;

		$empty = array(
			'total'      => 0,
			'agent'      => 0,
			'success'    => 0,
			'failed'     => 0,
			'high_risk'  => 0,
			'sessions'   => 0,
			'latest_id'  => 0,
			'top_agents' => array(),
		);

		if ( ! self::is_installed() ) {
			return $empty;
		}

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total,
					SUM( source = %s ) AS agent,
					SUM( status = %s ) AS success,
					SUM( status IN ( %s, %s ) ) AS failed,
					SUM( risk_level = %s ) AS high_risk,
					COUNT( DISTINCT session_id ) AS sessions
				FROM %i WHERE created_at >= %s',
				Agent_Detector::SOURCE_AGENT,
				'success',
				'failed',
				'blocked',
				Risk_Analyzer::HIGH,
				$table,
				$since
			),
			ARRAY_A
		);

		$top = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent_name, COUNT(*) AS actions FROM %i
				WHERE created_at >= %s AND source = %s AND agent_name <> %s
				GROUP BY agent_name ORDER BY actions DESC LIMIT 5',
				$table,
				$since,
				Agent_Detector::SOURCE_AGENT,
				''
			),
			ARRAY_A
		);

		return array(
			'total'      => (int) ( $row['total'] ?? 0 ),
			'agent'      => (int) ( $row['agent'] ?? 0 ),
			'success'    => (int) ( $row['success'] ?? 0 ),
			'failed'     => (int) ( $row['failed'] ?? 0 ),
			'high_risk'  => (int) ( $row['high_risk'] ?? 0 ),
			'sessions'   => (int) ( $row['sessions'] ?? 0 ),
			'latest_id'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $table ) ),
			'top_agents' => array_map(
				static fn( array $a ): array => array(
					'agent_name' => (string) $a['agent_name'],
					'actions'    => (int) $a['actions'],
				),
				$top ?: array()
			),
		);
	}

	/**
	 * Delete every stored event.
	 */
	public static function truncate(): void {
		global $wpdb;

		if ( self::is_installed() ) {
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table() ) );
		}
	}

	/**
	 * Drop events older than the retention window.
	 *
	 * @return int Rows removed.
	 */
	public static function purge( int $days ): int {
		global $wpdb;

		if ( $days < 1 || ! self::is_installed() ) {
			return 0;
		}

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				self::table(),
				gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);
	}

	/**
	 * JSON-encode a payload for storage, or null when there is nothing to keep.
	 *
	 * @param mixed $value Payload.
	 */
	private static function encode( $value ): ?string {
		if ( null === $value || array() === $value ) {
			return null;
		}

		$json = wp_json_encode( $value );

		return is_string( $json ) ? $json : null;
	}

	/**
	 * Cast a raw DB row into typed values with decoded payloads.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['id']          = (int) $row['id'];
		$row['user_id']     = (int) $row['user_id'];
		$row['duration_ms'] = (int) $row['duration_ms'];
		$row['before_data'] = $row['before_data'] ? json_decode( (string) $row['before_data'], true ) : null;
		$row['after_data']  = $row['after_data'] ? json_decode( (string) $row['after_data'], true ) : null;

		return $row;
	}
}
