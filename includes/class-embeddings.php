<?php
/**
 * OpenAI embeddings and cosine similarity for BotCreds Agent Memory.
 *
 * Only active when the `botcreds_memory_openai_key` option is set.
 *
 * @package BotCreds_Agent_Memory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles embedding generation via OpenAI and vector similarity search.
 */
class Botcreds_Memory_Embeddings {

	/**
	 * Default OpenAI embedding model.
	 */
	const MODEL = 'text-embedding-3-small';

	/**
	 * Embedding dimensions for the default model.
	 */
	const DIMS = 1536;

	/**
	 * Check if vector mode is enabled (OpenAI key is configured).
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$key = get_option( 'botcreds_memory_openai_key', '' );
		return ! empty( $key );
	}

	/**
	 * Generate an embedding for the given text via the OpenAI API.
	 *
	 * @param string $text The text to embed.
	 * @return array|null Float array of the embedding, or null on error.
	 */
	public static function embed( string $text ): ?array {
		$api_key = get_option( 'botcreds_memory_openai_key', '' );

		if ( empty( $api_key ) ) {
			return null;
		}

		$model = get_option( 'botcreds_memory_embedding_model', self::MODEL );

		$response = wp_remote_post(
			'https://api.openai.com/v1/embeddings',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'model' => $model,
					'input' => $text,
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'BotCreds Memory: OpenAI embedding request failed: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $body['data'][0]['embedding'] ) ) {
			$error_msg = $body['error']['message'] ?? 'Unknown error';
			error_log( "BotCreds Memory: OpenAI embedding failed (HTTP {$code}): {$error_msg}" );
			return null;
		}

		return $body['data'][0]['embedding'];
	}

	/**
	 * Calculate cosine similarity between two vectors.
	 *
	 * @param array $a First vector (float array).
	 * @param array $b Second vector (float array).
	 * @return float Similarity score between -1 and 1.
	 */
	public static function cosine_similarity( array $a, array $b ): float {
		$dot    = 0.0;
		$norm_a = 0.0;
		$norm_b = 0.0;
		$count  = min( count( $a ), count( $b ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$dot    += $a[ $i ] * $b[ $i ];
			$norm_a += $a[ $i ] * $a[ $i ];
			$norm_b += $b[ $i ] * $b[ $i ];
		}

		$denom = sqrt( $norm_a ) * sqrt( $norm_b );

		if ( $denom == 0 ) {
			return 0.0;
		}

		return $dot / $denom;
	}

	/**
	 * Perform semantic search across entries with embeddings.
	 *
	 * Embeds the query, computes cosine similarity against all provided
	 * entries, and returns them sorted by similarity (descending).
	 *
	 * @param string $query   The search query.
	 * @param array  $entries Entries with 'embedding' field (parsed float array).
	 * @return array Entries sorted by similarity with 'similarity' field added.
	 */
	public static function search( string $query, array $entries ): array {
		$query_embedding = self::embed( $query );

		if ( ! $query_embedding ) {
			return array();
		}

		$scored = array();

		foreach ( $entries as $entry ) {
			if ( empty( $entry['embedding'] ) || ! is_array( $entry['embedding'] ) ) {
				continue;
			}

			$similarity = self::cosine_similarity( $query_embedding, $entry['embedding'] );

			// Remove the raw embedding from the output.
			unset( $entry['embedding'] );
			$entry['similarity'] = round( $similarity, 4 );
			$scored[] = $entry;
		}

		// Sort by similarity descending.
		usort( $scored, function ( $a, $b ) {
			return $b['similarity'] <=> $a['similarity'];
		} );

		return $scored;
	}

	/**
	 * Schedule an embedding generation for an entry.
	 * Debounces by canceling any existing pending event for the same entry.
	 *
	 * @param int $entry_id The entry ID to embed.
	 */
	public static function schedule_embed( int $entry_id ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		// Debounce: cancel any existing pending event for this entry.
		$args      = array( $entry_id );
		$timestamp = wp_next_scheduled( 'botcreds_memory_embed_entry', $args );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'botcreds_memory_embed_entry', $args );
		}

		// Schedule for immediate execution (next cron run).
		wp_schedule_single_event( time(), 'botcreds_memory_embed_entry', $args );
	}

	/**
	 * WP-Cron callback: generate and store embedding for a single entry.
	 *
	 * @param int $entry_id The entry ID.
	 */
	public static function cron_embed_entry( int $entry_id ): void {
		$entry = Botcreds_Memory_DB::get_by_id( $entry_id );

		if ( ! $entry ) {
			error_log( "BotCreds Memory: Cannot embed entry #{$entry_id} — not found." );
			return;
		}

		// Text to embed: key + value concatenated.
		$text      = $entry['key'] . ' ' . $entry['value'];
		$embedding = self::embed( $text );

		if ( ! $embedding ) {
			error_log( "BotCreds Memory: Embedding failed for entry #{$entry_id}." );
			return;
		}

		Botcreds_Memory_DB::update_embedding( $entry['id'], $embedding );
	}

	/**
	 * WP-Cron callback: backfill embeddings for entries that don't have them.
	 * Processes 50 entries per run, reschedules itself if more remain.
	 */
	public static function cron_backfill(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$cursor  = (int) get_option( 'botcreds_memory_backfill_cursor', 0 );
		$entries = Botcreds_Memory_DB::get_entries_needing_embeddings( 50, $cursor );

		if ( empty( $entries ) ) {
			// All done — reset cursor.
			delete_option( 'botcreds_memory_backfill_cursor' );
			return;
		}

		$last_id = 0;

		foreach ( $entries as $entry ) {
			$text      = $entry['key'] . ' ' . $entry['value'];
			$embedding = self::embed( $text );

			if ( $embedding ) {
				Botcreds_Memory_DB::update_embedding( $entry['id'], $embedding );
			}

			$last_id = $entry['id'];
		}

		// Update cursor.
		update_option( 'botcreds_memory_backfill_cursor', $last_id );

		// Reschedule with 5-second delay if more entries may remain.
		wp_schedule_single_event( time() + 5, 'botcreds_memory_backfill_embeddings' );
	}

	/**
	 * Start the backfill process via WP-Cron.
	 */
	public static function start_backfill(): void {
		// Reset cursor.
		update_option( 'botcreds_memory_backfill_cursor', 0 );

		// Cancel any existing backfill event.
		$timestamp = wp_next_scheduled( 'botcreds_memory_backfill_embeddings' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'botcreds_memory_backfill_embeddings' );
		}

		// Schedule immediate execution.
		wp_schedule_single_event( time(), 'botcreds_memory_backfill_embeddings' );
	}
}
