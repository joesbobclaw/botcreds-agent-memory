<?php
/**
 * Export formatter for BotCreds Agent Memory.
 *
 * Generates Markdown or JSON exports of all memory entries.
 *
 * @package BotCreds_Agent_Memory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export formatter. Renamed from BCAM_Export to match plugin prefix conventions.
 */
class Botcreds_Memory_Export {

	/**
	 * Generate a Markdown-formatted export of all entries.
	 *
	 * @param array $entries Array of formatted entry arrays.
	 * @return string Full Markdown document.
	 */
	public static function to_markdown( $entries ) {
		$generated = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$lines     = array();

		$lines[] = '# Agent Memory Export';
		$lines[] = sprintf( '*Generated: %s*', $generated );
		$lines[] = '';

		if ( empty( $entries ) ) {
			$lines[] = '*No entries found.*';
			return implode( "\n", $lines );
		}

		$groups = array();
		foreach ( $entries as $entry ) {
			$prefix = self::get_prefix( $entry['key'] ?? '' );
			if ( ! isset( $groups[ $prefix ] ) ) {
				$groups[ $prefix ] = array();
			}
			$groups[ $prefix ][] = $entry;
		}

		ksort( $groups );
		if ( isset( $groups['uncategorized'] ) ) {
			$uncategorized = $groups['uncategorized'];
			unset( $groups['uncategorized'] );
			$groups['uncategorized'] = $uncategorized;
		}

		foreach ( $groups as $group_name => $group_entries ) {
			$lines[] = '## ' . $group_name;
			$lines[] = '';
			foreach ( $group_entries as $entry ) {
				$lines[] = '### ' . ( $entry['key'] ?? '' );
				$updated = substr( $entry['updated_at'] ?? '', 0, 10 );
				$meta    = sprintf( '**Author:** %s | **Updated:** %s', $entry['author'] ?: '(unknown)', $updated );
				if ( ! empty( $entry['tags'] ) ) {
					$meta .= ' | **Tags:** ' . implode( ', ', $entry['tags'] );
				}
				if ( ! empty( $entry['expires_at'] ) ) {
					$meta .= ' | **Expires:** ' . $entry['expires_at'];
				}
				$lines[] = $meta;
				$lines[] = '';
				$lines[] = $entry['value'] ?? '';
				$lines[] = '';
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Generate a JSON export of all entries.
	 *
	 * @param array $entries Array of formatted entry arrays.
	 * @return string JSON string.
	 */
	public static function to_json( $entries ) {
		$payload = array(
			'generated' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
			'count'     => count( $entries ),
			'entries'   => array_values( $entries ),
		);
		return wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Extract the top-level prefix from a key.
	 *
	 * @param string $key Memory key.
	 * @return string Prefix / group name.
	 */
	private static function get_prefix( $key ) {
		if ( preg_match( '/^([^\/\\.]+)[\/\\.]/', $key, $matches ) ) {
			return $matches[1];
		}
		return 'uncategorized';
	}
}

// Backwards compatibility alias.
class_alias( 'Botcreds_Memory_Export', 'BCAM_Export' );
