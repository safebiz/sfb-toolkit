<?php
/**
 * Plugin Name: SFB Toolkit
 * Plugin URI:  https://github.com/safebiz/sfb-toolkit
 * Description: MasterC infrastructure toolkit — file verify + nonce provider + options API + article modification tracker + inventory collector. REST endpoints for AI worker bridge.
 * Version:     1.4.0
 * Author:      Safebiz Solutions
 * Author URI:  https://safebiz.ro
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.4
 * Requires WP:  6.0
 *
 * Changelog:
 *   1.4.0 (2026-05-27) — /option: fix double-encode TypeError (accept both object
 *                        and JSON-string value); extend whitelist to allow
 *                        `litespeed.conf.*` options (cache excludes config via REST
 *                        on no-SSH sites). Discovered casaluxc dogfood.
 *   1.3.0 (2026-05-27) — Added HMAC auth helper + inventory collector module
 *                        (/wp-json/sfb/v1/inventory, HMAC-protected) for change
 *                        tracking pipeline (Migration 018). Trigger: task #2300.
 *   1.2.0 — Toggle on/off per module in Settings Page
 *   1.1.0 — Article Modification Tracker + Settings Page
 *   1.0.0 — Initial release (file verify + nonce provider + options API)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/includes/class-sfb-github-updater.php';
require_once __DIR__ . '/includes/class-sfb-hmac.php';
require_once __DIR__ . '/includes/class-sfb-inventory.php';
new SFB_GitHub_Updater( [
    'plugin_file'  => __FILE__,
    'github_repo'  => 'safebiz/sfb-toolkit',
    'plugin_slug'  => 'sfb-toolkit',
    'access_token' => '',
] );

// ── 1. FILE VERIFY ──────────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
    if ( ! get_option( 'sfbtk_file_verify_enabled', 1 ) ) return;

    register_rest_route( 'sfb/v1', '/verify/functions-php', [
        'methods'             => 'GET',
        'callback'            => fn() => sfbtk_verify_theme_file( 'functions.php' ),
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );

    register_rest_route( 'sfb/v1', '/verify/style-css', [
        'methods'             => 'GET',
        'callback'            => fn() => sfbtk_verify_theme_file( 'style.css' ),
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );
} );

function sfbtk_verify_theme_file( $filename ) {
    $allowed = [ 'functions.php', 'style.css' ];
    if ( ! in_array( $filename, $allowed, true ) ) {
        return new WP_Error( 'not_allowed', 'File not in whitelist', [ 'status' => 403 ] );
    }

    $file = get_stylesheet_directory() . '/' . $filename;
    if ( ! file_exists( $file ) ) {
        return new WP_Error( 'not_found', $filename . ' not found in active child theme', [ 'status' => 404 ] );
    }

    return [
        'theme'    => basename( get_stylesheet_directory() ),
        'file'     => $filename,
        'hash'     => hash_file( 'sha256', $file ),
        'size'     => filesize( $file ),
        'modified' => date( 'Y-m-d H:i:s', filemtime( $file ) ),
    ];
}

// ── 2. NONCE PROVIDER ───────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
    if ( ! get_option( 'sfbtk_nonce_enabled', 1 ) ) return;

    register_rest_route( 'masterc/v1', '/nonce', [
        'methods'             => 'GET',
        'callback'            => function () {
            return [
                'nonce'      => wp_create_nonce( 'wp_rest' ),
                'expires_in' => DAY_IN_SECONDS,
                'usage'      => 'Trimite ca header X-WP-Nonce in request-uri catre sure*/v1/* endpoints',
            ];
        },
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );

    register_rest_route( 'masterc/v1', '/nonce-test', [
        'methods'             => 'GET',
        'callback'            => function ( $request ) {
            $nonce = $request->get_header( 'X-WP-Nonce' );
            $valid = wp_verify_nonce( $nonce, 'wp_rest' );
            return [
                'nonce_received' => $nonce ? substr( $nonce, 0, 4 ) . '...' : null,
                'valid'          => (bool) $valid,
                'validity'       => 1 === $valid ? 'fresh (< 12h)' : ( 2 === $valid ? 'old (12-24h)' : 'invalid' ),
                'user_id'        => get_current_user_id(),
            ];
        },
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );

    register_rest_route( 'masterc/v1', '/option', [
        'methods'             => [ 'GET', 'POST' ],
        'callback'            => function ( $request ) {
            $name = $request->get_param( 'name' );
            if ( ! $name || ! preg_match( '/^(surecookie|suremembers|suredash|surerank)_|^litespeed\.conf\./', $name ) ) {
                return new WP_Error( 'invalid_option', 'Only sure*/litespeed.conf.* options allowed', [ 'status' => 400 ] );
            }
            if ( 'POST' === $request->get_method() ) {
                $value = $request->get_param( 'value' );
                if ( is_array( $value ) ) {
                    // REST framework already decoded a JSON object body.
                    update_option( $name, $value );
                } else {
                    // String value — may itself be a JSON-encoded payload.
                    $decoded = json_decode( $value, true );
                    update_option( $name, ( null !== $decoded ) ? $decoded : $value );
                }
                return [ 'updated' => true, 'name' => $name ];
            }
            return [ 'name' => $name, 'value' => get_option( $name, '__NOT_FOUND__' ) ];
        },
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );

    register_rest_route( 'masterc/v1', '/options-list', [
        'methods'             => 'GET',
        'callback'            => function () {
            global $wpdb;
            $prefix = sanitize_text_field( $_GET['prefix'] ?? 'surecookie' );
            if ( ! in_array( $prefix, [ 'surecookie', 'suremembers', 'suredash', 'surerank' ], true ) ) {
                return new WP_Error( 'invalid_prefix', 'Only sure* prefixes allowed', [ 'status' => 400 ] );
            }
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 50",
                    $prefix . '%'
                )
            );
            return array_map( fn( $r ) => $r->option_name, $results );
        },
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );
} );

