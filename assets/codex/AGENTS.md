## Agent Memory

This project uses BotCreds Agent Memory for persistent context across sessions.

### Setup
Set env vars before running Codex:
- `BOTCREDS_MEMORY_URL` — your WordPress site URL (e.g. `https://yoursite.com`)
- `BOTCREDS_MEMORY_KEY` — your application password (`username:app_password`)

### Usage Rules
1. **Before starting any task:** call `search_memory` with the task topic to load relevant prior context.
2. **After completing a task:** call `set_memory` to save key decisions, files changed, and outcomes. Use namespaced keys like `project/feature-name` or `decision/topic`.
3. **During a task:** call `set_memory` for any significant intermediate decision worth preserving.

### Tool Reference
- `get_memory(key)` — exact key lookup
- `set_memory(key, value, tags[])` — create or update
- `search_memory(query, limit?)` — semantic or text search

Memory server: `$BOTCREDS_MEMORY_URL/wp-json/botcreds-memory/v1/`
