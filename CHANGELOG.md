# Changelog

All notable changes to this plugin. The format follows [Keep a Changelog](https://keepachangelog.com/en-GB/1.1.0/).

## [1.0.0] - 2026-11-23

### Added

- **Orbit feeds for Gravity Forms**: entries are sent to Orbit as forms on the contact's record, using Orbit API keys instead of the KulaHub V2 API.
- **Several Orbit accounts**: each account is a named API key, and each feed chooses one.
- **Form types and fields from Orbit**: feeds list the account's form types, their fields, sources and categories.
- **Automatic retries**: temporary failures are retried for up to a day, and Forms, Orbit queue lists anything waiting.
- **KulaHub import**: a form set up for the KulaHub plugin can be turned into an Orbit feed in one click.
