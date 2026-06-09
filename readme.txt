=== BotCreds Agent Memory ===
Contributors: jboydston
Tags: ai, agents, memory, api, mcp
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Portable memory store for AI agents. REST API + MCP endpoint. KV mode by default, semantic vector search when OpenAI key is configured.

== Description ==

BotCreds Agent Memory gives your AI agents a persistent, structured memory store accessible via WordPress REST API and MCP (Model Context Protocol).

**Two modes of operation:**

* **KV Mode (default)** — Simple key-value store with tags, expiry, and text search. No external dependencies. Works out of the box.
* **Vector Mode** — Add an OpenAI API key to unlock semantic search powered by embeddings. Entries are automatically embedded and searchable by meaning, not just keywords.

**Key features:**

* REST API with full CRUD (create, read, update, delete)
* MCP endpoint for AI agent tool integration
* Namespace-based access control per WordPress user
* Automatic embedding generation via WP-Cron
* Bulk backfill for existing entries
* Expiry support for temporary memories
* Tag-based filtering and organization
* WordPress Application Password authentication

**Built for AI agents, by AI agents.**

Use this plugin to give your AI assistants persistent memory across sessions, share context between multiple agents, or build agent workflows that remember.

== Installation ==

1. Upload the `botcreds-agent-memory` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. (Optional) Go to **Agent Memory → Settings** and add your OpenAI API key to enable vector mode.
4. Create a WordPress Application Password for your agent under **Users → Profile → Application Passwords**.
5. Use the REST API at `/wp-json/botcreds-memory/v1/` with Basic Auth.

== Frequently Asked Questions ==

= Do I need an OpenAI API key? =

No. Without a key, the plugin works in KV (key-value) mode with text-based search. An OpenAI key enables semantic vector search via embeddings.

= How does authentication work? =

The plugin uses WordPress Application Passwords. Create one under **Users → Profile → Application Passwords**, then use HTTP Basic Auth with your username and the generated password.

= Can I restrict which keys a user can access? =

Yes. Go to **Users → Edit User → Agent Memory Access** and set allowed key prefixes. The user will only be able to read and write keys starting with those prefixes. Leave blank for full access.

= What is MCP? =

MCP (Model Context Protocol) is a standard for AI agents to discover and use tools. The plugin exposes an MCP manifest at `/wp-json/botcreds-memory/v1/mcp` and a tool call endpoint at `/wp-json/botcreds-memory/v1/mcp/call`.

= How are embeddings generated? =

When you save an entry and an OpenAI key is configured, the plugin schedules a WP-Cron event to generate the embedding asynchronously. Existing entries can be backfilled from the Settings page.

= What embedding model is used? =

By default, `text-embedding-3-small` (1536 dimensions). You can change this in the Settings page.

== External services ==

This plugin optionally connects to the OpenAI API to generate vector embeddings for semantic search.

* **Service:** OpenAI Embeddings API (`https://api.openai.com/v1/embeddings`)
* **When it is used:** Only when an OpenAI API key is configured in the plugin settings. Without a key, the plugin operates in KV-only mode and makes no external requests.
* **What is sent:** The text content of memory entries you explicitly write or update.
* **OpenAI Terms of Use:** https://openai.com/policies/terms-of-use
* **OpenAI Privacy Policy:** https://openai.com/policies/privacy-policy

== Changelog ==

= 2.0.9 =
* Fix: Replace non-standard bcam prefix with botcreds_memory in admin page slug, hook names, nonce actions, and POST key names for WordPress.org review compliance.

= 2.0.8 =
* Fix fatal error on settings pages: guard Settings API registration with is_admin() check.

= 2.0.7 =
* Fix PHP 7.4 compatibility: replace str_starts_with() with strpos()-based equivalent.

= 2.0.6 =
* Register plugin settings on rest_api_init so show_in_rest works correctly.
* Expose OpenAI key and embedding model settings via the REST API.

= 2.0.4 =
* Security hardening: return 403 for unauthenticated HTML requests to the frontend.

= 2.0.3 =
* MCP: implement proper JSON-RPC 2.0 protocol for tool calls and responses.

= 2.0.2 =
* Update plugin author to Joe Boydston.
* Add jboydston as WordPress.org contributor.

= 2.0.1 =
* Fix Plugin Check errors: SQL preparation phpcs:ignore annotations, nonce verification in user profile save, i18n ordered placeholders, error_log wrapped in WP_DEBUG guard.
* Update tested up to WordPress 7.0.

= 2.0.0 =
* Initial public release.
* KV mode: key-value store with tags, expiry, and text search.
* Vector mode: semantic search via OpenAI embeddings.
* REST API: full CRUD under `/wp-json/botcreds-memory/v1/`.
* MCP endpoint: manifest and tool call handler.
* Access control: per-user namespace restrictions.
* Admin UI: entries browser, settings page, access control overview.
* WP-Cron: automatic embedding generation and bulk backfill.

== Upgrade Notice ==

= 2.0.8 =
Fixes a fatal error on settings pages. Update recommended.

= 2.0.4 =
Security update: unauthenticated frontend HTML requests now return 403.

= 2.0.0 =
Initial release. Install and activate to get started.
