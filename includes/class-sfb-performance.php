<?php
/**
 * SFB_Performance — diagnostice de performanță BACKEND, read-only (v1.5.9, P1.6).
 *
 * Endpoint: GET /wp-json/masterc/v1/performance   (manage_options, ca restul masterc/v1)
 *
 * Acoperă stratul pe care auditul lunar îl raporta „nu se poate măsura remote":
 * autoload bloat, object cache, transients, sănătate cron, flag-uri debug, DB.
 * Sursa metodologiei: skill-ul oficial `wp-performance` (autoload-options, object-cache,
 * cron, database, measurement). Totul e READ-ONLY — zero side effects.
 *
 * Confidențialitate: se returnează DOAR nume de opțiuni + dimensiuni, NICIODATĂ valori.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFB_Performance {

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes() {
		if ( ! get_option( 'sfbtk_performance_enabled', 1 ) ) return;

		register_rest_route( 'masterc/v1', '/performance', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'args'                => [
				'top' => [
					'type'              => 'integer',
					'default'           => 10,
					'minimum'           => 1,
					'maximum'           => 50,
					'sanitize_callback' => 'absint',
					'validate_callback' => fn( $v ) => is_numeric( $v ) && $v >= 1 && $v <= 50,
				],
			],
		] );
	}

	public static function handle( WP_REST_Request $request ) {
		$top = (int) $request->get_param( 'top' );
		return rest_ensure_response( [
			'generated_at' => gmdate( 'c' ),
			'autoload'     => self::autoload_stats( $top ),
			'object_cache' => self::object_cache_stats(),
			'transients'   => self::transient_stats( $top ),
			'cron'         => self::cron_stats(),
			'database'     => self::db_stats(),
			'environment'  => self::env_stats(),
		] );
	}

	/* ── 1. Autoloaded options (încărcate la FIECARE request) ─────────────── */

	private static function autoload_stats( $top ) {
		global $wpdb;
		// WP 6.6+: valorile autoload pot fi yes/on/auto/auto-on (+ off/no/auto-off).
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)),0) AS bytes
			   FROM {$wpdb->options}
			  WHERE autoload NOT IN ('no','off','auto-off')"
		);
		$biggest = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, LENGTH(option_value) AS bytes
			   FROM {$wpdb->options}
			  WHERE autoload NOT IN ('no','off','auto-off')
			  ORDER BY LENGTH(option_value) DESC
			  LIMIT %d", $top
		) );
		$bytes = (int) $row->bytes;
		return [
			'total_bytes'   => $bytes,
			'total_human'   => size_format( $bytes ),
			'count'         => (int) $row->cnt,
			// praguri: <800KB ok · 800KB-2MB warn · >2MB critical (convenție wp doctor ~900KB warn)
			'verdict'       => $bytes < 800 * 1024 ? 'ok' : ( $bytes < 2 * 1024 * 1024 ? 'warn' : 'critical' ),
			'biggest'       => array_map( fn( $o ) => [ 'name' => $o->option_name, 'bytes' => (int) $o->bytes ], $biggest ),
		];
	}

	/* ── 2. Object cache persistent ───────────────────────────────────────── */

	private static function object_cache_stats() {
		$dropin  = file_exists( WP_CONTENT_DIR . '/object-cache.php' );
		$ext     = (bool) wp_using_ext_object_cache();
		$backend = 'none';
		if ( class_exists( 'Redis' ) && $ext ) $backend = 'redis(ext prezent)';
		elseif ( class_exists( 'Memcached' ) && $ext ) $backend = 'memcached(ext prezent)';
		elseif ( $ext ) $backend = 'persistent(necunoscut)';
		return [
			'persistent'    => $ext,
			'dropin_exists' => $dropin,
			'backend_hint'  => $backend,
			// fără cache persistent, transients stau în DB și fiecare request re-face munca
			'verdict'       => $ext ? 'ok' : 'warn',
		];
	}

	/* ── 3. Transients în DB (simptom de lipsă object cache + bloat) ─────── */

	private static function transient_stats( $top ) {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)),0) AS bytes
			   FROM {$wpdb->options}
			  WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'"
		);
		// expirate = timeout-ul a trecut dar rândul încă există (gunoi nedelete-uit)
		$expired = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options}
			  WHERE option_name LIKE '\\_transient\\_timeout\\_%' AND option_value < %d", time()
		) );
		$biggest = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options}
			  WHERE option_name LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_transient\\_timeout\\_%'
			  ORDER BY LENGTH(option_value) DESC LIMIT %d", $top
		) );
		return [
			'count'        => (int) $row->cnt,
			'total_bytes'  => (int) $row->bytes,
			'expired'      => $expired,
			'verdict'      => $expired > 500 ? 'warn' : 'ok',
			'biggest'      => array_map( fn( $o ) => [ 'name' => $o->option_name, 'bytes' => (int) $o->bytes ], $biggest ),
		];
	}

	/* ── 4. Sănătate WP-Cron ──────────────────────────────────────────────── */

	private static function cron_stats() {
		$crons = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : [];
		$total = 0; $overdue = 0; $per_hook = []; $oldest_overdue = null;
		$now = time();
		foreach ( $crons as $ts => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				$n = count( (array) $events );
				$total += $n;
				$per_hook[ $hook ] = ( $per_hook[ $hook ] ?? 0 ) + $n;
				if ( $ts < $now - 5 * MINUTE_IN_SECONDS ) {
					$overdue += $n;
					if ( null === $oldest_overdue || $ts < $oldest_overdue ) $oldest_overdue = $ts;
				}
			}
		}
		arsort( $per_hook );
		$dupes = array_filter( $per_hook, fn( $n ) => $n > 3 );
		return [
			'total_events'      => $total,
			'overdue_events'    => $overdue,
			'oldest_overdue_min' => $oldest_overdue ? (int) round( ( $now - $oldest_overdue ) / 60 ) : 0,
			'duplicate_hooks'   => array_slice( $dupes, 0, 10, true ),
			'disable_wp_cron'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			// overdue mult = cronul nu se declanșează (trafic mic fără cron de sistem, sau blocat)
			'verdict'           => $overdue > 10 || ( $oldest_overdue && $now - $oldest_overdue > HOUR_IN_SECONDS ) ? 'warn' : 'ok',
		];
	}

	/* ── 5. DB: dimensiuni + gunoi tipic ──────────────────────────────────── */

	private static function db_stats() {
		global $wpdb;
		$revisions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		$options_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$postmeta_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" );
		// orphan postmeta: meta fără post (gunoi clasic de la pluginuri dezinstalate)
		$orphan_meta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"
		);
		$tables = [];
		// information_schema poate fi restricționat pe shared hosting → degradare curată
		$res = $wpdb->get_results( $wpdb->prepare(
			"SELECT table_name AS t, ROUND((data_length+index_length)/1048576,1) AS mb
			   FROM information_schema.TABLES WHERE table_schema = %s
			  ORDER BY (data_length+index_length) DESC LIMIT 8", DB_NAME
		) );
		if ( $res ) foreach ( $res as $r ) $tables[] = [ 'table' => $r->t, 'mb' => (float) $r->mb ];
		return [
			'revisions'      => $revisions,
			'options_rows'   => $options_rows,
			'postmeta_rows'  => $postmeta_rows,
			'orphan_postmeta' => $orphan_meta,
			'biggest_tables' => $tables,
			'verdict'        => ( $revisions > 2000 || $orphan_meta > 5000 ) ? 'warn' : 'ok',
		];
	}

	/* ── 6. Mediu: flag-uri debug + limite ────────────────────────────────── */

	private static function env_stats() {
		return [
			'php_version'     => PHP_VERSION,
			'wp_version'      => get_bloginfo( 'version' ),
			'memory_limit'    => ini_get( 'memory_limit' ),
			'wp_memory_limit' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : null,
			// true în producție = overhead + informație divulgată
			'wp_debug'        => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'    => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'savequeries'     => defined( 'SAVEQUERIES' ) && SAVEQUERIES,
			'script_debug'    => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			'active_plugins'  => count( (array) get_option( 'active_plugins', [] ) ),
			'verdict'         => ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) ? 'warn' : 'ok',
		];
	}
}

SFB_Performance::init();
