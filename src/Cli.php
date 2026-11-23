<?php
/**
 * WP-CLI commands.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms;

use GFAPI;
use Orbit\GravityForms\Api\Client;
use Orbit\GravityForms\Migration\LegacyImporter;
use Orbit\GravityForms\Queue\RetryQueue;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Orbit for Gravity Forms from the command line.
 */
final class Cli {

	/**
	 * List the Orbit accounts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp orbit-gf accounts
	 *
	 * @subcommand accounts
	 */
	public function accounts(): void {
		$connections = new Connections();
		$rows        = array();
		foreach ( $connections->names() as $id => $name ) {
			$rows[] = array(
				'id'   => $id,
				'name' => $name,
				'key'  => $connections->masked_key( $id ) ?? 'unreadable',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'key' ) );
	}

	/**
	 * Add an Orbit account and test it.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name shown when choosing the account in a feed.
	 *
	 * <api-key>
	 * : The Orbit API key.
	 *
	 * @subcommand add-account
	 *
	 * @param string[] $args Arguments.
	 */
	public function add_account( array $args ): void {
		list( $name, $key ) = $args;
		if ( ! Connections::is_valid_key_format( $key ) ) {
			WP_CLI::error( 'That is not an Orbit API key (expected a GUID).' );
		}
		$id     = ( new Connections() )->save( $name, $key );
		$result = ( new Client( $key ) )->test_connection();
		if ( is_wp_error( $result ) ) {
			WP_CLI::warning( "Saved as {$id}, but the test failed: " . $result->get_error_message() );
			return;
		}
		WP_CLI::success( "Saved as {$id}; Orbit accepted the key." );
	}

	/**
	 * Create Orbit feeds from the KulaHub plugin's settings.
	 *
	 * ## OPTIONS
	 *
	 * --account=<id>
	 * : Orbit account ID (see `wp orbit-gf accounts`).
	 *
	 * [--form=<id>]
	 * : Only this form. Defaults to every form with KulaHub settings and no Orbit feed.
	 *
	 * [--dry-run]
	 * : Show what would be created without saving.
	 *
	 * @subcommand import-legacy
	 *
	 * @param string[]              $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function import_legacy( array $args, array $assoc_args ): void {
		$account  = (string) $assoc_args['account'];
		$dry_run  = isset( $assoc_args['dry-run'] );
		$form_ids = isset( $assoc_args['form'] ) ? array( (int) $assoc_args['form'] ) : wp_list_pluck( GFAPI::get_forms( null ), 'id' );
		$addon    = Addon::get_instance();
		$importer = new LegacyImporter();

		foreach ( $form_ids as $form_id ) {
			$form = GFAPI::get_form( (int) $form_id );
			if ( ! is_array( $form ) || ! LegacyImporter::has_legacy_settings( $form ) ) {
				continue;
			}
			if ( ! empty( $addon->get_feeds( (int) $form_id ) ) ) {
				WP_CLI::log( "Form {$form_id}: already has an Orbit feed, skipped." );
				continue;
			}
			if ( $dry_run ) {
				$plan = LegacyImporter::plan( $form );
				WP_CLI::log( "Form {$form_id} ({$form['title']}): " . wp_json_encode( $plan ) );
				continue;
			}
			$result = $importer->import( (int) $form_id, $account );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "Form {$form_id}: " . $result->get_error_message() );
				continue;
			}
			$skipped = empty( $result['skipped'] ) ? '' : ' Left out: ' . implode( ', ', $result['skipped'] ) . '.';
			WP_CLI::success( "Form {$form_id}: created feed {$result['feed_id']}.{$skipped}" );
		}
	}

	/**
	 * Retry queued entries that are due now.
	 *
	 * @subcommand retry
	 */
	public function retry(): void {
		( new RetryQueue() )->run_due();
		WP_CLI::success( 'Due retries processed. See Forms, Orbit queue for the results.' );
	}
}
