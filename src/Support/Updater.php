<?php
/**
 * Offers new GitHub releases through the WordPress plugin updater.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the latest release of the plugin's GitHub repository.
 *
 * The repository is private, so a site needs a fine-grained GitHub token with
 * read access to its contents, defined in wp-config.php:
 *
 *     define( 'ORBIT_GF_GITHUB_TOKEN', 'github_pat_...' );
 *
 * Without it the site still works; it just does not see updates. Each release
 * carries an orbit-gravity-forms.zip asset built by the release workflow, whose
 * top folder is already named orbit-gravity-forms, so no folder renaming is needed.
 */
final class Updater {

	const REPOSITORY = 'Converta-Agency/wpplugin-orbit-gravity-forms';
	const ASSET_NAME = 'orbit-gravity-forms.zip';
	const CACHE_KEY  = 'orbit_gf_latest_release';
	const CACHE_TTL  = 6 * HOUR_IN_SECONDS;

	/**
	 * Main plugin file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Plugin basename (folder/file.php).
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Build for the main plugin file.
	 *
	 * @param string $file Main plugin file.
	 */
	public function __construct( string $file ) {
		$this->file     = $file;
		$this->basename = plugin_basename( $file );
	}

	/**
	 * Hook into the update checks.
	 */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'add_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'authorise_download' ), 10, 2 );
	}

	/**
	 * Add this plugin to the update list when a newer release exists.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public function add_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$release = $this->latest_release();
		if ( null === $release ) {
			return $transient;
		}
		$current = (string) ( $transient->checked[ $this->basename ] ?? ORBIT_GF_VERSION );
		$item    = (object) array(
			'id'           => self::REPOSITORY,
			'slug'         => dirname( $this->basename ),
			'plugin'       => $this->basename,
			'new_version'  => $release['version'],
			'url'          => 'https://github.com/' . self::REPOSITORY,
			'package'      => $release['package'],
			'requires_php' => '7.4',
		);
		if ( version_compare( $release['version'], $current, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
		} else {
			$transient->no_update[ $this->basename ] = $item;
		}
		return $transient;
	}

	/**
	 * Details for the "View details" popup.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action API action.
	 * @param object $args   Arguments.
	 * @return mixed
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || dirname( $this->basename ) !== $args->slug ) {
			return $result;
		}
		$release = $this->latest_release();
		if ( null === $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Orbit for Gravity Forms',
			'slug'          => $args->slug,
			'version'       => $release['version'],
			'author'        => 'Converta',
			'homepage'      => 'https://github.com/' . self::REPOSITORY,
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => wp_kses_post( wpautop( esc_html( $release['notes'] ) ) ),
			),
		);
	}

	/**
	 * Send the token, and ask for the binary, when WordPress downloads this plugin's release asset.
	 *
	 * @param array  $args HTTP arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function authorise_download( $args, $url ) {
		$prefix = 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/assets/';
		if ( 0 !== strpos( (string) $url, $prefix ) || '' === self::token() ) {
			return $args;
		}
		$args['headers']                  = (array) ( $args['headers'] ?? array() );
		$args['headers']['Authorization'] = 'Bearer ' . self::token();
		$args['headers']['Accept']        = 'application/octet-stream';
		return $args;
	}

	/**
	 * The latest release, cached for six hours.
	 *
	 * @return array{version: string, package: string, notes: string, published: string}|null
	 */
	private function latest_release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$headers = array( 'Accept' => 'application/vnd.github+json' );
		if ( '' !== self::token() ) {
			$headers['Authorization'] = 'Bearer ' . self::token();
		}
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => $headers,
			)
		);

		$release = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['tag_name'] ) ) {
				$package = '';
				foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
					if ( self::ASSET_NAME === ( $asset['name'] ?? '' ) ) {
						$package = (string) $asset['url'];
					}
				}
				if ( '' !== $package ) {
					$release = array(
						'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
						'package'   => $package,
						'notes'     => (string) ( $body['body'] ?? '' ),
						'published' => (string) ( $body['published_at'] ?? '' ),
					);
				}
			}
		}

		// Cache a miss too (as an empty array) so a site without a token does not ask GitHub on every check.
		set_site_transient( self::CACHE_KEY, $release ?? array(), self::CACHE_TTL );
		return $release;
	}

	/**
	 * The GitHub token from wp-config.php, if any.
	 */
	private static function token(): string {
		return defined( 'ORBIT_GF_GITHUB_TOKEN' ) ? (string) ORBIT_GF_GITHUB_TOKEN : '';
	}
}
