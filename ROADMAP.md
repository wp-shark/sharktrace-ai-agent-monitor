# SharkTrace AI Agent Monitor Ã¢â‚¬â€ Task Breakdown

Sequenced so every milestone ships something usable on its own. Milestone 0 and 1
are implemented in this repository; everything after is planned.

## Milestone 0 Ã¢â‚¬â€ Skeleton Ã¢Å“â€¦ done

| # | Task | Output |
|---|------|--------|
| 0.1 | Plugin header, constants, autoloader | `sharktrace-ai-agent-monitor.php` |
| 0.2 | Container + activation/deactivation lifecycle | `includes/class-plugin.php` |
| 0.3 | Events table via `dbDelta`, schema version option | `includes/class-event-store.php` |
| 0.4 | Retention cron (`staiam_daily_cleanup`, 30 days default) | `Plugin::run_cleanup()` |

## Milestone 1 Ã¢â‚¬â€ Phase 1 slice: collect, expose, display Ã¢Å“â€¦ done

| # | Task | Output |
|---|------|--------|
| 1.1 | Attribution engine Ã¢â‚¬â€ transport + client detection, never guess | `includes/class-agent-detector.php` |
| 1.2 | Abilities API collector (`wp_ability_invoked` Ã¢â€ â€™ `wp_ability_execute_result`), incl. blocked + failed | `includes/class-event-collector.php` |
| 1.3 | Core write collectors: posts, trash, delete, media, terms, users, roles, watched options, plugin activation | same |
| 1.4 | Risk classifier (low / medium / high, critical options override) | `includes/class-risk-analyzer.php` |
| 1.5 | Per-request session grouping, honours MCP session headers | `includes/class-session-manager.php` |
| 1.6 | REST API: `/events`, `/events/<id>`, `/stats`, `/sessions/<id>`, `DELETE /events` | `includes/class-rest-api.php` |
| 1.7 | Control-room screen: stat tiles, live feed, filters, detail pane with before/after diff | `admin/`, `assets/` |
| 1.8 | Payload safety: depth limit, 500-char truncation, credential redaction | `Event_Collector::sanitize_payload()` |

## Milestone 2 Ã¢â‚¬â€ Real-time & scale

| # | Task | Notes |
|---|------|-------|
| 2.1 | Replace polling with SSE (`/stream` endpoint, `Last-Event-ID` resume) | JS already funnels both paths through `prepend()` |
| 2.2 | Async writes Ã¢â‚¬â€ buffer events in-request, flush once on `shutdown` | one INSERT per request instead of N |
| 2.3 | Feed pagination / infinite scroll past the first 50 | REST already takes `offset` |
| 2.4 | Settings screen: retention, log scope, watched options | currently filters only |
| 2.5 | Stress harness: 50 concurrent agents, 1000+ events | validates indexes and the feed cap |

## Milestone 3 Ã¢â‚¬â€ Tracing

| # | Task |
|---|------|
| 3.1 | Session view: one agent task as a vertical timeline |
| 3.2 | Nest core writes under the ability that caused them (parent/child event ids) |
| 3.3 | WP-CLI collector (`WP_CLI::add_hook( 'before_run_command' )`) with duration + exit code |
| 3.4 | Per-agent stats: actions, success rate, mean duration |
| 3.5 | Post meta collector with an allowlist (deliberately excluded from Phase 1 Ã¢â‚¬â€ too noisy) |

## Milestone 4 Ã¢â‚¬â€ Safety

| # | Task |
|---|------|
| 4.1 | High-risk alerting (admin notice, email, webhook) |
| 4.2 | Policy engine: match action + source, then allow / block / require approval |
| 4.3 | Blocking via `wp_pre_execute_ability` short-circuit |
| 4.4 | Approval queue UI |

## Milestone 5 Ã¢â‚¬â€ Intelligence & operations

| # | Task |
|---|------|
| 5.1 | Daily natural-language activity summary |
| 5.2 | CSV / JSON export, audit report |
| 5.3 | Rollback for reversible changes (posts, options, roles) |
| 5.4 | Multisite network view |

---

## Design rules carried through every milestone

1. **Never falsely attribute.** An event is `agent` only when an ability executed
   or an explicit AI client header arrived over an automation transport.
   Everything else is `unknown` Ã¢â‚¬â€ displayed as *Unknown / WordPress*.
2. **Never store credentials.** Any payload key containing `pass`, `token`,
   `secret`, `api_key`, `nonce`, `auth`, `salt`, or `private_key` is replaced
   with `[redacted]` before it reaches the database.
3. **Collection must stay cheap.** No queries in hot paths, bounded payloads,
   indexed lookups, front-end page views ignored unless agent-attributed.
4. **Read access is `manage_options`** on every REST route, filterable through
   `staiam_view_capability`.
