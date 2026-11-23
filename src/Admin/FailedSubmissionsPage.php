<?php
/**
 * Forms > Orbit queue: entries that have not reached Orbit yet.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Admin;

use Orbit\GravityForms\Queue\RetryQueue;
use Orbit\GravityForms\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Lists queued and failed sends with Retry and Dismiss buttons.
 */
final class FailedSubmissionsPage {

	const SLUG       = 'orbit-gf-queue';
	const CAPABILITY = 'gravityforms_edit_entries';
	const PER_PAGE   = 50;

	/**
	 * Queue store.
	 *
	 * @var RetryQueue
	 */
	private $queue;

	/**
	 * Build the page.
	 *
	 * @param RetryQueue $queue Queue store.
	 */
	public function __construct( RetryQueue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * Hook the menu and handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_orbit_gf_queue_action', array( $this, 'handle_action' ) );
	}

	/**
	 * Add the page under Forms, with a count of entries waiting.
	 */
	public function add_menu(): void {
		if ( ! class_exists( 'GFForms' ) ) {
			return;
		}
		$waiting = $this->queue->count( array( RetryQueue::STATUS_PENDING, RetryQueue::STATUS_FAILED ) );
		$label   = __( 'Orbit queue', 'orbit-gf' );
		if ( $waiting > 0 ) {
			$label .= sprintf( ' <span class="awaiting-mod">%d</span>', $waiting );
		}
		add_submenu_page( 'gf_edit_forms', __( 'Orbit queue', 'orbit-gf' ), $label, Capabilities::for_menu( self::CAPABILITY ), self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! Capabilities::can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view the Orbit queue.', 'orbit-gf' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$show_sent = isset( $_GET['show'] ) && 'all' === sanitize_key( wp_unslash( $_GET['show'] ) );
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable
		$statuses = $show_sent
			? array( RetryQueue::STATUS_PENDING, RetryQueue::STATUS_FAILED, RetryQueue::STATUS_SENT, RetryQueue::STATUS_DISMISSED )
			: array( RetryQueue::STATUS_PENDING, RetryQueue::STATUS_FAILED );
		$rows     = $this->queue->list( $statuses, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$total    = $this->queue->count( $statuses );
		$labels   = array(
			RetryQueue::STATUS_PENDING   => __( 'Retrying automatically', 'orbit-gf' ),
			RetryQueue::STATUS_FAILED    => __( 'Needs attention', 'orbit-gf' ),
			RetryQueue::STATUS_SENT      => __( 'Sent', 'orbit-gf' ),
			RetryQueue::STATUS_DISMISSED => __( 'Dismissed', 'orbit-gf' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Orbit queue', 'orbit-gf' ); ?></h1>
			<p><?php esc_html_e( 'Entries that have not reached Orbit. Temporary problems (Orbit unreachable or busy) are retried automatically up to five times over a day. Problems that need a change, such as a removed API key or an email address Orbit refuses, show "Needs attention": fix the cause, then click Retry.', 'orbit-gf' ); ?></p>
			<p>
				<?php if ( $show_sent ) : ?>
					<a href="<?php echo esc_url( remove_query_arg( array( 'show', 'paged' ) ) ); ?>"><?php esc_html_e( 'Show only entries waiting to be sent', 'orbit-gf' ); ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'show', 'all', remove_query_arg( 'paged' ) ) ); ?>"><?php esc_html_e( 'Also show sent and dismissed entries', 'orbit-gf' ); ?></a>
				<?php endif; ?>
			</p>
			<?php ( new ConnectionsPage( new \Orbit\GravityForms\Connections() ) )->render_notice(); ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Entry', 'orbit-gf' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Form', 'orbit-gf' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'orbit-gf' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Attempts', 'orbit-gf' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last error', 'orbit-gf' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Next retry (UTC)', 'orbit-gf' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'orbit-gf' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Nothing waiting. Every entry has reached Orbit.', 'orbit-gf' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$form       = class_exists( 'GFAPI' ) ? \GFAPI::get_form( (int) $row->form_id ) : false;
					$entry_link = admin_url( sprintf( 'admin.php?page=gf_entries&view=entry&id=%d&lid=%d', $row->form_id, $row->entry_id ) );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $entry_link ); ?>">#<?php echo esc_html( (string) $row->entry_id ); ?></a></td>
						<td><?php echo esc_html( is_array( $form ) ? (string) $form['title'] : __( 'Deleted form', 'orbit-gf' ) ); ?></td>
						<td><?php echo esc_html( $labels[ $row->status ] ?? (string) $row->status ); ?></td>
						<td><?php echo esc_html( (string) $row->attempts ); ?></td>
						<td><?php echo esc_html( (string) $row->last_error ); ?></td>
						<td><?php echo esc_html( $row->next_attempt_at ? (string) $row->next_attempt_at : __( 'n/a', 'orbit-gf' ) ); ?></td>
						<td>
							<?php if ( in_array( $row->status, array( RetryQueue::STATUS_PENDING, RetryQueue::STATUS_FAILED ), true ) ) : ?>
								<?php $this->render_button( (int) $row->id, 'retry', __( 'Retry', 'orbit-gf' ) ); ?>
								<?php $this->render_button( (int) $row->id, 'dismiss', __( 'Dismiss', 'orbit-gf' ), 'button-link-delete' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / self::PER_PAGE );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * A Retry or Dismiss button.
	 *
	 * @param int    $id    Queue row ID.
	 * @param string $op    retry or dismiss.
	 * @param string $label Button label.
	 * @param string $extra_class Extra class.
	 */
	private function render_button( int $id, string $op, string $label, string $extra_class = '' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<input type="hidden" name="action" value="orbit_gf_queue_action">
			<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( 'orbit_gf_queue_' . $op . '_' . $id ); ?>
			<button type="submit" class="button <?php echo esc_attr( $extra_class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Handle Retry and Dismiss.
	 */
	public function handle_action(): void {
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( ! in_array( $op, array( 'retry', 'dismiss' ), true ) ) {
			wp_die( esc_html__( 'Unknown action.', 'orbit-gf' ), 400 );
		}
		check_admin_referer( 'orbit_gf_queue_' . $op . '_' . $id );
		if ( ! Capabilities::can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change the Orbit queue.', 'orbit-gf' ), 403 );
		}

		if ( 'dismiss' === $op ) {
			$this->queue->dismiss( $id );
			$notice = array( 'success', __( 'Dismissed. The entry stays in Gravity Forms but is not sent to Orbit.', 'orbit-gf' ) );
		} else {
			$result = $this->queue->retry( $id );
			$notice = is_wp_error( $result )
				? array( 'error', $result->get_error_message() )
				: array( 'success', __( 'Sent to Orbit.', 'orbit-gf' ) );
		}

		set_transient(
			'orbit_gf_notice_' . get_current_user_id(),
			array(
				'type'    => $notice[0],
				'message' => $notice[1],
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
