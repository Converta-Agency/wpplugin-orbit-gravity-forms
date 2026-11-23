<?php
/**
 * Forms > Orbit accounts: add, test and remove Orbit API keys.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Admin;

use Orbit\GravityForms\Api\Client;
use Orbit\GravityForms\Connections;
use Orbit\GravityForms\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Keys are write-only: once saved, the page shows only the last four characters.
 */
final class ConnectionsPage {

	const SLUG       = 'orbit-gf-accounts';
	const CAPABILITY = 'manage_options';

	/**
	 * Stored accounts.
	 *
	 * @var Connections
	 */
	private $connections;

	/**
	 * Build the page.
	 *
	 * @param Connections $connections Stored accounts.
	 */
	public function __construct( Connections $connections ) {
		$this->connections = $connections;
	}

	/**
	 * URL of the page.
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Hook the menu and form handlers.
	 */
	public function register(): void {
		// Priority 20: after Gravity Forms has added its Forms menu.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_orbit_gf_save_connection', array( $this, 'handle_save' ) );
		add_action( 'admin_post_orbit_gf_delete_connection', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_orbit_gf_test_connection', array( $this, 'handle_test' ) );
		add_action( 'admin_post_orbit_gf_import_legacy', array( $this, 'handle_import_legacy' ) );
	}

	/**
	 * Add the page under Forms, or under Settings when Gravity Forms is missing.
	 */
	public function add_menu(): void {
		$parent = class_exists( 'GFForms' ) ? 'gf_edit_forms' : 'options-general.php';
		add_submenu_page(
			$parent,
			__( 'Orbit accounts', 'orbit-gf' ),
			__( 'Orbit accounts', 'orbit-gf' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Orbit accounts.', 'orbit-gf' ), 403 );
		}
		$connections = $this->connections->all();
		$editing     = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects which row to edit; saving is nonce-checked.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Orbit accounts', 'orbit-gf' ); ?></h1>
			<p><?php esc_html_e( 'Each account is an Orbit API key. Add one for every Orbit account this site sends entries to, then add an Orbit feed to each form (open the form, then Settings, Orbit).', 'orbit-gf' ); ?></p>
			<?php $this->render_notice(); ?>

			<?php if ( ! empty( $connections ) ) : ?>
				<table class="widefat striped" style="max-width:960px">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'orbit-gf' ); ?></th>
							<th scope="col"><?php esc_html_e( 'API key', 'orbit-gf' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'orbit-gf' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $connections as $id => $connection ) : ?>
						<?php $masked = $this->connections->masked_key( (string) $id ); ?>
						<tr>
							<td><?php echo esc_html( (string) $connection['name'] ); ?></td>
							<td>
								<?php if ( null === $masked ) : ?>
									<span style="color:#b32d2e"><?php esc_html_e( 'Cannot be read: edit the account and enter the key again', 'orbit-gf' ); ?></span>
								<?php else : ?>
									<code><?php echo esc_html( $masked ); ?></code>
								<?php endif; ?>
							</td>
							<td>
								<?php $this->render_action_button( 'orbit_gf_test_connection', (string) $id, __( 'Test connection', 'orbit-gf' ) ); ?>
								<a class="button" href="<?php echo esc_url( add_query_arg( 'edit', $id, self::url() ) ); ?>"><?php esc_html_e( 'Edit', 'orbit-gf' ); ?></a>
								<?php $this->render_action_button( 'orbit_gf_delete_connection', (string) $id, __( 'Remove', 'orbit-gf' ), 'button-link-delete' ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php $this->render_form( isset( $connections[ $editing ] ) ? $editing : '', $connections[ $editing ]['name'] ?? '' ); ?>

			<h2><?php esc_html_e( 'Where entries go', 'orbit-gf' ); ?></h2>
			<p>
				<?php
				/* translators: %s: Orbit API address. */
				echo esc_html( sprintf( __( 'This site sends to %s.', 'orbit-gf' ), Client::base_url() ) );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * The add or edit form.
	 *
	 * @param string $id   Connection being edited, or empty to add one.
	 * @param string $name Its current name.
	 */
	private function render_form( string $id, string $name ): void {
		$is_edit = '' !== $id;
		?>
		<h2><?php echo esc_html( $is_edit ? __( 'Edit account', 'orbit-gf' ) : __( 'Add an account', 'orbit-gf' ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="orbit_gf_save_connection">
			<input type="hidden" name="connection_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( 'orbit_gf_save_connection_' . $id ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="orbit-gf-name"><?php esc_html_e( 'Name', 'orbit-gf' ); ?></label></th>
					<td>
						<input type="text" id="orbit-gf-name" name="name" class="regular-text" required maxlength="100" value="<?php echo esc_attr( $name ); ?>" aria-describedby="orbit-gf-name-help">
						<p class="description" id="orbit-gf-name-help"><?php esc_html_e( 'Your name for this account, shown when you choose it in a form\'s Orbit feed. For example "Acme Ltd".', 'orbit-gf' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="orbit-gf-key"><?php esc_html_e( 'API key', 'orbit-gf' ); ?></label></th>
					<td>
						<input type="password" id="orbit-gf-key" name="api_key" class="regular-text code" autocomplete="off" spellcheck="false" <?php echo $is_edit ? '' : 'required'; ?> placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" aria-describedby="orbit-gf-key-help">
						<p class="description" id="orbit-gf-key-help">
							<?php esc_html_e( 'In Orbit, open the account menu (top right), choose Access and API keys, then click New key. Copy the key shown and paste it here; Orbit shows it only once.', 'orbit-gf' ); ?>
							<?php if ( $is_edit ) : ?>
								<?php esc_html_e( 'Leave empty to keep the current key.', 'orbit-gf' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( $is_edit ? __( 'Save and test', 'orbit-gf' ) : __( 'Add and test', 'orbit-gf' ), 'primary', 'submit', false ); ?>
			<?php if ( $is_edit ) : ?>
				<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'orbit-gf' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * A one-button form posting to admin-post.php.
	 *
	 * @param string $action Action name.
	 * @param string $id     Connection ID.
	 * @param string $label  Button label.
	 * @param string $extra_class  Extra button class.
	 */
	private function render_action_button( string $action, string $id, string $label, string $extra_class = '' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="connection_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( $action . '_' . $id ); ?>
			<button type="submit" class="button <?php echo esc_attr( $extra_class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Save a new or edited account, then test it.
	 */
	public function handle_save(): void {
		$id = $this->posted_id();
		$this->authorise( 'orbit_gf_save_connection_' . $id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorise() above.
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$key  = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		// phpcs:enable

		if ( '' === $name ) {
			$this->redirect( 'error', __( 'Enter a name for the account.', 'orbit-gf' ), $id );
		}
		if ( '' === $id && '' === $key ) {
			$this->redirect( 'error', __( 'Paste the API key from Orbit.', 'orbit-gf' ) );
		}
		if ( '' !== $key && ! Connections::is_valid_key_format( $key ) ) {
			$this->redirect( 'error', __( 'That is not an Orbit API key. A key looks like 1b4e28ba-2fa1-11d2-883f-0016d3cca427: copy it again from Orbit, Access and API keys.', 'orbit-gf' ), $id );
		}

		$saved_id = $this->connections->save( $name, '' === $key ? null : $key, '' === $id ? null : $id );
		delete_transient( Client::cache_key( $saved_id ) );
		$this->redirect_with_test( $saved_id, __( 'Account saved.', 'orbit-gf' ) );
	}

	/**
	 * Test an account.
	 */
	public function handle_test(): void {
		$id = $this->posted_id();
		$this->authorise( 'orbit_gf_test_connection_' . $id );
		$this->redirect_with_test( $id, '' );
	}

	/**
	 * Remove an account.
	 */
	public function handle_delete(): void {
		$id = $this->posted_id();
		$this->authorise( 'orbit_gf_delete_connection_' . $id );
		$this->connections->delete( $id );
		$this->redirect( 'success', __( 'Account removed. Feeds that used it stop sending until you choose another account in each feed.', 'orbit-gf' ) );
	}

	/**
	 * Turn a form's KulaHub settings into an Orbit feed.
	 */
	public function handle_import_legacy(): void {
		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		check_admin_referer( 'orbit_gf_import_legacy_' . $form_id );
		if ( ! Capabilities::can( 'gravityforms_edit_forms' ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this form.', 'orbit-gf' ), 403 );
		}
		$connection = $this->posted_id( 'connection' );
		$result     = ( new \Orbit\GravityForms\Migration\LegacyImporter() )->import( $form_id, $connection );
		$back       = admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=orbit-gf&id=' . $form_id );
		if ( is_wp_error( $result ) ) {
			$this->flash( 'error', $result->get_error_message() );
			wp_safe_redirect( $back );
			exit;
		}
		$message = __( 'Feed created from the KulaHub settings. Check each mapping below, then click Save Settings.', 'orbit-gf' );
		if ( ! empty( $result['skipped'] ) ) {
			/* translators: %s: comma-separated list of field IDs. */
			$message .= ' ' . sprintf( __( 'These field IDs have no Orbit equivalent and were left out: %s.', 'orbit-gf' ), implode( ', ', $result['skipped'] ) );
		}
		$this->flash( 'success', $message );
		wp_safe_redirect( add_query_arg( 'fid', $result['feed_id'], $back ) );
		exit;
	}

	/**
	 * Run the connection test and redirect with its result.
	 *
	 * @param string $id     Connection ID.
	 * @param string $prefix Message to put before the result.
	 */
	private function redirect_with_test( string $id, string $prefix ): void {
		$key = $this->connections->api_key( $id );
		if ( null === $key ) {
			$this->redirect( 'error', trim( $prefix . ' ' . __( 'The saved key cannot be read. Edit the account and enter the key again.', 'orbit-gf' ) ) );
		}
		$result = ( new Client( (string) $key ) )->test_connection();
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', trim( $prefix . ' ' . $result->get_error_message() ) );
		}
		$this->redirect( 'success', trim( $prefix . ' ' . __( 'Orbit accepted the key.', 'orbit-gf' ) ) );
	}

	/**
	 * Check the nonce and capability.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private function authorise( string $nonce_action ): void {
		check_admin_referer( $nonce_action );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Orbit accounts.', 'orbit-gf' ), 403 );
		}
	}

	/**
	 * The posted connection ID.
	 *
	 * @param string $field Field name.
	 */
	private function posted_id( string $field = 'connection_id' ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- every caller verifies the nonce, whose action includes this ID.
		return isset( $_POST[ $field ] ) ? sanitize_key( wp_unslash( $_POST[ $field ] ) ) : '';
		// phpcs:enable
	}

	/**
	 * Store a message for the next page view and go back to the page.
	 *
	 * @param string $type    success or error.
	 * @param string $message Message.
	 * @param string $edit    Connection to reopen for editing.
	 */
	private function redirect( string $type, string $message, string $edit = '' ): void {
		$this->flash( $type, $message );
		wp_safe_redirect( '' === $edit ? self::url() : add_query_arg( 'edit', $edit, self::url() ) );
		exit;
	}

	/**
	 * Store a one-off notice for the current user.
	 *
	 * @param string $type    success or error.
	 * @param string $message Message.
	 */
	private function flash( string $type, string $message ): void {
		set_transient(
			'orbit_gf_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and clear the stored notice for the current user.
	 *
	 * @return array{type: string, message: string}|null
	 */
	public static function take_notice(): ?array {
		$key    = 'orbit_gf_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'type'    => (string) ( $notice['type'] ?? 'success' ),
			'message' => (string) ( $notice['message'] ?? '' ),
		);
	}

	/**
	 * Print and clear the stored notice.
	 */
	public function render_notice(): void {
		$notice = self::take_notice();
		if ( null === $notice ) {
			return;
		}
		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( (string) $notice['message'] )
		);
	}
}
