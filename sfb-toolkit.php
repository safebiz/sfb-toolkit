<?php
/**
 * Plugin Name: SFB Toolkit
 * Plugin URI:  https://github.com/safebiz/sfb-toolkit
 * Description: MasterC infrastructure toolkit — file verify + nonce provider + options API + article modification tracker + inventory collector. REST endpoints for AI worker bridge.
 * Version:     1.8.2
 * Author:      Safebiz Solutions
 * Author URI:  https://safebiz.ro
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:  https://github.com/safebiz/sfb-toolkit
 * Text Domain: sfb-toolkit
 * Requires PHP: 7.4
 * Requires WP:  6.0
 *
 * Changelog:
 *   1.8.2 (2026-08-22) — Tracking Health: (1) prinde și trimiterile prin FORMULAR/iframe — fbevents.js
 *         trimite Purchase către facebook.com/tr ca POST de formular într-un iframe ascuns (HAR taki,
 *         #32138), invizibil pentru fetch/XHR/beacon/Resource Timing ⇒ marcajul spunea fals „doar server";
 *         (2) buffer Resource Timing 2000 (pagini grele); (3) casetă „Măsurare" în ecranul comenzii
 *         + coloană în lista de comenzi (HPOS și clasic), în română, doar citire.
 *   1.8.1 (2026-08-22) — Tracking Health OPRIT implicit. 1.8.0 îl pornea pe orice site care se actualiza
 *         automat; modulul se activează acum per site, din Setări → SFB Toolkit (decizie taki).
 *   1.8.0 (2026-08-22) — NOU modul Tracking Health (includes/class-sfb-tracking-health.php): marcaj
 *         tehnic pe fiecare comandă, scris din browserul cumpărătorului pe pagina de mulțumire —
 *         GTM încărcat? `purchase` în dataLayer? a plecat cererea către GA4 (/g/collect en=purchase)?
 *         pixelul Meta (/tr ev=Purchase)? releul PYS? Gateway-ul on.aws? ce a ales la cookie-uri?
 *         + numărătoarea randărilor în PHP (independentă de JS). Motiv: pe special-plus, 18–21 aug,
 *         marcajele PYS/GTM Kit erau „1" pe comenzi pe care Google/Meta nu le primiseră niciodată —
 *         ele spun că PHP-ul a construit evenimentul, nu că a plecat. Observator în <head> (prio 0,
 *         înfășoară sendBeacon/fetch/XHR — GA4 trimite prin sendBeacon, invizibil în Resource Timing),
 *         raport la T+3s/T+8s/pagehide pe POST /sfb/v1/tracking-health, autorizat cu HMAC din cheia
 *         comenzii. Meta `_sfb_tracking_health` (JSON, doar bool/int) + `_sfb_th_renders`.
 *         Citit de wat/tools/purchase-truth.js ⇒ verdict „dovedit" în loc de „probabil". Bifă:
 *         `sfbtk_tracking_health_enabled` (implicit PORNIT); oprire: SFB_TRACKING_HEALTH_DISABLE.
 *   1.7.5 (2026-08-19) — NOU masterc/v1/wpml-set-terms: pune categoriile pe o traducere, in
 *         perechea de termen din limba postului. Motiv: WPML REMAPEAZA id-urile de termen dupa
 *         limba contextului, la citire SI la scriere (incident 18 aug: scrisul lui 120 a produs
 *         109, iar stergerea lui 109 a sters 120 — ambele „au reusit"). Ruta comuta contextul pe
 *         limba postului inainte de scriere si CITESTE INAPOI cu SQL brut (wp_get_post_terms minte
 *         la fel). Fara pereche in limba tinta NU pune originalul — raporteaza in `missing`.
 *   1.7.4 (2026-08-19) — SEO-T6, completare: aceiasi trei pasi se aplica si TERMENILOR.
 *         1.7.3 ii lasa doar cu pasul 1 (curatare), de teama remapului WPML de ID dupa limba.
 *         csagasa a aratat ca nu ajunge: categoria „Portofoliu" are pereche reala (1 ↔ 2), dar
 *         `get_term_link()` intoarce adresa ROMANEASCA pentru amandoua in limba `ro`. Remapul
 *         exista, dar e inofensiv cand limba tinta are pereche confirmata. Non-regresie masurata
 *         pe cele 10 perechi de categorii safebiz: adrese identice inainte si dupa.
 *   1.7.3 (2026-08-19) — SEO-T6: repararea hreflang-ului din sitemapul SureRank (sectiunea 2.4) +
 *         `sfbtk_wpml_permalink_in_lang()` (2.3), folosita si de wpml-map-urls.
 *         Doua defecte SureRank, prezente identic in 1.9.3 si in 1.10.0 (lansata azi):
 *         (A) `wpml_object_id(..., $return_original_if_missing = TRUE, ...)` inventeaza o
 *         alternativa catre propria adresa cand traducerea lipseste — 27 din 70 de adrese pe
 *         safebiz; (B) `get_permalink($id_tradus)` intoarce adresa in limba CURENTA, nu in cea a
 *         postului — rupe pagina de start chiar si cand perechea exista.
 *         🔴 (B) lovea SI ruta noastra `wpml-map-urls` (1.7.1): la ro→hu intorcea adrese ROMANESTI
 *         cu `status: ok` pentru TOATE linkurile, nu doar pentru pagina de start. Nedeclansat inca
 *         (articolul 37980 n-avea link catre radacina). Semnalat de auditul Codex, confirmat live.
 *         Comutator: optiunea `sfbtk_sr_hreflang_fix`. Semnal de viata: `sfbtk_sr_hreflang_stats`.
 *   1.7.2 (2026-08-19) — wpml-map-urls accepta si `attachment_ids`: intoarce perechea de imagine
 *         din limba tinta. Fara ea, traducerea mostenea imaginea originalului CU TOT CU limba din
 *         ea (masurat: 2 din 4 articole romanesti aratau imagini cu text maghiar), plus numele de
 *         fisier si textul alternativ in limba sursei — pierdere de SEO pe imagini in ambele limbi.
 *   1.7.1 (2026-08-19) — NOU masterc/v1/wpml-map-urls: intoarce echivalentul in limba tinta
 *         pentru linkurile interne dintr-un articol sursa. Motiv: modelul „traducea" href-urile
 *         si inventa slug-uri inexistente (masurat: butonul CTA al articolului #4176 ducea in 404).
 *         `wpml_object_id(..., $return_original_if_missing = FALSE, ...)` — vrem `null` cand nu
 *         exista pereche, NU pagina din limba sursa (acelasi bug ca SEO-T6 din sitemap).
 *   1.7.0 (2026-08-19) — NOU masterc/v1/wpml-link: leaga o traducere de originalul ei in WPML.
 *         Motiv: `translation_of` trimis prin REST catre /wp/v2/posts NU leaga nimic — nu e camp
 *         REST inregistrat, iar WPML citeste `icl_translation_of` doar din $_POST si doar in
 *         `WPML_Admin_Post_Actions`, care nici nu se incarca pe un apel REST obisnuit. Toate
 *         traducerile publicate de conducta article-engine erau legate manual. Ruta ruleaza
 *         `wpml_element_trid` + `wpml_set_element_language_details`, apoi CITESTE INAPOI
 *         wp_icl_translations ca dovada si goleste `wpml_resolved_url_persist` (harta in care WPML
 *         memoreaza si raspunsurile negative — fara golire perechea e corecta in DB si lipseste din
 *         sitemap) + checksum sitemap SureRank + cache de pagina. Idempotenta pe (trid, limba,
 *         directie). Refuza legarea de un element care e el insusi traducere.
 *   1.6.0 (2026-08-11) — NOU modul URL Bases (paritate Rank Math la migrarea spre SureRank):
 *         4 bife (baza produsului / baza categoriei de produs / slug-uri parinte / baza categoriei
 *         de blog), router la radacina pentru produse, reguli de rescriere per termen, 301 vechi-nou.
 *         Toate OPRITE implicit. Raporteaza coliziunile slug produs-categorie in pagina de setari.
 *         Motiv: SureRank nu are echivalent pentru baza produsului si slug-urile parinte.
 *   1.5.9 (2026-07-12) — NOU masterc/v1/performance (P1.6, read-only): autoload bloat,
 *         object cache, transients, cron health, DB (revisions/orphan meta/tabele), env flags
 *         (metodologie: skill oficial wp-performance). + REST hardening (P0.3, audit wp-rest-api):
 *         args schema pe rutele masterc/v1 (/option, /options-list, /write-lang-file) cu
 *         validate/sanitize declarativ; fix /options-list $_GET[prefix] → $request->get_param();
 *         headers License URI/Update URI/Text Domain; date() → gmdate(). + fix-uri PHPStan level 5
 *         (autoload bool ×3, proprietate nefolosită) — gate nou pre-deploy: devtools/phpstan-wp.
 *         (Notă: hardening-ul a purtat local eticheta 1.5.8 câteva ore, doar pe safehost — pliat aici.)
 *   1.5.8 (2026-06-10) — New /masterc/v1/rankmath-redirect endpoint: insert deterministic 301
 *                        redirect in wp_rank_math_redirections via RankMathRedirectionsDB::add
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
require_once __DIR__ . '/includes/class-sfb-performance.php';
require_once __DIR__ . '/includes/class-sfb-url-bases.php';
require_once __DIR__ . '/includes/class-sfb-order-received-alias.php';
require_once __DIR__ . '/includes/class-sfb-tracking-health.php';
new SFB_Hardening();
// Trebuie instanțiat devreme: se agață de `request` și `rewrite_rules_array`.
new SFB_URL_Bases();

// Punte peste gateway-urile care hardcodează endpointul englez `order-received`
// (ex. Fusion Pay TBI v3.0). No-op pe site-urile cu endpoint netradus.
new SFB_Order_Received_Alias();

// Marcaj tehnic pe comandă: ce a plecat efectiv din browser pe pagina de mulțumire (v1.8.0).
new SFB_Tracking_Health();

// Regenerează regulile de rescriere când se schimbă vreo bifă de bază URL.
foreach ( SFB_URL_Bases::options() as $sfbtk_url_opt ) {
    add_action( "update_option_{$sfbtk_url_opt}", function () {
        add_action( 'shutdown', function () { flush_rewrite_rules( false ); } );
    } );
    add_action( "add_option_{$sfbtk_url_opt}", function () {
        add_action( 'shutdown', function () { flush_rewrite_rules( false ); } );
    } );
}
unset( $sfbtk_url_opt );

// Activare/dezactivare: DOAR regenerăm regulile de rescriere.
// ⚠️ NU reseta bifele la dezactivare. Pe un magazin care depinde de modul, o dezactivare
// temporară (debug, conflict de plugin) urmată de reactivare ar lăsa bifele OPRITE și ar
// schimba tăcut adresa fiecărui produs. Bifele sunt starea dorită de administrator și
// trebuie să supraviețuiască ciclului activ/inactiv; regulile se regenerează singure.
register_activation_hook( __FILE__, function () {
    flush_rewrite_rules( false );
} );
register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules( false );
} );
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
        'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $file ) ),
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
        'args'                => [
            'name'  => [
                'description'       => 'Option name — whitelist: sure*_ / litespeed.conf.* / whl_page',
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => fn( $v ) => is_string( $v ) && (bool) preg_match( '/^(surecookie|suremembers|suredash|surerank)_|^litespeed\.conf\.|^whl_page$/', $v ),
            ],
            'value' => [
                'description' => 'POST only: option value (JSON object sau string; string-urile JSON se decodează în callback)',
                'required'    => false,
            ],
        ],
    ] );

    register_rest_route( 'masterc/v1', '/options-list', [
        'methods'             => 'GET',
        'callback'            => function ( $request ) {
            global $wpdb;
            $prefix = $request->get_param( 'prefix' );
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
        'args'                => [
            'prefix' => [
                'description'       => 'Prefix opțiuni (enum)',
                'type'              => 'string',
                'default'           => 'surecookie',
                'enum'              => [ 'surecookie', 'suremembers', 'suredash', 'surerank' ],
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
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
        'args'                => [
            'filename'       => [
                'description'       => 'Nume fișier traducere: {textdomain}-{locale}[-{md5}].{po|mo|json}',
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_file_name',
                'validate_callback' => fn( $v ) => is_string( $v ) && (bool) preg_match( '/^[a-z0-9_-]+-[a-z]{2,3}_[A-Z]{2}(-[a-f0-9]{32})?\.(po|mo|json)$/', $v ),
            ],
            'type'           => [
                'description' => 'Destinație în WP_LANG_DIR',
                'type'        => 'string',
                'required'    => true,
                'enum'        => [ 'plugins', 'themes' ],
            ],
            'content_base64' => [
                'description'       => 'Conținut fișier, base64 (max 5MB decodat — verificat în callback)',
                'type'              => 'string',
                'required'          => true,
                'validate_callback' => fn( $v ) => is_string( $v ) && '' !== $v,
            ],
        ],
    ] );

    // RankMath redirect insert — paritate F6 cu SureRank /redirection (RankMath n-are REST pt redirect arbitrar).
    register_rest_route( 'masterc/v1', '/rankmath-redirect', [
        'methods'             => 'POST',
        'callback'            => 'sfbtk_rankmath_redirect',
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );

    // WPML translation linking — vezi sfbtk_wpml_link() pentru motivatie completa.
    register_rest_route( 'masterc/v1', '/wpml-link', [
        'methods'             => 'POST',
        'callback'            => 'sfbtk_wpml_link',
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
        'args'                => [
            'source_id'    => [ 'required' => true,  'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0, 'sanitize_callback' => 'absint' ],
            'target_id'    => [ 'required' => true,  'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0, 'sanitize_callback' => 'absint' ],
            'source_lang'  => [ 'required' => true,  'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^[a-z]{2}(-[a-z]{2,4})?$/i', $v ) ],
            'target_lang'  => [ 'required' => true,  'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^[a-z]{2}(-[a-z]{2,4})?$/i', $v ) ],
            'element_type' => [ 'required' => false, 'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^(post|tax)_[a-z0-9_-]+$/', $v ) ],
            'original'     => [ 'required' => false, 'validate_callback' => fn( $v ) => in_array( $v, [ 'source', 'default_language' ], true ) ],
            'flush'        => [ 'required' => false, 'validate_callback' => fn( $v ) => is_bool( $v ) || in_array( $v, [ '0', '1', 0, 1, 'true', 'false' ], true ) ],
        ],
    ] );
} );

// ── 2.2. WPML URL MAPPING ───────────────────────────────────────────────────
// DE CE EXISTA: la traducerea unui articol, linkurile interne din original arata spre pagini in
// limba SURSA. Modelul de limbaj le „traduce" — inventeaza slug-uri care nu exista (masurat pe
// safebiz: /hu/wordpress-weboldal-karbantartas/ → /ro/mentenanta-website-wordpress/, 404, si era
// chiar butonul de CTA). Singura sursa corecta pentru URL-ul echivalent e perechea WPML.
// Ruta primeste URL-urile din original si intoarce echivalentul lor in limba tinta.
//
// ⚠️ `$return_original_if_missing = false` — INTENTIONAT. Daca pagina nu are versiune in limba
// tinta vrem `null`, ca apelantul sa scoata linkul; `true` ar intoarce pagina din limba sursa si
// am trimite cititorul romana pe o pagina maghiara. (Exact bug-ul SEO-T6 din sitemap.)
add_action( 'rest_api_init', function () {
    if ( ! get_option( 'sfbtk_nonce_enabled', 1 ) ) return;

    register_rest_route( 'masterc/v1', '/wpml-map-urls', [
        'methods'             => 'POST',
        'callback'            => 'sfbtk_wpml_map_urls',
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
        'args'                => [
            'urls'           => [ 'required' => false, 'validate_callback' => fn( $v ) => is_array( $v ) && count( $v ) <= 200 ],
            'attachment_ids' => [ 'required' => false, 'validate_callback' => fn( $v ) => is_array( $v ) && count( $v ) <= 50 ],
            'target_lang'    => [ 'required' => true,  'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^[a-z]{2}(-[a-z]{2,4})?$/i', $v ) ],
        ],
    ] );
} );

function sfbtk_wpml_map_urls( $request ) {
    if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
        return new WP_Error( 'wpml_inactive', 'WPML nu e activ pe acest site', [ 'status' => 409 ] );
    }
    $urls        = (array) $request->get_param( 'urls' );
    $target_lang = strtolower( (string) $request->get_param( 'target_lang' ) );

    $home = untrailingslashit( home_url() );
    $map = [];
    foreach ( $urls as $raw ) {
        $url = esc_url_raw( (string) $raw );
        if ( '' === $url ) { continue; }
        // Doar linkuri interne — pe cele externe nu le atingem.
        if ( 0 !== strpos( $url, $home ) ) { $map[ $raw ] = [ 'status' => 'external' ]; continue; }

        $post_id = url_to_postid( $url );
        if ( ! $post_id ) { $map[ $raw ] = [ 'status' => 'unknown_url' ]; continue; }

        $post_type   = get_post_type( $post_id );
        $translated  = apply_filters( 'wpml_object_id', $post_id, $post_type, false, $target_lang );
        if ( ! $translated ) { $map[ $raw ] = [ 'status' => 'no_translation', 'source_post_id' => (int) $post_id ]; continue; }

        // ⚠️ NU `get_permalink( $translated )` direct — vezi sfbtk_wpml_permalink_in_lang().
        // Fara comutarea limbii intoarce adresa in limba CURENTA, nu in cea tinta: la ro→hu toate
        // linkurile ar veni inapoi ca adrese ROMANESTI, cu `status: ok`.
        $permalink = sfbtk_wpml_permalink_in_lang( $translated, $target_lang );
        if ( ! $permalink ) { $map[ $raw ] = [ 'status' => 'no_permalink', 'source_post_id' => (int) $post_id ]; continue; }

        $map[ $raw ] = [
            'status'         => 'ok',
            'source_post_id' => (int) $post_id,
            'target_post_id' => (int) $translated,
            'url'            => $permalink,
        ];
    }
    // Imaginile: aceeasi problema, alt obiect. Fara maparea asta, traducerea mosteneste imaginea
    // originalului — cu textul din ea, cu numele de fisier si cu textul alternativ in limba sursei.
    // Masurat pe safebiz: 2 din 4 articole romanesti aratau imagini cu text maghiar pe ele.
    $media = [];
    foreach ( (array) $request->get_param( 'attachment_ids' ) as $raw_id ) {
        $id = absint( $raw_id );
        if ( ! $id || 'attachment' !== get_post_type( $id ) ) { $media[ $raw_id ] = [ 'status' => 'not_attachment' ]; continue; }
        $tr = apply_filters( 'wpml_object_id', $id, 'attachment', false, $target_lang );
        if ( ! $tr ) { $media[ $raw_id ] = [ 'status' => 'no_translation' ]; continue; }
        $media[ $raw_id ] = [
            'status'    => 'ok',
            'target_id' => (int) $tr,
            'url'       => wp_get_attachment_url( $tr ),
            'alt'       => get_post_meta( $tr, '_wp_attachment_image_alt', true ),
        ];
    }

    return [ 'target_lang' => $target_lang, 'map' => $map, 'media' => $media ];
}

// ── 2.3. CATEGORII PE TRADUCERE ─────────────────────────────────────────────
// DE CE O RUTA SEPARATA, si nu `POST /wp/v2/posts/{id}` cu `categories`:
// 🔴 WPML REMAPEAZA ID-URILE DE TERMEN DUPA LIMBA CONTEXTULUI, la citire SI la scriere.
// Incident real (safebiz, 18 aug): `wp_set_object_terms($post_hu, [120])` rulat in context RO a
// scris de fapt termenul 109 (perechea RO), iar stergerea rulata in context HU a sters 120. Ambele
// „au reusit". Si `wp_get_post_terms()` minte la fel, deci nici verificarea nu se putea face cu el.
//
// Ruta asta face trei lucruri pe care apelul REST simplu nu le poate face:
//   1. rezolva perechea termenului in limba tinta (`wpml_object_id`, fara fallback pe original);
//   2. COMUTA contextul WPML pe limba postului inainte de scriere, si il pune la loc dupa;
//   3. CITESTE INAPOI cu SQL brut (JOIN pe wp_terms) — singura verificare care nu minte.
add_action( 'rest_api_init', function () {
    if ( ! get_option( 'sfbtk_nonce_enabled', 1 ) ) return;

    register_rest_route( 'masterc/v1', '/wpml-set-terms', [
        'methods'             => 'POST',
        'callback'            => 'sfbtk_wpml_set_terms',
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
        'args'                => [
            'post_id'          => [ 'required' => true, 'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0, 'sanitize_callback' => 'absint' ],
            'post_lang'        => [ 'required' => true, 'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^[a-z]{2}(-[a-z]{2,4})?$/i', $v ) ],
            'source_term_ids'  => [ 'required' => true, 'validate_callback' => fn( $v ) => is_array( $v ) && count( $v ) <= 20 ],
            'taxonomy'         => [ 'required' => false, 'validate_callback' => fn( $v ) => is_string( $v ) && taxonomy_exists( $v ) ],
        ],
    ] );
} );

function sfbtk_wpml_set_terms( $request ) {
    if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
        return new WP_Error( 'wpml_inactive', 'WPML nu e activ pe acest site', [ 'status' => 409 ] );
    }
    global $wpdb;

    $post_id   = (int) $request->get_param( 'post_id' );
    $post_lang = strtolower( (string) $request->get_param( 'post_lang' ) );
    $taxonomy  = (string) ( $request->get_param( 'taxonomy' ) ?: 'category' );
    $src_ids   = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'source_term_ids' ) ) ) );

    if ( ! get_post( $post_id ) ) {
        return new WP_Error( 'not_found', "postul {$post_id} nu exista", [ 'status' => 404 ] );
    }

    // 1. Perechea fiecarui termen in limba postului. Fara pereche ⇒ raportam, NU punem originalul:
    //    o categorie romaneasca pe un articol maghiar rupe arhiva si amesteca limbile.
    $resolved = [];
    $missing  = [];
    foreach ( $src_ids as $sid ) {
        $tid = apply_filters( 'wpml_object_id', $sid, $taxonomy, false, $post_lang );
        if ( $tid ) {
            $t = get_term( $tid, $taxonomy );
            $resolved[] = [ 'source' => $sid, 'target' => (int) $tid, 'slug' => $t && ! is_wp_error( $t ) ? $t->slug : null ];
        } else {
            $t = get_term( $sid, $taxonomy );
            $missing[] = [ 'source' => $sid, 'slug' => $t && ! is_wp_error( $t ) ? $t->slug : null ];
        }
    }

    $target_ids = array_map( fn( $r ) => $r['target'], $resolved );

    // 2. Scrierea, cu contextul de limba fixat pe limba POSTULUI (altfel WPML remapeaza tacit).
    $applied = false;
    if ( $target_ids ) {
        $previous = apply_filters( 'wpml_current_language', null );
        do_action( 'wpml_switch_language', $post_lang );
        $res = wp_set_object_terms( $post_id, $target_ids, $taxonomy, false );
        do_action( 'wpml_switch_language', $previous );
        $applied = ! is_wp_error( $res );
        if ( ! $applied ) {
            return new WP_Error( 'set_terms_failed', $res->get_error_message(), [ 'status' => 500 ] );
        }
        wp_update_term_count_now( $target_ids, $taxonomy );
        clean_post_cache( $post_id );
    }

    // 3. DOVADA: SQL brut. `wp_get_post_terms()` trece prin acelasi filtru care remapeaza.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT t.term_id, t.name, t.slug
           FROM {$wpdb->term_relationships} tr
           JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
           JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
          WHERE tr.object_id = %d AND tt.taxonomy = %s",
        $post_id, $taxonomy
    ), ARRAY_A );

    $actual = array_map( fn( $r ) => (int) $r['term_id'], (array) $rows );
    sort( $actual );
    $expected = $target_ids;
    sort( $expected );

    return [
        'ok'        => $expected === $actual,
        'post_id'   => $post_id,
        'post_lang' => $post_lang,
        'taxonomy'  => $taxonomy,
        'resolved'  => $resolved,
        'missing'   => $missing,
        'actual'    => $rows,
    ];
}

// ── 2.3. WPML — ADRESA CORECTA A UNEI TRADUCERI ─────────────────────────────
// DE CE EXISTA: `get_permalink( $id_tradus )` NU intoarce adresa in limba postului, ci in limba
// CURENTA a cererii. Masurat live pe safebiz (19 aug 2026, context `ro`):
//
//   get_permalink(35431) /* fooldal,      pagina de start HU */ = https://safebiz.ro/
//   get_permalink(35853) /* elerhetoseg,  contact HU         */ = https://safebiz.ro/contact/
//   get_permalink(37979) /* srl-cegalapitas, articol HU      */ = https://safebiz.ro/blog/infiintare-firma-srl-8-pasi.../
//
// Toate trei sunt adrese ROMANESTI pentru posturi MAGHIARE. Cu limba comutata pe `hu` ies corect:
//
//   https://safebiz.ro/hu/  ·  https://safebiz.ro/hu/elerhetoseg/  ·  https://safebiz.ro/hu/blog/srl-cegalapitas-8-lepesben/
//
// Comutarea rezolva si cazul special al paginii de start (slug gol), pe care WPML il trateaza prin
// logica proprie de radacina odata ce limba coincide. E strict mai bun decat
// `apply_filters('wpml_permalink', $url, $lang, false)`, care prefixeaza limba dar NU traduce
// slug-ul (`/hu/contact/` in loc de `/hu/elerhetoseg/`) si ar rupe perechile corecte.
function sfbtk_wpml_permalink_in_lang( $post_id, $lang ) {
    $post_id = (int) $post_id;
    $lang    = (string) $lang;
    if ( ! $post_id ) { return ''; }
    if ( ! defined( 'ICL_SITEPRESS_VERSION' ) || '' === $lang ) { return (string) get_permalink( $post_id ); }

    $current = (string) apply_filters( 'wpml_current_language', null );
    if ( $current === $lang ) { return (string) get_permalink( $post_id ); }

    do_action( 'wpml_switch_language', $lang );
    $url = get_permalink( $post_id );
    do_action( 'wpml_switch_language', $current );

    return is_string( $url ) ? $url : '';
}

