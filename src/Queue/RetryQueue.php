<?php
/**
 * Entries that could not be sent to Orbit, and their automatic retries.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Queue;

use GFAPI;
use Orbit\GravityForms\Addon;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A failed send is stored with the entry ID only; the submitted data stays in
 * the Gravity Forms entry, so this table holds no personal data.
 *
 * Temporary failures (Orbit unreachable, rate limited or erroring) are retried
 * automatically after 5 minutes, 30 minutes, 2 hours, 6 hours and 24 hours.
 * Failures Orbit will repeat (a rejected key, invalid data) wait for someone to
 * fix the cause and press Retry.
 */
final class RetryQueue {

	const CRON_HOOK     = 'orbit_gf_retry_failed';
	const CRON_INTERVAL = 'orbit_gf_five_minutes';
	const BATCH_SIZE    = 20;

	const STATUS_PENDING   = 'pending';
	const STATUS_FAILED    = 'failed';
	const STATUS_SENT      = 'sent';
	const STATUS_DISMISSED = 'dismissed';

	/**
	 * Delay in seconds before each automatic retry.
	 *
	 * @var int[]
	 */
	const BACKOFF = array( 300, 1800, 7200, 21600, 86400 );

	/**
	 * Hook the retry job.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- five minutes is deliberate: the first retry is due then.
		add_action( self::CRON_HOOK, array( $this, 'run_due' ) );
	}

	/**
	 * Add the five-minute cron interval.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function add_interval( $schedules ) {
		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Orbit retries)', 'orbit-gf' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the job if it is not already.
	 */
	public static function schedule(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Remove the job.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Table name with the site prefix.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'orbit_gf_queue';
	}

	/**
	 * Create or update the table.
	 */
	public function install(): void {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint(20) unsigned NOT NULL,
			form_id bigint(20) unsigned NOT NULL,
			feed_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			next_attempt_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY entry_feed (entry_id, feed_id),
			KEY status_next (status, next_attempt_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record a failed send, scheduling a retry when the failure is temporary.
	 *
	 * @param int      $entry_id Entry ID.
	 * @param int      $form_id  Form ID.
	 * @param int      $feed_id  Feed ID.
	 * @param WP_Error $error    Why it failed.
	 */
	public function record_failure( int $entry_id, int $form_id, int $feed_id, WP_Error $error ): void {
		global $wpdb;
		$table    = self::table();
		$now      = current_time( 'mysql', true );
		$existing = $this->find( $entry_id, $feed_id );
		$attempts = $existing ? (int) $existing->attempts + 1 : 1;
		$data     = (array) $error->get_error_data();
		$retry    = ! empty( $data['retryable'] ) && $attempts <= count( self::BACKOFF );

		$row = array(
			'entry_id'        => $entry_id,
			'form_id'         => $form_id,
			'feed_id'         => $feed_id,
			'status'          => $retry ? self::STATUS_PENDING : self::STATUS_FAILED,
			'attempts'        => $attempts,
			'last_error'      => $error->get_error_message(),
			'next_attempt_at' => $retry ? gmdate( 'Y-m-d H:i:s', time() + self::BACKOFF[ $attempts - 1 ] ) : null,
			'updated_at'      => $now,
		);

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			return;
		}
		$row['created_at'] = $now;
		$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	}

	/**
	 * Mark a queued entry as delivered.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 */
	public function mark_sent( int $entry_id, int $feed_id ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			self::table(),
			array(
				'status'          => self::STATUS_SENT,
				'next_attempt_at' => null,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array(
				'entry_id' => $entry_id,
				'feed_id'  => $feed_id,
			)
		);
	}

	/**
	 * Hide a failure from the list without sending it.
	 *
	 * @param int $id Row ID.
	 */
	public function dismiss( int $id ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			self::table(),
			array(
				'status'          => self::STATUS_DISMISSED,
				'next_attempt_at' => null,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Send one queued entry now.
	 *
	 * @param int $id Row ID.
	 * @return true|WP_Error
	 */
	public function retry( int $id ) {
		$row = $this->get( $id );
		if ( ! $row ) {
			return new WP_Error( 'orbit_gf_not_queued', __( 'That entry is no longer in the queue.', 'orbit-gf' ) );
		}
		return $this->send_row( $row );
	}

	/**
	 * Retry every pending row that is due, a batch at a time.
	 */
	public function run_due(): void {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				self::STATUS_PENDING,
				current_time( 'mysql', true ),
				self::BATCH_SIZE
			)
		);
		foreach ( (array) $rows as $row ) {
			$this->send_row( $row );
		}
	}

	/**
	 * Rows for the admin list, newest first.
	 *
	 * @param string[] $statuses Statuses to include.
	 * @param int      $limit    Maximum rows.
	 * @param int      $offset   Rows to skip.
	 * @return object[]
	 */
	public function list( array $statuses, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$args         = array_merge( $statuses, array( $limit, $offset ) );
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ({$placeholders}) ORDER BY updated_at DESC LIMIT %d OFFSET %d", $args ) // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- table name from $wpdb->prefix, placeholders built above.
		);
	}

	/**
	 * Count rows in the given statuses.
	 *
	 * @param string[] $statuses Statuses.
	 */
	public function count( array $statuses ): int {
		global $wpdb;
		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status IN ({$placeholders})", $statuses ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQLPlaceholders -- custom table, placeholders built above.
	}

	/**
	 * Load the entry, form and feed for a row and send it.
	 *
	 * @param object $row Queue row.
	 * @return true|WP_Error
	 */
	private function send_row( $row ) {
		$entry = GFAPI::get_entry( (int) $row->entry_id );
		$form  = GFAPI::get_form( (int) $row->form_id );
		$addon = Addon::get_instance();
		$feed  = $addon->get_feed( (int) $row->feed_id );

		if ( is_wp_error( $entry ) || ! is_array( $form ) || ! is_array( $feed ) ) {
			$error = new WP_Error( 'orbit_gf_gone', __( 'The entry, form or feed has been deleted, so there is nothing to send.', 'orbit-gf' ), array( 'retryable' => false ) );
			$this->record_failure( (int) $row->entry_id, (int) $row->form_id, (int) $row->feed_id, $error );
			return $error;
		}

		$result = $addon->send( $feed, $entry, $form );
		if ( is_wp_error( $result ) ) {
			$this->record_failure( (int) $row->entry_id, (int) $row->form_id, (int) $row->feed_id, $result );
			$addon->add_feed_error( $result->get_error_message(), $feed, $entry, $form );
			return $result;
		}
		return true;
	}

	/**
	 * A row by entry and feed.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 * @return object|null
	 */
	private function find( int $entry_id, int $feed_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id = %d AND feed_id = %d", $entry_id, $feed_id ) ); // phpcs:ignore WordPress.DB -- custom table.
	}

	/**
	 * A row by ID.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	private function get( int $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB -- custom table.
	}
}