// ── 3. ARTICLE MODIFICATION TRACKER ─────────────────────────────────────────
// Captureaza save_post pe articole published si trimite webhook la n8n.
// Activat din WP Admin → Settings → SFB Toolkit.
// Configuratie: sfbtk_article_tracker_enabled, sfbtk_tracker_client_id, sfbtk_tracker_n8n_url

add_action( 'pre_post_update', function ( $post_id, $data ) {
    if ( ! get_option( 'sfbtk_article_tracker_enabled', 0 ) ) return;
    if ( get_post_type( $post_id ) !== 'post' ) return;
    $old = get_post( $post_id );
    if ( ! $old || $old->post_status !== 'publish' ) return;
    set_transient( 'sfbtk_pre_' . $post_id, [
        'title'   => $old->post_title,
        'content' => $old->post_content,
    ], 120 );
}, 10, 2 );

add_action( 'post_updated', function ( $post_id, $post_after, $post_before ) {
    if ( ! get_option( 'sfbtk_article_tracker_enabled', 0 ) ) return;
    if ( $post_after->post_type !== 'post' ) return;
    if ( $post_after->post_status !== 'publish' ) return;

    $pre = get_transient( 'sfbtk_pre_' . $post_id );
    delete_transient( 'sfbtk_pre_' . $post_id );

    $client_id = get_option( 'sfbtk_tracker_client_id', '' );
    $n8n_url   = get_option( 'sfbtk_tracker_n8n_url', 'https://n8n.safebiz.ro/webhook/article-modification' );
    if ( ! $client_id ) return;

    $words_before = str_word_count( strip_tags( $post_before->post_content ) );
    $words_after  = str_word_count( strip_tags( $post_after->post_content ) );

    wp_remote_post( $n8n_url, [
        'body'     => wp_json_encode( [
            'client_id'         => $client_id,
            'wp_post_id'        => $post_id,
            'wp_post_url'       => get_permalink( $post_id ),
            'modification_type' => 'manual_edit',
            'applied_by'        => 'human',
            'diff_summary'      => [
                'title_changed' => ( $pre['title'] ?? '' ) !== $post_after->post_title,
                'words_before'  => $words_before,
                'words_after'   => $words_after,
                'words_delta'   => $words_after - $words_before,
            ],
        ] ),
        'headers'  => [ 'Content-Type' => 'application/json' ],
        'blocking' => false,
        'timeout'  => 5,
    ] );
}, 10, 3 );