// Acelasi lucru pentru termeni. `get_term_link()` are exact aceeasi problema — masurat pe csagasa:
// categoria „Portofoliu" are pereche reala (1 ↔ 2), dar in context `ro` AMBELE ID-uri intorc
// https://csagasa.ro/portofoliu/, deci sitemap-ul declara „versiunea maghiara = pagina romaneasca".
//
// ⚠️ WPML REMAPEAZA ID-ul de termen dupa limba curenta: cu limba pe `hu`, si get_term_link(1) si
// get_term_link(2) intorc https://csagasa.ro/hu/portfolio/. Deci apelul e sigur DOAR pentru limbi
// in care perechea chiar exista — de aceea se cheama numai dupa ce `wpml_object_id(..., FALSE, ...)`
// a confirmat traducerea. Non-regresie verificata pe cele 10 perechi de categorii safebiz:
// adresele recalculate ies identice cu cele deja publicate.
function sfbtk_wpml_term_link_in_lang( $term_id, $taxonomy, $lang ) {
    $term_id = (int) $term_id;
    $lang    = (string) $lang;
    if ( ! $term_id ) { return ''; }

    $get = static function () use ( $term_id, $taxonomy ) {
        $link = get_term_link( $term_id, $taxonomy );
        return is_wp_error( $link ) ? '' : (string) $link;
    };

    if ( ! defined( 'ICL_SITEPRESS_VERSION' ) || '' === $lang ) { return $get(); }

    $current = (string) apply_filters( 'wpml_current_language', null );
    if ( $current === $lang ) { return $get(); }

    do_action( 'wpml_switch_language', $lang );
    $url = $get();
    do_action( 'wpml_switch_language', $current );

    return $url;
}

