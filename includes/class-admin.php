<?php
/**
 * WP-Admin UI for BotCreds Agent Memory.
 *
 * Provides a list view with tag/author/search filters, inline value expansion,
 * and per-row delete (nonce-protected). No external dependencies.
 *
 * @package BotCreds_Agent_Memory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for BotCreds Agent Memory (legacy compatibility class).
 * Renamed from BCAM_Admin to match plugin prefix conventions.
 *
 * @deprecated Use Botcreds_Memory_Settings instead for new features.
 */
class Botcreds_Memory_Admin {

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_botcreds_memory_delete', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Register the top-level admin menu page.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Agent Memory', 'botcreds-agent-memory' ),
			__( 'Agent Memory', 'botcreds-agent-memory' ),
			'manage_options',
			'botcreds-memory-entries',
			array( __CLASS__, 'render_page' ),
			'dashicons-database',
			81
		);
	}

	/**
	 * Handle the nonce-protected delete POST action.
	 */
	public static function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'botcreds-agent-memory' ), 403 );
		}

		check_admin_referer( 'botcreds_memory_delete_entry' );

		$key = isset( $_POST['botcreds_memory_key'] ) ? sanitize_text_field( wp_unslash( $_POST['botcreds_memory_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $key ) {
			Botcreds_Memory_DB::delete_by_key( $key );
		}

		wp_safe_redirect( add_query_arg(
			array(
				'page'    => 'botcreds-memory-entries',
				'deleted' => $key ? '1' : '0',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Render the admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Read filters from GET. These are read-only filter params, no nonce needed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_author = isset( $_GET['author'] ) ? sanitize_text_field( wp_unslash( $_GET['author'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_tag    = isset( $_GET['tag'] )    ? sanitize_text_field( wp_unslash( $_GET['tag'] ) )    : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_search = isset( $_GET['s'] )      ? sanitize_text_field( wp_unslash( $_GET['s'] ) )      : '';

		$args = array( 'limit' => 500 );
		if ( $filter_author ) {
			$args['author'] = $filter_author;
		}
		if ( $filter_tag ) {
			$args['tag'] = $filter_tag;
		}
		if ( $filter_search ) {
			$args['search'] = $filter_search;
		}

		$entries     = Botcreds_Memory_DB::list_entries( $args );
		$all_entries = Botcreds_Memory_DB::list_entries( array( 'limit' => 500 ) );

		$authors  = array();
		$all_tags = array();
		foreach ( $all_entries['entries'] as $e ) {
			if ( $e['author'] ) {
				$authors[ $e['author'] ] = true;
			}
			foreach ( $e['tags'] as $t ) {
				if ( $t ) {
					$all_tags[ $t ] = true;
				}
			}
		}
		ksort( $authors );
		ksort( $all_tags );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$deleted_notice = isset( $_GET['deleted'] ) && '1' === $_GET['deleted'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Memory (Legacy View)', 'botcreds-agent-memory' ); ?></h1>
			<p class="description"><?php esc_html_e( 'This is a legacy view. Please use Agent Memory > Entries for the full interface.', 'botcreds-agent-memory' ); ?></p>

			<?php if ( $deleted_notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Entry deleted.', 'botcreds-agent-memory' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

// Backwards compatibility alias.
class_alias( 'Botcreds_Memory_Admin', 'BCAM_Admin' );
