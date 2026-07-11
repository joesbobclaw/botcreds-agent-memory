"""
BotCreds Agent Memory — Codex tool adapters (Python 3, stdlib only)

Exports a list MEMORY_TOOLS of tool dicts in OpenAI function-calling format:
    { "name", "description", "parameters", "execute" }

Also exports call_tool(name, args) for simple dispatch.

Environment variables (set before running Codex):
    BOTCREDS_MEMORY_URL  — Base URL of your WordPress site.
                           Example: https://yoursite.com
    BOTCREDS_MEMORY_KEY  — Application password in "username:app_password" format.
                           Example: jboydston:AbCd 1234 EfGh 5678 IjKl 9012
                           Optional: if absent, requests are unauthenticated
                           (read-only, public entries only).

Full documentation: https://botcreds.com/agent-memory/
"""

import base64
import json
import os
import urllib.error
import urllib.parse
import urllib.request

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _base_url() -> str:
    return os.environ.get("BOTCREDS_MEMORY_URL", "").rstrip("/")


def _api_root() -> str:
    return f"{_base_url()}/wp-json/botcreds-memory/v1"


def _auth_header() -> dict:
    """Return an Authorization header dict, or empty dict if no key is set."""
    key = os.environ.get("BOTCREDS_MEMORY_KEY", "")
    if not key:
        return {}
    encoded = base64.b64encode(key.encode()).decode()
    return {"Authorization": f"Basic {encoded}"}


def _api_request(url: str, method: str = "GET", body: dict | None = None) -> dict:
    """
    Make an HTTP request to the memory API.

    Returns parsed JSON on success or {"error": str} on failure.
    Never raises.
    """
    try:
        headers = {"Content-Type": "application/json", **_auth_header()}
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        with urllib.request.urlopen(req, timeout=15) as resp:
            raw = resp.read().decode()
            return json.loads(raw)
    except urllib.error.HTTPError as exc:
        try:
            payload = json.loads(exc.read().decode())
            return {"error": payload.get("message", f"HTTP {exc.code}: {exc.reason}")}
        except Exception:
            return {"error": f"HTTP {exc.code}: {exc.reason}"}
    except Exception as exc:  # noqa: BLE001
        return {"error": str(exc)}


# ---------------------------------------------------------------------------
# Tool: get_memory
# ---------------------------------------------------------------------------

def _get_memory(key: str) -> dict:
    """
    Retrieve a memory entry by exact key.

    Args:
        key: The exact key to look up (e.g. "project/feature-name").

    Returns:
        Entry dict with value, tags, and metadata, or {"error": str}.
    """
    if not key:
        return {"error": "key is required"}
    encoded_key = urllib.parse.quote(key, safe="")
    url = f"{_api_root()}/entries/{encoded_key}"
    return _api_request(url)


GET_MEMORY_TOOL = {
    "name": "get_memory",
    "description": (
        "Retrieve a memory entry by exact key. Returns the entry object including "
        "value, tags, and metadata, or an error if not found."
    ),
    "parameters": {
        "type": "object",
        "properties": {
            "key": {
                "type": "string",
                "description": (
                    "The exact key to look up. Supports namespaced keys "
                    'like "project/feature-name".'
                ),
            },
        },
        "required": ["key"],
    },
    "execute": _get_memory,
}

# ---------------------------------------------------------------------------
# Tool: set_memory
# ---------------------------------------------------------------------------

def _set_memory(key: str, value: str, tags: list[str] | None = None) -> dict:
    """
    Create or update a memory entry.

    Args:
        key:   Unique key (e.g. "project/name", "decision/topic").
        value: The value to store (plain text, JSON string, or any content).
        tags:  Optional list of tags for filtering and organization.

    Returns:
        Saved entry dict, or {"error": str}.
    """
    if not key:
        return {"error": "key is required"}
    if value is None:
        return {"error": "value is required"}
    body = {"key": key, "value": value, "tags": tags or []}
    return _api_request(f"{_api_root()}/entries", method="POST", body=body)


SET_MEMORY_TOOL = {
    "name": "set_memory",
    "description": (
        'Create or update a memory entry. Use namespaced keys like "project/feature" '
        'or "decision/topic" for organization. Returns the saved entry.'
    ),
    "parameters": {
        "type": "object",
        "properties": {
            "key": {
                "type": "string",
                "description": (
                    'Unique key for this memory. Use namespaced format: "project/name", '
                    '"decision/topic", "context/thing".'
                ),
            },
            "value": {
                "type": "string",
                "description": (
                    "The value to store. Can be plain text, JSON, or any string content."
                ),
            },
            "tags": {
                "type": "array",
                "items": {"type": "string"},
                "description": (
                    'Optional tags for filtering and organization (e.g. ["project", "decision"]).'
                ),
            },
        },
        "required": ["key", "value"],
    },
    "execute": _set_memory,
}

# ---------------------------------------------------------------------------
# Tool: search_memory
# ---------------------------------------------------------------------------

def _search_memory(query: str, limit: int = 10) -> dict:
    """
    Search memory entries by text or semantic similarity.

    Args:
        query: Text or natural-language description of what to find.
        limit: Maximum number of results (default 10).

    Returns:
        List of matching entry dicts, or {"error": str}.
    """
    if not query:
        return {"error": "query is required"}
    params = urllib.parse.urlencode({"search": query, "limit": limit})
    url = f"{_api_root()}/entries?{params}"
    return _api_request(url)


SEARCH_MEMORY_TOOL = {
    "name": "search_memory",
    "description": (
        "Search memory entries by text or semantic similarity. Returns a list of "
        "matching entries. Use this before starting a task to load relevant prior context."
    ),
    "parameters": {
        "type": "object",
        "properties": {
            "query": {
                "type": "string",
                "description": (
                    "Search query — text or natural-language description of what to find."
                ),
            },
            "limit": {
                "type": "integer",
                "description": "Maximum number of results to return. Defaults to 10.",
                "default": 10,
            },
        },
        "required": ["query"],
    },
    "execute": _search_memory,
}

# ---------------------------------------------------------------------------
# Public exports
# ---------------------------------------------------------------------------

MEMORY_TOOLS: list[dict] = [GET_MEMORY_TOOL, SET_MEMORY_TOOL, SEARCH_MEMORY_TOOL]

_TOOL_MAP: dict[str, dict] = {t["name"]: t for t in MEMORY_TOOLS}


def call_tool(name: str, args: dict) -> dict:
    """
    Dispatch a tool call by name.

    Args:
        name: Tool name — one of "get_memory", "set_memory", "search_memory".
        args: Keyword arguments for the tool's execute function.

    Returns:
        Tool result dict, or {"error": str} if tool not found.

    Example::

        result = call_tool("search_memory", {"query": "deployment config", "limit": 5})
    """
    tool = _TOOL_MAP.get(name)
    if tool is None:
        available = ", ".join(_TOOL_MAP.keys())
        return {"error": f"Unknown tool '{name}'. Available: {available}"}
    return tool["execute"](**args)
