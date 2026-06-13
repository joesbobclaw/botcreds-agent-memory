<?php
/**
 * MCP (Model Context Protocol) JSON-RPC 2.0 handler for BotCreds Agent Memory.
 *
 * Implements the MCP Streamable HTTP transport on a single endpoint:
 *   POST /wp-json/botcreds-memory/v1/mcp  — JSON-RPC 2.0 dispatcher
 *   GET  /wp-json/botcreds-memory/v1/mcp  — Human-readable server info + tools list
 *
 * Compatible with mcp-remote and Claude Desktop.
 *
 * @package BotCreds_Agent_Memory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers MCP REST routes and handles JSON-RPC 2.0 requests.
 */
class Botcreds_Memory_MCP {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'botcreds-memory/v1';

	/**
	 * MCP protocol version this server implements.
	 */
	const PROTOCOL_VERSION = '2024-11-05';

	/**
	 * Register MCP REST routes.
	 */
	public static function register_routes(): void {

		// POST /mcp — JSON-RPC 2.0 dispatcher.
		register_rest_route( self::NAMESPACE, '/mcp', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_jsonrpc' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
		) );

		// OPTIONS /mcp — CORS preflight.
		register_rest_route( self::NAMESPACE, '/mcp', array(
			'methods'             => 'OPTIONS',
			'callback'            => array( __CLASS__, 'handle_options' ),
			'permission_callback' => '__return_true',
		) );

