# Changelog

All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

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
