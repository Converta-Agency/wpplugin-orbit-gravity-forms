<?php
/**
 * The Gravity Forms feed add-on that sends entries to Orbit.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms;

use GFAPI;
use GFCommon;
use GFFeedAddOn;
use Orbit\GravityForms\Admin\ConnectionsPage;
use Orbit\GravityForms\Api\Client;
use Orbit\GravityForms\Mapping\Columns;
use Orbit\GravityForms\Mapping\ContactFields;
use Orbit\GravityForms\Mapping\FieldCatalogue;
use Orbit\GravityForms\Mapping\PayloadBuilder;
use Orbit\GravityForms\Mapping\ValueConverter;
use Orbit\GravityForms\Queue\RetryQueue;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Each form can have one or more Orbit feeds. A feed names the Orbit account,
 * the form type to create, how Gravity Forms fields fill the contact and the
 * form type's fields, and optionally a source, categories and a condition.
 */
class Addon extends GFFeedAddOn {

	// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore -- property names are defined by GFAddOn.

	/**
	 * Add-on version.
	 *
	 * @var string
	 */
	protected $_version = ORBIT_GF_VERSION;

	/**
	 * Oldest Gravity Forms release with the settings framework used here.
	 *
	 * @var string
	 */
	protected $_min_gravityforms_version = '2.5';

	/**
	 * Add-on slug, also the feed's addon_slug.
	 *
	 * @var string
	 */
	protected $_slug = 'orbit-gf';

	/**
	 * Relative path to the main plugin file.
	 *
	 * @var string
	 */
	protected $_path = 'orbit-gravity-forms/orbit-gravity-forms.php';

	/**
	 * Full path to the main plugin file.
	 *
	 * @var string
	 */
	protected $_full_path = ORBIT_GF_FILE;

	/**
	 * Add-on title.
	 *
	 * @var string
	 */
	protected $_title = 'Orbit for Gravity Forms';

	/**
	 * Short title, shown in the form settings menu.
	 *
	 * @var string
	 */
	protected $_short_title = 'Orbit';

	/**
	 * Send in a background request so the visitor never waits for Orbit.
	 *
	 * @var bool
	 */
	protected $_async_feed_processing = true;

	/**
	 * Capability needed to edit feeds.
	 *
	 * @var string
	 */
	protected $_capabilities_form_settings = 'gravityforms_edit_forms';

	/**
	 * Capabilities removed on uninstall.
	 *
	 * @var string[]
	 */
	protected $_capabilities = array( 'gravityforms_edit_forms' );

	/**
	 * Singleton instance, as the add-on framework expects.
	 *
	 * @var Addon|null
	 */
	private static $_instance = null;

	// phpcs:enable

	/**
	 * Stored Orbit accounts.
	 *
	 * @var Connections|null
	 */
	private $connections;

