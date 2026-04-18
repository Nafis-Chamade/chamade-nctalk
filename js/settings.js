/**
 * Admin settings — {brand_id}_talk.
 *
 * The settings page is purely diagnostic as of v2.5.0: the only action
 * is the "Connect to Chamade" anchor (a plain <a href> that initiates
 * the inverse OAuth flow via the `connect-start` route). There is no
 * form to submit, no pair-code to generate, no user-links table to
 * refresh — all of that was removed when the pairing model switched
 * to the browser-redirect consent flow.
 *
 * This file is intentionally left empty; kept as a placeholder so the
 * `script()` call in `templates/settings.php` doesn't 404 on older
 * installs that still have the stale asset cached.
 */
