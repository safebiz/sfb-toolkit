<?php
/**
 * SFB Hardening — rezolvă cele 3 probleme de securitate din auditul lunar:
 *   1. wp_generator_visible    — elimină versiunea WP din meta tag
 *   2. missing_security_headers — adaugă headere HTTP de securitate
 *   3. login_page_exposed      — fallback redirect /wp-login.php dacă WPS Hide Login lipsește
 *
 * Implementat via WordPress hooks — server-agnostic (Apache/LiteSpeed/Nginx).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFB_Hardening {

    public function __construct() {
        // Fix 1: elimină versiunea WP din <meta name="generator">
        remove_action( 'wp_head', 'wp_generator' );

        // Fix 2: headere de securitate HTTP
        add_action( 'send_headers', [ $this, 'send_security_headers' ] );

        // Fix 3: protecție login — fallback dacă WPS Hide Login nu e activ.
        // IMPORTANT: verificarea WPS Hide Login se face în protect_login() la runtime (init),
        // NU aici în constructor. Constructorul rulează la include-time, iar SFB Toolkit se
        // încarcă alfabetic ÎNAINTEA wps-hide-login → constanta WPS_HIDE_LOGIN_VERSION încă nu
        // e definită în acest moment, deci un gate `defined()` aici ar fi mereu fals-negativ și
        // am bloca greșit login-ul custom servit de WPS Hide Login pe slug-ul whl_page.
        // (Bug galprogressio 2026-05-29: /beleppo redirecționa spre home cu WPS Hide Login activ.)
        add_action( 'init', [ $this, 'protect_login' ], 1 );
    }

    public function send_security_headers() {
        if ( headers_sent() ) return;

        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        // Minimal — nu restricționăm features care pot fi folosite pe site-uri client
        header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );

        // HSTS — doar pe HTTPS, previne downgrade attacks
        if ( is_ssl() ) {
            header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
        }

        // CSP minimal — safe pe orice WP site, nu depinde de conținut
        // object-src: blochează Flash/Java; base-uri: previne injecție <base>; upgrade: forțează HTTPS resurse
        header( "Content-Security-Policy: object-src 'none'; base-uri 'self'; upgrade-insecure-requests" );
    }

    /**
     * Fallback: dacă WPS Hide Login nu e activ dar whl_page e setat (ex: plugin dezinstalat),
     * blocăm accesul direct la /wp-login.php și redirecționăm spre homepage.
     * Login-urile reale (POST cu credențiale) sunt lăsate să treacă.
     */
    public function protect_login() {
        // WPS Hide Login activ → el gestionează login-ul custom pe slug-ul whl_page; nu interveni.
        // Verificat AICI la runtime (init), când constanta e garantat definită (vezi nota din __construct).
        if ( defined( 'WPS_HIDE_LOGIN_VERSION' ) ) return;

        $slug = get_option( 'whl_page', '' );
        if ( ! $slug ) return;

        global $pagenow;
        if ( 'wp-login.php' !== $pagenow ) return;

        // Permite: POST login, logout, resetare parolă, xmlrpc
        $action = $_REQUEST['action'] ?? '';
        if ( ! empty( $_POST['log'] ) ) return;
        if ( in_array( $action, [ 'logout', 'lostpassword', 'rp', 'resetpass', 'postpass' ], true ) ) return;

        wp_redirect( home_url( '/' ), 302 );
        exit;
    }
}