// Termenul citit IN limba lui — pentru nume/slug/descriere corecte pe intrarea noua de sitemap.
// (`Translation_Manager::get_translated_term_name()` citeste fara comutare si intoarce numele din
// limba sursei; noi vrem numele real din limba tintei.)
function sfbtk_wpml_get_term_in_lang( $term_id, $taxonomy, $lang ) {
    $current = defined( 'ICL_SITEPRESS_VERSION' ) ? (string) apply_filters( 'wpml_current_language', null ) : '';
    $switch  = ( '' !== $current && '' !== $lang && $current !== $lang );

    if ( $switch ) { do_action( 'wpml_switch_language', $lang ); }
    $term = get_term( (int) $term_id, $taxonomy );
    if ( $switch ) { do_action( 'wpml_switch_language', $current ); }

    return ( $term instanceof WP_Term ) ? $term : null;
}

// ── 2.4. SURERANK — REPARAREA hreflang DIN SITEMAP ──────────────────────────
// DE CE EXISTA: `surerank/inc/third-party-integrations/multilingual/providers/wpml.php` are DOUA
// defecte, ambele confirmate in sursa publica 1.9.3 SI 1.10.0 (analiza: §18, §20 din
// projects/safebiz/ANALIZA-CANIBALIZARE-TRADUCERI-2026-08-18.md):
//
//   CAUZA A (linia 40 posturi / 145 termeni) — `wpml_object_id(..., $return_original_if_missing =
//   TRUE, ...)`. Cand nu exista traducere, WPML intoarce postul INSUSI, iar SureRank il scrie ca
//   alternativa pentru limba lipsa. Rezultat masurat pe safebiz: 27 din 70 de adrese declarau
//   `hreflang="hu-HU"` catre propria adresa romaneasca. Ca dovada ca e o scapare, nu o intentie:
//   `is_translation_available()` din ACELASI fisier are garda `$translated_id !== $post_id`.
//
//   CAUZA B (linia 46) — `get_permalink( $translated_id )`, vezi 2.3. Loveste pagina de start
//   chiar si cand perechea EXISTA (165 ↔ 35431 pe safebiz). Nu e specifica WPML: `polylang.php`
//   face acelasi apel, iar un fir public de suport o raporteaza pe TranslatePress.
//
// CE FACE FILTRUL, la prioritatea 20 (Translation_Manager ruleaza la 10, deci tabloul exista deja):
//   1. cere perechea reala cu `$return_original_if_missing = FALSE`; lipseste → SCOATE limba;
//   2. recalculeaza adresa cu 2.3; identica → nu atinge nimic;
//   3. adauga `<loc>` propriu pentru limbile ramase fara intrare (Google: „Create a separate
//      <url> element for each URL"). Singurul caz real e pagina de start: Translation_Manager sare
//      peste ea fiindca adresa tradusa era EGALA cu cea originala (efectul cauzei B).
//
// Termenii trec prin aceiasi trei pasi. Prima versiune (1.7.3) le dadea doar pasul 1, de teama
// remapului de ID dupa limba; csagasa a aratat ca nu e suficient (categoria „Portofoliu": pereche
// reala 1 ↔ 2, dar ambele ID-uri intorc adresa romaneasca in context `ro`). Remapul e real, dar
// inofensiv aici — vezi nota de la sfbtk_wpml_term_link_in_lang(). Non-regresie masurata pe cele
// 10 perechi de categorii safebiz: adrese identice inainte si dupa.
//
// Comutator de urgenta: optiunea `sfbtk_sr_hreflang_fix` = 0 opreste filtrul fara redeploy.
// Semnal de viata: `sfbtk_sr_hreflang_stats` — daca ramane vechi dupa o regenerare de sitemap,
// SureRank si-a redenumit carligele si filtrul a devenit inert TACIT.
add_filter( 'surerank_sitemap_sync_posts_post_data', 'sfbtk_sr_fix_post_hreflang', 20, 2 );
add_filter( 'surerank_sitemap_sync_terms_term_data', 'sfbtk_sr_fix_term_hreflang', 20, 2 );

