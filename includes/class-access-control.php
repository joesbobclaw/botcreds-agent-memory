<?php
/**
 * Namespace-based access control for BotCreds Agent Memory.
 *
 * Admins get full access. Other WP users can be restricted to
 * specific key prefixes stored in user meta.
 *
 * @package BotCreds_Agent_Memory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles per-user namespace restrictions for memory keys.
 */
class Botcreds_Memory_Access_Control {

	/**
	 * User meta key for storing allowed prefixes.
	 */
	const META_KEY = 'botcreds_memory_allowed_prefixes';

	/**
	 * Check if the current user can access a given key.
	 *
	 * @param string $key     The memory key to check.
	 * @param int    $user_id Optional. User ID. Defaults to current user.
	 * @return bool True if access is allowed.
	 */
	public static function can_access_key( string $key, int $user_id = 0 ): bool {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		// Admins bypass all checks.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$prefixes = self::get_allowed_prefixes( $user_id );

		// Blank prefixes = full access (backward compat).
		if ( empty( $prefixes ) ) {
			return true;
		}

		// Check if the key starts with any allowed prefix.
		foreach ( $prefixes as $prefix ) {
			if ( strpos( $key, $prefix ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Filter an array of entries to only those the user can access.
	 *
	 * @param array $entries Array of entry arrays (must have 'key' field).
	 * @param int   $user_id Optional. User ID. Defaults to current user.
	 * @return array Filtered entries.
	 */
	public static function filter_entries( array $entries, int $user_id = 0 ): array {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		// Admins see everything.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return $entries;
		}

		$prefixes = self::get_allowed_prefixes( $user_id );

		// Blank prefixes = full access.
		if ( empty( $prefixes ) ) {
			return $entries;
		}

		return array_values(
			array_filter(
				$entries,
				function ( $entry ) use ( $prefixes ) {
					$key = $entry['key'] ?? '';
					foreach ( $prefixes as $prefix ) {
						if ( strpos( $key, $prefix ) === 0 ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * Get the allowed prefixes for a user.
	 *
	 * @param int $user_id The user ID.
	 * @return array Array of prefix strings.
	 */
	public static function get_allowed_prefixes( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_KEY, true );

		if ( empty( $raw ) || ! is_string( $raw ) ) {
			return array();
		}

		$lines = array_map( 'trim', explode( "\n", $raw ) );
		return array_values( array_filter( $lines, 'strlen' ) );
	}

	/**
	 * Render the access control fields on the user profile page.
	 *
	 * @param WP_User $user The user object being edited.
	 */
	public static function render_user_fields( WP_User $user ): void {
		// Only admins can manage access control.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$prefixes = get_user_meta( $user->ID, self::META_KEY, true );
		?>
		<h3><?php esc_html_e( 'Agent Memory Access', 'botcreds-agent-memory' ); ?></h3>
		<?php wp_nonce_field( 'botcreds_memory_user_profile', 'botcreds_memory_user_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th>
					<label for="botcreds_memory_prefixes">
						<?php esc_html_e( 'Allowed Key Prefixes', 'botcreds-agent-memory' ); ?>
					</label>
				</th>
				<td>
					<textarea
						name="botcreds_memory_prefixes"
						id="botcreds_memory_prefixes"
						rows="5"
						cols="50"
						class="large-text"
					><?php echo esc_textarea( $prefixes ); ?></textarea>
					<p class="description">
						<?php esc_html_e(
							'One prefix per line. This user can only read/write memory keys starting with these prefixes. Leave blank for full access.',
							'botcreds-agent-memory'
						); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the access control fields from the user profile page.
	 *
	 * @param int $user_id The user ID being saved.
	 */
	public static function save_user_fields( int $user_id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_POST['botcreds_memory_user_nonce'] ) ||
		     ! wp_verify_nonce( sanitize_key( $_POST['botcreds_memory_user_nonce'] ), 'botcreds_memory_user_profile' ) ) {
			return;
		}

		if ( ! isset( $_POST['botcreds_memory_prefixes'] ) ) {
			return;
		}

		$prefixes = sanitize_textarea_field( wp_unslash( $_POST['botcreds_memory_prefixes'] ) );
		update_user_meta( $user_id, self::META_KEY, $prefixes );
	}

	/**
	 * Get all users with configured access restrictions.
	 *
	 * @return array Array of user data with prefixes.
	 */
	public static function get_all_restricted_users(): array {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$users = get_users( array(
			'meta_key'     => self::META_KEY,
			'meta_compare' => '!=',
			'meta_value'   => '',
		) );
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		$result = array();
		foreach ( $users as $user ) {
			$result[] = array(
				'id'       => $user->ID,
				'login'    => $user->user_login,
				'email'    => $user->user_email,
				'prefixes' => self::get_allowed_prefixes( $user->ID ),
			);
		}

		return $result;
	}
}
