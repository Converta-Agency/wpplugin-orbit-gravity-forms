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

## What it does

| Feature | Detail |
| --- | --- |
| Several Orbit accounts | Each account is a named API key; each feed picks one, so different forms can go to different accounts |
| Feeds per form | Built on the Gravity Forms feed framework: several feeds per form, each with an optional condition |
| Form types from Orbit | The feed lists the account's form types and each type's fields, so nothing is typed by hand |
| Contact matching | Orbit attaches the form to the contact with the same email, or creates the contact |
| Typed values | Tick boxes become `true`/`false`, money and numbers become numbers (`£1,250` gives `1250`), dates become ISO dates |
| Email opt-in | Tick boxes, consent fields and Yes/No answers all work; "No thanks" and "Opt out" are read as no |
| Source and categories | Optional per feed, chosen from the account's lists |
| Background sending | Entries are sent after the visitor's page has loaded, so Orbit's speed never slows the form |
| Retries | Temporary failures are retried after 5 minutes, 30 minutes, 2 hours, 6 hours and 24 hours; **Forms, Orbit queue** lists anything waiting, with Retry and Dismiss |
| Entry box | Each entry shows its Orbit form ID and has **Send to Orbit again** |
| KulaHub import | A form set up for the KulaHub plugin offers **Create feed from KulaHub settings** (also `wp orbit-gf import-legacy`) |
| Updates | New GitHub releases appear in Plugins like any other update (needs a token, see below) |

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