function sfbtk_sr_fix_post_hreflang( $data, $post ) {
    if ( ! ( $post instanceof WP_Post ) ) { return $data; }
    return sfbtk_sr_fix_hreflang( $data, $post->ID, $post->post_type, 'post' );
}

function sfbtk_sr_fix_term_hreflang( $data, $term ) {
    if ( ! ( $term instanceof WP_Term ) ) { return $data; }
    return sfbtk_sr_fix_hreflang( $data, $term->term_id, $term->taxonomy, 'term' );
}

function sfbtk_sr_fix_hreflang( $data, $object_id, $object_type, $kind ) {
    if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) { return $data; }
    if ( ! get_option( 'sfbtk_sr_hreflang_fix', 1 ) ) { return $data; }
    if ( empty( $data ) || ! is_array( $data ) ) { return $data; }

    // Aceeasi conventie de detectie ca SureRank (`inc/batch-process/sync-posts.php`).
    $was_list = isset( $data[0] ) && is_array( $data[0] );
    $entries  = $was_list ? array_values( $data ) : [ $data ];
    $base     = $entries[0];

    if ( empty( $base['translations'] ) || ! is_array( $base['translations'] ) ) { return $data; }

    $default_lang = isset( $base['default_language'] ) && '' !== $base['default_language']
        ? (string) $base['default_language']
        : (string) apply_filters( 'wpml_default_language', '' );

    $dropped = 0;
    $rewrote = 0;
    $fixed   = [];

    foreach ( $base['translations'] as $lang => $tr ) {
        $real = apply_filters( 'wpml_object_id', $object_id, $object_type, false, $lang );
        if ( empty( $real ) ) { $dropped++; continue; }                       // CAUZA A

        // CAUZA B — recalcularea adresei in limba tinta.
        $url = ( 'term' === $kind )
            ? sfbtk_wpml_term_link_in_lang( (int) $real, (string) $object_type, (string) $lang )
            : sfbtk_wpml_permalink_in_lang( (int) $real, (string) $lang );

        // Plasa de siguranta: pastram valoarea SureRank daca recalcularea iese goala sau pe alta
        // gazda. Mai bine o adresa veche decat una inventata de noi.
        if ( is_string( $url ) && '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
            if ( $url !== ( $tr['url'] ?? '' ) ) { $rewrote++; }
            $tr['url'] = $url;
        }

        $fixed[ $lang ] = $tr;
    }

    if ( empty( $fixed ) ) { return $data; }   // nu golim niciodata complet — lasam SureRank in pace

    $added = 0;

    if ( $dropped || $rewrote ) {
        foreach ( $entries as $i => $unused ) { $entries[ $i ]['translations'] = $fixed; }
        $base = $entries[0];
    }

    // Pas 3 — `<loc>` propriu pentru fiecare limba ramasa fara intrare proprie.
    // Google: „Create a separate <url> element for each URL as you would with any other sitemap."
    // In mod normal Translation_Manager le adauga singur (prioritate 10); sare peste ele exact cand
    // adresa tradusa iesea EGALA cu originalul — adica fix efectul cauzei B, pe care tocmai am
    // reparat-o. Masurat: pagina de start pe safebiz/mindformers/avocatkisiulia, categoria
    // „Portofoliu" pe csagasa.
    $links = [];
    foreach ( $entries as $e ) { if ( ! empty( $e['link'] ) ) { $links[] = $e['link']; } }

    foreach ( $fixed as $lang => $tr ) {
        if ( $lang === $default_lang || empty( $tr['url'] ) ) { continue; }
        if ( in_array( $tr['url'], $links, true ) ) { continue; }

        $real = apply_filters( 'wpml_object_id', $object_id, $object_type, false, $lang );
        if ( empty( $real ) ) { continue; }

        $new                     = $base;
        $new['link']             = $tr['url'];
        $new['translations']     = $fixed;
        $new['default_language'] = $default_lang;

        if ( 'term' === $kind ) {
            $term = sfbtk_wpml_get_term_in_lang( (int) $real, (string) $object_type, (string) $lang );
            if ( ! $term ) { continue; }
            $new['id']          = (int) $term->term_id;
            $new['name']        = $term->name;
            $new['slug']        = $term->slug;
            $new['taxonomy']    = $term->taxonomy;
            $new['description'] = $term->description;
            $new['count']       = $term->count;
        } else {
            $new['id']    = (int) $object_id;
            $new['title'] = get_the_title( (int) $real );
        }

        $entries[] = $new;
        $links[]   = $tr['url'];
        $added++;
    }

    if ( ! $dropped && ! $rewrote && ! $added ) { return $data; }   // no-op curat

    sfbtk_sr_hreflang_bump( $dropped, $rewrote, $added );

    return ( ! $was_list && 1 === count( $entries ) ) ? $entries[0] : $entries;
}

