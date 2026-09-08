<?php
/**
 * Control room markup.
 *
 * @package AIAM
 *
 * @var bool                   $installed Whether the events table exists.
 * @var array<string, string>  $sources   Source filter options.
 * @var array<string, string>  $risks     Risk filter options.
 * @var array<string, string>  $statuses  Status filter options.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap aiam">

	<div class="aiam-topbar">
		<h1 class="aiam-title"><?php esc_html_e( 'AI Control Room', 'ai-agent-monitor' ); ?></h1>
		<div class="aiam-topbar__right">
			<button type="button" class="aiam-live" id="aiam-live" aria-pressed="true">
				<span class="aiam-live__dot"></span><span id="aiam-live-label"><?php esc_html_e( 'LIVE', 'ai-agent-monitor' ); ?></span>
			</button>
			<button type="button" class="button button-link-delete" id="aiam-clear"><?php esc_html_e( 'Clear log', 'ai-agent-monitor' ); ?></button>
		</div>
	</div>

	<?php if ( ! $installed ) : ?>
		<div class="notice notice-error"><p>
			<?php esc_html_e( 'The events table is missing. Deactivate and reactivate AI Agent Monitor to create it.', 'ai-agent-monitor' ); ?>
		</p></div>
	<?php endif; ?>

	<div class="aiam-stats" id="aiam-stats">
		<div class="aiam-stat"><span class="aiam-stat__label"><?php esc_html_e( 'Agent actions', 'ai-agent-monitor' ); ?></span><span class="aiam-stat__value" data-stat="agent">—</span></div>
		<div class="aiam-stat"><span class="aiam-stat__label"><?php esc_html_e( 'Total events', 'ai-agent-monitor' ); ?></span><span class="aiam-stat__value" data-stat="total">—</span></div>
		<div class="aiam-stat"><span class="aiam-stat__label"><?php esc_html_e( 'Sessions', 'ai-agent-monitor' ); ?></span><span class="aiam-stat__value" data-stat="sessions">—</span></div>
		<div class="aiam-stat aiam-stat--warn"><span class="aiam-stat__label"><?php esc_html_e( 'High risk', 'ai-agent-monitor' ); ?></span><span class="aiam-stat__value" data-stat="high_risk">—</span></div>
		<div class="aiam-stat aiam-stat--error"><span class="aiam-stat__label"><?php esc_html_e( 'Failed / blocked', 'ai-agent-monitor' ); ?></span><span class="aiam-stat__value" data-stat="failed">—</span></div>
		<p class="aiam-stats__note"><?php esc_html_e( 'Last 24 hours', 'ai-agent-monitor' ); ?></p>
	</div>

	<div class="aiam-filters">
		<label class="screen-reader-text" for="aiam-search"><?php esc_html_e( 'Search activity', 'ai-agent-monitor' ); ?></label>
		<input type="search" id="aiam-search" placeholder="<?php esc_attr_e( 'Search activity…', 'ai-agent-monitor' ); ?>">

		<select id="aiam-source" aria-label="<?php esc_attr_e( 'Source', 'ai-agent-monitor' ); ?>">
			<option value=""><?php esc_html_e( 'All sources', 'ai-agent-monitor' ); ?></option>
			<?php foreach ( $sources as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<select id="aiam-risk" aria-label="<?php esc_attr_e( 'Risk level', 'ai-agent-monitor' ); ?>">
			<option value=""><?php esc_html_e( 'All risk levels', 'ai-agent-monitor' ); ?></option>
			<?php foreach ( $risks as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<select id="aiam-status" aria-label="<?php esc_attr_e( 'Status', 'ai-agent-monitor' ); ?>">
			<option value=""><?php esc_html_e( 'All statuses', 'ai-agent-monitor' ); ?></option>
			<?php foreach ( $statuses as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<div class="aiam-layout">
		<section class="aiam-panel aiam-panel--feed" aria-labelledby="aiam-feed-heading">
			<h2 id="aiam-feed-heading" class="aiam-panel__heading"><?php esc_html_e( 'Live activity', 'ai-agent-monitor' ); ?></h2>
			<ol class="aiam-feed" id="aiam-feed"></ol>
			<p class="aiam-empty" id="aiam-empty"><?php esc_html_e( 'Loading activity…', 'ai-agent-monitor' ); ?></p>
		</section>

		<aside class="aiam-panel aiam-panel--detail" aria-labelledby="aiam-detail-heading">
			<h2 id="aiam-detail-heading" class="aiam-panel__heading"><?php esc_html_e( 'Event details', 'ai-agent-monitor' ); ?></h2>
			<div class="aiam-detail" id="aiam-detail">
				<p class="aiam-empty"><?php esc_html_e( 'Select an event to inspect what changed.', 'ai-agent-monitor' ); ?></p>
			</div>
		</aside>
	</div>

	<p class="aiam-footnote">
		<?php
		printf(
			/* translators: %d: number of days. */
			esc_html__( 'Events older than %d days are removed automatically. Attribution is only shown when WordPress can prove it — anything else is reported as unknown.', 'ai-agent-monitor' ),
			(int) AIAM\Plugin::retention_days()
		);
		?>
	</p>
</div>
