<?php
/**
 * Plugin Name: BotCreds Agent Memory
 * Description: Portable memory store for AI agents. REST API + MCP endpoint. KV mode by default, semantic vector search when OpenAI key is configured.
 * Version: 2.0.2
 * Author: Joe Boydston
 * Author URI: https://botcreds.com
 * License: GPL-2.0-or-later
 * Text Domain: botcreds-agent-memory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BOTCREDS_MEMORY_VERSION', '2.0.1' );
define( 'BOTCREDS_MEMORY_FILE', __FILE__ );
define( 'BOTCREDS_MEMORY_DIR', plugin_dir_path( __FILE__ ) );

/*
 * Include class files.
 */
require_once BOTCREDS_MEMORY_DIR . 'includes/class-db.php';
require_once BOTCREDS_MEMORY_DIR . 'includes/class-access-control.php';
require_once BOTCREDS_MEMORY_DIR . 'includes/class-embeddings.php';
require_once BOTCREDS_MEMORY_DIR . 'includes/class-rest-api.php';
require_once BOTCREDS_MEMORY_DIR . 'includes/class-mcp.php';
require_once BOTCREDS_MEMORY_DIR . 'includes/class-settings.php';

/*
 * Activation: create the custom table.
 */
register_activation_hook( BOTCREDS_MEMORY_FILE, array( 'Botcreds_Memory_DB', 'create_table' ) );

/*
 * Deactivation: clean up all scheduled cron events.
 */
register_deactivation_hook( BOTCREDS_MEMORY_FILE, 'botcreds_memory_deactivation' );

/**
 * Clean up cron events on deactivation.
 */
function botcreds_memory_deactivation() {
	// Unschedule the backfill event.
	$timestamp = wp_next_scheduled( 'botcreds_memory_backfill_embeddings' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'botcreds_memory_backfill_embeddings' );
	}

	// Unschedule any pending embed events. Since they carry an entry ID arg,
	// clear all matching events by removing the hook entirely.
	wp_unschedule_hook( 'botcreds_memory_embed_entry' );
}

/*
 * Register WP-Cron action hooks.
 */
add_action( 'botcreds_memory_embed_entry', array( 'Botcreds_Memory_Embeddings', 'cron_embed_entry' ) );
add_action( 'botcreds_memory_backfill_embeddings', array( 'Botcreds_Memory_Embeddings', 'cron_backfill' ) );

/*
 * Register REST API routes.
 */
add_action( 'rest_api_init', array( 'Botcreds_Memory_REST_API', 'register_routes' ) );
add_action( 'rest_api_init', array( 'Botcreds_Memory_MCP', 'register_routes' ) );

/*
 * Admin menu and settings.
 */
add_action( 'admin_menu', array( 'Botcreds_Memory_Settings', 'add_menus' ) );
add_action( 'admin_init', array( 'Botcreds_Memory_Settings', 'register_settings' ) );
add_action( 'admin_enqueue_scripts', 'botcreds_memory_admin_assets' );

/**
 * Enqueue admin CSS on plugin pages only.
 *
 * @param string $hook_suffix The current admin page hook suffix.
 */
function botcreds_memory_admin_assets( $hook_suffix ) {
	// Only load on our own admin pages.
	if ( strpos( $hook_suffix, 'botcreds-memory' ) === false ) {
		return;
	}
	wp_enqueue_style(
		'botcreds-memory-admin',
		plugins_url( 'assets/admin.css', BOTCREDS_MEMORY_FILE ),
		array(),
		BOTCREDS_MEMORY_VERSION
	);
}

/*
 * Access control hooks for user profile pages.
 */
add_action( 'show_user_profile', array( 'Botcreds_Memory_Access_Control', 'render_user_fields' ) );
add_action( 'edit_user_profile', array( 'Botcreds_Memory_Access_Control', 'render_user_fields' ) );
add_action( 'personal_options_update', array( 'Botcreds_Memory_Access_Control', 'save_user_fields' ) );
add_action( 'edit_user_profile_update', array( 'Botcreds_Memory_Access_Control', 'save_user_fields' ) );