// Semnal de viata + contoare, ca sa se vada daca filtrul a devenit inert dupa un update SureRank.
// Acumulam in memorie si scriem O SINGURA data pe cerere (la `shutdown`) — o regenerare de sitemap
// trece prin filtru de zeci de ori si n-are rost sa lovim `wp_options` la fiecare intrare.
function &sfbtk_sr_hreflang_run() {
    static $run = [ 'dropped' => 0, 'rewrote' => 0, 'added' => 0, 'hooked' => false ];
    return $run;
}

function sfbtk_sr_hreflang_bump( $dropped, $rewrote, $added ) {
    $run = &sfbtk_sr_hreflang_run();
    $run['dropped'] += (int) $dropped;
    $run['rewrote'] += (int) $rewrote;
    $run['added']   += (int) $added;

    if ( ! $run['hooked'] ) {
        $run['hooked'] = true;
        add_action( 'shutdown', 'sfbtk_sr_hreflang_persist', 99 );
    }
}

function sfbtk_sr_hreflang_persist() {
    $run = &sfbtk_sr_hreflang_run();
    update_option( 'sfbtk_sr_hreflang_stats', [
        'last_run' => current_time( 'mysql' ),
        'dropped'  => $run['dropped'],
        'rewrote'  => $run['rewrote'],
        'added'    => $run['added'],
        'surerank' => defined( 'SURERANK_VERSION' ) ? SURERANK_VERSION : 'necunoscut',
    ], false );
}

