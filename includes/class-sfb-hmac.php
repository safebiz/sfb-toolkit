<?php
/**
 * SFB HMAC Verification
 *
 * Static helper pentru verificarea HMAC-SHA256 signatures pe endpoint-uri
 * read-only care nu folosesc App Password (ex. inventory collector).
 *
 * Protocol:
 *   - Collector trimite headers:
 *     X-SafeBiz-Timestamp: <unix_ts>      (epoch seconds)
 *     X-SafeBiz-Nonce:     <16-byte hex>  (32 chars random per call)
 *     X-SafeBiz-Signature: <hex>          (HMAC_SHA256(secret, "ts.nonce"))
 *   - Server verifică:
 *     1. Timestamp în window ±300s vs server now() (replay protection)
 *     2. Signature recomputed match (constant-time compare)
 *     3. Nonce nefolosit recent (60s transient cache)
 *
 * Secret per-site stocat ca WP option `sfb_inventory_secret`, generat la
 * activare plugin (vezi class-sfb-inventory.php).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFB_HMAC {

    // SECURITATE: NONCE_CACHE_TTL DEBE FI >= SIGNATURE_WINDOW_SECONDS,
    // altfel un nonce poate fi reutilizat în fereastra (TTL, WINDOW) = replay attack.
    // Setăm ambele la 300s. Adăugăm 60s margin la nonce cache pentru clock skew.
    const SIGNATURE_WINDOW_SECONDS = 300;  // ±5 min — accepta requests cu drift max 5min
    const NONCE_CACHE_TTL          = 360;  // 6 min — > WINDOW + 60s margin clock skew
    const NONCE_CACHE_PREFIX       = 'sfb_hmac_nonce_';

    /**
     * Verifică HMAC dintr-un WP_REST_Request.
     *
     * @param WP_REST_Request $request
     * @param string          $option_key Option WP care stochează secretul.
     * @return true|WP_Error
     */
    public static function verify_request( $request, $option_key = 'sfb_inventory_secret' ) {
        $secret = get_option( $option_key, '' );
        if ( empty( $secret ) || strlen( $secret ) < 32 ) {
            return new WP_Error( 'sfb_hmac_no_secret',
                'HMAC secret not configured on this site',
                [ 'status' => 503 ] );
        }

        $timestamp = $request->get_header( 'x_safebiz_timestamp' );
        $nonce     = $request->get_header( 'x_safebiz_nonce' );
        $signature = $request->get_header( 'x_safebiz_signature' );

        if ( ! $timestamp || ! $nonce || ! $signature ) {
            return new WP_Error( 'sfb_hmac_missing_headers',
                'Required HMAC headers missing (X-SafeBiz-Timestamp, X-SafeBiz-Nonce, X-SafeBiz-Signature)',
                [ 'status' => 401 ] );
        }

        // 1. Window check
        $ts = (int) $timestamp;
        $drift = abs( time() - $ts );
        if ( $drift > self::SIGNATURE_WINDOW_SECONDS ) {
            return new WP_Error( 'sfb_hmac_timestamp_drift',
                sprintf( 'Timestamp drift %ds exceeds window', $drift ),
                [ 'status' => 401 ] );
        }

        // 2. Nonce format (32-char hex)
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $nonce ) ) {
            return new WP_Error( 'sfb_hmac_invalid_nonce',
                'Nonce must be 32-char lowercase hex',
                [ 'status' => 401 ] );
        }

        // 3. Replay protection (nonce cache)
        $nonce_key = self::NONCE_CACHE_PREFIX . $nonce;
        if ( get_transient( $nonce_key ) ) {
            return new WP_Error( 'sfb_hmac_replay',
                'Nonce already used',
                [ 'status' => 401 ] );
        }

        // 4. Signature verify (constant-time)
        $expected = hash_hmac( 'sha256', $ts . '.' . $nonce, $secret );
        if ( ! hash_equals( $expected, strtolower( $signature ) ) ) {
            return new WP_Error( 'sfb_hmac_signature_mismatch',
                'Signature mismatch',
                [ 'status' => 401 ] );
        }

        // OK — mark nonce used (TTL 60s)
        set_transient( $nonce_key, 1, self::NONCE_CACHE_TTL );

        return true;
    }

    /**
     * Generează un secret nou aleator (256-bit hex).
     */
    public static function generate_secret() {
        return bin2hex( random_bytes( 32 ) );
    }
}