	/**
	 * The add-on instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Hooks that run on every request.
	 */
	public function init() {
		parent::init();
		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'add_entry_meta_box' ), 10, 3 );
		add_action( 'admin_post_orbit_gf_resend_entry', array( $this, 'handle_resend_entry' ) );
	}

	/**
	 * Lazily built connections store.
	 */
	public function connections(): Connections {
		if ( null === $this->connections ) {
			$this->connections = new Connections();
		}
		return $this->connections;
	}

	/**
	 * The menu icon.
	 */
	public function get_menu_icon() {
		return 'dashicons-admin-site-alt3';
	}

	// ------------------------------------------------------------------
	// Feed settings.
	// ------------------------------------------------------------------

	/**
	 * Feed settings, built from the chosen account's form types, sources and categories.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function feed_settings_fields() {
		$connection_id = (string) $this->get_setting( 'connection' );
		$catalogue     = $this->catalogue_for( $connection_id );
		$form_type_id  = (int) $this->get_setting( 'formTypeId' );

		$sections = array(
			array(
				'title'  => esc_html__( 'Orbit account and form type', 'orbit-gf' ),
				'fields' => array_merge(
					$this->flash_notice_fields(),
					array(
						array(
							'name'          => 'feedName',
							'label'         => esc_html__( 'Name', 'orbit-gf' ),
							'type'          => 'text',
							'required'      => true,
							'class'         => 'medium',
							'default_value' => $this->get_default_feed_name(),
							'description'   => esc_html__( 'A name for this feed, shown in the list of Orbit feeds for this form. For example "Brochure request to Orbit".', 'orbit-gf' ),
						),
						array(
							'name'        => 'connection',
							'label'       => esc_html__( 'Orbit account', 'orbit-gf' ),
							'type'        => 'select',
							'required'    => true,
							'choices'     => $this->choices( $this->connections()->names(), __( 'Choose an account', 'orbit-gf' ) ),
							'onchange'    => "jQuery(this).parents('form').submit();",
							'description' => $this->connection_description(),
						),
					),
					$this->form_type_fields( $connection_id, $catalogue )
				),
			),
		);

		if ( ! ( $catalogue instanceof FieldCatalogue ) || ! $catalogue->has_form_type( $form_type_id ) ) {
			return $sections;
		}

		$sections[] = array(
			'title'       => esc_html__( 'Contact details', 'orbit-gf' ),
			'description' => esc_html__( 'Orbit attaches the form to the contact with the same email address, or creates a new contact when there is none. An existing contact keeps the details already in Orbit.', 'orbit-gf' ),
			'fields'      => array(
				array(
					'name'      => 'contactMap',
					'label'     => esc_html__( 'Contact fields', 'orbit-gf' ),
					'type'      => 'field_map',
					'field_map' => $this->contact_field_map(),
				),
				array(
					'name'        => 'emailSubscribeField',
					'label'       => esc_html__( 'Email opt-in', 'orbit-gf' ),
					'type'        => 'field_select',
					'description' => esc_html__( 'The tick box or Yes/No question where the person agrees to receive marketing email. A tick, "Yes" or "Opt in" subscribes a new contact; anything else leaves them unsubscribed. Leave empty when the form does not ask: new contacts are then not subscribed.', 'orbit-gf' ),
				),
				array(
					'name'    => 'tidyNames',
					'label'   => esc_html__( 'Name capitalisation', 'orbit-gf' ),
					'type'    => 'checkbox',
					'choices' => array(
						array(
							'name'    => 'tidyNames',
							'label'   => esc_html__( 'Tidy names typed all in capitals or all in lower case ("JANE SMITH" becomes "Jane Smith")', 'orbit-gf' ),
							'tooltip' => esc_html__( 'Applies to first name, last name, title, town and county only. Mixed-case names such as McDonald are left as typed, and emails and postcodes are never changed.', 'orbit-gf' ),
						),
					),
				),
			),
		);

		$sections[] = array(
			'title'       => esc_html__( 'Form fields', 'orbit-gf' ),
			'description' => esc_html__( 'Choose which Gravity Forms field fills each of this form type\'s fields in Orbit. Numbers, money, dates and tick boxes are converted to the type Orbit stores. Fields left unmapped stay empty in Orbit.', 'orbit-gf' ),
			'fields'      => array( $this->form_fields_map( $catalogue->fields( $form_type_id ) ) ),
		);

		$sections[] = array(
			'title'  => esc_html__( 'Source and categories', 'orbit-gf' ),
			'fields' => array(
				array(
					'name'        => 'sourceId',
					'label'       => esc_html__( 'Source', 'orbit-gf' ),
					'type'        => 'select',
					'choices'     => $this->choices( $catalogue->sources(), __( 'No source', 'orbit-gf' ) ),
					'description' => esc_html__( 'Where these enquiries come from, for example "Website". Stored on the form and on new contacts. Leave as "No source" to set none.', 'orbit-gf' ),
				),
				$this->categories_field( $catalogue->categories() ),
			),
		);

		$sections[] = array(
			'title'  => esc_html__( 'Conditions', 'orbit-gf' ),
			'fields' => array(
				array(
					'name'           => 'condition',
					'label'          => esc_html__( 'Send only when', 'orbit-gf' ),
					'type'           => 'feed_condition',
					'checkbox_label' => esc_html__( 'Send to Orbit only when a condition is met', 'orbit-gf' ),
					'instructions'   => esc_html__( 'Send to Orbit if', 'orbit-gf' ),
					'description'    => esc_html__( 'Leave unticked to send every entry.', 'orbit-gf' ),
				),
			),
		);

		return $sections;
	}

	/**
	 * Columns on the feed list.
	 *
	 * @return array<string, string>
	 */
	public function feed_list_columns() {
		return array(
			'feedName'   => esc_html__( 'Name', 'orbit-gf' ),
			'connection' => esc_html__( 'Orbit account', 'orbit-gf' ),
			'formTypeId' => esc_html__( 'Form type', 'orbit-gf' ),
		);
	}

	/**
	 * Account name for the feed list.
	 *
	 * @param array $feed Feed.
	 */
	public function get_column_value_connection( $feed ) {
		$names = $this->connections()->names();
		$id    = (string) rgars( $feed, 'meta/connection' );
		return esc_html( $names[ $id ] ?? __( 'Account removed: choose another', 'orbit-gf' ) );
	}

	/**
	 * Form type name for the feed list.
	 *
	 * @param array $feed Feed.
	 */
	public function get_column_value_formTypeId( $feed ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- name set by the add-on framework.
		$catalogue = $this->catalogue_for( (string) rgars( $feed, 'meta/connection' ) );
		$id        = (int) rgars( $feed, 'meta/formTypeId' );
		if ( $catalogue instanceof FieldCatalogue ) {
			$names = $catalogue->form_type_names();
			if ( isset( $names[ $id ] ) ) {
				return esc_html( $names[ $id ] );
			}
		}
		/* translators: %d: Orbit form type ID. */
		return esc_html( sprintf( __( 'Form type %d', 'orbit-gf' ), $id ) );
	}

	/**
	 * Allow a feed to be copied, for example to set up a similar form quickly.
	 *
	 * @param int|array $id Feed ID or feed.
	 */
	public function can_duplicate_feed( $id ) {
		return true;
	}

	/**
	 * Feeds can only be created once an account is added.
	 */
	public function can_create_feed() {
		return ! empty( $this->connections()->names() );
	}

	/**
	 * Message on the feed list when no account exists yet.
	 */
	public function feed_list_message() {
		if ( $this->can_create_feed() ) {
			return parent::feed_list_message();
		}
		return sprintf(
			/* translators: 1: opening link tag, 2: closing link tag. */
			esc_html__( 'Add an Orbit account first: go to %1$sForms, Orbit accounts%2$s and add an API key.', 'orbit-gf' ),
			'<a href="' . esc_url( ConnectionsPage::url() ) . '">',
			'</a>'
		);
	}

	/**
	 * Offer the legacy import above the feed list when it applies.
	 */
	public function feed_list_title() {
		$title = parent::feed_list_title();
		$form  = $this->get_current_form();
		if ( is_array( $form ) && Migration\LegacyImporter::has_legacy_settings( $form ) && empty( $this->get_feeds( (int) $form['id'] ) ) ) {
			add_action( 'admin_footer', array( $this, 'render_legacy_import_notice' ) );
		}
		return $title;
	}

	/**
	 * Notice with a button that turns the KulaHub plugin's settings into a feed.
	 */
	public function render_legacy_import_notice(): void {
		$form = $this->get_current_form();
		if ( ! is_array( $form ) || ! $this->can_create_feed() ) {
			return;
		}
		$form_id = (int) $form['id'];
		?>
		<div class="notice notice-info inline orbit-gf-legacy-notice" style="margin:16px 0">
			<p><?php esc_html_e( 'This form has settings from the KulaHub plugin (a form ID and field IDs). Orbit can turn them into a feed so you do not have to map every field again. Check the new feed before relying on it.', 'orbit-gf' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="orbit_gf_import_legacy">
				<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>">
				<?php wp_nonce_field( 'orbit_gf_import_legacy_' . $form_id ); ?>
				<label for="orbit-gf-legacy-connection"><?php esc_html_e( 'Send to Orbit account', 'orbit-gf' ); ?></label>
				<select id="orbit-gf-legacy-connection" name="connection">
					<?php foreach ( $this->connections()->names() as $id => $name ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create feed from KulaHub settings', 'orbit-gf' ); ?></button>
			</form>
		</div>
		<script>
			( function () {
				var notice = document.querySelector( '.orbit-gf-legacy-notice' );
				var panel = document.querySelector( '.gform-settings-panel' );
				if ( notice && panel ) { panel.parentNode.insertBefore( notice, panel ); }
			}() );
		</script>
		<?php
	}

	// ------------------------------------------------------------------
	// Sending.
	// ------------------------------------------------------------------

	/**
	 * Send the entry when the feed's condition is met.
	 *
	 * @param array $feed  Feed.
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return array The entry, unchanged.
	 */
	public function process_feed( $feed, $entry, $form ) {
		$result = $this->send( $feed, $entry, $form );
		if ( is_wp_error( $result ) ) {
			$queue = new RetryQueue();
			$queue->record_failure( (int) $entry['id'], (int) $form['id'], (int) $feed['id'], $result );
			$this->add_feed_error( $result->get_error_message(), $feed, $entry, $form );
		}
		return $entry;
	}

	/**
	 * Build and send one entry for one feed.
	 *
	 * @param array $feed  Feed.
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return array<string, mixed>|WP_Error Orbit's response body, or why it failed.
	 */
	public function send( array $feed, array $entry, array $form ) {
		$feed_id       = (int) $feed['id'];
		$connection_id = (string) rgars( $feed, 'meta/connection' );
		$api_key       = $this->connections()->api_key( $connection_id );
		if ( null === $api_key ) {
			return new WP_Error( 'orbit_gf_no_key', __( 'The feed\'s Orbit account has been removed or its API key can no longer be read. Edit the feed and choose an account.', 'orbit-gf' ), array( 'retryable' => false ) );
		}

		$form_type_id = (int) rgars( $feed, 'meta/formTypeId' );
		if ( $form_type_id <= 0 ) {
			return new WP_Error( 'orbit_gf_no_form_type', __( 'The feed has no Orbit form type. Edit the feed and choose one.', 'orbit-gf' ), array( 'retryable' => false ) );
		}

		$builder  = $this->build_payload( $feed, $entry, $form, $form_type_id );
		$problems = $builder->problems();
		if ( ! empty( $problems ) ) {
			return new WP_Error( 'orbit_gf_invalid', implode( ' ', $problems ), array( 'retryable' => false ) );
		}

		/**
		 * Filters the body sent to Orbit's POST /api/Forms.
		 *
		 * @param array $payload Request body.
		 * @param array $feed    Feed.
		 * @param array $entry   Entry.
		 * @param array $form    Form.
		 */
		$payload = (array) apply_filters( 'orbit_gf_payload', $builder->build(), $feed, $entry, $form );

		$this->log_debug( sprintf( '%s(): sending entry %d with feed %d to form type %d.', __METHOD__, $entry['id'], $feed_id, $form_type_id ) );

		$client   = new Client( $api_key );
		$response = $client->create_form( $payload );
		if ( is_wp_error( $response ) ) {
			$this->log_error( sprintf( '%s(): entry %d, feed %d: %s', __METHOD__, $entry['id'], $feed_id, $response->get_error_message() ) );
			return $response;
		}

		$body       = is_array( $response['body'] ) ? $response['body'] : array();
		$orbit_form = (int) ( $body['formId'] ?? $body['FormId'] ?? 0 );
		$contact_id = (int) ( $body['contactId'] ?? $body['ContactId'] ?? 0 );

		gform_update_meta( (int) $entry['id'], 'orbit_gf_form_id_' . $feed_id, $orbit_form );
		gform_update_meta( (int) $entry['id'], 'orbit_gf_contact_id_' . $feed_id, $contact_id );
		( new RetryQueue() )->mark_sent( (int) $entry['id'], $feed_id );

		$this->add_note(
			(int) $entry['id'],
			/* translators: 1: Orbit form ID, 2: Orbit contact ID, 3: feed name. */
			sprintf( __( 'Sent to Orbit as form %1$d for contact %2$d (feed "%3$s").', 'orbit-gf' ), $orbit_form, $contact_id, (string) rgars( $feed, 'meta/feedName' ) ),
			'success'
		);

		/**
		 * Fires after an entry is created in Orbit.
		 *
		 * @param array $body  Orbit's response (the created form).
		 * @param array $feed  Feed.
		 * @param array $entry Entry.
		 * @param array $form  Form.
		 */
		do_action( 'orbit_gf_sent', $body, $feed, $entry, $form );

		return $body;
	}

	/**
	 * Read the entry into a payload according to the feed's mapping.
	 *
	 * @param array $feed         Feed.
	 * @param array $entry        Entry.
	 * @param array $form         Form.
	 * @param int   $form_type_id Orbit form type ID.
	 */
	private function build_payload( array $feed, array $entry, array $form, int $form_type_id ): PayloadBuilder {
		$builder = new PayloadBuilder( $form_type_id );
		$tidy    = '1' === (string) rgars( $feed, 'meta/tidyNames' );

		foreach ( $this->get_field_map_fields( $feed, 'contactMap' ) as $property => $field_id ) {
			if ( '' !== (string) $field_id && array_key_exists( $property, ContactFields::labels() ) ) {
				$builder->contact( $property, $this->get_field_value( $form, $entry, $field_id ), $tidy );
			}
		}

		$opt_in_field = (string) rgars( $feed, 'meta/emailSubscribeField' );
		if ( '' !== $opt_in_field ) {
			$builder->email_subscribe( $this->read_boolean( $form, $entry, $opt_in_field ) );
		}

		foreach ( $this->get_generic_map_fields( $feed, 'formFields', $form, $entry ) as $column => $value ) {
			$field_id = $this->mapped_field_id( $feed, 'formFields', $column );
			if ( Columns::KIND_BOOLEAN === Columns::kind( $column ) && '' !== $field_id ) {
				$value = $this->read_boolean( $form, $entry, $field_id );
			}
			$builder->custom( $column, $value );
		}

		$source_id = (int) rgars( $feed, 'meta/sourceId' );
		$builder->source( $source_id > 0 ? $source_id : null );

		$category_ids = array();
		foreach ( (array) $feed['meta'] as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'category_' ) && '1' === (string) $value ) {
				$category_ids[] = (int) substr( (string) $key, strlen( 'category_' ) );
			}
		}
		$builder->categories( $category_ids );

		return $builder;
	}

	/**
	 * Read a field as a Boolean, the way each field type stores its answer.
	 *
	 * A tick box field stores each ticked choice under its own input (5.1, 5.2)
	 * and a consent field stores the tick under input .1, so presence is the
	 * answer. Other fields (radio, select, text) are read as Yes/No text.
	 *
	 * @param array  $form     Form.
	 * @param array  $entry    Entry.
	 * @param string $field_id Field or input ID.
	 */
	private function read_boolean( array $form, array $entry, string $field_id ): bool {
		$field = GFAPI::get_field( $form, (int) $field_id );
		$whole = false === strpos( $field_id, '.' );
		if ( $field && $whole ) {
			$input_type = $field->get_input_type();
			if ( 'consent' === $input_type ) {
				return '' !== (string) rgar( $entry, $field_id . '.1' );
			}
			if ( 'checkbox' === $input_type ) {
				foreach ( (array) $field->inputs as $input ) {
					if ( '' !== (string) rgar( $entry, (string) $input['id'] ) ) {
						return true;
					}
				}
				return false;
			}
		}
		if ( $field && ! $whole && in_array( $field->get_input_type(), array( 'checkbox', 'consent' ), true ) ) {
			return '' !== (string) rgar( $entry, $field_id );
		}
		return ValueConverter::to_bool( $this->get_field_value( $form, $entry, $field_id ) );
	}

	/**
	 * The Gravity Forms field ID mapped to an Orbit column in a generic map.
	 *
	 * @param array  $feed   Feed.
	 * @param string $name   Generic map setting name.
	 * @param string $column Orbit column.
	 */
	private function mapped_field_id( array $feed, string $name, string $column ): string {
		foreach ( (array) rgars( $feed, 'meta/' . $name ) as $row ) {
			if ( isset( $row['key'] ) && $row['key'] === $column ) {
				return (string) ( $row['value'] ?? '' );
			}
		}
		return '';
	}

	// ------------------------------------------------------------------
	// Entry detail.
	// ------------------------------------------------------------------

	/**
	 * Add an Orbit box to the entry screen.
	 *
	 * @param array $meta_boxes Meta boxes.
	 * @param array $entry      Entry.
	 * @param array $form       Form.
	 * @return array
	 */
	public function add_entry_meta_box( $meta_boxes, $entry, $form ) {
		if ( empty( $this->get_active_feeds( (int) $form['id'] ) ) ) {
			return $meta_boxes;
		}
		$meta_boxes['orbit_gf'] = array(
			'title'    => esc_html__( 'Orbit', 'orbit-gf' ),
			'callback' => array( $this, 'render_entry_meta_box' ),
			'context'  => 'side',
		);
		return $meta_boxes;
	}

	/**
	 * Show where the entry went in Orbit, with a button to send it again.
	 *
	 * @param array $args Entry and form.
	 */
	public function render_entry_meta_box( $args ): void {
		$entry = $args['entry'];
		$form  = $args['form'];
		foreach ( $this->get_active_feeds( (int) $form['id'] ) as $feed ) {
			$orbit_form = (int) gform_get_meta( (int) $entry['id'], 'orbit_gf_form_id_' . $feed['id'] );
			echo '<p><strong>' . esc_html( (string) rgars( $feed, 'meta/feedName' ) ) . '</strong><br>';
			if ( $orbit_form > 0 ) {
				/* translators: %d: Orbit form ID. */
				echo esc_html( sprintf( __( 'Sent as Orbit form %d.', 'orbit-gf' ), $orbit_form ) );
			} else {
				esc_html_e( 'Not sent yet. See the notes below for the reason.', 'orbit-gf' );
			}
			echo '</p>';
		}
		if ( ! Support\Capabilities::can( 'gravityforms_edit_entries' ) ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="orbit_gf_resend_entry">
			<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $entry['id'] ); ?>">
			<?php wp_nonce_field( 'orbit_gf_resend_entry_' . $entry['id'] ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Send to Orbit again', 'orbit-gf' ); ?></button>
			<p class="description"><?php esc_html_e( 'Sends this entry with every active Orbit feed whose condition it meets. Orbit adds a new form each time, so use this only when the entry did not arrive.', 'orbit-gf' ); ?></p>
		</form>
		<?php
	}

	/**
	 * Handle the "Send to Orbit again" button.
	 */
	public function handle_resend_entry(): void {
		$entry_id = isset( $_POST['entry_id'] ) ? absint( wp_unslash( $_POST['entry_id'] ) ) : 0;
		check_admin_referer( 'orbit_gf_resend_entry_' . $entry_id );
		if ( ! Support\Capabilities::can( 'gravityforms_edit_entries' ) ) {
			wp_die( esc_html__( 'You are not allowed to send entries to Orbit.', 'orbit-gf' ), 403 );
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			wp_die( esc_html__( 'That entry no longer exists.', 'orbit-gf' ), 404 );
		}
		$form = GFAPI::get_form( (int) $entry['form_id'] );
		foreach ( $this->get_active_feeds( (int) $form['id'] ) as $feed ) {
			if ( $this->is_feed_condition_met( $feed, $form, $entry ) ) {
				$this->process_feed( $feed, $entry, $form );
			}
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=gf_entries' ) );
		exit;
	}

	// ------------------------------------------------------------------
	// Settings helpers.
	// ------------------------------------------------------------------

	/**
	 * The account's catalogue, or the error that stopped it loading.
	 *
	 * @param string $connection_id Connection ID.
	 * @return FieldCatalogue|WP_Error|null Null when no account is chosen.
	 */
	public function catalogue_for( string $connection_id ) {
		if ( '' === $connection_id || ! $this->connections()->exists( $connection_id ) ) {
			return null;
		}
		$api_key = $this->connections()->api_key( $connection_id );
		if ( null === $api_key ) {
			return new WP_Error( 'orbit_gf_no_key', __( 'This account\'s API key can no longer be read (the site\'s security keys may have changed). Enter the key again under Forms, Orbit accounts.', 'orbit-gf' ) );
		}
		$refresh = isset( $_GET['orbit_gf_refresh'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only bypasses a read cache.
		return ( new Client( $api_key ) )->catalogue( $connection_id, $refresh );
	}

	/**
	 * The form type select, or an explanation of why it cannot be shown.
	 *
	 * @param string                       $connection_id Connection ID.
	 * @param FieldCatalogue|WP_Error|null $catalogue  Catalogue.
	 * @return array<int, array<string, mixed>>
	 */
	private function form_type_fields( string $connection_id, $catalogue ): array {
		if ( null === $catalogue ) {
			return array();
		}
		if ( is_wp_error( $catalogue ) ) {
			return array(
				array(
					'name' => 'catalogueError',
					'type' => 'html',
					'html' => '<div class="notice notice-error inline"><p>' . esc_html( $catalogue->get_error_message() ) . '</p></div>',
				),
			);
		}

		$refresh_url = add_query_arg( 'orbit_gf_refresh', '1' );
		return array(
			array(
				'name'        => 'formTypeId',
				'label'       => esc_html__( 'Orbit form type', 'orbit-gf' ),
				'type'        => 'select',
				'required'    => true,
				'choices'     => $this->choices( $catalogue->form_type_names(), __( 'Choose a form type', 'orbit-gf' ) ),
				'onchange'    => "jQuery(this).parents('form').submit();",
				'description' => sprintf(
					/* translators: 1: opening link tag, 2: closing link tag. */
					esc_html__( 'Each entry becomes a form of this type in Orbit, on the contact\'s record. Form types are set up in Orbit under Forms. Added one just now? %1$sReload the list%2$s.', 'orbit-gf' ),
					'<a href="' . esc_url( $refresh_url ) . '">',
					'</a>'
				),
			),
		);
	}

	/**
	 * A one-off message (from the KulaHub import, say), shown at the top of the feed.
	 *
	 * Gravity Forms hides admin notices on its own screens, so the message is a
	 * settings field. It is read once per request because the framework builds
	 * the settings more than once.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function flash_notice_fields(): array {
		static $notice = null;
		if ( null === $notice ) {
			$notice = ConnectionsPage::take_notice() ?? array();
		}
		if ( empty( $notice ) ) {
			return array();
		}
		return array(
			array(
				'name' => 'orbitNotice',
				'type' => 'html',
				'html' => sprintf(
					'<div class="notice notice-%s inline"><p>%s</p></div>',
					'error' === $notice['type'] ? 'error' : 'success',
					esc_html( $notice['message'] )
				),
			),
		);
	}

	/**
	 * Helper text under the account select.
	 */
	private function connection_description(): string {
		return sprintf(
			/* translators: 1: opening link tag, 2: closing link tag. */
			esc_html__( 'The Orbit account entries are sent to. Accounts are added under %1$sForms, Orbit accounts%2$s.', 'orbit-gf' ),
			'<a href="' . esc_url( ConnectionsPage::url() ) . '">',
			'</a>'
		);
	}

	/**
	 * The contact field map definition.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contact_field_map(): array {
		$map = array();
		foreach ( ContactFields::labels() as $property => $label ) {
			$row = array(
				'name'     => $property,
				'label'    => $label,
				'required' => 'email' === $property,
			);
			if ( 'email' === $property ) {
				$row['field_type']    = array( 'email', 'hidden', 'text' );
				$row['default_value'] = $this->get_first_field_by_type( 'email' );
			}
			$map[] = $row;
		}
		return $map;
	}

	/**
	 * The generic map from the form type's Orbit fields to Gravity Forms fields.
	 *
	 * @param array<string, array{name: string, kind: string, required: bool}> $fields Orbit fields.
	 * @return array<string, mixed>
	 */
	private function form_fields_map( array $fields ): array {
		if ( empty( $fields ) ) {
			return array(
				'name' => 'formFieldsEmpty',
				'type' => 'html',
				'html' => '<p>' . esc_html__( 'This form type has no fields of its own in Orbit, so only the contact details are sent.', 'orbit-gf' ) . '</p>',
			);
		}

		$kinds   = array(
			Columns::KIND_TEXT    => __( 'text', 'orbit-gf' ),
			Columns::KIND_DATE    => __( 'date', 'orbit-gf' ),
			Columns::KIND_MONEY   => __( 'money', 'orbit-gf' ),
			Columns::KIND_BOOLEAN => __( 'tick box', 'orbit-gf' ),
			Columns::KIND_NUMBER  => __( 'number', 'orbit-gf' ),
			Columns::KIND_INTEGER => __( 'whole number', 'orbit-gf' ),
		);
		$choices = array();
		foreach ( $fields as $column => $field ) {
			$label = sprintf( '%s (%s)', $field['name'], $kinds[ $field['kind'] ] ?? $field['kind'] );
			if ( $field['required'] ) {
				/* translators: %s: Orbit field name and type. */
				$label = sprintf( __( '%s, required in Orbit', 'orbit-gf' ), $label );
			}
			$choices[] = array(
				'label' => $label,
				'value' => $column,
			);
		}

		return array(
			'name'        => 'formFields',
			'label'       => esc_html__( 'Orbit fields', 'orbit-gf' ),
			'type'        => 'generic_map',
			'key_field'   => array(
				'title'        => esc_html__( 'Orbit field', 'orbit-gf' ),
				'choices'      => $choices,
				'allow_custom' => false,
			),
			'value_field' => array(
				'title'        => esc_html__( 'Gravity Forms field', 'orbit-gf' ),
				'allow_custom' => false,
			),
		);
	}

	/**
	 * Category tick boxes, or a note when the account has none.
	 *
	 * @param array<int, string> $categories Categories.
	 * @return array<string, mixed>
	 */
	private function categories_field( array $categories ): array {
		if ( empty( $categories ) ) {
			return array(
				'name' => 'categoriesEmpty',
				'type' => 'html',
				'html' => '<p>' . esc_html__( 'This Orbit account has no categories.', 'orbit-gf' ) . '</p>',
			);
		}
		$choices = array();
		foreach ( $categories as $id => $name ) {
			$choices[] = array(
				'label' => $name,
				'name'  => 'category_' . $id,
			);
		}
		return array(
			'name'        => 'categories',
			'label'       => esc_html__( 'Categories', 'orbit-gf' ),
			'type'        => 'checkbox',
			'choices'     => $choices,
			'description' => esc_html__( 'Categories the contact is put in. Tick at least one if your Orbit account requires every contact to have a category, or Orbit refuses the entry. Leave all unticked to add none.', 'orbit-gf' ),
		);
	}

	/**
	 * Turn ID => name into select choices with a first empty option.
	 *
	 * @param array<int|string, string> $options     Options.
	 * @param string                    $placeholder Label of the empty option.
	 * @return array<int, array{label: string, value: string}>
	 */
	private function choices( array $options, string $placeholder ): array {
		$choices = array(
			array(
				'label' => $placeholder,
				'value' => '',
			),
		);
		foreach ( $options as $value => $label ) {
			$choices[] = array(
				'label' => $label,
				'value' => (string) $value,
			);
		}
		return $choices;
	}
}
