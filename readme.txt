=== SharkTrace AI Agent Monitor ===
Contributors: iqbal1hossain
Tags: ai, activity log, audit log, monitoring, security
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI observability for WordPress. See what AI agents do inside your site, in real time.

== Description ==

SharkTrace AI Agent Monitor records what AI agents, MCP clients and automations actually do on your
site, and shows it in a live control room screen inside wp-admin.

Every recorded event keeps the who, what, when and the values that changed, so you can
answer "what did that agent just do?" without digging through logs.

**Attribution you can trust**

An event is labelled as an AI agent only when WordPress can prove it: an Abilities API
execution, or an explicit `X-Agent-Name` / `X-MCP-Client` header over REST or WP-CLI.
Anything else is reported as *Unknown / WordPress* rather than guessed.

**What is recorded**

* Abilities API executions, with input, result, duration and outcome, including permission denials (blocked) and runs that never completed.
* Core writes: posts, trash, permanent deletes, media uploads, terms, user creation, role changes, user deletion, watched options and plugin activation.
* Risk classification of low, medium or high, with critical options such as `siteurl`, `home`, `active_plugins` and `default_role` always treated as high risk.

**Control room screen**

A live feed with 24 hour counters, search and filters by source, risk level and status,
plus a detail pane showing before and after values for each event.

**REST API**

Read-only endpoints under the `sharktrace-ai-agent-monitor/v1` namespace, all requiring the
`manage_options` capability:

* `GET /sharktrace-ai-agent-monitor/v1/events` - the feed, with `search`, `source`, `status`, `risk_level`, `action_name`, `session_id`, `since_id`, `per_page` and `offset` parameters.
* `GET /sharktrace-ai-agent-monitor/v1/events/<id>` - one event with full payloads.
* `GET /sharktrace-ai-agent-monitor/v1/stats` - 24 hour counters and top agents.
* `GET /sharktrace-ai-agent-monitor/v1/sessions/<id>` - every event in one session, oldest first.
* `DELETE /sharktrace-ai-agent-monitor/v1/events` - clear the log.

**Extension points**

* `staiam_should_record` - filter. Skip events by context.
* `staiam_pre_record_event` - filter. Alter or drop an event before storage; return a non-array to drop it.
* `staiam_risk_level` - filter. Override risk classification.
* `staiam_watched_options` - filter. Choose which options are logged.
* `staiam_view_capability` - filter. Capability required to read monitoring data.

**Privacy and data safety**

All data stays in your own database. Nothing is sent to any external service.

Payload keys matching `pass`, `password`, `token`, `secret`, `api_key`, `nonce`, `auth`,
`salt` or `private_key` are stored as `[redacted]`. Values are truncated at 500
characters, arrays at 30 entries and 4 levels deep. Events older than the retention
window, 30 days by default, are deleted daily.

== Installation ==

1. Upload the `sharktrace-ai-agent-monitor` folder to `/wp-content/plugins/`, or install the plugin through the Plugins screen in WordPress.
2. Activate the plugin through the Plugins screen. Activation creates the events table.
3. Open **Tools > SharkTrace AI Agent Monitor** to watch activity as it happens.

== Frequently Asked Questions ==

= Which activity counts as an AI agent? =

Only activity WordPress can prove came from an agent: an Abilities API execution, or a
request carrying an explicit `X-Agent-Name` or `X-MCP-Client` header over REST or WP-CLI.
Everything else is recorded as *Unknown / WordPress*, never guessed.

= Do I need the Abilities API? =

No. Core write collection works on WordPress 6.7 and later on its own. Abilities API
collection additionally needs WordPress 7.1 or the Abilities API plugin.

= Who can see the recorded data? =

Users with the `manage_options` capability. Use the `staiam_view_capability` filter to
require a different capability.

= How long is data kept? =

30 days by default. Older events are removed by a daily cron job. Change the window with
the `staiam_retention_days` option.

= Does the plugin send anything to an external service? =

No. Events are stored in your own database only.

= What happens when I delete the plugin? =

Uninstalling drops the events table, deletes the plugin options and clears the scheduled
cleanup job. Deactivating alone keeps the stored events.

== Changelog ==

= 0.1.0 =
* First release: Abilities API and core write collection, risk classification, live control room screen, REST API and daily retention cleanup.

== Upgrade Notice ==

= 0.1.0 =
First release.
