=== Orbit for Gravity Forms ===
Contributors: converta
Tags: gravity forms, orbit, crm, integration
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends Gravity Forms entries to Orbit CRM as forms on the contact's record.

== Description ==

Add an Orbit API key, then add an Orbit feed to any form. Each entry becomes a form of the chosen Orbit form type, attached to the contact with the same email address (or a new contact).

* Several Orbit accounts on one site
* Form types, fields, sources and categories loaded from Orbit
* Tick boxes, numbers, money and dates sent in the type Orbit stores
* Sent in the background, with automatic retries and a queue screen
* One-click import of settings from the KulaHub plugin

== Installation ==

1. Activate Gravity Forms 2.5 or later.
2. Upload and activate this plugin.
3. In Orbit, open the account menu, choose Access and API keys, and click New key.
4. In WordPress, go to Forms, Orbit accounts and add the key.
5. Open a form, then Settings, Orbit, and add a feed.

== Privacy ==

When a form with an Orbit feed is submitted, the mapped fields are sent to Orbit CRM (api.orbitcrm.co.uk). The plugin stores Orbit's form and contact IDs on the entry, and the IDs of entries waiting to be sent; it keeps no other copy of submitted data.

== Changelog ==

= 1.0.0 =
* First release: Orbit feeds, several accounts, automatic retries and KulaHub import.
