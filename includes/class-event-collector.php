<?php
/**
 * Event collection: turns WordPress hooks into monitor events.
 *
 * @package STAIAM
 */

declare( strict_types = 1 );

namespace STAIAM;

use WP_Error;
use WP_Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to WordPress and records what happened.
 */
class Event_Collector {

	private const MAX_VALUE_LENGTH = 500;

	/**
	 * Abilities currently mid-execution, keyed by call depth.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $pending = array();

	/**
	 * Meta keys and option fragments whose values are never stored.
	 *
	 * @var array<int, string>
	 */
	private const REDACTED = array( 'pass', 'password', 'token', 'secret', 'api_key', 'apikey', 'nonce', 'auth', 'salt', 'private_key' );

	/**
	 * Register every hook this collector listens to.
	 */
	public function register(): void {
		// Abilities API Ã¢â‚¬â€ the one path where attribution is certain.
		add_action( 'wp_ability_invoked', array( $this, 'on_ability_invoked' ), 10, 3 );
		add_filter( 'wp_ability_permission_result', array( $this, 'on_ability_permission' ), 100, 3 );
		// execute_result fires for successes *and* WP_Error returns; wp_after_execute_ability does not.
		add_filter( 'wp_ability_execute_result', array( $this, 'on_ability_finished' ), 999, 3 );
		add_action( 'shutdown', array( $this, 'flush_pending' ), 1 );

		// Content.
		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 10, 4 );
		add_action( 'wp_trash_post', array( $this, 'on_post_trashed' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ), 10, 2 );
		add_action( 'add_attachment', array( $this, 'on_attachment_added' ), 10, 1 );
		add_action( 'created_term', array( $this, 'on_term_created' ), 10, 3 );

