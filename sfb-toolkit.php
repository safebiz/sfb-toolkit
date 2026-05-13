<?php
/**
 * Plugin Name: SFB Toolkit
 * Plugin URI:  https://github.com/safebiz/sfb-toolkit
 * Description: MasterC infrastructure toolkit — file verify + nonce provider + options API. REST endpoints for AI worker bridge.
 * Version:     1.0.0
 * Author:      Safebiz Solutions
 * Author URI:  https://safebiz.ro
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.4
 * Requires WP:  6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/includes/class-sfb-github-updater.php';
new SFB_GitHub_Updater( [
    'plugin_file'  => __FILE__,
    'github_repo'  => 'safebiz/sfb-toolkit',
    'plugin_slug'  => 'sfb-toolkit',
    'access_token' => '',
] );

// ── 1. FILE VERIFY ──────────────────────────────────────────────────────────
// Endpoint-uri read-only pentru verificarea hash-ului child theme files.
// Folosit de: wat/tools/file-verify-check.js

add_action( 'rest_api_init', function () {
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
// Expune wp_rest nonce pentru MasterC bridge + acces read/write la sure* options.
// Folosit de: skills fluentcrm, surerank, suremembers, suredash, surecookies

add_action( 'rest_api_init', function () {
    // GET /wp-json/masterc/v1/nonce
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

    // GET /wp-json/masterc/v1/nonce-test
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

    // GET|POST /wp-json/masterc/v1/option
    register_rest_route( 'masterc/v1', '/option', [
        'methods'             => [ 'GET', 'POST' ],
        'callback'            => function ( $request ) {
            $name = $request->get_param( 'name' );
            if ( ! $name || ! preg_match( '/^(surecookie|suremembers|suredash|surerank)_/', $name ) ) {
                return new WP_Error( 'invalid_option', 'Only sure* options allowed', [ 'status' => 400 ] );
            }
            if ( 'POST' === $request->get_method() ) {
                $value = $request->get_param( 'value' );
                update_option( $name, json_decode( $value, true ) ?: $value );
                return [ 'updated' => true, 'name' => $name ];
            }
            return [ 'name' => $name, 'value' => get_option( $name, '__NOT_FOUND__' ) ];
        },
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );

    // GET /wp-json/masterc/v1/options-list
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
