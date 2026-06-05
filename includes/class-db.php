<?php
/**
 * Database schema and CRUD operations for BotCreds Agent Memory.
 *
 * @package BotCreds_Agent_Memory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles database table creation and all CRUD operations.
 */
class Botcreds_Memory_DB {

	/**
	 * Get the full table name including the WP prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'botcreds_memory';
	}

	/**
	 * Create or upgrade the custom table.
	 * Called on plugin activation.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			memory_key  VARCHAR(255)    NOT NULL,
			value       LONGTEXT        NOT NULL,
			tags        TEXT            NULL,
			author      VARCHAR(100)    NULL DEFAULT '',
			expires_at  DATETIME        NULL DEFAULT NULL,
			embedding   LONGTEXT        NULL DEFAULT NULL COMMENT 'JSON float array from OpenAI',
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY memory_key (memory_key),
			KEY author (author),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'botcreds_memory_db_version', BOTCREDS_MEMORY_VERSION );
	}

	/**
	 * Get a single entry by exact key.
	 *
	 * @param string $key             The memory key.
	 * @param bool   $include_expired Whether to include expired entries.
	 * @return array|null Row as associative array or null.
	 */
	public static function get_by_key( string $key, bool $include_expired = false ): ?array {
		global $wpdb;
		$table = self::table_name();

		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE memory_key = %s", $key );

		if ( ! $include_expired ) {
			$sql .= ' AND (expires_at IS NULL OR expires_at > NOW())';
		}

		$row = $wpdb->get_row( $sql, ARRAY_A );
		return $row ? self::format_row( $row ) : null;
	}

	/**
	 * Get a single entry by ID.
	 *
	 * @param int $id The entry ID.
	 * @return array|null Row as associative array or null.
	 */
	public static function get_by_id( int $id ): ?array {
		global $wpdb;
		$table = self::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ? self::format_row( $row ) : null;
	}

	/**
	 * List entries with optional filtering.
	 *
	 * @param array $args {
	 *     Optional arguments.
	 *     @type string $key_prefix      Key prefix filter.
	 *     @type array  $tags            Tags to filter by.
	 *     @type string $search          Text search term (LIKE on value).
	 *     @type int    $limit           Max results (default 20, max 100).
	 *     @type int    $offset          Pagination offset.
	 *     @type bool   $include_expired Include expired entries.
	 * }
	 * @return array { entries: array[], total: int }
	 */
	public static function list_entries( array $args = array() ): array {
		global $wpdb;
		$table = self::table_name();

		$defaults = array(
			'key_prefix'      => '',
			'tags'            => array(),
			'search'          => '',
			'limit'           => 20,
			'offset'          => 0,
			'include_expired' => false,
		);
		$args = wp_parse_args( $args, $defaults );

		// Clamp limit.
		$args['limit'] = max( 1, min( 100, (int) $args['limit'] ) );
		$args['offset'] = max( 0, (int) $args['offset'] );

		$where  = array();
		$values = array();

		// Expiry filter.
		if ( ! $args['include_expired'] ) {
			$where[] = '(expires_at IS NULL OR expires_at > NOW())';
		}

		// Key prefix filter.
		if ( ! empty( $args['key_prefix'] ) ) {
			$where[] = 'memory_key LIKE %s';
			$values[] = $wpdb->esc_like( $args['key_prefix'] ) . '%';
		}

		// Tag filter — match all provided tags.
		if ( ! empty( $args['tags'] ) ) {
			foreach ( (array) $args['tags'] as $tag ) {
				$tag = sanitize_text_field( $tag );
				// Match comma-separated tags: tag at start, end, middle, or alone.
				$where[] = "(tags LIKE %s OR tags LIKE %s OR tags LIKE %s OR tags = %s)";
				$escaped  = $wpdb->esc_like( $tag );
				$values[] = $escaped . ',%';
				$values[] = '%,' . $escaped . ',%';
				$values[] = '%,' . $escaped;
				$values[] = $tag;
			}
		}

		// Text search (LIKE on value and key).
		if ( ! empty( $args['search'] ) ) {
			$where[] = '(value LIKE %s OR memory_key LIKE %s)';
			$escaped  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values[] = $escaped;
			$values[] = $escaped;
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		// Count total.
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		if ( ! empty( $values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, ...$values );
		}
		$total = (int) $wpdb->get_var( $count_sql );

		// Fetch rows.
		$query = "SELECT * FROM {$table} {$where_sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d";
		$query_values = array_merge( $values, array( $args['limit'], $args['offset'] ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( $query, ...$query_values ),
			ARRAY_A
		);

		$entries = array_map( array( __CLASS__, 'format_row' ), $rows ?: array() );

		return array(
			'entries' => $entries,
			'total'   => $total,
		);
	}

	/**
	 * Upsert an entry (insert or update by key).
	 *
	 * @param array $data {
	 *     @type string      $key        Required. The memory key.
	 *     @type string      $value      Required. The value to store.
	 *     @type array       $tags       Optional. Array of tag strings.
	 *     @type string      $author     Optional. Author identifier.
	 *     @type string|null $expires_at Optional. Expiry datetime (Y-m-d H:i:s).
	 * }
	 * @return array|null The upserted row or null on failure.
	 */
	public static function upsert( array $data ): ?array {
		global $wpdb;
		$table = self::table_name();

		$key   = sanitize_text_field( $data['key'] ?? '' );
		$value = $data['value'] ?? '';

		if ( empty( $key ) || $value === '' ) {
			return null;
		}

		$tags       = isset( $data['tags'] ) ? implode( ',', array_map( 'sanitize_text_field', (array) $data['tags'] ) ) : '';
		$author     = sanitize_text_field( $data['author'] ?? '' );
		$expires_at = ! empty( $data['expires_at'] ) ? sanitize_text_field( $data['expires_at'] ) : null;

		$existing = self::get_by_key( $key, true );

		if ( $existing ) {
			// Update existing entry.
			$update_data = array(
				'value'  => $value,
				'tags'   => $tags,
				'author' => $author,
			);
			$formats = array( '%s', '%s', '%s' );

			if ( array_key_exists( 'expires_at', $data ) ) {
				$update_data['expires_at'] = $expires_at;
				$formats[] = $expires_at ? '%s' : null;
				// Handle null expires_at explicitly.
				if ( $expires_at === null ) {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table} SET value = %s, tags = %s, author = %s, expires_at = NULL, embedding = NULL WHERE id = %d",
							$value,
							$tags,
							$author,
							$existing['id']
						)
					);
					return self::get_by_id( $existing['id'] );
				}
			}

			// Clear embedding on value change so it gets re-embedded.
			$update_data['embedding'] = null;

			$wpdb->update(
				$table,
				$update_data,
				array( 'id' => $existing['id'] ),
				array( '%s', '%s', '%s', null ), // null format for NULL value.
				array( '%d' )
			);

			return self::get_by_id( $existing['id'] );
		}