		// Users.
		add_action( 'user_register', array( $this, 'on_user_created' ), 10, 1 );
		add_action( 'set_user_role', array( $this, 'on_user_role_changed' ), 10, 3 );
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ), 10, 3 );

		// Settings.
		add_action( 'updated_option', array( $this, 'on_option_updated' ), 10, 3 );

		// Plugins.
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Abilities API
	 * ------------------------------------------------------------------ */

	/**
	 * An ability was invoked Ã¢â‚¬â€ open a pending record and claim attribution.
	 *
	 * @param string $ability_name Ability name.
	 * @param mixed  $input        Raw input.
	 */
	public function on_ability_invoked( string $ability_name, $input = null ): void {
		$this->pending[] = array(
			'ability' => $ability_name,
			'input'   => $this->sanitize_payload( $input ),
			'start'   => microtime( true ),
		);

		Agent_Detector::enter_ability( $ability_name );
	}

	/**
	 * Permission was denied for an ability that is mid-execution: record it as blocked.
	 *
	 * @param bool|WP_Error $permission   Permission result.
	 * @param string        $ability_name Ability name.
	 * @param mixed         $input        Input data.
	 * @return bool|WP_Error Unmodified permission result.
	 */
	public function on_ability_permission( $permission, string $ability_name = '', $input = null ) {
		$granted = ( true === $permission );

		if ( $granted || ! $this->has_pending( $ability_name ) ) {
			return $permission;
		}

		$record = $this->close_pending( $ability_name );

		$this->record(
			array(
				'action'      => 'execute_ability',
				'object_type' => 'ability',
				'object_id'   => $ability_name,
				'label'       => sprintf(
					/* translators: %s: ability name. */
					__( 'Permission denied for ability "%s"', 'sharktrace-ai-agent-monitor' ),
					$ability_name
				),
				'status'      => 'blocked',
				'risk_level'  => Risk_Analyzer::HIGH,
				'before_data' => $record['input'] ?? null,
				'error'       => $permission instanceof WP_Error
					? $permission->get_error_message()
					: __( 'Ability permission callback returned false.', 'sharktrace-ai-agent-monitor' ),
				'duration_ms' => $this->elapsed_ms( $record ),
				'ability'     => $ability_name,
			)
		);

		return $permission;
	}

	/**
	 * An ability's execute callback returned Ã¢â‚¬â€ success or WP_Error.
	 *
	 * @param mixed  $result       Execution result.
	 * @param string $ability_name Ability name.
	 * @param mixed  $input        Normalized input data.
	 * @return mixed The unmodified result.
	 */
	public function on_ability_finished( $result, string $ability_name = '', $input = null ) {
		$record = $this->close_pending( $ability_name );

		$is_error = $result instanceof WP_Error;

		$this->record(
			array(
				'action'      => 'execute_ability',
				'object_type' => 'ability',
				'object_id'   => $ability_name,
				'label'       => sprintf(
					/* translators: %s: ability name. */
					__( 'Executed ability "%s"', 'sharktrace-ai-agent-monitor' ),
					$ability_name
				),
				'status'      => $is_error ? 'failed' : 'success',
				'before_data' => $record['input'] ?? $this->sanitize_payload( $input ),
				'after_data'  => $is_error ? null : $this->sanitize_payload( $result ),
				'error'       => $is_error ? $result->get_error_message() : null,
				'duration_ms' => $this->elapsed_ms( $record ),
				'ability'     => $ability_name,
			)
		);

		return $result;
	}

	/**
	 * Any ability that never reached the "finished" hook failed on the way there
	 * (input validation, output validation, or a short-circuit). Record it once.
	 */
	public function flush_pending(): void {
		while ( $this->pending ) {
			$record = array_pop( $this->pending );

			Agent_Detector::enter_ability( (string) $record['ability'] );

			$this->record(
				array(
					'action'      => 'execute_ability',
					'object_type' => 'ability',
					'object_id'   => (string) $record['ability'],
					'label'       => sprintf(
						/* translators: %s: ability name. */
						__( 'Ability "%s" did not complete', 'sharktrace-ai-agent-monitor' ),
						$record['ability']
					),
					'status'      => 'failed',
					'before_data' => $record['input'] ?? null,
					'error'       => __( 'Execution ended before completion (input validation, output validation, or a fatal error).', 'sharktrace-ai-agent-monitor' ),
					'duration_ms' => $this->elapsed_ms( $record ),
					'ability'     => (string) $record['ability'],
				)
			);
		}

		Agent_Detector::exit_ability();
	}

	/* ---------------------------------------------------------------------
	 * Content
	 * ------------------------------------------------------------------ */

	/**
	 * A post was created or updated.
	 *
	 * @param int          $post_id     Post id.
	 * @param WP_Post      $post        Post after the save.
	 * @param bool         $update      Whether this was an update.
	 * @param WP_Post|null $post_before Post before the save.
	 */
	public function on_post_saved( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before = null ): void {
		if ( $this->is_ignorable_post( $post ) ) {
			return;
		}

		$fields = array( 'post_title', 'post_status', 'post_type', 'post_name', 'post_author', 'post_parent' );
		$after  = array();
		$before = array();

		foreach ( $fields as $field ) {
			$after[ $field ] = $this->truncate( (string) $post->$field );

			if ( $post_before instanceof WP_Post ) {
				$before[ $field ] = $this->truncate( (string) $post_before->$field );
			}
		}

		// Only keep the fields that actually moved.
		if ( $before ) {
			foreach ( $fields as $field ) {
				if ( $before[ $field ] === $after[ $field ] ) {
					unset( $before[ $field ], $after[ $field ] );
				}
			}

			if ( ! $after ) {
				return; // Nothing meaningful changed.
			}
		}

		$this->record(
			array(
				'action'      => $update ? 'update_post' : 'create_post',
				'object_type' => $post->post_type,
				'object_id'   => (string) $post_id,
				'label'       => sprintf(
					/* translators: 1: post type label, 2: post title, 3: post id. */
					__( '%1$s "%2$s" (#%3$d) %4$s', 'sharktrace-ai-agent-monitor' ),
					$this->post_type_label( $post->post_type ),
					$this->truncate( (string) $post->post_title, 80 ),
					$post_id,
					$update ? __( 'updated', 'sharktrace-ai-agent-monitor' ) : __( 'created', 'sharktrace-ai-agent-monitor' )
				),
				'before_data' => $before ?: null,
				'after_data'  => $after,
			)
		);
	}

	/**
	 * A post was moved to trash.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_post_trashed( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || $this->is_ignorable_post( $post ) ) {
			return;
		}

		$this->record(
			array(
				'action'      => 'trash_post',
				'object_type' => $post->post_type,
				'object_id'   => (string) $post_id,
				'label'       => sprintf(
					/* translators: 1: post type label, 2: post title, 3: post id. */
					__( '%1$s "%2$s" (#%3$d) moved to trash', 'sharktrace-ai-agent-monitor' ),
					$this->post_type_label( $post->post_type ),
					$this->truncate( (string) $post->post_title, 80 ),
					$post_id
				),
				'before_data' => array( 'post_status' => $post->post_status ),
				'after_data'  => array( 'post_status' => 'trash' ),
			)
		);
	}

	/**
	 * A post is about to be permanently deleted.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post object.
	 */
	public function on_post_deleted( int $post_id, $post = null ): void {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );

		if ( ! $post instanceof WP_Post || $this->is_ignorable_post( $post ) ) {
			return;
		}

		$this->record(
			array(
				'action'      => 'delete_post',
				'object_type' => $post->post_type,
				'object_id'   => (string) $post_id,
				'label'       => sprintf(
					/* translators: 1: post type label, 2: post title, 3: post id. */
					__( '%1$s "%2$s" (#%3$d) permanently deleted', 'sharktrace-ai-agent-monitor' ),
					$this->post_type_label( $post->post_type ),
					$this->truncate( (string) $post->post_title, 80 ),
					$post_id
				),
				'before_data' => array(
					'post_title'  => $this->truncate( (string) $post->post_title ),
					'post_status' => $post->post_status,
					'post_type'   => $post->post_type,
				),
			)
		);
	}

	/**
	 * A media item was uploaded.
	 *
	 * @param int $post_id Attachment id.
	 */
	public function on_attachment_added( int $post_id ): void {
		$this->record(
			array(
				'action'      => 'upload_media',
				'object_type' => 'attachment',
				'object_id'   => (string) $post_id,
				'label'       => sprintf(
					/* translators: 1: file name, 2: attachment id. */
					__( 'Uploaded media "%1$s" (#%2$d)', 'sharktrace-ai-agent-monitor' ),
					$this->truncate( (string) get_the_title( $post_id ), 80 ),
					$post_id
				),
				'after_data'  => array(
					'mime_type' => (string) get_post_mime_type( $post_id ),
					'url'       => (string) wp_get_attachment_url( $post_id ),
				),
			)
		);
	}

	/**
	 * A taxonomy term was created.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function on_term_created( int $term_id, int $tt_id, string $taxonomy ): void {
		$term = get_term( $term_id, $taxonomy );

		$this->record(
			array(
				'action'      => 'create_term',
				'object_type' => $taxonomy,
				'object_id'   => (string) $term_id,
				'label'       => sprintf(
					/* translators: 1: term name, 2: taxonomy slug. */
					__( 'Created term "%1$s" in %2$s', 'sharktrace-ai-agent-monitor' ),
					is_wp_error( $term ) || ! $term ? (string) $term_id : $this->truncate( (string) $term->name, 80 ),
					$taxonomy
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Users
	 * ------------------------------------------------------------------ */

	/**
	 * A user account was created.
	 *
	 * @param int $user_id New user id.
	 */
	public function on_user_created( int $user_id ): void {
		$user = get_userdata( $user_id );

		$this->record(
			array(
				'action'      => 'create_user',
				'object_type' => 'user',
				'object_id'   => (string) $user_id,
				'label'       => sprintf(
					/* translators: 1: user login, 2: user id. */
					__( 'Created user "%1$s" (#%2$d)', 'sharktrace-ai-agent-monitor' ),
					$user instanceof WP_User ? $user->user_login : (string) $user_id,
					$user_id
				),
				'after_data'  => array(
					'user_login' => $user instanceof WP_User ? $user->user_login : '',
					'roles'      => $user instanceof WP_User ? $user->roles : array(),
				),
			)
		);
	}

	/**
	 * A user's role was replaced.
	 *
	 * @param int                $user_id   User id.
	 * @param string             $role      New role.
	 * @param array<int, string> $old_roles Previous roles.
	 */
	public function on_user_role_changed( int $user_id, string $role, array $old_roles = array() ): void {
		// user_register also triggers set_user_role; the create event already covers it.
		if ( ! $old_roles ) {
			return;
		}

		$user = get_userdata( $user_id );

		$this->record(
			array(
				'action'      => 'change_user_role',
				'object_type' => 'user',
				'object_id'   => (string) $user_id,
				'label'       => sprintf(
					/* translators: 1: user login, 2: old role, 3: new role. */
					__( 'User "%1$s" role changed from %2$s to %3$s', 'sharktrace-ai-agent-monitor' ),
					$user instanceof WP_User ? $user->user_login : (string) $user_id,
					implode( ', ', $old_roles ),
					$role
				),
				'before_data' => array( 'roles' => $old_roles ),
				'after_data'  => array( 'roles' => array( $role ) ),
			)
		);
	}

	/**
	 * A user account was deleted.
	 *
	 * @param int      $user_id  Deleted user id.
	 * @param int|null $reassign User id content was reassigned to.
	 * @param WP_User  $user     The deleted user object.
	 */
	public function on_user_deleted( int $user_id, $reassign = null, $user = null ): void {
		$this->record(
			array(
				'action'      => 'delete_user',
				'object_type' => 'user',
				'object_id'   => (string) $user_id,
				'label'       => sprintf(
					/* translators: 1: user login, 2: user id. */
					__( 'Deleted user "%1$s" (#%2$d)', 'sharktrace-ai-agent-monitor' ),
					$user instanceof WP_User ? $user->user_login : (string) $user_id,
					$user_id
				),
				'before_data' => array(
					'user_login' => $user instanceof WP_User ? $user->user_login : '',
					'roles'      => $user instanceof WP_User ? $user->roles : array(),
				),
				'after_data'  => array( 'reassigned_to' => $reassign ? (int) $reassign : null ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Settings & plugins
	 * ------------------------------------------------------------------ */

	/**
	 * A watched option changed.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 */
	public function on_option_updated( string $option, $old_value = null, $value = null ): void {
		if ( ! in_array( $option, Risk_Analyzer::watched_options(), true ) ) {
			return;
		}

		$this->record(
			array(
				'action'      => Risk_Analyzer::is_critical_option( $option ) ? 'update_core_option' : 'update_option',
				'object_type' => 'option',
				'object_id'   => $option,
				'label'       => sprintf(
					/* translators: %s: option name. */
					__( 'Option "%s" updated', 'sharktrace-ai-agent-monitor' ),
					$option
				),
				'before_data' => array( $option => $this->sanitize_payload( $old_value ) ),
				'after_data'  => array( $option => $this->sanitize_payload( $value ) ),
			)
		);
	}

	/**
	 * A plugin was activated.
	 *
	 * @param string $plugin Plugin file.
	 */
	public function on_plugin_activated( string $plugin ): void {
		$this->record(
			array(
				'action'      => 'activate_plugin',
				'object_type' => 'plugin',
				'object_id'   => $plugin,
				'label'       => sprintf(
					/* translators: %s: plugin file. */
					__( 'Activated plugin "%s"', 'sharktrace-ai-agent-monitor' ),
					$plugin
				),
			)
		);
	}

	/**
	 * A plugin was deactivated.
	 *
	 * @param string $plugin Plugin file.
	 */
	public function on_plugin_deactivated( string $plugin ): void {
		$this->record(
			array(
				'action'      => 'deactivate_plugin',
				'object_type' => 'plugin',
				'object_id'   => $plugin,
				'label'       => sprintf(
					/* translators: %s: plugin file. */
					__( 'Deactivated plugin "%s"', 'sharktrace-ai-agent-monitor' ),
					$plugin
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Attach attribution, classify risk, and store the event.
	 *
	 * @param array<string, mixed> $event Partial event data.
	 */
	private function record( array $event ): void {
		$context = Agent_Detector::context();

		if ( ! $this->should_record( $context ) ) {
			return;
		}

		$event['source']     = $context['source'];
		$event['transport']  = $context['transport'];
		$event['agent_name'] = $context['agent_name'];
		$event['ability']    = $event['ability'] ?? $context['ability'];
		$event['user_id']    = $context['user_id'];
		$event['session_id'] = Session_Manager::current();
		$event['risk_level'] = $event['risk_level'] ?? Risk_Analyzer::level( (string) $event['action'], $event );

		/**
		 * Filters an event immediately before it is stored. Return null to drop it.
		 *
		 * @param array<string, mixed>|null $event   Event data.
		 * @param array<string, mixed>      $context Attribution context.
		 */
		$event = apply_filters( 'staiam_pre_record_event', $event, $context );

		if ( ! is_array( $event ) ) {
			return;
		}

		Event_Store::insert( $event );
	}

	/**
	 * Front-end page views generate a lot of noise with nothing to attribute.
	 *
	 * @param array<string, mixed> $context Attribution context.
	 */
	private function should_record( array $context ): bool {
		$record = ! ( Agent_Detector::TRANSPORT_WEB === $context['transport'] && Agent_Detector::SOURCE_AGENT !== $context['source'] );

		/**
		 * Filters whether an event in this context is recorded at all.
		 *
		 * @param bool                 $record  Whether to record.
		 * @param array<string, mixed> $context Attribution context.
		 */
		return (bool) apply_filters( 'staiam_should_record', $record, $context );
	}

	/**
	 * Whether a pending record exists for an ability.
	 */
	private function has_pending( string $ability ): bool {
		foreach ( $this->pending as $record ) {
			if ( $record['ability'] === $ability ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove and return the innermost pending record for an ability.
	 *
	 * @return array<string, mixed>
	 */
	private function close_pending( string $ability ): array {
		for ( $i = count( $this->pending ) - 1; $i >= 0; $i-- ) {
			if ( $this->pending[ $i ]['ability'] === $ability ) {
				$record = $this->pending[ $i ];
				array_splice( $this->pending, $i, 1 );

				$parent = end( $this->pending );
				if ( is_array( $parent ) ) {
					Agent_Detector::enter_ability( (string) $parent['ability'] );
				} else {
					Agent_Detector::exit_ability();
				}

				return $record;
			}
		}

		return array( 'ability' => $ability );
	}

	/**
	 * Milliseconds since a pending record was opened.
	 *
	 * @param array<string, mixed> $record Pending record.
	 */
	private function elapsed_ms( array $record ): int {
		if ( empty( $record['start'] ) ) {
			return 0;
		}

		return (int) round( ( microtime( true ) - (float) $record['start'] ) * 1000 );
	}

	/**
	 * Revisions, autosaves and placeholder drafts are not real activity.
	 */
	private function is_ignorable_post( WP_Post $post ): bool {
		return wp_is_post_revision( $post ) || wp_is_post_autosave( $post )
			|| 'auto-draft' === $post->post_status
			|| in_array( $post->post_type, array( 'revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request' ), true );
	}

	/**
	 * Human-readable post type name.
	 */
	private function post_type_label( string $post_type ): string {
		$object = get_post_type_object( $post_type );

		return $object ? (string) $object->labels->singular_name : $post_type;
	}

	/**
	 * Recursively shrink and redact a payload before storage.
	 *
	 * @param mixed $value Payload.
	 * @param int   $depth Current recursion depth.
	 * @return mixed
	 */
	private function sanitize_payload( $value, int $depth = 0 ) {
		if ( $depth > 4 ) {
			return 'Ã¢â‚¬Â¦';
		}

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$out = array();

			foreach ( array_slice( $value, 0, 30, true ) as $key => $item ) {
				$out[ $key ] = $this->is_secret( (string) $key ) ? '[redacted]' : $this->sanitize_payload( $item, $depth + 1 );
			}

			return $out;
		}

		if ( is_string( $value ) ) {
			return $this->truncate( $value );
		}

		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		return '[' . gettype( $value ) . ']';
	}

	/**
	 * Whether a key name looks like a credential.
	 */
	private function is_secret( string $key ): bool {
		$key = strtolower( $key );

		foreach ( self::REDACTED as $needle ) {
			if ( str_contains( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cap a stored string.
	 */
	private function truncate( string $value, int $length = self::MAX_VALUE_LENGTH ): string {
		return mb_strlen( $value ) > $length ? mb_substr( $value, 0, $length ) . 'Ã¢â‚¬Â¦' : $value;
	}
}
