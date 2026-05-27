<?php
/**
 * SFB Inventory Collector
 *
 * Modul lightweight read-only care expune un snapshot al starii site-ului
 * pentru change tracking centralizat în PG (sco_global.site_inventory_snapshots).
 *
 * Endpoint-uri:
 *   GET /wp-json/sfb/v1/inventory          (HMAC auth)
 *     → snapshot complet: WP core, theme, plugins (inclusiv premium), mu-plugins,
 *       php, file hashes whitelisted, options whitelisted, reusable blocks
 *
 *   GET /wp-json/sfb/v1/inventory-secret   (manage_options auth, one-time bootstrap)
 *     → retrieve secret per-site (bridge îl stochează în credentials.env)
 *
 *   POST /wp-json/sfb/v1/inventory-secret  (manage_options auth, rotire opțională)
 *     → regenerează secret (invalidează collector-urile existente)
 *
 * Generare secret: la activare plugin (register_activation_hook) DACĂ
 * opțiunea nu există deja.
 *
 * Folosit de:
 *   - claude-bridge/bin/inventory-snapshot.js (cron zilnic 03:00 UTC)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFB_Inventory {

    const SECRET_OPTION   = 'sfb_inventory_secret';
    const AUDIT_OPTION    = 'sfb_inventory_audit_log';
    const AGENT_VERSION   = '1.0.0';
    const AUDIT_MAX_ENTRIES = 100;

    /**
     * Whitelist fișiere pentru hash + mtime (NU content).
     * Returns asocieri logice (role => relative_path) — relative la ABSPATH,
     * computate cu realpath() pentru a fi safe pe symlinks.
     */
    public static function whitelisted_files() {
        $abspath_real  = self::realpath_safe( ABSPATH );
        $theme_dir     = self::realpath_safe( get_stylesheet_directory() );
        $theme_rel     = ( $abspath_real && $theme_dir && strpos( $theme_dir, $abspath_real ) === 0 )
                         ? ltrim( substr( $theme_dir, strlen( $abspath_real ) ), '/' )
                         : 'wp-content/themes/' . get_stylesheet();

        return [
            // role => relative_path (relative la ABSPATH)
            'theme_functions'  => $theme_rel . '/functions.php',
            'theme_style'      => $theme_rel . '/style.css',
            'root_htaccess'    => '.htaccess',
            'root_robots'      => 'robots.txt',
            'wp_config'        => 'wp-config.php',
        ];
    }

    /**
     * Whitelist options pentru raport.
     * VERIFIED REAL: doar options care există efectiv în WP / FluentCRM / RankMath / WooCommerce.
     * Theme/plugin versions provin din collect_theme() / collect_plugins() (mai sigur decât options).
     */
    public static function whitelisted_options() {
        return [
            // WP core (always present)
            'siteurl', 'blogname', 'blogdescription',
            'template', 'stylesheet', 'db_version', 'WPLANG',
            // Plugin DB version markers (forensic — verificat că există pe specialplus)
            'fluentcrm_db_version',         // FluentCRM (free) — confirmat live
            'rank_math_db_version',         // RankMath SEO — confirmat live
            'woocommerce_db_version',       // WooCommerce — confirmat live
            // SCOS din whitelist:
            //   - fluentcrm_pro_version: NU există ca option separat (Pro share fluentcrm_db_version)
            //   - astra_theme_version:   NU există ca option (versiune in wp_get_theme + collect_theme)
            //   - wc_db_version:         duplicate cu woocommerce_db_version (legacy alias)
        ];
    }

    /**
     * Safe realpath cu fallback la input dacă realpath() returns false (path nu există).
     */
    protected static function realpath_safe( $path ) {
        $real = realpath( $path );
        return $real ? rtrim( str_replace( '\\', '/', $real ), '/' ) : rtrim( str_replace( '\\', '/', $path ), '/' );
    }

    /**
     * Init hooks.
     */
    public static function init() {
        // Generate secret on plugin activation if missing
        register_activation_hook(
            dirname( __DIR__ ) . '/sfb-toolkit.php',
            [ __CLASS__, 'maybe_generate_secret' ]
        );

        // Also try on plugins_loaded (idempotent — covers existing installs)
        add_action( 'plugins_loaded', [ __CLASS__, 'maybe_generate_secret' ] );

        // REST endpoints
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /**
     * Generează secret dacă nu există (idempotent).
     */
    public static function maybe_generate_secret() {
        if ( ! get_option( self::SECRET_OPTION, '' ) ) {
            // Ensure HMAC helper loaded
            if ( ! class_exists( 'SFB_HMAC' ) ) {
                require_once __DIR__ . '/class-sfb-hmac.php';
            }
            add_option( self::SECRET_OPTION, SFB_HMAC::generate_secret(), '', 'no' );
        }
    }

    /**
     * Register REST routes.
     */
    public static function register_routes() {
        // Respect Settings page toggle (default ON)
        if ( ! get_option( 'sfbtk_inventory_enabled', 1 ) ) return;

        // Main inventory endpoint (HMAC auth)
        register_rest_route( 'sfb/v1', '/inventory', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'handle_inventory' ],
            'permission_callback' => [ __CLASS__, 'check_hmac' ],
            'args'                => [
                'include_files' => [
                    'type'    => 'boolean',
                    'default' => true,
                ],
                'include_options' => [
                    'type'    => 'boolean',
                    'default' => true,
                ],
                'include_reusable_blocks' => [
                    'type'    => 'boolean',
                    'default' => true,
                ],
            ],
        ] );

        // Bootstrap: retrieve secret (admin-only)
        register_rest_route( 'sfb/v1', '/inventory-secret', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'handle_get_secret' ],
                'permission_callback' => fn() => current_user_can( 'manage_options' ),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_rotate_secret' ],
                'permission_callback' => fn() => current_user_can( 'manage_options' ),
            ],
        ] );
    }

    /**
     * HMAC permission callback.
     */
    public static function check_hmac( $request ) {
        if ( ! class_exists( 'SFB_HMAC' ) ) {
            require_once __DIR__ . '/class-sfb-hmac.php';
        }
        $result = SFB_HMAC::verify_request( $request, self::SECRET_OPTION );
        if ( is_wp_error( $result ) ) {
            self::audit_log( 'denied', $request, $result->get_error_code() );
            return $result;
        }
        self::audit_log( 'ok', $request );
        return true;
    }

    /**
     * Main handler: collect inventory and return.
     */
    public static function handle_inventory( $request ) {
        // Headers anti-caching
        nocache_headers();

        $payload = [
            'agent_version'  => self::AGENT_VERSION,
            'agent_tz'       => wp_timezone_string(),
            'reported_at'    => current_time( 'c' ),  // ISO 8601 with TZ
            'site'           => [
                'url'      => get_site_url(),
                'name'     => get_bloginfo( 'name' ),
                'is_multisite' => is_multisite(),
            ],
            'wp_core'        => self::collect_wp_core(),
            'php'            => self::collect_php(),
            'theme'          => self::collect_theme(),
            'plugins'        => self::collect_plugins(),
            'mu_plugins'     => self::collect_mu_plugins(),
        ];

        if ( $request->get_param( 'include_files' ) ) {
            $payload['files'] = self::collect_files();
        }
        if ( $request->get_param( 'include_options' ) ) {
            $payload['options'] = self::collect_options();
        }
        if ( $request->get_param( 'include_reusable_blocks' ) ) {
            $payload['reusable_blocks'] = self::collect_reusable_blocks();
        }

        return new WP_REST_Response( $payload, 200, [
            'Cache-Control' => 'no-store, must-revalidate',
        ] );
    }

    public static function handle_get_secret( $request ) {
        return [
            'secret'         => get_option( self::SECRET_OPTION, '' ),
            'agent_version'  => self::AGENT_VERSION,
            'option_key'     => self::SECRET_OPTION,
        ];
    }

    public static function handle_rotate_secret( $request ) {
        if ( ! class_exists( 'SFB_HMAC' ) ) {
            require_once __DIR__ . '/class-sfb-hmac.php';
        }
        $new = SFB_HMAC::generate_secret();
        update_option( self::SECRET_OPTION, $new, 'no' );
        return [ 'rotated' => true, 'secret' => $new ];
    }

    // ─── Collectors ─────────────────────────────────────────────────────────

    protected static function collect_wp_core() {
        global $wp_version;
        return [
            'version'      => $wp_version,
            'is_multisite' => is_multisite(),
            'locale'       => get_locale(),
        ];
    }

    protected static function collect_php() {
        return [
            'version'      => PHP_VERSION,
            'memory_limit' => ini_get( 'memory_limit' ),
            'sapi'         => php_sapi_name(),
        ];
    }

    protected static function collect_theme() {
        $stylesheet = wp_get_theme();
        $parent     = $stylesheet->parent();
        return [
            'stylesheet'        => $stylesheet->get_stylesheet(),
            'stylesheet_name'   => $stylesheet->get( 'Name' ),
            'stylesheet_version'=> $stylesheet->get( 'Version' ),
            'template'          => $stylesheet->get_template(),
            'parent_name'       => $parent ? $parent->get( 'Name' ) : null,
            'parent_version'    => $parent ? $parent->get( 'Version' ) : null,
        ];
    }

    protected static function collect_plugins() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all      = get_plugins();
        $active   = is_multisite()
            ? array_keys( get_site_option( 'active_sitewide_plugins', [] ) )
            : [];
        $active   = array_merge( $active, get_option( 'active_plugins', [] ) );
        $active   = array_flip( array_unique( $active ) );

        $out = [];
        foreach ( $all as $file => $data ) {
            $slug = explode( '/', $file )[0];
            $out[] = [
                'file'          => $file,
                'slug'          => $slug,
                'name'          => $data['Name'] ?? '',
                'version'       => $data['Version'] ?? '',
                'author'        => $data['Author'] ?? '',
                'plugin_uri'    => $data['PluginURI'] ?? '',
                'active'        => isset( $active[ $file ] ),
                'network_active'=> isset( $active[ $file ] ) && is_multisite()
                                   && array_key_exists( $file, get_site_option( 'active_sitewide_plugins', [] ) ),
            ];
        }
        return $out;
    }

    protected static function collect_mu_plugins() {
        if ( ! function_exists( 'get_mu_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_mu_plugins();
        $out = [];
        foreach ( $all as $file => $data ) {
            $out[] = [
                'file'    => $file,
                'name'    => $data['Name'] ?? '',
                'version' => $data['Version'] ?? '',
            ];
        }
        return $out;
    }

    protected static function collect_files() {
        $out = [];
        foreach ( self::whitelisted_files() as $role => $relative ) {
            $absolute = ABSPATH . ltrim( $relative, '/' );
            if ( ! file_exists( $absolute ) ) continue;

            // wp-config: NU hash content, doar metadata (sensitive)
            $is_sensitive = ( basename( $relative ) === 'wp-config.php' );

            $entry = [
                'role'  => $role,                     // logical role (theme_functions, theme_style, wp_config, etc.)
                'path'  => $relative,                 // relative path (canonical, post-realpath)
                'size'  => filesize( $absolute ),
                'mtime' => date( 'c', filemtime( $absolute ) ),
            ];

            if ( ! $is_sensitive ) {
                $entry['sha256'] = hash_file( 'sha256', $absolute );
            } else {
                $entry['sha256'] = null;
                $entry['note']   = 'sensitive_file_metadata_only';
            }
            $out[] = $entry;
        }
        return $out;
    }

    protected static function collect_options() {
        $out = [];
        foreach ( self::whitelisted_options() as $key ) {
            $val = get_option( $key, null );
            if ( $val !== null && $val !== false ) {
                // Truncate long values, scrub potential PII (none in whitelist but safety)
                if ( is_string( $val ) && strlen( $val ) > 500 ) {
                    $val = substr( $val, 0, 500 ) . '...';
                }
                $out[] = [ 'key' => $key, 'value' => $val ];
            }
        }
        return $out;
    }

    protected static function collect_reusable_blocks() {
        $posts = get_posts( [
            'post_type'      => 'wp_block',
            'post_status'    => [ 'publish', 'draft', 'private' ],
            'posts_per_page' => 100,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ] );

        $out = [];
        foreach ( $posts as $p ) {
            $content_hash = hash( 'sha256', $p->post_content );
            $out[] = [
                'id'           => $p->ID,
                'title'        => $p->post_title,
                'status'       => $p->post_status,
                'modified_gmt' => $p->post_modified_gmt,
                'hash'         => $content_hash,
                'size'         => strlen( $p->post_content ),
            ];
        }
        return $out;
    }

    // ─── Audit log ──────────────────────────────────────────────────────────

    protected static function audit_log( $outcome, $request, $error_code = null ) {
        $log = get_option( self::AUDIT_OPTION, [] );
        if ( ! is_array( $log ) ) $log = [];

        $entry = [
            'ts'      => current_time( 'c' ),
            'outcome' => $outcome,  // 'ok' | 'denied'
            'ip'      => self::client_ip(),
            'ua'      => substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100 ),
        ];
        if ( $error_code ) $entry['error'] = $error_code;

        array_unshift( $log, $entry );
        $log = array_slice( $log, 0, self::AUDIT_MAX_ENTRIES );

        update_option( self::AUDIT_OPTION, $log, 'no' );
    }

    protected static function client_ip() {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
                return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
            }
        }
        return '';
    }
}

SFB_Inventory::init();