		// GET /mcp — Human-readable manifest for discoverability.
		register_rest_route( self::NAMESPACE, '/mcp', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_manifest' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
		) );
	}

	/**
	 * Permission callback: user must be authenticated.
	 *
	 * @return bool
	 */
	public static function check_auth(): bool {
		return is_user_logged_in();
	}

	/**
	 * Add CORS headers required by mcp-remote to any response.
	 */
	private static function add_cors_headers(): void {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
	}

	/**
	 * OPTIONS /mcp — Handle CORS preflight.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_options(): WP_REST_Response {
		self::add_cors_headers();
		return new WP_REST_Response( null, 200 );
	}

	/**
	 * GET /mcp — Return human-readable server info + tools list.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_manifest(): WP_REST_Response {
		self::add_cors_headers();

		$data = array(
			'serverInfo'      => array(
				'name'    => 'BotCreds Agent Memory',
				'version' => BOTCREDS_MEMORY_VERSION,
			),
			'protocolVersion' => self::PROTOCOL_VERSION,
			'description'     => 'Portable memory store for AI agents. Use POST with JSON-RPC 2.0 to call tools.',
			'tools'           => self::tools_list(),
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * POST /mcp — JSON-RPC 2.0 dispatcher.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_jsonrpc( WP_REST_Request $request ): WP_REST_Response {
		self::add_cors_headers();

		$body = $request->get_json_params();

		// Validate JSON-RPC envelope.
		if ( empty( $body ) || ( $body['jsonrpc'] ?? '' ) !== '2.0' || ! isset( $body['method'] ) ) {
			return self::jsonrpc_error(
				null,
				-32600,
				'Invalid Request',
				null
			);
		}

		$id     = $body['id'] ?? null;
		$method = $body['method'];
		$params = $body['params'] ?? array();

		switch ( $method ) {

			case 'initialize':
				return self::rpc_initialize( $id, $params );

			case 'notifications/initialized':
				// Notification: no response body needed.
				return new WP_REST_Response( null, 200 );

			case 'tools/list':
				return self::rpc_tools_list( $id );

			case 'tools/call':
				return self::rpc_tools_call( $id, $params );

			default:
				return self::jsonrpc_error( $id, -32601, 'Method not found', null );
		}
	}

	// -------------------------------------------------------------------------
	// JSON-RPC method handlers
	// -------------------------------------------------------------------------

	/**
	 * Handle "initialize" — handshake.
	 *
	 * @param mixed $id     Request id.
	 * @param array $params Request params.
	 * @return WP_REST_Response
	 */
	private static function rpc_initialize( $id, array $params ): WP_REST_Response {
		return self::jsonrpc_result( $id, array(
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities'    => array(
				'tools' => (object) array(),
			),
			'serverInfo'      => array(
				'name'    => 'BotCreds Agent Memory',
				'version' => BOTCREDS_MEMORY_VERSION,
			),
		) );
	}

	/**
	 * Handle "tools/list".
	 *
	 * @param mixed $id Request id.
	 * @return WP_REST_Response
	 */
	private static function rpc_tools_list( $id ): WP_REST_Response {
		return self::jsonrpc_result( $id, array(
			'tools' => self::tools_list(),
		) );
	}

	/**
	 * Handle "tools/call".
	 *
	 * @param mixed $id     Request id.
	 * @param array $params Request params (name, arguments).
	 * @return WP_REST_Response
	 */
	private static function rpc_tools_call( $id, array $params ): WP_REST_Response {
		$tool_name = $params['name'] ?? '';
		$args      = $params['arguments'] ?? array();

		switch ( $tool_name ) {

			case 'get_memory':
				return self::tool_get_memory( $id, $args );

			case 'set_memory':
				return self::tool_set_memory( $id, $args );

			case 'delete_memory':
				return self::tool_delete_memory( $id, $args );

			case 'list_memory':
				return self::tool_list_memory( $id, $args );

			case 'search_memory':
				return self::tool_search_memory( $id, $args );

			case 'get_by_tag':
				return self::tool_get_by_tag( $id, $args );

			case 'list_namespaces':
				return self::tool_list_namespaces( $id );

			case 'list_tags':
				return self::tool_list_tags( $id );

			case 'get_revisions':
				return self::tool_get_revisions( $id, $args );

			default:
				return self::jsonrpc_result( $id, array(
					'content' => array(
						array( 'type' => 'text', 'text' => "Unknown tool: {$tool_name}" ),
					),
					'isError' => true,
				) );
		}
	}

	// -------------------------------------------------------------------------
	// Tool implementations
	// -------------------------------------------------------------------------

	/**
	 * Tool: get_memory — Retrieve a memory entry by exact key.
	 *
	 * @param mixed $id   Request id.
	 * @param array $args Tool arguments.
	 * @return WP_REST_Response
	 */
	private static function tool_get_memory( $id, array $args ): WP_REST_Response {
		$key = $args['key'] ?? '';

		if ( empty( $key ) ) {
			return self::tool_error( $id, 'Parameter "key" is required.' );
		}

		if ( ! Botcreds_Memory_Access_Control::can_access_key( $key ) ) {
			return self::tool_error( $id, 'You do not have access to this key namespace.' );
		}

		$entry = Botcreds_Memory_DB::get_by_key( $key );

		if ( ! $entry ) {
			return self::jsonrpc_result( $id, array(
				'content' => array(
					array( 'type' => 'text', 'text' => "Key not found: {$key}" ),
				),
				'isError' => true,
			) );
		}

		return self::jsonrpc_result( $id, array(
			'content' => array(
				array( 'type' => 'text', 'text' => $entry['value'] ),
			),
			'isError' => false,
		) );
	}

	/**
	 * Tool: set_memory — Create or update a memory entry.
	 *
	 * @param mixed $id   Request id.
	 * @param array $args Tool arguments.
	 * @return WP_REST_Response
	 */
	private static function tool_set_memory( $id, array $args ): WP_REST_Response {
		$key   = $args['key'] ?? '';
		$value = $args['value'] ?? '';

		if ( empty( $key ) || $value === '' ) {
			return self::tool_error( $id, 'Parameters "key" and "value" are required.' );
		}

		if ( ! Botcreds_Memory_Access_Control::can_access_key( $key ) ) {
			return self::tool_error( $id, 'You do not have access to this key namespace.' );
		}

		$data = array(
			'key'   => $key,
			'value' => $value,
		);

		if ( isset( $args['tags'] ) ) {
			$data['tags'] = $args['tags'];
		}

		if ( isset( $args['expires_at'] ) ) {
			$data['expires_at'] = $args['expires_at'];
		}

		$entry = Botcreds_Memory_DB::upsert( $data );

		if ( ! $entry ) {
			return self::tool_error( $id, 'Failed to save entry.' );
		}

		// Schedule embedding generation if available.
		if ( class_exists( 'Botcreds_Memory_Embeddings' ) ) {
			Botcreds_Memory_Embeddings::schedule_embed( $entry['id'] );
		}

		return self::jsonrpc_result( $id, array(
			'content' => array(
				array( 'type' => 'text', 'text' => "Saved: {$key}" ),
			),
			'isError' => false,
		) );
	}

	/**
	 * Tool: delete_memory — Delete a memory entry by key.
	 *
	 * @param mixed $id   Request id.
	 * @param array $args Tool arguments.
	 * @return WP_REST_Response
	 */
	private static function tool_delete_memory( $id, array $args ): WP_REST_Response {
		$key = $args['key'] ?? '';

		if ( empty( $key ) ) {
			return self::tool_error( $id, 'Parameter "key" is required.' );
		}

		if ( ! Botcreds_Memory_Access_Control::can_access_key( $key ) ) {
			return self::tool_error( $id, 'You do not have access to this key namespace.' );
		}

		$deleted = Botcreds_Memory_DB::delete_by_key( $key );

		if ( ! $deleted ) {
			return self::jsonrpc_result( $id, array(
				'content' => array(
					array( 'type' => 'text', 'text' => "Key not found: {$key}" ),
				),
				'isError' => true,
			) );
		}

		return self::jsonrpc_result( $id, array(
			'content' => array(
				array( 'type' => 'text', 'text' => "Deleted: {$key}" ),
			),
			'isError' => false,
		) );
	}

	/**
	 * Tool: list_memory — List entries with optional filters.
	 *
	 * @param mixed $id   Request id.
	 * @param array $args Tool arguments.
	 * @return WP_REST_Response
	 */
	private static function tool_list_memory( $id, array $args ): WP_REST_Response {
		$query_args = array(
			'key_prefix' => $args['key_prefix'] ?? '',
			'namespace'  => $args['namespace'] ?? '',
			'tags'       => $args['tags'] ?? array(),
			'limit'      => $args['limit'] ?? 20,
		);

		// Support single ?tag= alongside array tags.
		if ( ! empty( $args['tag'] ) ) {
			$extra_tags             = array_map( 'trim', explode( ',', $args['tag'] ) );
			$query_args['tags']     = array_unique( array_merge( $query_args['tags'], $extra_tags ) );
		}

		$result  = Botcreds_Memory_DB::list_entries( $query_args );
		$entries = Botcreds_Memory_Access_Control::filter_entries( $result['entries'] );

		return self::jsonrpc_result( $id, array(
			'content' => array(
				array( 'type' => 'text', 'text' => wp_json_encode( $entries, JSON_PRETTY_PRINT ) ),
			),
			'isError' => false,
		) );
	}

	/**
	 * Tool: search_memory — Semantic or text search.
	 *
	 * @param mixed $id   Request id.
	 * @param array $args Tool arguments.
	 * @return WP_REST_Response
	 */
	private static function tool_search_memory( $id, array $args ): WP_REST_Response {
		$query = $args['query'] ?? '';

		if ( empty( $query ) ) {
			return self::tool_error( $id, 'Parameter "query" is required.' );
		}

		$limit      = $args['limit'] ?? 10;
		$key_prefix = $args['key_prefix'] ?? '';

		$namespace  = $args['namespace'] ?? '';
		$tag_filter = ! empty( $args['tag'] ) ? array_map( 'trim', explode( ',', $args['tag'] ) ) : array();

		if ( class_exists( 'Botcreds_Memory_Embeddings' ) && Botcreds_Memory_Embeddings::is_enabled() ) {
			// Semantic (vector) search.
			$filter_args             = array(
				'key_prefix' => $key_prefix,
				'namespace'  => $namespace,
			);
			$entries_with_embeddings = Botcreds_Memory_DB::get_entries_with_embeddings( $filter_args );
			$results                 = Botcreds_Memory_Embeddings::search( $query, $entries_with_embeddings );
			$results                 = Botcreds_Memory_Access_Control::filter_entries( $results );
			// Apply tag filter post-search when tags requested.
			if ( ! empty( $tag_filter ) ) {
				$results = array_values( array_filter( $results, function ( $e ) use ( $tag_filter ) {
					return ! empty( array_intersect( $tag_filter, $e['tags'] ?? array() ) );
				} ) );
			}
			$results = array_slice( $results, 0, $limit );
		} else {
			// Fallback: text search.
			$search_args = array(
				'key_prefix' => $key_prefix,
				'namespace'  => $namespace,
				'tags'       => $tag_filter,
				'search'     => $query,
				'limit'      => $limit,
			);
			$result  = Botcreds_Memory_DB::list_entries( $search_args );
			$results = Botcreds_Memory_Access_Control::filter_entries( $result['entries'] );
		}

		return self::jsonrpc_result( $id, array(
			'content' => array(
				array( 'type' => 'text', 'text' => wp_json_encode( $results, JSON_PRETTY_PRINT ) ),
			),
			'isError' => false,
		) );
	}

	// -------------------------------------------------------------------------
	// New tool implementations (v2.2.0)
	// -------------------------------------------------------------------------

	/**
	 * Tool: get_by_tag — Fetch all memory entries with a given tag.
	 *
	 * @param mixed $id   Request id.
	 * @param array $args Tool arguments.
	 * @return WP_REST_Response
	 */
	private static function tool_get_by_tag( $id, array $args ): WP_REST_Response {
		$tag = sanitize_text_field( $args['tag'] ?? '' );

		if ( empty( $tag ) ) {
			return self::tool_error( $id, 'Parameter "tag" is required.' );
		}

		$limit   = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$entries = Botcreds_Memory_DB::get_by_tag( $tag, $limit );
		$entries = Botcreds_Memory_Access_Control::filter_entries( $entries );

		return self::jsonrpc_result( $id, array(
			'content' => array(
				array( 'type' => 'text', 'text' => wp_json_encode( $entries, JSON_PRETTY_PRINT ) ),
			),
			'isError' => false,
		) );
	}

	/**
	 * Tool: list_namespaces — List all namespaces with entry counts.
	 *
	 * @param mixed $id Request id.
	 * @return WP_REST_Response
	 */
	private static function tool_list_namespaces( $id ): WP_REST_Response {
		$namespaces = Botcreds_Memory_DB::list_namespaces();

		return self::jsonrpc_result( $id, array(
			'content' => array(
				array( 'type' => 'text', 'text' => wp_json_encode( $namespaces, JSON_PRETTY_PRINT ) ),
			),
			'isError' => false,
		) );
	}

	/**
	 * Tool: list_tags — List all tags used in the memory store with entry counts.
	 *
	 * @param mixed $id Request id.
	 * @return WP_REST_Response
	 */
	private static function tool_list_tags( $id ): WP_REST_Response {
		$tags = Botcreds_Memory_DB::list_tags();

		return self::jsonrpc_result( $id, array(
			'content' => array(
				array( 'type' => 'text', 'text' => wp_json_encode( $tags, JSON_PRETTY_PRINT ) ),
			),
			'isError' => false,
		) );
	}

	/**
	 * Tool: get_revisions — Get revision history for a memory entry.
	 *
	 * @param mixed $id   Request id.
	 * @param array $args Tool arguments.
	 * @return WP_REST_Response
	 */
	private static function tool_get_revisions( $id, array $args ): WP_REST_Response {
		$key = sanitize_text_field( $args['key'] ?? '' );

		if ( empty( $key ) ) {
			return self::tool_error( $id, 'Parameter "key" is required.' );
		}

		if ( ! Botcreds_Memory_Access_Control::can_access_key( $key ) ) {
			return self::tool_error( $id, 'You do not have access to this key namespace.' );
		}

		$entry = Botcreds_Memory_DB::get_by_key( $key, true );

		if ( ! $entry ) {
			return self::jsonrpc_result( $id, array(
				'content' => array(
					array( 'type' => 'text', 'text' => "Key not found: {$key}" ),
				),
				'isError' => true,
			) );
		}

		$limit     = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$revisions = Botcreds_Memory_DB::get_revisions( $entry['id'], $limit );

		$result = array(
			'key'       => $key,
			'revisions' => $revisions,
		);

		return self::jsonrpc_result( $id, array(
			'content' => array(
				array( 'type' => 'text', 'text' => wp_json_encode( $result, JSON_PRETTY_PRINT ) ),
			),
			'isError' => false,
		) );
	}

	// -------------------------------------------------------------------------
	// Tools schema definition (shared by GET manifest and tools/list)
	// -------------------------------------------------------------------------

	/**
	 * Return the canonical list of MCP tools with input schemas.
	 *
	 * @return array
	 */
	private static function tools_list(): array {
		return array(
			array(
				'name'        => 'get_memory',
				'description' => 'Retrieve a memory entry by exact key',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'key' => array(
							'type'        => 'string',
							'description' => 'The exact memory key to retrieve',
						),
					),
					'required'   => array( 'key' ),
				),
			),
			array(
				'name'        => 'set_memory',
				'description' => 'Create or update a memory entry',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'key'        => array(
							'type'        => 'string',
							'description' => 'Memory key (use namespaced format like joe/projects/foo)',
						),
						'value'      => array(
							'type'        => 'string',
							'description' => 'Value to store',
						),
						'tags'       => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Optional tags',
						),
						'expires_at' => array(
							'type'        => 'string',
							'description' => 'Optional ISO 8601 expiry datetime',
						),
					),
					'required'   => array( 'key', 'value' ),
				),
			),
			array(
				'name'        => 'delete_memory',
				'description' => 'Delete a memory entry by key',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'key' => array(
							'type'        => 'string',
							'description' => 'The exact memory key to delete',
						),
					),
					'required'   => array( 'key' ),
				),
			),
			array(
				'name'        => 'list_memory',
				'description' => 'List memory entries, optionally filtered by key prefix, namespace, or tags',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'key_prefix' => array(
							'type'        => 'string',
							'description' => 'Filter by key prefix (e.g. joe/projects)',
						),
						'namespace'  => array(
							'type'        => 'string',
							'description' => 'Filter by namespace (e.g. joe/projects — matches exact and children)',
						),
						'tags'       => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Filter by tags (array)',
						),
						'tag'        => array(
							'type'        => 'string',
							'description' => 'Filter by a single tag string',
						),
						'limit'      => array(
							'type'        => 'integer',
							'description' => 'Max results (default 20)',
						),
					),
				),
			),
			array(
				'name'        => 'search_memory',
				'description' => 'Search memory entries by text or semantic similarity',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'      => array(
							'type'        => 'string',
							'description' => 'Search query',
						),
						'key_prefix' => array(
							'type'        => 'string',
							'description' => 'Limit search to key prefix',
						),
						'namespace'  => array(
							'type'        => 'string',
							'description' => 'Limit search to namespace (exact and children)',
						),
						'tag'        => array(
							'type'        => 'string',
							'description' => 'Filter results by a single tag',
						),
						'limit'      => array(
							'type'        => 'integer',
							'description' => 'Max results (default 10)',
						),
					),
					'required'   => array( 'query' ),
				),
			),
			array(
				'name'        => 'get_by_tag',
				'description' => 'Fetch all memory entries with a given tag. Use to load a context bundle by topic label.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'tag'   => array(
							'type'        => 'string',
							'description' => 'Tag to filter by',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Max results (default 50)',
						),
					),
					'required'   => array( 'tag' ),
				),
			),
			array(
				'name'        => 'list_namespaces',
				'description' => 'List all namespaces in the memory store with entry counts.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
			),
			array(
				'name'        => 'list_tags',
				'description' => 'List all tags used in the memory store with entry counts.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
			),
			array(
				'name'        => 'get_revisions',
				'description' => 'Get the revision history for a memory entry. Returns who changed it, when, and a summary of what changed.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'key'   => array(
							'type'        => 'string',
							'description' => 'The exact memory key to get revisions for',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Max revisions to return (default 10)',
						),
					),
					'required'   => array( 'key' ),
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// JSON-RPC helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a JSON-RPC 2.0 success response.
	 *
	 * @param mixed $id     Request id.
	 * @param mixed $result Result payload.
	 * @return WP_REST_Response
	 */
	private static function jsonrpc_result( $id, $result ): WP_REST_Response {
		return new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		), 200 );
	}

	/**
	 * Build a JSON-RPC 2.0 error response.
	 *
	 * @param mixed       $id      Request id (may be null).
	 * @param int         $code    JSON-RPC error code.
	 * @param string      $message Error message.
	 * @param mixed       $data    Optional additional data.
	 * @return WP_REST_Response
	 */
	private static function jsonrpc_error( $id, int $code, string $message, $data = null ): WP_REST_Response {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( $data !== null ) {
			$error['data'] = $data;
		}

		return new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		), 200 ); // JSON-RPC always returns HTTP 200, error is in the body.
	}

	/**
	 * Convenience helper: return a tool-level error result (isError=true).
	 *
	 * @param mixed  $id      Request id.
	 * @param string $message Human-readable error message.
	 * @return WP_REST_Response
	 */
	private static function tool_error( $id, string $message ): WP_REST_Response {
		return self::jsonrpc_result( $id, array(
			'content' => array(
				array( 'type' => 'text', 'text' => $message ),
			),
			'isError' => true,
		) );
	}
}
