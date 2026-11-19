<?php
/**
 * Control room markup.
 *
 * @package STAIAM
 *
 * @var bool                   $installed Whether the events table exists.
 * @var array<string, string>  $sources   Source filter options.
 * @var array<string, string>  $risks     Risk filter options.
 * @var array<string, string>  $statuses  Status filter options.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap staiam">

	<div class="staiam-topbar">
		<h1 class="staiam-title"><?php esc_html_e( 'AI Control Room', 'sharktrace-ai-agent-monitor' ); ?></h1>
		<div class="staiam-topbar__right">
			<button type="button" class="staiam-live" id="staiam-live" aria-pressed="true">
				<span class="staiam-live__dot"></span><span id="staiam-live-label"><?php esc_html_e( 'LIVE', 'sharktrace-ai-agent-monitor' ); ?></span>
			</button>
			<button type="button" class="button button-link-delete" id="staiam-clear"><?php esc_html_e( 'Clear log', 'sharktrace-ai-agent-monitor' ); ?></button>
		</div>
	</div>

	<?php if ( ! $installed ) : ?>
		<div class="notice notice-error"><p>
			<?php esc_html_e( 'The events table is missing. Deactivate and reactivate SharkTrace AI Agent Monitor to create it.', 'sharktrace-ai-agent-monitor' ); ?>
		</p></div>
	<?php endif; ?>

	<div class="staiam-stats" id="staiam-stats">
		<div class="staiam-stat"><span class="staiam-stat__label"><?php esc_html_e( 'Agent actions', 'sharktrace-ai-agent-monitor' ); ?></span><span class="staiam-stat__value" data-stat="agent">—</span></div>
		<div class="staiam-stat"><span class="staiam-stat__label"><?php esc_html_e( 'Total events', 'sharktrace-ai-agent-monitor' ); ?></span><span class="staiam-stat__value" data-stat="total">—</span></div>
		<div class="staiam-stat"><span class="staiam-stat__label"><?php esc_html_e( 'Sessions', 'sharktrace-ai-agent-monitor' ); ?></span><span class="staiam-stat__value" data-stat="sessions">—</span></div>
		<div class="staiam-stat staiam-stat--warn"><span class="staiam-stat__label"><?php esc_html_e( 'High risk', 'sharktrace-ai-agent-monitor' ); ?></span><span class="staiam-stat__value" data-stat="high_risk">—</span></div>
		<div class="staiam-stat staiam-stat--error"><span class="staiam-stat__label"><?php esc_html_e( 'Failed / blocked', 'sharktrace-ai-agent-monitor' ); ?></span><span class="staiam-stat__value" data-stat="failed">—</span></div>
		<p class="staiam-stats__note"><?php esc_html_e( 'Last 24 hours', 'sharktrace-ai-agent-monitor' ); ?></p>
	</div>

	<div class="staiam-filters">
		<label class="screen-reader-text" for="staiam-search"><?php esc_html_e( 'Search activity', 'sharktrace-ai-agent-monitor' ); ?></label>
		<input type="search" id="staiam-search" placeholder="<?php esc_attr_e( 'Search activity…', 'sharktrace-ai-agent-monitor' ); ?>">

		<select id="staiam-source" aria-label="<?php esc_attr_e( 'Source', 'sharktrace-ai-agent-monitor' ); ?>">
			<option value=""><?php esc_html_e( 'All sources', 'sharktrace-ai-agent-monitor' ); ?></option>
			<?php foreach ( $sources as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<select id="staiam-risk" aria-label="<?php esc_attr_e( 'Risk level', 'sharktrace-ai-agent-monitor' ); ?>">
			<option value=""><?php esc_html_e( 'All risk levels', 'sharktrace-ai-agent-monitor' ); ?></option>
			<?php foreach ( $risks as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<select id="staiam-status" aria-label="<?php esc_attr_e( 'Status', 'sharktrace-ai-agent-monitor' ); ?>">
			<option value=""><?php esc_html_e( 'All statuses', 'sharktrace-ai-agent-monitor' ); ?></option>
			<?php foreach ( $statuses as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<div class="staiam-layout">
		<section class="staiam-panel staiam-panel--feed" aria-labelledby="staiam-feed-heading">
			<h2 id="staiam-feed-heading" class="staiam-panel__heading"><?php esc_html_e( 'Live activity', 'sharktrace-ai-agent-monitor' ); ?></h2>
			<ol class="staiam-feed" id="staiam-feed"></ol>
			<p class="staiam-empty" id="staiam-empty"><?php esc_html_e( 'Loading activity…', 'sharktrace-ai-agent-monitor' ); ?></p>
		</section>

		<aside class="staiam-panel staiam-panel--detail" aria-labelledby="staiam-detail-heading">
			<h2 id="staiam-detail-heading" class="staiam-panel__heading"><?php esc_html_e( 'Event details', 'sharktrace-ai-agent-monitor' ); ?></h2>
			<div class="staiam-detail" id="staiam-detail">
				<p class="staiam-empty"><?php esc_html_e( 'Select an event to inspect what changed.', 'sharktrace-ai-agent-monitor' ); ?></p>
			</div>
		</aside>
	</div>

	<p class="staiam-footnote">
		<?php
		printf(
			/* translators: %d: number of days. */
			esc_html__( 'Events older than %d days are removed automatically. Attribution is only shown when WordPress can prove it — anything else is reported as unknown.', 'sharktrace-ai-agent-monitor' ),
			(int) STAIAM\Plugin::retention_days()
		);
		?>
	</p>
</div>
