<?php
/**
 * Plugin Name: SFB Toolkit
 * Plugin URI:  https://github.com/safebiz/sfb-toolkit
 * Description: MasterC infrastructure toolkit — file verify + nonce provider + options API + article modification tracker + inventory collector. REST endpoints for AI worker bridge.
 * Version:     1.5.8
 * Author:      Safebiz Solutions
 * Author URI:  https://safebiz.ro
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.4
 * Requires WP:  6.0
 *
 * Changelog:
 *   1.5.8 (2026-06-10) — New /masterc/v1/rankmath-redirect endpoint: insert deterministic 301
 *                        redirect in wp_rank_math_redirections via RankMath\Redirections\DB::add
 *                        (corect serializare sources + 301 servit imediat, validat live monitorstup).
 *                        Idempotent (nu dublează pattern exact). Paritate F6 cu SureRank /redirection
 *                        pentru cele ~17/21 site-uri RankMath fără SSH. Tool: fix-404-redirect.js.
 *   1.5.7 (2026-05-29) — Fix conflict hardening login_page_exposed vs WPS Hide Login: gate-ul
 *                        `defined('WPS_HIDE_LOGIN_VERSION')` era evaluat în constructor la
 *                        include-time, dar SFB se încarcă alfabetic ÎNAINTEA wps-hide-login →
 *                        constanta nu era încă definită → protect_login se înregistra mereu și
 *                        redirecționa login-ul custom (ex: /beleppo) spre home. Fix: înregistrează
 *                        protect_login mereu pe init, mută verificarea defined() la runtime în
 *                        protect_login(). Bug raportat galprogressio (WPS Hide Login 1.9.18).
 *   1.5.4 (2026-05-28) — Security hardening module: Fix wp_generator_visible (remove meta
 *                        generator tag), missing_security_headers (X-Frame-Options,
 *                        X-Content-Type-Options, Referrer-Policy, Permissions-Policy via
 *                        PHP send_headers hook — server-agnostic), login_page_exposed
 *                        fallback redirect. Whitelist extins cu whl_page (WPS Hide Login).
 *   1.5.3 (2026-05-28) — Fix settings page: remove n8n.safebiz.ro from form field default.
 *   1.5.2 (2026-05-28) — Article tracker: remove hardcoded n8n.safebiz.ro default URL
 *                        (privacy fix — strangers installing plugin would send data to our
 *                        webhook); add guard: tracker silent-skips if n8n_url is empty.
 *   1.5.1 (2026-05-27) — Security hardening of /write-lang-file after GPT-5.4 + Claude
 *                        audit: DROP .l10n.php (data-only — no PHP code-exec vector);
 *                        5MB size cap; atomic write (temp + rename + LOCK_EX); realpath
 *                        confinement to WP_LANG_DIR; reject NUL/control chars; chmod 0644.
 *   1.5.0 (2026-05-27) — New /masterc/v1/write-lang-file endpoint: deploy translation
 *                        files into wp-content/languages/{plugins,themes}/ via REST.
 *                        Enables i18n (gettext + wp.i18n React strings) on no-SSH sites.
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
require_once __DIR__ . '/includes/class-sfb-hardening.php';
new SFB_Hardening();
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
            if ( ! $name || ! preg_match( '/^(surecookie|suremembers|suredash|surerank)_|^litespeed\.conf\.|^whl_page$/', $name ) ) {
                return new WP_Error( 'invalid_option', 'Only sure*/litespeed.conf.*/whl_page options allowed', [ 'status' => 400 ] );
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

    // Write a translation DATA file (.po/.mo/.json) into wp-content/languages/{plugins,themes}/.
    // Enables deploying gettext + JS i18n (wp.i18n) translations on sites WITHOUT SSH access.
    // Content is sent base64-encoded; compiled .mo + JSON i18n are produced client-side.
    // SECURITY: data-only — NO executable extensions (.php) accepted. Admin-gated, size-capped,
    // atomic write, realpath-confined to WP_LANG_DIR. (Audited GPT-5.4 + Claude, 2026-05-27.)
    register_rest_route( 'masterc/v1', '/write-lang-file', [
        'methods'             => 'POST',
        'callback'            => function ( $request ) {
            $filename = (string) $request->get_param( 'filename' );
            $type     = (string) $request->get_param( 'type' );
            $b64      = (string) $request->get_param( 'content_base64' );

            if ( ! in_array( $type, [ 'plugins', 'themes' ], true ) ) {
                return new WP_Error( 'bad_type', 'type must be plugins|themes', [ 'status' => 400 ] );
            }
            // Data-only whitelist: {td}-{locale}[-{md5}].{po|mo|json}. NO .php (no code-exec vector).
            if ( ! preg_match( '/^[a-z0-9_-]+-[a-z]{2,3}_[A-Z]{2}(-[a-f0-9]{32})?\.(po|mo|json)$/', $filename ) ) {
                return new WP_Error( 'bad_filename', 'invalid translation filename (allowed: *.po/.mo/.json)', [ 'status' => 400 ] );
            }
            // Defense-in-depth: reject path separators, traversal, NUL/control chars.
            if ( strpbrk( $filename, "/\\\0" ) !== false || strpos( $filename, '..' ) !== false ) {
                return new WP_Error( 'bad_filename', 'filename must not contain path separators or control chars', [ 'status' => 400 ] );
            }
            $content = base64_decode( $b64, true );
            if ( false === $content ) {
                return new WP_Error( 'bad_b64', 'content_base64 invalid', [ 'status' => 400 ] );
            }
            // Size cap: 5 MB decoded (translation files are tiny; this blocks abuse).
            if ( strlen( $content ) > 5 * 1024 * 1024 ) {
                return new WP_Error( 'too_large', 'content exceeds 5MB cap', [ 'status' => 413 ] );
            }

            $dir = trailingslashit( WP_LANG_DIR ) . $type;
            if ( ! file_exists( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            // Confirm the resolved directory is really inside WP_LANG_DIR (realpath confinement).
            $real_dir  = realpath( $dir );
            $real_base = realpath( WP_LANG_DIR );
            if ( false === $real_dir || false === $real_base || strpos( $real_dir, $real_base ) !== 0 ) {
                return new WP_Error( 'bad_dir', 'target dir escapes WP_LANG_DIR', [ 'status' => 400 ] );
            }
            $path = trailingslashit( $real_dir ) . $filename;

            // Atomic write: temp file in same dir + rename (no partial/corrupt file on failure).
            $tmp = $path . '.tmp-' . wp_generate_password( 8, false );
            $bytes = file_put_contents( $tmp, $content, LOCK_EX );
            if ( false === $bytes ) {
                return new WP_Error( 'write_failed', 'could not write temp file (check permissions)', [ 'status' => 500 ] );
            }
            @chmod( $tmp, 0644 );
            if ( ! @rename( $tmp, $path ) ) {
                @unlink( $tmp );
                return new WP_Error( 'rename_failed', 'could not finalize file', [ 'status' => 500 ] );
            }
            return [ 'written' => true, 'path' => str_replace( ABSPATH, '', $path ), 'bytes' => $bytes ];
        },
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );

    // RankMath redirect insert — paritate F6 cu SureRank /redirection (RankMath n-are REST pt redirect arbitrar).
    register_rest_route( 'masterc/v1', '/rankmath-redirect', [
        'methods'             => 'POST',
        'callback'            => 'sfbtk_rankmath_redirect',
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );
} );

// Insert deterministic în wp_rank_math_redirections via clasa oficială DB::add (serializare corectă +
// 301 servit imediat, verificat live monitorstup 2026-06-10). Idempotent: nu dublează un pattern exact.
function sfbtk_rankmath_redirect( $request ) {
    if ( ! class_exists( '\RankMath\Redirections\DB' ) ) {
        return new WP_Error( 'rankmath_redirections_inactive', 'RankMath Redirections module inactiv (sau RankMath neinstalat)', [ 'status' => 409 ] );
    }
    $from = (string) $request->get_param( 'from' );
    $to   = (string) $request->get_param( 'to' );
    $code = (int) ( $request->get_param( 'header_code' ) ?: 301 );
    if ( '' === $from || '' === $to ) {
        return new WP_Error( 'bad_request', 'parametrii "from" si "to" sunt obligatorii', [ 'status' => 400 ] );
    }
    if ( ! in_array( $code, [ 301, 302, 307, 308, 410, 451 ], true ) ) { $code = 301; }

    // pattern exact = path fără slash-uri de capăt (cum normalizează RankMath get_clean_pattern)
    $from_path = wp_parse_url( $from, PHP_URL_PATH );
    $pattern   = trim( $from_path ? $from_path : $from, '/' );
    if ( '' === $pattern ) {
        return new WP_Error( 'bad_request', '"from" invalid (pattern gol dupa normalizare)', [ 'status' => 400 ] );
    }
    $to_path = wp_parse_url( $to, PHP_URL_PATH );
    $url_to  = $to_path ? $to_path : $to;

    global $wpdb;
    $table = $wpdb->prefix . 'rank_math_redirections';
    // idempotenta: cauta un redirect activ cu acelasi pattern exact (evita dublarea la re-apply)
    $like = '%' . $wpdb->esc_like( $pattern ) . '%';
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, sources FROM {$table} WHERE status = 'active' AND sources LIKE %s LIMIT 50", $like ) );
    foreach ( (array) $rows as $row ) {
        $srcs = maybe_unserialize( $row->sources );
        if ( is_array( $srcs ) ) {
            foreach ( $srcs as $s ) {
                if ( isset( $s['pattern'] ) && trim( (string) $s['pattern'], '/' ) === $pattern ) {
                    return [ 'created' => false, 'existing_id' => (int) $row->id, 'pattern' => $pattern, 'reason' => 'redirect activ exista deja pentru acest pattern' ];
                }
            }
        }
    }

    $id = \RankMath\Redirections\DB::add( [
        'sources'     => [ [ 'pattern' => $pattern, 'comparison' => 'exact' ] ],
        'url_to'      => $url_to,
        'header_code' => (string) $code,
        'status'      => 'active',
    ] );
    if ( ! $id ) {
        return new WP_Error( 'insert_failed', 'RankMath DB::add a esuat', [ 'status' => 500 ] );
    }
    if ( class_exists( '\RankMath\Redirections\Cache' ) && method_exists( '\RankMath\Redirections\Cache', 'purge' ) ) {
        \RankMath\Redirections\Cache::purge( (int) $id );
    }
    return [ 'created' => true, 'id' => (int) $id, 'pattern' => $pattern, 'url_to' => $url_to, 'header_code' => $code ];
}

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
    $n8n_url   = get_option( 'sfbtk_tracker_n8n_url', '' );
    if ( ! $client_id || ! $n8n_url ) return;

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
    register_setting( 'sfbtk_options', 'sfbtk_tracker_n8n_url',         [ 'type' => 'string',  'default' => '' ] );
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
                            Activat — endpoints <code>/masterc/v1/nonce</code>, <code>/masterc/v1/nonce-test</code>, <code>/masterc/v1/option</code>, <code>/masterc/v1/options-list</code>, <code>/masterc/v1/write-lang-file</code>, <code>/masterc/v1/rankmath-redirect</code>
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
                            value="<?php echo esc_attr( get_option( 'sfbtk_tracker_n8n_url', '' ) ); ?>"
                            class="regular-text" />
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
