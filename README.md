# SharkTrace AI Agent Monitor

> AI observability for WordPress. See what AI agents do inside your site Ã¢â‚¬â€ in real time.

Version 0.1.0 Ã¢â‚¬â€ Phase 1 (observability). See [ROADMAP.md](ROADMAP.md) for the full task breakdown.

## What works today

- **Attribution you can trust.** Events are labelled `agent` only when WordPress can
  prove it: an Abilities API execution, or an explicit `X-Agent-Name` / `X-MCP-Client`
  header over REST or WP-CLI. Everything else reads *Unknown / WordPress*.
- **Abilities API collection.** Every `WP_Ability::execute()` is recorded with input,
  result, duration, and outcome Ã¢â‚¬â€ including permission denials (`blocked`) and
  failures that never reached completion.
- **Core write collection.** Posts, trash, permanent deletes, media uploads, terms,
  user creation, role changes, user deletion, watched options, plugin activation.
- **Risk classification.** low / medium / high, with critical options
  (`siteurl`, `home`, `active_plugins`, `default_role`, Ã¢â‚¬Â¦) always high.
- **Control room screen.** Live feed with 24-hour counters, search and filters, and a
  detail pane showing before/after values.
- **REST API** under `sharktrace-ai-agent-monitor/v1`, `manage_options` required.

## Requirements

WordPress 6.7+ (Abilities API collection needs WP 7.1 or the Abilities API plugin), PHP 8.0+.

## REST endpoints

| Route | Purpose |
|-------|---------|
| `GET /sharktrace-ai-agent-monitor/v1/events` | Feed. Params: `search`, `source`, `status`, `risk_level`, `action_name`, `session_id`, `since_id`, `per_page`, `offset` |
| `GET /sharktrace-ai-agent-monitor/v1/events/<id>` | One event with full payloads |
| `GET /sharktrace-ai-agent-monitor/v1/stats` | 24-hour counters and top agents |
| `GET /sharktrace-ai-agent-monitor/v1/sessions/<id>` | Every event in one session, oldest first |
| `DELETE /sharktrace-ai-agent-monitor/v1/events` | Clear the log |

## Extension points

| Hook | Type | Purpose |
|------|------|---------|
| `staiam_should_record` | filter | Skip events by context |
| `staiam_pre_record_event` | filter | Alter or drop an event before storage (return non-array to drop) |
| `staiam_risk_level` | filter | Override risk classification |
| `staiam_watched_options` | filter | Choose which options are logged |
| `staiam_view_capability` | filter | Capability required to read monitoring data |

## Data safety

Payload keys matching `pass`, `password`, `token`, `secret`, `api_key`, `nonce`,
`auth`, `salt`, or `private_key` are stored as `[redacted]`. Values are truncated at
500 characters, arrays at 30 entries and 4 levels deep. Events older than the
retention window (30 days by default) are deleted daily.