// ── 3.1. SETTINGS PAGE ──────────────────────────────────────────────────────
add_action( 'admin_menu', function () {
    add_options_page(
        'SFB Toolkit',
        'SFB Toolkit',
        'manage_options',
        'sfb-toolkit',
        'sfbtk_settings_page'
    );
} );

add_action( 'admin_init', function () {
    register_setting( 'sfbtk_options', 'sfbtk_file_verify_enabled',     [ 'type' => 'boolean', 'default' => 1 ] );
    register_setting( 'sfbtk_options', 'sfbtk_nonce_enabled',           [ 'type' => 'boolean', 'default' => 1 ] );
    register_setting( 'sfbtk_options', 'sfbtk_article_tracker_enabled', [ 'type' => 'boolean', 'default' => 0 ] );
    register_setting( 'sfbtk_options', 'sfbtk_tracker_client_id',       [ 'type' => 'string',  'default' => '' ] );
    register_setting( 'sfbtk_options', 'sfbtk_tracker_n8n_url',         [ 'type' => 'string',  'default' => 'https://n8n.safebiz.ro/webhook/article-modification' ] );
    register_setting( 'sfbtk_options', 'sfbtk_inventory_enabled',       [ 'type' => 'boolean', 'default' => 1 ] );
} );

function sfbtk_settings_page() {
    ?>
    <div class="wrap">
        <h1>SFB Toolkit Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'sfbtk_options' ); ?>
            <table class="form-table">
                <tr>
                    <th>File Verify (sfb/v1)</th>
                    <td>
                        <label>
                            <input type="checkbox" name="sfbtk_file_verify_enabled" value="1"
                                <?php checked( 1, get_option( 'sfbtk_file_verify_enabled', 1 ) ); ?> />
                            Activat — endpoints <code>/sfb/v1/verify/functions-php</code> și <code>/sfb/v1/verify/style-css</code>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Nonce Provider (masterc/v1)</th>
                    <td>
                        <label>
                            <input type="checkbox" name="sfbtk_nonce_enabled" value="1"
                                <?php checked( 1, get_option( 'sfbtk_nonce_enabled', 1 ) ); ?> />
                            Activat — endpoints <code>/masterc/v1/nonce</code>, <code>/masterc/v1/nonce-test</code>, <code>/masterc/v1/option</code>, <code>/masterc/v1/options-list</code>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Article Modification Tracker</th>
                    <td>
                        <label>
                            <input type="checkbox" name="sfbtk_article_tracker_enabled" value="1"
                                <?php checked( 1, get_option( 'sfbtk_article_tracker_enabled', 0 ) ); ?> />
                            Activat — trimite webhook la n8n la fiecare save_post pe articole published
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Inventory Collector (sfb/v1/inventory)</th>
                    <td>
                        <label>
                            <input type="checkbox" name="sfbtk_inventory_enabled" value="1"
                                <?php checked( 1, get_option( 'sfbtk_inventory_enabled', 1 ) ); ?> />
                            Activat — endpoint HMAC-protejat <code>/sfb/v1/inventory</code> pentru change-tracking pipeline (plugins, files, options)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Client ID</th>
                    <td>
                        <input type="text" name="sfbtk_tracker_client_id"
                            value="<?php echo esc_attr( get_option( 'sfbtk_tracker_client_id', '' ) ); ?>"
                            placeholder="mpss / salonnunta / safebiz" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th>n8n Webhook URL</th>
                    <td>
                        <input type="url" name="sfbtk_tracker_n8n_url"
                            value="<?php echo esc_attr( get_option( 'sfbtk_tracker_n8n_url', 'https://n8n.safebiz.ro/webhook/article-modification' ) ); ?>"
                            class="regular-text" />
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
