/**
 * Admin settings — {brand_id}_talk.
 *
 * Handles the E2EE (v3.0+) section: toggle, regenerate keypair, add/
 * remove paired shim devices. Connect flow remains a plain <a href>
 * driven anchor, no JS needed there.
 *
 * All mutating calls go to the `/apps/{app}/settings/e2ee/*` routes
 * defined in appinfo/routes.php → E2eeAdminController. NC core provides
 * `OC.requestToken` for the CSRF requesttoken header.
 */
(function () {
    'use strict';

    const appSlug = document.body.getAttribute('data-appid')
        || document.querySelector('#chamade-talk-settings')?.closest('[data-app]')?.getAttribute('data-app')
        || 'chamade_talk';

    function requestToken() {
        return (window.OC && OC.requestToken) || '';
    }

    function apiUrl(path) {
        const generator = window.OC && OC.generateUrl;
        const base = `/apps/${appSlug}`;
        if (generator) {
            return generator(base + path);
        }
        return base + path;
    }

    async function call(method, path, body) {
        const headers = {
            'Content-Type': 'application/json',
            'requesttoken': requestToken(),
            'OCS-APIREQUEST': 'true',
        };
        const init = { method, headers, credentials: 'same-origin' };
        if (body !== undefined) {
            init.body = JSON.stringify(body);
        }
        const r = await fetch(apiUrl(path), init);
        let payload = null;
        try { payload = await r.json(); } catch (_) { /* empty body ok */ }
        if (!r.ok) {
            const msg = (payload && payload.error) || `HTTP ${r.status}`;
            throw new Error(msg);
        }
        return payload;
    }

    function reloadSoon() {
        // After a state change we reload the page so the template re-
        // renders from the fresh appconfig — avoids having to mirror
        // server-side state in two places. Short debounce so users see
        // the button reacted before the reload wipes UI state.
        setTimeout(() => window.location.reload(), 300);
    }

    function flash(msg, kind) {
        // Fall back to alert() if OC.dialogs is unavailable — this is an
        // admin page, polish can come later.
        if (window.OC && OC.dialogs && OC.dialogs.info) {
            OC.dialogs.info(msg, kind === 'error' ? 'E2EE' : 'Chamade');
        } else {
            /* eslint-disable-next-line no-alert */
            alert(msg);
        }
    }

    const toggle = document.getElementById('chamade-e2ee-toggle');
    if (toggle) {
        toggle.addEventListener('change', async (evt) => {
            try {
                await call('POST', '/settings/e2ee/toggle', { enabled: evt.target.checked });
                reloadSoon();
            } catch (e) {
                toggle.checked = !evt.target.checked;
                flash(`Toggle failed: ${e.message}`, 'error');
            }
        });
    }

    const regen = document.getElementById('chamade-e2ee-regen');
    if (regen) {
        regen.addEventListener('click', async () => {
            if (!window.confirm('Regenerate addon keypair? Paired shims will need to re-paste the new pubkey before they can send or receive E2EE messages.')) {
                return;
            }
            try {
                await call('POST', '/settings/e2ee/regenerate');
                reloadSoon();
            } catch (e) {
                flash(`Regenerate failed: ${e.message}`, 'error');
            }
        });
    }

    const addBtn = document.getElementById('chamade-e2ee-add-device');
    if (addBtn) {
        addBtn.addEventListener('click', async () => {
            const pubInput = document.getElementById('chamade-e2ee-new-pubkey');
            const labelInput = document.getElementById('chamade-e2ee-new-label');
            const pubkey = (pubInput?.value || '').trim();
            const label = (labelInput?.value || '').trim();
            if (!pubkey) {
                flash('Paste the shim pubkey first.', 'error');
                return;
            }
            try {
                await call('POST', '/settings/e2ee/devices', {
                    pubkey_b64: pubkey,
                    label: label,
                });
                reloadSoon();
            } catch (e) {
                flash(`Add device failed: ${e.message}`, 'error');
            }
        });
    }

    document.querySelectorAll('.chamade-e2ee-device-remove').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const deviceId = btn.getAttribute('data-device-id');
            if (!deviceId) return;
            if (!window.confirm(`Remove device ${deviceId}? The corresponding shim will stop receiving encrypted messages.`)) {
                return;
            }
            try {
                await call('DELETE', `/settings/e2ee/devices/${encodeURIComponent(deviceId)}`);
                reloadSoon();
            } catch (e) {
                flash(`Remove device failed: ${e.message}`, 'error');
            }
        });
    });
})();
