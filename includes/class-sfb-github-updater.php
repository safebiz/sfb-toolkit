<?php
/**
 * GitHub Auto-Updater pentru SFB Toolkit
 *
 * Verifica GitHub Releases pentru versiuni noi si integreaza cu WordPress
 * native update mechanism (Dashboard -> Updates / Plugins -> Update Available).
 *
 * @package SFB_Toolkit
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SFB_GitHub_Updater' ) ) :

class SFB_GitHub_Updater {

    private $plugin_file;
    private $plugin_slug;
    private $plugin_basename;
    private $github_repo;
    private $access_token;
    private $plugin_data;
    private $github_response;
    private $cache_key;
    private $cache_seconds = 21600; // 6 ore

    public function __construct( $config ) {
        $this->plugin_file     = $config['plugin_file'];
        $this->github_repo     = $config['github_repo'];
        $this->plugin_slug     = $config['plugin_slug'];
        $this->access_token    = ! empty( $config['access_token'] ) ? $config['access_token'] : '';
        $this->plugin_basename = plugin_basename( $this->plugin_file );
        $this->cache_key       = 'sfb_ghupdate_' . md5( $this->github_repo );

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_details_modal' ], 10, 3 );
        add_filter( 'upgrader_source_selection',             [ $this, 'fix_source_folder_name' ], 10, 4 );
        add_action( 'upgrader_process_complete',             [ $this, 'clear_cache' ], 10, 2 );
    }

    private function get_plugin_data() {
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! $this->plugin_data ) {
            $this->plugin_data = get_plugin_data( $this->plugin_file, false, false );
        }
        return $this->plugin_data;
    }

    private function get_latest_release() {
        $cached = get_transient( $this->cache_key );
        if ( false !== $cached ) return $cached;

        $url  = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
        $args = [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'SFB-Toolkit-Updater',
            ],
        ];
        if ( ! empty( $this->access_token ) ) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
        }

        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) {
            error_log( '[SFB-Toolkit] GH updater error: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            set_transient( $this->cache_key, false, HOUR_IN_SECONDS );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) return false;

        $release = [
            'version'      => ltrim( $body['tag_name'], 'vV' ),
            'tag_name'     => $body['tag_name'],
            'zipball_url'  => $body['zipball_url'] ?? '',
            'html_url'     => $body['html_url'] ?? '',
            'body'         => $body['body'] ?? '',
            'published_at' => $body['published_at'] ?? '',
            'assets'       => $body['assets'] ?? [],
        ];

        set_transient( $this->cache_key, $release, $this->cache_seconds );
        return $release;
    }

    private function get_download_url( $release ) {
        foreach ( $release['assets'] as $asset ) {
            if ( ! empty( $asset['browser_download_url'] ) && str_ends_with( $asset['name'], '.zip' ) ) {
                return $asset['browser_download_url'];
            }
        }
        return $release['zipball_url'];
    }

    public function inject_update( $transient ) {
        if ( ! is_object( $transient ) || empty( $transient->checked ) ) return $transient;

        $release = $this->get_latest_release();
        if ( ! $release ) return $transient;

        $current = $this->get_plugin_data()['Version'] ?? '0.0.0';
        if ( version_compare( $release['version'], $current, '>' ) ) {
            $obj               = new stdClass();
            $obj->slug         = $this->plugin_slug;
            $obj->plugin       = $this->plugin_basename;
            $obj->new_version  = $release['version'];
            $obj->url          = $release['html_url'];
            $obj->package      = $this->get_download_url( $release );
            $obj->tested       = get_bloginfo( 'version' );
            $obj->requires_php = $this->get_plugin_data()['RequiresPHP'] ?? '7.4';
            $obj->compatibility = new stdClass();

            $transient->response[ $this->plugin_basename ] = $obj;
        }

        return $transient;
    }

    public function plugin_details_modal( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) return $result;
        if ( empty( $args->slug ) || $args->slug !== $this->plugin_slug ) return $result;

        $release     = $this->get_latest_release();
        $plugin_data = $this->get_plugin_data();
        if ( ! $release ) return $result;

        $info               = new stdClass();
        $info->name         = $plugin_data['Name'] ?? $this->plugin_slug;
        $info->slug         = $this->plugin_slug;
        $info->version      = $release['version'];
        $info->author       = $plugin_data['Author'] ?? '';
        $info->homepage     = $release['html_url'];
        $info->requires     = $plugin_data['RequiresWP'] ?? '6.0';
        $info->tested       = get_bloginfo( 'version' );
        $info->requires_php = $plugin_data['RequiresPHP'] ?? '7.4';
        $info->last_updated = $release['published_at'];
        $info->download_link = $this->get_download_url( $release );
        $info->sections     = [
            'description' => wpautop( esc_html( $plugin_data['Description'] ?? '' ) ),
            'changelog'   => wpautop( esc_html( $release['body'] ) ),
        ];

        return $info;
    }

    public function fix_source_folder_name( $source, $remote_source, $upgrader, $hook_extra = null ) {
        global $wp_filesystem;

        $plugin = $hook_extra['plugin'] ?? '';
        if ( $plugin && $plugin !== $this->plugin_basename ) return $source;

        $folder_name     = basename( rtrim( $source, '/\\' ) );
        if ( $folder_name === $this->plugin_slug ) return $source;

        $expected_prefix = str_replace( '/', '-', $this->github_repo ) . '-';
        if ( strpos( $folder_name, $expected_prefix ) !== 0 ) return $source;

        $new_source = trailingslashit( $remote_source ) . $this->plugin_slug;
        if ( $wp_filesystem && $wp_filesystem->move( $source, $new_source ) ) {
            return trailingslashit( $new_source );
        }
        return $source;
    }

    public function clear_cache( $upgrader, $hook_extra ) {
        if ( ! is_array( $hook_extra ) ) return;
        if ( ( $hook_extra['action'] ?? '' ) !== 'update' ) return;
        if ( ( $hook_extra['type'] ?? '' ) !== 'plugin' ) return;
        if ( empty( $hook_extra['plugins'] ) || ! in_array( $this->plugin_basename, (array) $hook_extra['plugins'], true ) ) return;

        delete_transient( $this->cache_key );
    }
}

endif;