		// Insert new entry.
		$insert_data = array(
			'memory_key' => $key,
			'value'      => $value,
			'tags'       => $tags,
			'author'     => $author,
		);
		$formats = array( '%s', '%s', '%s', '%s' );

		if ( $expires_at ) {
			$insert_data['expires_at'] = $expires_at;
			$formats[] = '%s';
		}

		$wpdb->insert( $table, $insert_data, $formats );

		if ( ! $wpdb->insert_id ) {
			return null;
		}

		return self::get_by_id( $wpdb->insert_id );
	}

	/**
	 * Delete an entry by exact key.
	 *
	 * @param string $key The memory key.
	 * @return bool True if a row was deleted.
	 */
	public static function delete_by_key( string $key ): bool {
		global $wpdb;
		$table = self::table_name();

		$deleted = $wpdb->delete( $table, array( 'memory_key' => $key ), array( '%s' ) );
		return $deleted > 0;
	}

	/**
	 * Update the embedding for an entry.
	 *
	 * @param int   $id        The entry ID.
	 * @param array $embedding The float array embedding.
	 * @return bool
	 */
	public static function update_embedding( int $id, array $embedding ): bool {
		global $wpdb;
		$table = self::table_name();

		$result = $wpdb->update(
			$table,
			array( 'embedding' => wp_json_encode( $embedding ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Get entries that need embeddings (embedding IS NULL).
	 *
	 * @param int $limit  Max rows to return.
	 * @param int $cursor Start after this ID.
	 * @return array
	 */
	public static function get_entries_needing_embeddings( int $limit = 50, int $cursor = 0 ): array {
		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE embedding IS NULL AND id > %d ORDER BY id ASC LIMIT %d",
				$cursor,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'format_row' ), $rows ?: array() );
	}

	/**
	 * Get all entries with embeddings for similarity search.
	 *
	 * @param array $args Optional filters (key_prefix, include_expired).
	 * @return array
	 */
	public static function get_entries_with_embeddings( array $args = array() ): array {
		global $wpdb;
		$table = self::table_name();

		$where  = array( 'embedding IS NOT NULL' );
		$values = array();

		if ( empty( $args['include_expired'] ) ) {
			$where[] = '(expires_at IS NULL OR expires_at > NOW())';
		}

		if ( ! empty( $args['key_prefix'] ) ) {
			$where[] = 'memory_key LIKE %s';
			$values[] = $wpdb->esc_like( $args['key_prefix'] ) . '%';
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$query     = "SELECT * FROM {$table} {$where_sql} ORDER BY updated_at DESC";

		if ( ! empty( $values ) ) {
			$query = $wpdb->prepare( $query, ...$values );
		}

		$rows = $wpdb->get_results( $query, ARRAY_A );
		return array_map( array( __CLASS__, 'format_row_with_embedding' ), $rows ?: array() );
	}

	/**
	 * Count all entries.
	 *
	 * @return int
	 */
	public static function count_entries(): int {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Count entries that have embeddings.
	 *
	 * @return int
	 */
	public static function count_embedded(): int {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE embedding IS NOT NULL" );
	}

	/**
	 * Format a database row for API output (no embedding blob).
	 *
	 * @param array $row Raw database row.
	 * @return array Formatted row.
	 */
	public static function format_row( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'key'        => $row['memory_key'],
			'value'      => $row['value'],
			'tags'       => ! empty( $row['tags'] ) ? explode( ',', $row['tags'] ) : array(),
			'author'     => $row['author'] ?? '',
			'expires_at' => $row['expires_at'],
			'created_at' => $row['created_at'],
			'updated_at' => $row['updated_at'],
		);
	}

	/**
	 * Format a row including the parsed embedding vector.
	 *
	 * @param array $row Raw database row.
	 * @return array Formatted row with 'embedding' as float array.
	 */
	private static function format_row_with_embedding( array $row ): array {
		$formatted = self::format_row( $row );
		$formatted['embedding'] = ! empty( $row['embedding'] ) ? json_decode( $row['embedding'], true ) : null;
		return $formatted;
	}
}
