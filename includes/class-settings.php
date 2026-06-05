<?php
/**
 * Admin settings page for BotCreds Agent Memory.
 *
 * Provides:
 * - Entries browser (WP_List_Table)
 * - Settings (OpenAI key, model, backfill)
 * - Access control overview
 *
 * @package BotCreds_Agent_Memory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menu registration, settings fields, and page rendering.
 */
class Botcreds_Memory_Settings {

	/**
	 * Register admin menus.
	 */
	public static function add_menus(): void {
		// Top-level menu.
		add_menu_page(
			__( 'Agent Memory', 'botcreds-agent-memory' ),
			__( 'Agent Memory', 'botcreds-agent-memory' ),
			'manage_options',
			'botcreds-memory-entries',
			array( __CLASS__, 'render_entries_page' ),
			'dashicons-database',
			80
		);

		// Entries submenu (same as top-level).
		add_submenu_page(
			'botcreds-memory-entries',
			__( 'Entries', 'botcreds-agent-memory' ),
			__( 'Entries', 'botcreds-agent-memory' ),
			'manage_options',
			'botcreds-memory-entries',
			array( __CLASS__, 'render_entries_page' )
		);

		// Settings submenu.
		add_submenu_page(
			'botcreds-memory-entries',
			__( 'Settings', 'botcreds-agent-memory' ),
			__( 'Settings', 'botcreds-agent-memory' ),
			'manage_options',
			'botcreds-memory-settings',
			array( __CLASS__, 'render_settings_page' )
		);

		// Access Control submenu.
		add_submenu_page(
			'botcreds-memory-entries',
			__( 'Access Control', 'botcreds-agent-memory' ),
			__( 'Access Control', 'botcreds-agent-memory' ),
			'manage_options',
			'botcreds-memory-access',
			array( __CLASS__, 'render_access_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public static function register_settings(): void {
		// These two calls are safe on any hook (rest_api_init, admin_init).
		register_setting( 'botcreds_memory_settings', 'botcreds_memory_openai_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
			'show_in_rest'      => true,
		) );

		register_setting( 'botcreds_memory_settings', 'botcreds_memory_embedding_model', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => Botcreds_Memory_Embeddings::MODEL,
			'show_in_rest'      => true,
		) );

		// Admin-only Settings API functions — skip on REST requests.
		if ( ! is_admin() ) {
			return;
		}

		add_settings_section(
			'botcreds_memory_vector_section',
			__( 'Vector Mode (Semantic Search)', 'botcreds-agent-memory' ),
			function () {
				echo '<p>' . esc_html__( 'Configure an OpenAI API key to enable semantic vector search. Without a key, the plugin operates in KV (key-value) mode with text-based search.', 'botcreds-agent-memory' ) . '</p>';
			},
			'botcreds-memory-settings'
		);

		add_settings_field(
			'botcreds_memory_openai_key',
			__( 'OpenAI API Key', 'botcreds-agent-memory' ),
			array( __CLASS__, 'render_openai_key_field' ),
			'botcreds-memory-settings',
			'botcreds_memory_vector_section'
		);

		add_settings_field(
			'botcreds_memory_embedding_model',
			__( 'Embedding Model', 'botcreds-agent-memory' ),
			array( __CLASS__, 'render_model_field' ),
			'botcreds-memory-settings',
			'botcreds_memory_vector_section'
		);

		// Handle backfill trigger.
		if ( isset( $_POST['botcreds_memory_backfill'] ) && check_admin_referer( 'botcreds_memory_backfill_action' ) ) {
			Botcreds_Memory_Embeddings::start_backfill();
			add_settings_error(
				'botcreds_memory_settings',
				'backfill_started',
				__( 'Embedding backfill has been started. Entries will be processed in the background via WP-Cron.', 'botcreds-agent-memory' ),
				'success'
			);
		}
	}

