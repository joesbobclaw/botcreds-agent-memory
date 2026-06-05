<?php
/**
 * MCP (Model Context Protocol) manifest and handler for BotCreds Agent Memory.
 *
 * Provides a tool manifest at GET /mcp and a tool call handler at POST /mcp/call.
 *
 * @package BotCreds_Agent_Memory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers MCP REST routes and handles tool calls.
 */
class Botcreds_Memory_MCP {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'botcreds-memory/v1';

	/**
	 * Register MCP REST routes.
	 */
	public static function register_routes(): void {

		// GET /mcp — MCP manifest.
		register_rest_route( self::NAMESPACE, '/mcp', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_manifest' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
		) );

		// POST /mcp/call — Execute an MCP tool call.
		register_rest_route( self::NAMESPACE, '/mcp/call', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_call' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
			'args'                => array(
				'tool' => array(
					'type'     => 'string',
					'required' => true,
				),
				'parameters' => array(
					'type'    => 'object',
					'default' => array(),
				),
			),
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
	 * GET /mcp — Return the MCP tool manifest.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_manifest(): WP_REST_Response {
		$manifest = array(
			'schema_version' => 'v1',
			'name'           => 'BotCreds Agent Memory',
			'description'    => 'Portable memory store for AI agents',
			'tools'          => array(
				array(
					'name'        => 'get_memory',
					'description' => 'Retrieve a memory entry by exact key',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'key' => array( 'type' => 'string' ),
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
							'key'        => array( 'type' => 'string' ),
							'value'      => array( 'type' => 'string' ),
							'tags'       => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'expires_at' => array(
								'type'   => 'string',
								'format' => 'date-time',
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
							'key' => array( 'type' => 'string' ),
						),
						'required'   => array( 'key' ),
					),
				),
				array(
					'name'        => 'list_memory',
					'description' => 'List memory entries, optionally filtered by key prefix or tags',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'key_prefix' => array( 'type' => 'string' ),
							'tags'       => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'limit'      => array(
								'type'    => 'integer',
								'default' => 20,
							),
						),
					),
				),
				array(
					'name'        => 'search_memory',
					'description' => 'Semantic search across memory entries (vector mode only). Falls back to text search if vector mode disabled.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'query'      => array( 'type' => 'string' ),
							'key_prefix' => array( 'type' => 'string' ),
							'limit'      => array(
								'type'    => 'integer',
								'default' => 10,
							),
						),
						'required'   => array( 'query' ),
					),
				),
			),
		);

		return new WP_REST_Response( $manifest, 200 );
	}

	/**
	 * POST /mcp/call — Execute an MCP tool call.
	 *
	 * Routes the tool call to the appropriate internal handler.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_call( WP_REST_Request $request ) {
		$tool   = $request->get_param( 'tool' );
		$params = $request->get_param( 'parameters' ) ?: array();

		switch ( $tool ) {
			case 'get_memory':
				return self::tool_get_memory( $params );

			case 'set_memory':
				return self::tool_set_memory( $params );

			case 'delete_memory':
				return self::tool_delete_memory( $params );

			case 'list_memory':
				return self::tool_list_memory( $params );

			case 'search_memory':
				return self::tool_search_memory( $params );

			default:
				return new WP_Error(
					'botcreds_mcp_unknown_tool',
					"Unknown tool: {$tool}",
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * MCP tool: get_memory — Retrieve a memory entry by exact key.
	 *
	 * @param array $params Tool parameters.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function tool_get_memory( array $params ) {
		$key = $params['key'] ?? '';

		if ( empty( $key ) ) {
			return new WP_Error(
				'botcreds_mcp_missing_param',
				'Parameter "key" is required.',
				array( 'status' => 400 )
			);
		}

		if ( ! Botcreds_Memory_Access_Control::can_access_key( $key ) ) {
			return new WP_Error(
				'botcreds_memory_forbidden',
				'You do not have access to this key namespace.',
				array( 'status' => 403 )
			);
		}

		$entry = Botcreds_Memory_DB::get_by_key( $key );

		if ( ! $entry ) {
			return new WP_REST_Response( array(
				'result' => null,
				'error'  => 'Entry not found.',
			), 200 );
		}

		return new WP_REST_Response( array( 'result' => $entry ), 200 );
	}

	/**
	 * MCP tool: set_memory — Create or update a memory entry.
	 *
	 * @param array $params Tool parameters.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function tool_set_memory( array $params ) {
		$key   = $params['key'] ?? '';
		$value = $params['value'] ?? '';

		if ( empty( $key ) || $value === '' ) {
			return new WP_Error(
				'botcreds_mcp_missing_param',
				'Parameters "key" and "value" are required.',
				array( 'status' => 400 )
			);
		}

		if ( ! Botcreds_Memory_Access_Control::can_access_key( $key ) ) {
			return new WP_Error(
				'botcreds_memory_forbidden',
				'You do not have access to this key namespace.',
				array( 'status' => 403 )
			);
		}

		$data = array(
			'key'   => $key,
			'value' => $value,
		);

		if ( isset( $params['tags'] ) ) {
			$data['tags'] = $params['tags'];
		}

		if ( isset( $params['expires_at'] ) ) {
			$data['expires_at'] = $params['expires_at'];
		}

		$entry = Botcreds_Memory_DB::upsert( $data );

		if ( ! $entry ) {
			return new WP_Error(
				'botcreds_mcp_save_failed',
				'Failed to save entry.',
				array( 'status' => 500 )
			);
		}

		// Schedule embedding.
		Botcreds_Memory_Embeddings::schedule_embed( $entry['id'] );

		return new WP_REST_Response( array( 'result' => $entry ), 200 );
	}

	/**
	 * MCP tool: delete_memory — Delete a memory entry by key.
	 *
	 * @param array $params Tool parameters.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function tool_delete_memory( array $params ) {
		$key = $params['key'] ?? '';

		if ( empty( $key ) ) {
			return new WP_Error(
				'botcreds_mcp_missing_param',
				'Parameter "key" is required.',
				array( 'status' => 400 )
			);
		}

		if ( ! Botcreds_Memory_Access_Control::can_access_key( $key ) ) {
			return new WP_Error(
				'botcreds_memory_forbidden',
				'You do not have access to this key namespace.',
				array( 'status' => 403 )
			);
		}

		$deleted = Botcreds_Memory_DB::delete_by_key( $key );

		return new WP_REST_Response( array(
			'result' => array(
				'deleted' => $deleted,
				'key'     => $key,
			),
		), 200 );
	}

	/**
	 * MCP tool: list_memory — List entries with optional filters.
	 *
	 * @param array $params Tool parameters.
	 * @return WP_REST_Response
	 */
	private static function tool_list_memory( array $params ): WP_REST_Response {
		$args = array(
			'key_prefix' => $params['key_prefix'] ?? '',
			'tags'       => $params['tags'] ?? array(),
			'limit'      => $params['limit'] ?? 20,
		);

		$result  = Botcreds_Memory_DB::list_entries( $args );
		$entries = Botcreds_Memory_Access_Control::filter_entries( $result['entries'] );

		return new WP_REST_Response( array(
			'result' => array(
				'entries' => $entries,
				'total'   => count( $entries ),
			),
		), 200 );
	}

	/**
	 * MCP tool: search_memory — Semantic or text search.
	 *
	 * @param array $params Tool parameters.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function tool_search_memory( array $params ) {
		$query = $params['query'] ?? '';

		if ( empty( $query ) ) {
			return new WP_Error(
				'botcreds_mcp_missing_param',
				'Parameter "query" is required.',
				array( 'status' => 400 )
			);
		}

		$limit      = $params['limit'] ?? 10;
		$key_prefix = $params['key_prefix'] ?? '';

		if ( Botcreds_Memory_Embeddings::is_enabled() ) {
			// Semantic search.
			$filter_args = array( 'key_prefix' => $key_prefix );
			$entries_with_embeddings = Botcreds_Memory_DB::get_entries_with_embeddings( $filter_args );
			$results = Botcreds_Memory_Embeddings::search( $query, $entries_with_embeddings );
			$results = Botcreds_Memory_Access_Control::filter_entries( $results );
			$results = array_slice( $results, 0, $limit );
		} else {
			// Fallback: text search.
			$args = array(
				'key_prefix' => $key_prefix,
				'search'     => $query,
				'limit'      => $limit,
			);
			$result  = Botcreds_Memory_DB::list_entries( $args );
			$results = Botcreds_Memory_Access_Control::filter_entries( $result['entries'] );
		}

		return new WP_REST_Response( array(
			'result' => array(
				'entries' => $results,
				'total'   => count( $results ),
				'mode'    => Botcreds_Memory_Embeddings::is_enabled() ? 'vector' : 'text',
			),
		), 200 );
	}
}
