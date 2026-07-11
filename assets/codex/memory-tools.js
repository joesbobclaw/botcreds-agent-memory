/**
 * BotCreds Agent Memory — Codex tool adapters (ESM, Node 18+)
 *
 * Exports an array of tool objects in OpenAI function-calling format for use
 * with OpenAI Codex or any agent framework that accepts { name, description,
 * parameters, execute } tool definitions.
 *
 * Environment variables (set before running Codex):
 *   BOTCREDS_MEMORY_URL  — Base URL of your WordPress site.
 *                          Example: https://yoursite.com
 *   BOTCREDS_MEMORY_KEY  — Application password in "username:app_password" format.
 *                          Example: jboydston:AbCd 1234 EfGh 5678 IjKl 9012
 *                          Optional: if absent, requests are unauthenticated
 *                          (read-only, public entries only).
 *
 * Full documentation: https://botcreds.com/agent-memory/
 */

const BASE_URL = (process.env.BOTCREDS_MEMORY_URL || '').replace(/\/$/, '');
const API_ROOT = `${BASE_URL}/wp-json/botcreds-memory/v1`;

/**
 * Build the Authorization header value from the env key.
 * Returns null when no key is configured (unauthenticated mode).
 *
 * @returns {string|null}
 */
function buildAuthHeader() {
  const key = process.env.BOTCREDS_MEMORY_KEY;
  if (!key) return null;
  return 'Basic ' + Buffer.from(key).toString('base64');
}

/**
 * Make an authenticated fetch call to the memory API.
 *
 * @param {string} url      Full URL to request.
 * @param {object} [init]   fetch init options (method, body, etc.).
 * @returns {Promise<object>} Parsed JSON response or { error: string }.
 */
async function apiFetch(url, init = {}) {
  const headers = { 'Content-Type': 'application/json', ...(init.headers || {}) };
  const auth = buildAuthHeader();
  if (auth) headers['Authorization'] = auth;

  try {
    const res = await fetch(url, { ...init, headers });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      data = { raw: text };
    }
    if (!res.ok) {
      return { error: data?.message || `HTTP ${res.status}: ${res.statusText}` };
    }
    return data;
  } catch (err) {
    return { error: err?.message || String(err) };
  }
}

// ---------------------------------------------------------------------------
// Tool: get_memory
// ---------------------------------------------------------------------------

const get_memory = {
  name: 'get_memory',
  description:
    'Retrieve a memory entry by exact key. Returns the entry object including value, tags, and metadata, or an error if not found.',
  parameters: {
    type: 'object',
    properties: {
      key: {
        type: 'string',
        description:
          'The exact key to look up. Supports namespaced keys like "project/feature-name".',
      },
    },
    required: ['key'],
  },

  /**
   * @param {{ key: string }} args
   * @returns {Promise<object>}
   */
  async execute({ key }) {
    if (!key) return { error: 'key is required' };
    const url = `${API_ROOT}/entries/${encodeURIComponent(key)}`;
    return apiFetch(url);
  },
};

// ---------------------------------------------------------------------------
// Tool: set_memory
// ---------------------------------------------------------------------------

const set_memory = {
  name: 'set_memory',
  description:
    'Create or update a memory entry. Use namespaced keys like "project/feature" or "decision/topic" for organization. Returns the saved entry.',
  parameters: {
    type: 'object',
    properties: {
      key: {
        type: 'string',
        description:
          'Unique key for this memory. Use namespaced format: "project/name", "decision/topic", "context/thing".',
      },
      value: {
        type: 'string',
        description: 'The value to store. Can be plain text, JSON, or any string content.',
      },
      tags: {
        type: 'array',
        items: { type: 'string' },
        description: 'Optional tags for filtering and organization (e.g. ["project", "decision"]).',
      },
    },
    required: ['key', 'value'],
  },

  /**
   * @param {{ key: string, value: string, tags?: string[] }} args
   * @returns {Promise<object>}
   */
  async execute({ key, value, tags = [] }) {
    if (!key) return { error: 'key is required' };
    if (value === undefined || value === null) return { error: 'value is required' };
    return apiFetch(`${API_ROOT}/entries`, {
      method: 'POST',
      body: JSON.stringify({ key, value, tags }),
    });
  },
};

// ---------------------------------------------------------------------------
// Tool: search_memory
// ---------------------------------------------------------------------------

const search_memory = {
  name: 'search_memory',
  description:
    'Search memory entries by text or semantic similarity. Returns a list of matching entries. Use this before starting a task to load relevant prior context.',
  parameters: {
    type: 'object',
    properties: {
      query: {
        type: 'string',
        description: 'Search query — text or natural-language description of what to find.',
      },
      limit: {
        type: 'integer',
        description: 'Maximum number of results to return. Defaults to 10.',
        default: 10,
      },
    },
    required: ['query'],
  },

  /**
   * @param {{ query: string, limit?: number }} args
   * @returns {Promise<object>}
   */
  async execute({ query, limit = 10 }) {
    if (!query) return { error: 'query is required' };
    const params = new URLSearchParams({ search: query, limit: String(limit) });
    return apiFetch(`${API_ROOT}/entries?${params}`);
  },
};

// ---------------------------------------------------------------------------
// Exports
// ---------------------------------------------------------------------------

/**
 * All three memory tools ready to pass to a Codex agent or tool registry.
 *
 * @type {Array<{ name: string, description: string, parameters: object, execute: Function }>}
 */
const tools = [get_memory, set_memory, search_memory];

export default tools;
export { get_memory, set_memory, search_memory };