	/**
	 * Render the OpenAI API key field.
	 */
	public static function render_openai_key_field(): void {
		$value = get_option( 'botcreds_memory_openai_key', '' );
		$masked = ! empty( $value ) ? str_repeat( '•', 20 ) . substr( $value, -4 ) : '';
		?>
		<input
			type="password"
			name="botcreds_memory_openai_key"
			id="botcreds_memory_openai_key"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="sk-..."
			autocomplete="off"
		/>
		<?php if ( ! empty( $value ) ) : ?>
			<p class="description">
				<?php echo esc_html( sprintf(
					/* translators: %s: masked API key */
					__( 'Current key: %s', 'botcreds-agent-memory' ),
					$masked
				) ); ?>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'Enter your OpenAI API key to enable vector mode (semantic search via embeddings).', 'botcreds-agent-memory' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the embedding model dropdown.
	 */
	public static function render_model_field(): void {
		$value = get_option( 'botcreds_memory_embedding_model', Botcreds_Memory_Embeddings::MODEL );
		$models = array(
			'text-embedding-3-small' => 'text-embedding-3-small (1536 dims, recommended)',
			'text-embedding-3-large' => 'text-embedding-3-large (3072 dims)',
			'text-embedding-ada-002' => 'text-embedding-ada-002 (1536 dims, legacy)',
		);
		?>
		<select name="botcreds_memory_embedding_model" id="botcreds_memory_embedding_model">
			<?php foreach ( $models as $model_id => $label ) : ?>
				<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $value, $model_id ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render the Entries admin page.
	 */
	public static function render_entries_page(): void {
		$entries_data = Botcreds_Memory_DB::list_entries( array(
			'limit'           => 50,
			'offset'          => isset( $_GET['paged'] ) ? ( max( 1, (int) $_GET['paged'] ) - 1 ) * 50 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'include_expired' => true,
		) );
		?>
		<div class="wrap botcreds-memory-wrap">
			<h1><?php esc_html_e( 'Agent Memory Entries', 'botcreds-agent-memory' ); ?></h1>

			<p class="description">
				<?php echo esc_html( sprintf(
					/* translators: %d: total entry count */
					__( '%d total entries', 'botcreds-agent-memory' ),
					$entries_data['total']
				) ); ?>
				<?php if ( Botcreds_Memory_Embeddings::is_enabled() ) : ?>
					&bull;
					<?php echo esc_html( sprintf(
						/* translators: 1: number of entries with embeddings, 2: total entries */
						__( '%1$d/%2$d with embeddings', 'botcreds-agent-memory' ),
						Botcreds_Memory_DB::count_embedded(),
						$entries_data['total']
					) ); ?>
				<?php endif; ?>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th class="column-id" style="width: 50px;"><?php esc_html_e( 'ID', 'botcreds-agent-memory' ); ?></th>
						<th class="column-key"><?php esc_html_e( 'Key', 'botcreds-agent-memory' ); ?></th>
						<th class="column-value"><?php esc_html_e( 'Value', 'botcreds-agent-memory' ); ?></th>
						<th class="column-tags" style="width: 150px;"><?php esc_html_e( 'Tags', 'botcreds-agent-memory' ); ?></th>
						<th class="column-author" style="width: 100px;"><?php esc_html_e( 'Author', 'botcreds-agent-memory' ); ?></th>
						<th class="column-updated" style="width: 160px;"><?php esc_html_e( 'Updated', 'botcreds-agent-memory' ); ?></th>
						<th class="column-embedding" style="width: 70px;"><?php esc_html_e( 'Vector', 'botcreds-agent-memory' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $entries_data['entries'] ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No entries found.', 'botcreds-agent-memory' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $entries_data['entries'] as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['id'] ); ?></td>
								<td><code><?php echo esc_html( $entry['key'] ); ?></code></td>
								<td class="botcreds-value-cell"><?php echo esc_html( wp_trim_words( $entry['value'], 20, '…' ) ); ?></td>
								<td>
									<?php foreach ( $entry['tags'] as $tag ) : ?>
										<span class="botcreds-tag"><?php echo esc_html( $tag ); ?></span>
									<?php endforeach; ?>
								</td>
								<td><?php echo esc_html( $entry['author'] ); ?></td>
								<td><?php echo esc_html( $entry['updated_at'] ); ?></td>
								<td>
									<?php
									// Check if entry has embedding by looking at DB directly.
									global $wpdb;
									$emb_table = $wpdb->prefix . 'botcreds_memory';
									// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
									$emb_sql = $wpdb->prepare( "SELECT embedding IS NOT NULL FROM `{$emb_table}` WHERE id = %d", $entry['id'] );
									// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
									$has_embedding = $wpdb->get_var( $emb_sql );
									echo $has_embedding ? '✓' : '—';
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php
			// Simple pagination.
			$total_pages = ceil( $entries_data['total'] / 50 );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
			if ( $total_pages > 1 ) :
				?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
							<?php if ( $i === $current ) : ?>
								<span class="tablenav-pages-navspan button disabled"><?php echo esc_html( $i ); ?></span>
							<?php else : ?>
								<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>"><?php echo esc_html( $i ); ?></a>
							<?php endif; ?>
						<?php endfor; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Settings admin page.
	 */
	public static function render_settings_page(): void {
		$total    = Botcreds_Memory_DB::count_entries();
		$embedded = Botcreds_Memory_DB::count_embedded();
		$pending  = $total - $embedded;
		?>
		<div class="wrap botcreds-memory-wrap">
			<h1><?php esc_html_e( 'Agent Memory Settings', 'botcreds-agent-memory' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'botcreds_memory_settings' );
				do_settings_sections( 'botcreds-memory-settings' );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Embedding Backfill', 'botcreds-agent-memory' ); ?></h2>

			<?php if ( ! Botcreds_Memory_Embeddings::is_enabled() ) : ?>
				<p class="description">
					<?php esc_html_e( 'Configure an OpenAI API key above to enable embedding backfill.', 'botcreds-agent-memory' ); ?>
				</p>
			<?php else : ?>
				<p>
					<?php echo esc_html( sprintf(
						/* translators: %d: embedded count, %d: total count, %d: pending count */
						__( 'Embeddings: %1$d/%2$d entries have embeddings. %3$d pending.', 'botcreds-agent-memory' ),
						$embedded,
						$total,
						$pending
					) ); ?>
				</p>

				<?php if ( $pending > 0 ) : ?>
					<form method="post">
						<?php wp_nonce_field( 'botcreds_memory_backfill_action' ); ?>
						<p>
							<button type="submit" name="botcreds_memory_backfill" class="button button-secondary">
								<?php echo esc_html( sprintf(
									/* translators: %d: number of entries to backfill */
									__( 'Backfill %d Entries', 'botcreds-agent-memory' ),
									$pending
								) ); ?>
							</button>
						</p>
						<p class="description">
							<?php esc_html_e( 'Processes 50 entries per cron run with 5-second intervals. Safe to run anytime.', 'botcreds-agent-memory' ); ?>
						</p>
					</form>
				<?php else : ?>
					<p class="description">
						<?php esc_html_e( 'All entries have embeddings. Nothing to backfill.', 'botcreds-agent-memory' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Plugin Info', 'botcreds-agent-memory' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Version', 'botcreds-agent-memory' ); ?></th>
					<td><?php echo esc_html( BOTCREDS_MEMORY_VERSION ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Total Entries', 'botcreds-agent-memory' ); ?></th>
					<td><?php echo esc_html( $total ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Vector Mode', 'botcreds-agent-memory' ); ?></th>
					<td><?php echo Botcreds_Memory_Embeddings::is_enabled() ? esc_html__( 'Enabled', 'botcreds-agent-memory' ) : esc_html__( 'Disabled', 'botcreds-agent-memory' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'REST API Base', 'botcreds-agent-memory' ); ?></th>
					<td><code>/wp-json/botcreds-memory/v1/</code></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the Access Control admin page.
	 */
	public static function render_access_page(): void {
		$restricted_users = Botcreds_Memory_Access_Control::get_all_restricted_users();
		$all_users        = get_users( array( 'fields' => array( 'ID', 'user_login', 'user_email' ) ) );
		?>
		<div class="wrap botcreds-memory-wrap">
			<h1><?php esc_html_e( 'Agent Memory Access Control', 'botcreds-agent-memory' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Manage which key namespaces each user can access. Admins always have full access. Users without configured prefixes also have full access (backward compatible).', 'botcreds-agent-memory' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 150px;"><?php esc_html_e( 'User', 'botcreds-agent-memory' ); ?></th>
						<th style="width: 200px;"><?php esc_html_e( 'Email', 'botcreds-agent-memory' ); ?></th>
						<th><?php esc_html_e( 'Role', 'botcreds-agent-memory' ); ?></th>
						<th><?php esc_html_e( 'Allowed Prefixes', 'botcreds-agent-memory' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Action', 'botcreds-agent-memory' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $all_users as $user ) : ?>
						<?php
						$user_obj  = get_userdata( $user->ID );
						$prefixes  = Botcreds_Memory_Access_Control::get_allowed_prefixes( $user->ID );
						$is_admin  = user_can( $user->ID, 'manage_options' );
						$edit_url  = get_edit_user_link( $user->ID ) . '#botcreds_memory_prefixes';
						?>
						<tr>
							<td><strong><?php echo esc_html( $user->user_login ); ?></strong></td>
							<td><?php echo esc_html( $user->user_email ); ?></td>
							<td>
								<?php
								if ( $user_obj ) {
									echo esc_html( implode( ', ', $user_obj->roles ) );
								}
								?>
							</td>
							<td>
								<?php if ( $is_admin ) : ?>
									<em><?php esc_html_e( 'Full access (admin)', 'botcreds-agent-memory' ); ?></em>
								<?php elseif ( empty( $prefixes ) ) : ?>
									<em><?php esc_html_e( 'Full access (no restrictions)', 'botcreds-agent-memory' ); ?></em>
								<?php else : ?>
									<?php foreach ( $prefixes as $prefix ) : ?>
										<code><?php echo esc_html( $prefix ); ?></code><br />
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'botcreds-agent-memory' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
