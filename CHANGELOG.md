# Changelog

All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

## [2.5.0] — 2026-04-18

### Added
- NC-first inverse OAuth pairing flow (`ConnectController::connectStart` + `authorizeFinish`). Admin clicks "Connect to Chamade" in the addon settings page; the addon redirects to the gateway, the user authenticates and consents to linking this NC by hostname, and the addon creates the bot + posts credentials back without the admin ever editing a field. Replaces the Chamade-first redirect-authorize path as the canonical flow (legacy `/authorize` endpoints remain for backward compat).
- `gateway_url` appconfig override for pointing dev installs at a non-default gateway URL (`occ config:app:set chamade_talk gateway_url https://dev.example.com`).

### Changed
- Admin settings page is now diagnostic-only: status blocks + one "Connect to Chamade" button. No editable fields — the write path through `SettingsController::save` is a no-op. Closes a real incident where an admin manually typed a wrong backend URL and every subsequent authorize silently skipped correcting it.
- `backend_url` + `callback_url` are now authoritatively overwritten on every successful authorize (previously only bootstrapped when empty). The connect flow is the single source of truth.
- Status blocks use the documented NC `--color-{success,warning,error}-text` / background variable pair so contrast stays correct on every theme.

### Removed
- Legacy `PairController` + its 6 `/api/v1/pair/*` routes. Unused by any known caller and no longer reachable from the UI.
- Dead form/pair/user-links JS handlers from `js/settings.js`.

### Fixed
- Post-pairing browser redirect lands on `/settings/admin/talk` (correct section path) instead of a 403.
- `UninstallStep` now also wipes ephemeral `pending_nc_state` on disable.
- l10n `fr.json` + `en.json` are now bundled in the App Store tarball (pre-existing gap — translations were missing from every release up to 2.4.1, so non-English users saw raw source strings).

## [2.0.1] — 2026-04-04

### Fixed
- Repository and documentation URLs in info.xml
- App name and description (user-centric framing)
- Added screenshot and README

## [2.0.0] — 2026-03-31

Initial public release as `chamade_talk`, extracted from internal codebase.

### Features
- Owner-scoped bot users — each bot is tied to the NC user who authorized it
- Room authorization via `/activate` and `/deactivate` commands
- Text-only fallback for Nextcloud instances without HPB
- HMAC-secured API for all bridge communication
- Admin settings panel (Backend URL, API Key)
- Authorization flow for connecting AI agents
- i18n support (English, French)

### Architecture (v2.0.0)
- All Talk interactions via OCS REST API (`TalkApiService`) — no private imports
- Centralized HMAC verification (`HmacVerification` trait)
- Persistent bot passwords (no reset on each request)
- `class_exists` guard for Talk availability (installs without Talk)
