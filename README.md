# Orbit for Gravity Forms

Sends [Gravity Forms](https://www.gravityforms.com/) entries to Orbit CRM as forms on the contact's record, using Orbit API keys. It replaces the KulaHub plugin (`wpplugin-kulahub-gravity-forms`), which posts to the legacy KulaHub V2 API.

## Quick start

1. Install the plugin from the latest release's `orbit-gravity-forms.zip` (Plugins, Add New, Upload Plugin) and activate it. Gravity Forms 2.5 or later must already be active.
2. In Orbit, open the account menu (top right), choose **Access and API keys** and click **New key**. Copy the key.
3. In WordPress, go to **Forms, Orbit accounts**, add the account with the key and check it says "Orbit accepted the key".
4. Open a form, then **Settings, Orbit**, and click **Add New**. Choose the account and Orbit form type, map the contact fields (email is required) and the form type's fields, and save.

Submit the form and the entry's **Orbit** box shows the Orbit form it created.

## Prerequisites

- WordPress 6.0 or later, PHP 7.4 or later (with the sodium extension, standard since PHP 7.2)
- Gravity Forms 2.5 or later
- An Orbit account and a full-access API key (data sync keys are refused)

## Features

### Orbit accounts

**Forms, Orbit accounts** holds the Orbit API keys the site can send to.

- Add as many accounts as you need, each with your own name (for example "Acme Ltd"). Each feed chooses one, so different forms can send to different Orbit accounts.
- Every key is tested against Orbit when it is saved. **Test connection** re-checks it at any time.
- Keys are write-only. Once saved, only the last four characters are shown (`••••••••7df8`). **Edit** renames the account or replaces the key (leave the key empty to keep it), and **Remove** deletes it.
- The key format is checked before saving, so a pasted label or partial key is refused with an explanation.
- Managing accounts needs the `manage_options` capability (administrators).

### Feeds

Each form can have several Orbit feeds (open the form, then **Settings, Orbit, Add New**). A feed has these sections:

| Section | Settings |
| --- | --- |
| Orbit account and form type | Feed name; Orbit account; Orbit form type, listed from the account. **Reload the list** picks up a form type added in Orbit in the last 10 minutes |
| Contact details | Field map for email (required), first name, last name, title, job title, organisation name, telephone, mobile, website, address lines 1–3, town, county, postcode and country. Name and address sub-fields (First, Last, Street and so on) can be mapped individually |
| Email opt-in | The tick box, consent field or Yes/No question that subscribes a new contact. Empty means new contacts are not subscribed |
| Name capitalisation | Optional: tidies names typed all in capitals or all in lower case ("JANE SMITH" becomes "Jane Smith", "o'brien" becomes "O'Brien"). Applies to first name, last name, title, town and county only; mixed-case names, emails and postcodes are never changed |
| Form fields | The chosen form type's own fields, listed by name with their type (text, number, money, date, tick box, whole number) and marked when Orbit requires them. Map each to a Gravity Forms field |
| Source and categories | An optional Orbit source (stored on the form and on new contacts) and categories to put the contact in, both listed from the account |
| Conditions | Gravity Forms conditional logic: send only when the entry meets a rule, for example "Enquiry type is Brochure" |

The feed list shows each feed's name, Orbit account and form type, and feeds can be switched on and off, duplicated and deleted like any Gravity Forms feed.

### How values are sent

| Orbit field type | What the plugin sends |
| --- | --- |
| Text | The answer, trimmed; several ticked choices are joined with commas |
| Number, money | A number: `£1,250.50` and `1250.5` both give `1250.5` |
| Whole number | A rounded number |
| Date | An ISO date; Gravity Forms dates and UK `dd/mm/yyyy` are both read correctly |
| Tick box (and email opt-in) | `true` or `false`. A tick box or consent field is true when ticked. A radio, select or text answer is read as Yes/No: "Yes", "Yes please", "Opt in" are true; "No", "No thanks", "Not now", "Opt out", "Unsubscribe" and an empty answer are false |

Empty answers are left out, so they never blank a value. Email addresses are sent in lower case. An entry Orbit would refuse (no contact details, or an email address that is invalid or longer than Orbit's 80 characters) is stopped with a clear note instead of being sent.

### Contacts in Orbit

Orbit attaches each form to the contact with the same email address, or creates a new contact. When the contact already exists, Orbit keeps the details it holds; the submission does not overwrite them or change their subscription.

### Sending and retries

- Entries are sent in a background request after the visitor's confirmation page has loaded, so Orbit's response time never slows the form.
- On success the entry gets a note ("Sent to Orbit as form 2370975 for contact 9214748") and stores the Orbit form and contact IDs.
- Temporary failures (Orbit unreachable, rate limited or erroring) are retried automatically after 5 minutes, 30 minutes, 2 hours, 6 hours and 24 hours.
- Failures that sending again cannot fix (a refused or removed key, a missing form type, data Orbit rejects) are not retried; they wait for someone to fix the cause.
- Every failure adds an error note to the entry with Orbit's reason in plain words, read from Orbit's validation messages.

### Orbit queue

**Forms, Orbit queue** lists entries that have not reached Orbit, with a count on the menu item.

- Each row shows the entry (linked), form, status ("Retrying automatically" or "Needs attention"), attempts, last error and next retry time.
- **Retry** sends the entry now; **Dismiss** stops trying and keeps the entry in Gravity Forms.
- **Also show sent and dismissed entries** shows the history.

### Entry screen

Each entry on a form with an Orbit feed has an **Orbit** box showing, per feed, the Orbit form it created or that it has not been sent. **Send to Orbit again** resends it through every active feed whose condition it meets (Orbit adds a new form each time).

### Moving from the KulaHub plugin

A form still set up for the KulaHub plugin (a KulaHub form ID in the form settings and KulaHub field IDs on its fields) shows **Create feed from KulaHub settings** on its Orbit feed screen. Choose the Orbit account and the plugin:

- uses the KulaHub form ID as the Orbit form type (they are the same, as Orbit and KulaHub share a database) and checks the account has it;
- maps contact field IDs (`firstname`, `email`, `postcode` and so on) exactly, `emailsubscribe` to the email opt-in, and custom field codes (`varchar1`, `money2`, `bit1`, `datetime1`, `number1`) to the matching Orbit fields;
- lists any field ID it could not map, for example `coursetitle`, so you can map it by hand;
- turns on name capitalisation, closest to the KulaHub plugin's behaviour.

The KulaHub client ID is no longer needed. Check the new feed, then deactivate the KulaHub plugin so entries are not sent twice.

### WP-CLI

| Command | What it does |
| --- | --- |
| `wp orbit-gf accounts` | Lists accounts with masked keys |
| `wp orbit-gf add-account <name> <api-key>` | Adds an account and tests it |
| `wp orbit-gf import-legacy --account=<id> [--form=<id>] [--dry-run]` | Creates feeds from KulaHub settings for one form or every form without an Orbit feed; `--dry-run` shows the mapping without saving |
| `wp orbit-gf retry` | Sends queued entries that are due now |

### For developers

| Hook | Type | Use |
| --- | --- | --- |
| `orbit_gf_payload` | filter | Change the request body before it is sent: `( $payload, $feed, $entry, $form )` |
| `orbit_gf_sent` | action | Runs after Orbit creates the form: `( $orbit_form, $feed, $entry, $form )` |
| `orbit_gf_api_url` | filter | Change the Orbit API address |

Debug and error logs go to the Gravity Forms logging add-on (**Forms, Settings, Logging**, then enable **Orbit for Gravity Forms**). API keys are never logged.

### Updates

New releases of this repository appear under **Plugins** like any other update, with release notes under **View details**. As the repository is private, the site needs `ORBIT_GF_GITHUB_TOKEN` (see below); without it the plugin works but does not see updates. Release checks are cached for six hours.

### Privacy and removal

- Mapped answers are sent only to Orbit CRM. The plugin stores Orbit's form and contact IDs on each entry and the IDs of entries waiting to be sent; it keeps no other copy of submitted data.
- Deactivating stops the retry job. Deleting the plugin removes its accounts, Orbit feeds, queue table, cached Orbit lists and scheduled job. The Orbit IDs and notes stay on the entries as a record of what was sent.

## Improvements over the KulaHub plugin

- The API key travels in the `X-Api-Key` header, never the URL, and is encrypted in the database (libsodium, keyed from `ORBIT_GF_ENCRYPTION_KEY` or the site's auth salt). The admin screen shows only its last four characters.
- No client ID: the key decides the Orbit account.
- Contact fields are matched exactly. The old substring match sent a field called `coursetitle` as the contact's title.
- Values are no longer title-cased across the board (which turned `HG1 1JX` into `Hg1 1jx`). An optional setting tidies names typed all in capitals or all in lower case, and leaves everything else alone.
- Errors are explained in plain words (Orbit's validation messages are read from its problem details), and permanent errors are not retried pointlessly.
- No personal data is copied into the plugin's own table: the queue stores entry IDs; the data stays in the Gravity Forms entry.
- No debug logging of whole submissions to `error_log`; logging goes through the Gravity Forms logging add-on (**Forms, Settings, Logging**).

## Configuration constants

Set in `wp-config.php` when needed:

| Constant | Purpose |
| --- | --- |
| `ORBIT_GF_GITHUB_TOKEN` | Fine-grained GitHub token with read access to this repository's contents. Needed for update notifications, as the repository is private |
| `ORBIT_GF_ENCRYPTION_KEY` | Secret used to encrypt stored API keys. Defaults to the site's auth salt; set it if salts are rotated, or stored keys must be entered again |
| `ORBIT_GF_API_URL` | Orbit API address. Defaults to `https://api.orbitcrm.co.uk`; for testing against a local or staging API only |
| `ORBIT_GF_SSL_VERIFY` | Set to `false` only for a local API with a development certificate |

## Development

See [docs/developer.md](docs/developer.md) for the architecture, hooks, local test site and release process.

```bash
composer install
composer preflight   # validate, PHPCS, PHPUnit
```

## Troubleshooting

| Problem | Fix |
| --- | --- |
| "Orbit refused the API key" | The key was mistyped or revoked. Create a new one in Orbit, Access and API keys, and edit the account |
| "is not allowed to do this" | The key is a reporting (data sync) key. Create a full-access key |
| "Contacts must be in at least one category" | The Orbit account requires a category: tick one in the feed's **Categories** |
| HTTP 500 when the contact already exists and the feed sets categories | A known Orbit bug: Orbit tries to add a category the contact already has. Until it is fixed in Orbit, leave **Categories** unticked on forms that repeat contacts, or use an Orbit workflow to categorise |
| "This account's API key can no longer be read" | The site's security salts changed. Edit the account and paste the key again, and consider setting `ORBIT_GF_ENCRYPTION_KEY` |
| Entries never leave the queue | WP-Cron is not running. Run `wp cron event run --due-now` from the system scheduler every few minutes |

## Licence

GPL-2.0-or-later.