// ── 2.1. WPML TRANSLATION LINKING ───────────────────────────────────────────
// DE CE EXISTA: WPML nu poate lega o traducere prin REST. Parametrul `translation_of` trimis in
// corpul JSON catre /wp/v2/posts NU e un camp REST inregistrat (WP il arunca tacit), iar WPML
// citeste doar `icl_translation_of` din $_POST — si doar in `WPML_Admin_Post_Actions`, care nici
// nu se incarca pe un apel REST obisnuit (`inc/functions-load.php`: fara referer de pe pagina de
// editare si fara WP-CLI se incarca `WPML_Frontend_Post_Actions`, al carui `get_save_post_trid()`
// intoarce trid-ul postului INSUSI). Rezultat: fiecare traducere publicata prin REST se naste cu
// trid propriu, ca document independent. Nu exista nicio ruta REST WPML de linking (76 verificate).
// Hookurile din `SitePress::api_hooks()` sunt insa inregistrate NECONDITIONAT, deci merg pe REST.
//
// CE FACE IN PLUS fata de un simplu do_action:
//   1. citeste inapoi `wp_icl_translations` ca dovada (HTML-ul trece prin cache si minte ore intregi);
//   2. goleste `wpml_resolved_url_persist` — harta in care WPML memoreaza SI raspunsurile negative
//      („URL-ul asta n-are traducere in X"). `wpml_set_element_language_details` NU e in lista ei de
//      invalidare, deci fara pasul asta perechea e corecta in baza de date si invizibila in sitemap;
//   3. reseteaza checksum-ul de sitemap SureRank + cache-ul de pagina.
// (Diagnostic complet: projects/safebiz/ANALIZA-CANIBALIZARE-TRADUCERI-2026-08-18.md §13-B, §14, §16.)
//
// DIRECTIA CONTEAZA — decide ce URL primeste `x-default` si cine e „originalul" pentru Google:
//   original=source            → `source_id` ramane originalul (implicit; apelantul decide)
//   original=default_language  → originalul e postul aflat in limba IMPLICITA a site-ului, oricare
//                                ar fi el; pe un site cu default `ro` asta tine `x-default` pe romana
//                                si la traducerile ro→hu, si la cele hu→ro. Recomandat pentru conducta.
function sfbtk_wpml_link( $request ) {
    if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
        return new WP_Error( 'wpml_inactive', 'WPML (sitepress-multilingual-cms) nu e activ pe acest site', [ 'status' => 409 ] );
    }

    $source_id    = (int) $request->get_param( 'source_id' );
    $target_id    = (int) $request->get_param( 'target_id' );
    $source_lang  = strtolower( (string) $request->get_param( 'source_lang' ) );
    $target_lang  = strtolower( (string) $request->get_param( 'target_lang' ) );
    $element_type = (string) ( $request->get_param( 'element_type' ) ?: 'post_post' );
    $flush        = null === $request->get_param( 'flush' ) ? true : filter_var( $request->get_param( 'flush' ), FILTER_VALIDATE_BOOLEAN );

    if ( $source_id === $target_id ) {
        return new WP_Error( 'same_element', 'source_id si target_id sunt identice', [ 'status' => 400 ] );
    }
    if ( $source_lang === $target_lang ) {
        return new WP_Error( 'same_language', 'source_lang si target_lang sunt identice', [ 'status' => 400 ] );
    }

    // Inversare de directie cand originalul trebuie sa fie postul din limba implicita a site-ului.
    $original_mode    = (string) ( $request->get_param( 'original' ) ?: 'source' );
    $default_language = (string) apply_filters( 'wpml_default_language', null );
    $flipped          = false;
    if ( 'default_language' === $original_mode && $default_language ) {
        if ( $target_lang === $default_language && $source_lang !== $default_language ) {
            [ $source_id, $target_id ]     = [ $target_id, $source_id ];
            [ $source_lang, $target_lang ] = [ $target_lang, $source_lang ];
            $flipped = true;
        } elseif ( $source_lang !== $default_language && $target_lang !== $default_language ) {
            return new WP_Error(
                'no_default_language_side',
                "original=default_language, dar niciuna dintre limbi ({$source_lang}, {$target_lang}) nu e limba implicita ({$default_language})",
                [ 'status' => 400 ]
            );
        }
    }

    // Elementele trebuie sa existe si sa corespunda cu element_type (post_{cpt} = post real de acel tip).
    if ( 0 === strpos( $element_type, 'post_' ) ) {
        $expected_type = substr( $element_type, 5 );
        foreach ( [ 'source_id' => $source_id, 'target_id' => $target_id ] as $label => $pid ) {
            $p = get_post( $pid );
            if ( ! $p ) {
                return new WP_Error( 'not_found', "{$label}={$pid} nu exista", [ 'status' => 404 ] );
            }
            if ( $p->post_type !== $expected_type ) {
                return new WP_Error( 'type_mismatch', "{$label}={$pid} e '{$p->post_type}', nu '{$expected_type}' (element_type={$element_type})", [ 'status' => 400 ] );
            }
        }
    }

    $before = sfbtk_wpml_read_translation_rows( [ $source_id, $target_id ], $element_type );

    $trid = apply_filters( 'wpml_element_trid', null, $source_id, $element_type );
    if ( ! $trid ) {
        return new WP_Error( 'no_trid', "sursa {$source_id} nu are trid in WPML (element_type={$element_type}) — nu e inregistrata ca element traductibil", [ 'status' => 409 ] );
    }

    // Sursa TREBUIE sa fie originalul; altfel am lega traducerea de traducere si am rupe grupul.
    $src_row = $before[ $source_id ] ?? null;
    if ( $src_row && null !== $src_row['source_language_code'] && '' !== $src_row['source_language_code'] ) {
        return new WP_Error(
            'source_is_translation',
            "sursa {$source_id} e ea insasi o traducere (source_language_code='{$src_row['source_language_code']}') — leaga de originalul grupului, nu de ea",
            [ 'status' => 409 ]
        );
    }

    // Idempotenta: deja in acelasi grup, cu limba si directia corecte ⇒ nu rescriem nimic.
    $tgt_row  = $before[ $target_id ] ?? null;
    $already  = $tgt_row
        && (int) $tgt_row['trid'] === (int) $trid
        && $tgt_row['language_code'] === $target_lang
        && $tgt_row['source_language_code'] === $source_lang;

    if ( ! $already ) {
        do_action( 'wpml_set_element_language_details', [
            'element_id'           => $target_id,
            'element_type'         => $element_type,
            'trid'                 => (int) $trid,
            'language_code'        => $target_lang,
            'source_language_code' => $source_lang,
        ] );
    }

    // DOVADA: citim inapoi din tabel, nu din hreflang-ul HTML (care e servit din cache).
    $after  = sfbtk_wpml_read_translation_rows( [ $source_id, $target_id ], $element_type );
    $ok_row = $after[ $target_id ] ?? null;
    $linked = $ok_row
        && (int) $ok_row['trid'] === (int) $trid
        && $ok_row['language_code'] === $target_lang
        && $ok_row['source_language_code'] === $source_lang;

    $flushed = [];
    if ( $linked && $flush ) {
        $flushed = sfbtk_wpml_flush_url_maps();
    }

    if ( ! $linked ) {
        return new WP_Error( 'link_failed', 'WPML a acceptat apelul dar randul din wp_icl_translations nu confirma legarea', [
            'status' => 500,
            'trid'   => (int) $trid,
            'before' => $before,
            'after'  => $after,
        ] );
    }

    return [
        'linked'           => true,
        'changed'          => ! $already,
        'trid'             => (int) $trid,
        'element_type'     => $element_type,
        'original_id'      => $source_id,
        'translation_id'   => $target_id,
        'direction_mode'   => $original_mode,
        'direction_flipped' => $flipped,
        'default_language' => $default_language ?: null,
        'source'           => $after[ $source_id ] ?? null,
        'target'           => $after[ $target_id ] ?? null,
        'flushed'          => $flushed,
    ];
}

