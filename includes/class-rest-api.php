<?php
/**
 * REST API endpoints for BotCreds Agent Memory.
 *
 * All endpoints are under /wp-json/botcreds-memory/v1/.
 *
 * @package BotCreds_Agent_Memory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles all REST API routes for the memory store.
 */
class Botcreds_Memory_REST_API {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'botcreds-memory/v1';

	/**
	 * Register all REST routes.
	 */
	public static function register_routes(): void {

		// GET /entries — List/search entries.
		register_rest_route( self::NAMESPACE, '/entries', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_entries' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
			'args'                => array(
				'key'             => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'namespace'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'tag'             => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'tags'            => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'search'          => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit'           => array(
					'type'              => 'integer',
					'default'           => 20,
					'minimum'           => 1,
					'maximum'           => 100,
					'sanitize_callback' => 'absint',
				),
				'offset'          => array(
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'sanitize_callback' => 'absint',
				),
				'include_expired' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		) );

		// GET /entries/by-key — Get single entry by exact key.
		register_rest_route( self::NAMESPACE, '/entries/by-key', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_entry_by_key' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
			'args'                => array(
				'key' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// POST /entries — Create or update entry (upsert).
		register_rest_route( self::NAMESPACE, '/entries', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'upsert_entry' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
			'args'                => array(
				'key'        => array(
					'type'     => 'string',
					'required' => true,
				),
				'value'      => array(
					'type'     => 'string',
					'required' => true,
				),
				'tags'       => array(
					'type' => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'author'     => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'expires_at' => array(
					'type' => array( 'string', 'null' ),
				),
			),
		) );

		// DELETE /entries/by-key — Delete entry by exact key.
		register_rest_route( self::NAMESPACE, '/entries/by-key', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_entry' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
			'args'                => array(
				'key' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// GET /status — Plugin status.
		register_rest_route( self::NAMESPACE, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_status' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
		) );

		// GET /namespaces — List all namespaces with entry counts.
		register_rest_route( self::NAMESPACE, '/namespaces', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_namespaces' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
		) );

		// GET /tags — List all tags with entry counts.
		register_rest_route( self::NAMESPACE, '/tags', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_tags' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
		) );

		// GET /entries/revisions — Get revision history for a key.
		register_rest_route( self::NAMESPACE, '/entries/revisions', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_entry_revisions' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
			'args'                => array(
				'key'   => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit' => array(
					'type'              => 'integer',
					'default'           => 20,
					'minimum'           => 1,
					'maximum'           => 100,
					'sanitize_callback' => 'absint',
				),
			),
		) );
	}

	/**
	 * Permission callback: user must be authenticated.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_auth(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return true;
	}

	/**
	 * GET /entries — List and search memory entries.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_entries( WP_REST_Request $request ) {
		$search = $request->get_param( 'search' );

		// If search is provided and vector mode is enabled, do semantic search.
		if ( ! empty( $search ) && Botcreds_Memory_Embeddings::is_enabled() ) {
			return self::semantic_search( $request );
		}

		// Build tags array — merge ?tags= (CSV) and ?tag= (single).
		$tags = array();
		if ( ! empty( $request->get_param( 'tags' ) ) ) {
			$tags = array_map( 'trim', explode( ',', $request->get_param( 'tags' ) ) );
		}
		if ( ! empty( $request->get_param( 'tag' ) ) ) {
			$single_tags = array_map( 'trim', explode( ',', $request->get_param( 'tag' ) ) );
			$tags        = array_unique( array_merge( $tags, $single_tags ) );
		}

		// Standard list with optional filters.
		$args = array(
			'key_prefix'      => $request->get_param( 'key' ) ?? '',
			'namespace'       => $request->get_param( 'namespace' ) ?? '',
			'tags'            => $tags,
			'search'          => $search ?? '',
			'limit'           => $request->get_param( 'limit' ),
			'offset'          => $request->get_param( 'offset' ),
			'include_expired' => $request->get_param( 'include_expired' ),
		);

		$result  = Botcreds_Memory_DB::list_entries( $args );
		$entries = Botcreds_Memory_Access_Control::filter_entries( $result['entries'] );

		return new WP_REST_Response( array(
			'entries' => $entries,
			'total'   => count( $entries ),
		), 200 );
	}

	/**
	 * Perform semantic search using embeddings.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	private static function semantic_search( WP_REST_Request $request ): WP_REST_Response {
		$query  = $request->get_param( 'search' );
		$limit  = $request->get_param( 'limit' ) ?: 20;

		$filter_args = array(
			'key_prefix'      => $request->get_param( 'key' ) ?? '',
			'namespace'       => $request->get_param( 'namespace' ) ?? '',
			'include_expired' => $request->get_param( 'include_expired' ),
		);

		$entries_with_embeddings = Botcreds_Memory_DB::get_entries_with_embeddings( $filter_args );
		$results = Botcreds_Memory_Embeddings::search( $query, $entries_with_embeddings );

		// Apply access control.
		$results = Botcreds_Memory_Access_Control::filter_entries( $results );

		// Apply limit.
		$results = array_slice( $results, 0, $limit );

		return new WP_REST_Response( array(
			'entries' => $results,
			'total'   => count( $results ),
		), 200 );
	}

	/**
	 * GET /entries/by-key — Get a single entry by exact key.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_entry_by_key( WP_REST_Request $request ) {
		$key = $request->get_param( 'key' );

		if ( ! Botcreds_Memory_Access_Control::can_access_key( $key ) ) {
			return new WP_Error(
				'botcreds_memory_forbidden',
				'You do not have access to this key namespace.',
				array( 'status' => 403 )
			);
		}

		$entry = Botcreds_Memory_DB::get_by_key( $key );

		if ( ! $entry ) {
			return new WP_Error(
				'botcreds_memory_not_found',
				'Entry not found.',
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $entry, 200 );
	}

	/**
	 * POST /entries — Create or update a memory entry (upsert by key).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upsert_entry( WP_REST_Request $request ) {
		$key = sanitize_text_field( $request->get_param( 'key' ) );

		if ( empty( $key ) ) {
			return new WP_Error(
				'botcreds_memory_invalid_key',
				'Key is required.',
				array( 'status' => 400 )
			);
		}

		// Access control check.
		if ( ! Botcreds_Memory_Access_Control::can_access_key( $key ) ) {
			return new WP_Error(
				'botcreds_memory_forbidden',
				'You do not have access to this key namespace.',
				array( 'status' => 403 )
			);
		}

		$data = array(
			'key'   => $key,
			'value' => $request->get_param( 'value' ) ?? '',
		);

		if ( $request->get_param( 'tags' ) !== null ) {
			$data['tags'] = $request->get_param( 'tags' );
		}

		if ( $request->get_param( 'author' ) !== null ) {
			$data['author'] = $request->get_param( 'author' );
		}

		if ( $request->has_param( 'expires_at' ) ) {
			$data['expires_at'] = $request->get_param( 'expires_at' );
		}

		$entry = Botcreds_Memory_DB::upsert( $data );

		if ( ! $entry ) {
			return new WP_Error(
				'botcreds_memory_save_failed',
				'Failed to save entry.',
				array( 'status' => 500 )
			);
		}

		// Schedule embedding generation if vector mode is enabled.
		Botcreds_Memory_Embeddings::schedule_embed( $entry['id'] );

		return new WP_REST_Response( $entry, 200 );
	}

	/**
	 * DELETE /entries/by-key — Delete a memory entry by exact key.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_entry( WP_REST_Request $request ) {
		$key = $request->get_param( 'key' );

		if ( ! Botcreds_Memory_Access_Control::can_access_key( $key ) ) {
			return new WP_Error(
				'botcreds_memory_forbidden',
				'You do not have access to this key namespace.',
				array( 'status' => 403 )
			);
		}

		$deleted = Botcreds_Memory_DB::delete_by_key( $key );

		if ( ! $deleted ) {
			return new WP_Error(
				'botcreds_memory_not_found',
				'Entry not found.',
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array(
			'deleted' => true,
			'key'     => $key,
		), 200 );
	}

	/**
	 * GET /entries/revisions — Return revision history for a memory key.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_entry_revisions( WP_REST_Request $request ) {
		$key = $request->get_param( 'key' );

		if ( ! Botcreds_Memory_Access_Control::can_access_key( $key ) ) {
			return new WP_Error(
				'botcreds_memory_forbidden',
				'You do not have access to this key namespace.',
				array( 'status' => 403 )
			);
		}

		$entry = Botcreds_Memory_DB::get_by_key( $key, true );

		if ( ! $entry ) {
			return new WP_Error(
				'botcreds_memory_not_found',
				'Entry not found.',
				array( 'status' => 404 )
			);
		}

		$limit     = (int) $request->get_param( 'limit' );
		$revisions = Botcreds_Memory_DB::get_revisions( $entry['id'], $limit );

		return new WP_REST_Response( array(
			'key'       => $key,
			'revisions' => $revisions,
		), 200 );
	}

	/**
	 * GET /namespaces — Return all namespaces with entry counts.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_namespaces(): WP_REST_Response {
		$namespaces = Botcreds_Memory_DB::list_namespaces();
		return new WP_REST_Response( array( 'namespaces' => $namespaces ), 200 );
	}

	/**
	 * GET /tags — Return all tags with entry counts.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_tags(): WP_REST_Response {
		$tags = Botcreds_Memory_DB::list_tags();
		return new WP_REST_Response( array( 'tags' => $tags ), 200 );
	}

	/**
	 * GET /status — Return plugin status information.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_status(): WP_REST_Response {
		$total    = Botcreds_Memory_DB::count_entries();
		$embedded = Botcreds_Memory_DB::count_embedded();

		return new WP_REST_Response( array(
			'version'             => BOTCREDS_MEMORY_VERSION,
			'entries'             => $total,
			'vector_mode_enabled' => Botcreds_Memory_Embeddings::is_enabled(),
			'embeddings_coverage' => "{$embedded}/{$total}",
		), 200 );
	}
}
