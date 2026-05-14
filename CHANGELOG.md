# Changelog

All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

## [3.0.0] — 2026-05-14

### Added
- **End-to-end encryption for chat.** Sealed-box x25519 (NaCl `crypto_box_seal`) between the addon and the user's AI agent host. Each side holds its own keypair: the addon stores its keypair in this Nextcloud's app config, the agent host stores its own. Messages are sealed for the recipient's public key on the way out and opened on arrival; the Chamade gateway only relays opaque ciphertext and never holds the private keys. A new admin section under Settings → Talk lets the operator toggle E2EE, regenerate the addon's keypair, view the addon pubkey + fingerprint + device id, and manage paired agent shim device keys (add / remove / verify by fingerprint). State persists in appconfig. PHP `ext-sodium` on the addon side matches the libsodium-wrappers implementation on the shim side byte-for-byte. Opt-in: off by default, no behavior change for existing installs. Call audio still flows through the standard High-Performance Backend — E2EE is chat-only in this release.
- **Unified send endpoint** `POST /api/v1/messages/{token}` (`MessageController::send`). HMAC-authed like the rest of the addon's gateway-facing routes. Accepts `{bot_username, content?, encrypted?}` (mutually exclusive). Plaintext path forwards via `TalkApiService::sendMessage`; encrypted path is decrypted with the addon's private key before forwarding. The legacy OCS send stays in place — Chamade picks between the two paths based on the capability set advertised in the authorize callback, so older addons (≤ 2.5.0) keep working unchanged.
- **Capability advertisement and heartbeat.** The `/authorize/finish` callback now includes `addon_capabilities` and `addon_e2ee_schemes` so the gateway knows whether the new send path and E2EE are available on this instance. An `/api/e2ee/heartbeat` POST is sent after every admin E2EE action so the gateway's view of capabilities stays in sync without polling.

### Fixed
- `GET /settings` returned a 500 because `routes.php` declared it as `settings#index` but `SettingsController` only had `save()`. Any admin who landed on the addon's settings URL directly (e.g. via Nextcloud "Apps" admin) hit the unresolved method. Rerouted the GET to the existing `save()`, which returns a noop JSON read-only payload — the addon has had no admin-editable fields since 2.5.0.
- `AdminSettings::getForm()` now calls `\OCP\Util::addScript()`. Without it `js/settings.js` never loaded on the admin section, and the E2EE toggle / regenerate / add-device / remove-device buttons rendered with no click handlers — the page was inert.

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