// Citire directa din wp_icl_translations — sursa de adevar pentru starea unei perechi WPML.
function sfbtk_wpml_read_translation_rows( array $element_ids, string $element_type ) {
    global $wpdb;
    $ids = array_map( 'intval', $element_ids );
    if ( ! $ids ) { return []; }
    $in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $table = $wpdb->prefix . 'icl_translations';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in e generat din placeholders, $table din prefix
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT element_id, trid, language_code, source_language_code FROM {$table} WHERE element_type = %s AND element_id IN ({$in})",
        array_merge( [ $element_type ], $ids )
    ), ARRAY_A );

    $out = [];
    foreach ( (array) $rows as $r ) {
        $out[ (int) $r['element_id'] ] = [
            'element_id'           => (int) $r['element_id'],
            'trid'                 => (int) $r['trid'],
            'language_code'        => $r['language_code'],
            'source_language_code' => $r['source_language_code'],
        ];
    }
    return $out;
}

// Golirea hartilor care fac perechea VIZIBILA. Fara ele legarea e corecta si invizibila.
function sfbtk_wpml_flush_url_maps() {
    $done = [];

    // 1. Harta persistenta WPML de URL-uri traduse — memoreaza SI `false`, si nu se invalideaza
    //    la `wpml_set_element_language_details`. Asta a tinut articole HU in afara sitemap-ului.
    if ( class_exists( 'WPML_Absolute_Url_Persisted' ) && method_exists( 'WPML_Absolute_Url_Persisted', 'get_instance' ) ) {
        $inst = WPML_Absolute_Url_Persisted::get_instance();
        if ( $inst && method_exists( $inst, 'reset' ) ) {
            $inst->reset();
            $done[] = 'wpml_resolved_url_persist:reset';
        }
    }
    if ( ! in_array( 'wpml_resolved_url_persist:reset', $done, true ) && get_option( 'wpml_resolved_url_persist' ) !== false ) {
        delete_option( 'wpml_resolved_url_persist' );
        $done[] = 'wpml_resolved_url_persist:delete_option';
    }

    // 2. Cache-ul intern WPML (non-persistent + object cache).
    if ( function_exists( 'icl_cache_clear' ) ) { icl_cache_clear(); $done[] = 'icl_cache_clear'; }
    if ( class_exists( 'WPML_Non_Persistent_Cache' ) && method_exists( 'WPML_Non_Persistent_Cache', 'flush' ) ) {
        WPML_Non_Persistent_Cache::flush();
        $done[] = 'wpml_non_persistent_cache';
    }

    // 3. Sitemap SureRank — checksum + fisierele generate.
    if ( class_exists( '\SureRank\Inc\Sitemap\Checksum' ) ) {
        $cls = '\SureRank\Inc\Sitemap\Checksum';
        $obj = method_exists( $cls, 'get_instance' ) ? $cls::get_instance() : new $cls();
        if ( $obj && method_exists( $obj, 'clear_checksum' ) ) { $obj->clear_checksum(); $done[] = 'surerank_checksum'; }
    }
    if ( class_exists( '\SureRank\Inc\Functions\Cache' ) && method_exists( '\SureRank\Inc\Functions\Cache', 'clear_all' ) ) {
        \SureRank\Inc\Functions\Cache::clear_all();
        $done[] = 'surerank_sitemap_files';
    }

    // 4. Cache de pagina.
    if ( has_action( 'litespeed_purge_all' ) ) { do_action( 'litespeed_purge_all' ); $done[] = 'litespeed'; }
    if ( function_exists( 'wp_cache_flush' ) ) { wp_cache_flush(); $done[] = 'object_cache'; }

    return $done;
}

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
    register_setting( 'sfbtk_options', 'sfbtk_tracking_health_enabled', [ 'type' => 'boolean', 'default' => 0 ] );
    // Bazele de URL — TOATE oprite implicit: activarea schimbă adrese publice.
    // 🔴 GRUP SEPARAT, obligatoriu. `wp-admin/options.php` parcurge TOATE opțiunile grupului
    // salvat și scrie `null` peste cele absente din POST (core, options.php:337-345). Cu două
    // formulare pe aceeași pagină și un singur grup, salvarea formularului de sus ar fi OPRIT
    // tăcut cele 4 bife de URL — adică tot catalogul ar fi revenit la adresele cu bază — iar
    // salvarea formularului de jos ar fi oprit File Verify / Nonce / Tracker / Inventory.
    // Semnalat de contra-verificarea Codex, confirmat pe sursa WordPress, 2026-08-11.
    foreach ( SFB_URL_Bases::options() as $opt ) {
        register_setting( 'sfbtk_url_options', $opt, [ 'type' => 'boolean', 'default' => 0 ] );
    }
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
                    <th>Tracking Health (marcaj pe comandă)</th>
                    <td>
                        <label>
                            <input type="checkbox" name="sfbtk_tracking_health_enabled" value="1"
                                <?php checked( 1, get_option( 'sfbtk_tracking_health_enabled', 0 ) ); ?> />
                            Activat (implicit OPRIT) — pe pagina de mulțumire WooCommerce scrie pe comandă (<code>_sfb_tracking_health</code>) ce a plecat efectiv din browser: GTM/GA4, pixel Meta, releu PYS, consimțământ. Doar da/nu și numărători, nimic către terți.
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

        <hr />
        <h2>Structura URL — paritate Rank Math</h2>
        <p class="description">
            Scoate bazele din adresele publice, așa cum făcea Rank Math. Necesar la migrarea către
            SureRank, care <strong>nu are echivalent</strong> pentru baza produsului și pentru slug-urile părinte.
            <strong>Activarea schimbă adrese publice</strong> — bifează doar ce era pornit înainte în Rank Math.
            Nu combina cu filtrele <code>surerank_remove_*_base</code>, s-ar aplica de două ori.
        </p>

        <?php
        $sfbtk_foreign = SFB_URL_Bases::foreign_handlers();
        if ( $sfbtk_foreign ) : ?>
            <div class="notice notice-error inline" style="margin:12px 0;padding:10px 12px;">
                <p style="margin:0 0 6px;"><strong>⛔ Alt mecanism scoate deja bazele pe acest site — NU bifa</strong></p>
                <p style="margin:0 0 6px;">
                    Adresele sunt deja curate fără ca modulul să fie pornit, deci altcineva face treaba:
                    un router din tema copil, alt plugin SEO, sau filtrele <code>surerank_remove_*_base</code>.
                    Bifarea ar aplica mecanismul <strong>de două ori</strong>. Întâi scoate mecanismul vechi, apoi bifează.
                </p>
                <ul style="margin:0;list-style:disc inside;">
                    <?php foreach ( $sfbtk_foreign as $sfbtk_msg ) : ?>
                        <li><?php echo esc_html( $sfbtk_msg ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php
        $sfbtk_collisions = SFB_URL_Bases::collisions();
        if ( $sfbtk_collisions ) : ?>
            <div class="notice notice-warning inline" style="margin:12px 0;padding:10px 12px;">
                <p style="margin:0 0 6px;"><strong>⚠️ Coliziuni de slug produs ↔ categorie (<?php echo count( $sfbtk_collisions ); ?>)</strong></p>
                <p style="margin:0 0 6px;">
                    Cu baza produsului scoasă, <strong>produsul câștigă adresa</strong> (identic cu Rank Math),
                    iar categoria cu același nume devine inaccesibilă. Nu e un defect al modulului — e o
                    problemă de conținut. Remediu: redenumește slug-ul categoriei, cu redirect.
                </p>
                <p style="margin:0;"><code><?php echo esc_html( implode( '</code>, <code>', $sfbtk_collisions ) ); ?></code></p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'sfbtk_url_options' ); // grup SEPARAT — vezi nota de la register_setting ?>
            <table class="form-table">
                <?php
                $sfbtk_url_labels = [
                    'product_base'     => [ 'Scoate baza produsului', 'Rank Math: <em>Remove product base</em> — <code>/produs/nume/</code> → <code>/nume/</code>. Include un router la rădăcină și 301 de pe adresa veche. Cere WooCommerce.' ],
                    'product_cat_base' => [ 'Scoate baza categoriei de produs', 'Rank Math: <em>Remove category base</em> — <code>/product-category/nume/</code> → <code>/nume/</code>. Cere WooCommerce.' ],
                    'parent_slugs'     => [ 'Scoate slug-urile părinte', 'Rank Math: <em>Remove parent slugs</em> — <code>/parinte/copil/</code> → <code>/copil/</code>. Se aplică la categoriile de produs.' ],
                    'category_base'    => [ 'Scoate baza categoriei de blog', 'Rank Math: <em>Strip category base</em> — <code>/category/nume/</code> → <code>/nume/</code>.' ],
                ];
                foreach ( SFB_URL_Bases::options() as $sfbtk_key => $sfbtk_opt ) :
                    list( $sfbtk_title, $sfbtk_desc ) = $sfbtk_url_labels[ $sfbtk_key ];
                    ?>
                    <tr>
                        <th><?php echo esc_html( $sfbtk_title ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( $sfbtk_opt ); ?>" value="1"
                                    <?php checked( 1, get_option( $sfbtk_opt, 0 ) ); ?> />
                                Activat
                            </label>
                            <p class="description"><?php echo wp_kses_post( $sfbtk_desc ); ?></p>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <th>Adrese acum</th>
                    <td>
                        <table class="widefat striped" style="max-width:640px;">
                            <?php foreach ( SFB_URL_Bases::sample_urls() as $sfbtk_label => $sfbtk_url ) : ?>
                                <tr>
                                    <td style="width:180px;"><?php echo esc_html( $sfbtk_label ); ?></td>
                                    <td><code><?php echo esc_html( $sfbtk_url ); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                        <p class="description">Eșantion live. Salvează și reîncarcă pagina ca să vezi efectul bifelor.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Salvează structura URL' ); ?>
        </form>
    </div>
    <?php
}
