<?php
/**
 * Admin settings template for {brand_id}_talk.
 *
 * As of v2.5.0, this page is diagnostic + one-button pairing. No
 * editable fields — all pairing state is owned by the inverse OAuth
 * flow (see ConnectController). Removing the edit surface closed a
 * real incident where a user manually typed a wrong `backend_url`
 * that survived every subsequent authorize because the legacy flow
 * only bootstrapped empty values.
 */
script(\OCA\ChamadeTalk\AppInfo\Application::APP_ID, 'settings');

$appId = $_['app_id'];
$gatewayUrl = $_['gateway_url'];
$isPaired = $_['is_paired'];
$hasBot = $_['has_bot'];
$connectStartUrl = \OC::$server->getURLGenerator()->linkToRoute(
    $appId . '.connect.connectStart',
    ['origin' => 'nc_admin']
);

// Read URL params for the post-flow banner (connected=1 or error=...).
$successBanner = isset($_GET['connected']) && $_GET['connected'] === '1';
$errorBanner = isset($_GET['error']) ? (string) $_GET['error'] : '';
?>

<div id="chamade-talk-settings" class="section">
    <h2><?php p($l->t('Chamade Bridge')); ?></h2>

    <p class="settings-hint">
        <?php p($l->t('Pair this Nextcloud with a Chamade gateway so AI agents can reach Talk rooms through a bot.')); ?>
    </p>

    <?php if ($successBanner): ?>
    <div class="chamade-note chamade-note--success" role="status">
        <span class="chamade-note-icon" aria-hidden="true">✓</span>
        <span><?php p($l->t('Successfully paired with the Chamade gateway.')); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($errorBanner !== ''): ?>
    <div class="chamade-note chamade-note--error" role="alert">
        <span class="chamade-note-icon" aria-hidden="true">✗</span>
        <span>
            <?php p($l->t('Pairing failed')); ?>: <code><?php p($errorBanner); ?></code>.
            <?php p($l->t('Try again — if the problem persists, contact support.')); ?>
        </span>
    </div>
    <?php endif; ?>

    <!-- Bot registration status (separate from pairing — bot is registered
         by InstallStep on app enable, pairing is per-gateway). -->
    <?php if ($hasBot): ?>
    <div class="chamade-note chamade-note--success">
        <span class="chamade-note-icon" aria-hidden="true">✓</span>
        <span><?php p($l->t('Talk bot registered')); ?></span>
    </div>
    <?php else: ?>
    <div class="chamade-note chamade-note--warning">
        <span class="chamade-note-icon" aria-hidden="true">!</span>
        <span><?php p($l->t('Bot not registered — re-enable the app to register.')); ?></span>
    </div>
    <?php endif; ?>

    <!-- Pairing status. -->
    <?php if ($isPaired): ?>
    <div class="chamade-note chamade-note--success">
        <span class="chamade-note-icon" aria-hidden="true">✓</span>
        <span><?php p($l->t('Paired with Chamade gateway')); ?></span>
    </div>
    <?php else: ?>
    <div class="chamade-note chamade-note--warning">
        <span class="chamade-note-icon" aria-hidden="true">!</span>
        <span><?php p($l->t('Not paired with a gateway yet')); ?></span>
    </div>
    <?php endif; ?>

    <!-- Primary action: Connect. One button, no form fields, no surface
         for mis-configuration. -->
    <div class="chamade-field" style="margin-top: 20px;">
        <a href="<?php p($connectStartUrl); ?>" class="button primary chamade-connect-btn">
            <?php if ($isPaired): ?>
                <?php p($l->t('Reconnect to Chamade')); ?>
            <?php else: ?>
                <?php p($l->t('Connect to Chamade')); ?>
            <?php endif; ?>
        </a>
        <em class="chamade-help">
            <?php p($l->t('Opens the Chamade gateway in this browser. You will be asked to sign in and confirm.')); ?>
            <br>
            <?php print_unescaped($l->t(
                'Gateway: <code>%s</code>',
                [\OCP\Util::sanitizeHTML($gatewayUrl)]
            )); ?>
        </em>
    </div>

    <!-- E2EE — zero-knowledge chat (v3.0+). Separate section so it's
         visually distinct from pairing. The keypair + device list below
         never leave this NC server. -->
    <?php
    $e2eeAvailable = (bool) ($_['e2ee_available'] ?? false);
    $e2eeEnabled = (bool) ($_['e2ee_enabled'] ?? false);
    $e2eePubkey = (string) ($_['e2ee_pubkey_b64'] ?? '');
    $e2eeDeviceId = (string) ($_['e2ee_device_id'] ?? '');
    $e2eeFingerprint = (string) ($_['e2ee_fingerprint'] ?? '');
    $e2eeDevices = is_array($_['e2ee_devices'] ?? null) ? $_['e2ee_devices'] : [];
    ?>
    <section id="chamade-e2ee" style="margin-top: 32px;">
        <h3><?php p($l->t('End-to-end encryption (experimental)')); ?></h3>
        <p class="settings-hint">
            <?php p($l->t(
                'When enabled, user ↔ agent messages through this bridge are '
                . 'encrypted between your Nextcloud and the agent\'s MCP shim. '
                . 'Chamade transits opaque ciphertext. Commands (/help, /activate) '
                . 'and auto-messages stay in plaintext — documented behaviour.'
            )); ?>
        </p>

        <?php if (!$e2eeAvailable): ?>
        <div class="chamade-note chamade-note--warning">
            <span class="chamade-note-icon" aria-hidden="true">!</span>
            <span><?php p($l->t('libsodium (ext-sodium) is not loaded on this PHP. E2EE cannot run here.')); ?></span>
        </div>
        <?php else: ?>
        <div class="chamade-field">
            <label class="chamade-field-inline">
                <input type="checkbox" id="chamade-e2ee-toggle" <?php if ($e2eeEnabled) echo 'checked'; ?>>
                <?php p($l->t('Enable E2EE on this addon')); ?>
            </label>
            <em class="chamade-help">
                <?php p($l->t('Generates a curve25519 keypair on first enable. Disable to fall back to plaintext transit.')); ?>
            </em>
        </div>

        <?php if ($e2eePubkey !== ''): ?>
        <div class="chamade-field">
            <label><?php p($l->t('Addon public key (paste into shim)')); ?></label>
            <input type="text" value="<?php p($e2eePubkey); ?>" readonly class="chamade-readonly" />
            <em class="chamade-help">
                <?php p($l->t('Fingerprint:')); ?>
                <code><?php p($e2eeFingerprint); ?></code><br>
                <?php p($l->t('Device ID:')); ?>
                <code><?php p($e2eeDeviceId); ?></code>
            </em>
            <div style="margin-top: 8px;">
                <button type="button" id="chamade-e2ee-regen" class="button">
                    <?php p($l->t('Regenerate keypair')); ?>
                </button>
                <em class="chamade-help">
                    <?php p($l->t('Paired shims must re-paste this new pubkey after rotation.')); ?>
                </em>
            </div>
        </div>
        <?php endif; ?>

        <div class="chamade-field" style="margin-top: 16px;">
            <h4 style="margin: 0 0 8px 0;"><?php p($l->t('Paired shims (devices)')); ?></h4>
            <ul id="chamade-e2ee-devices" class="chamade-device-list">
                <?php if (empty($e2eeDevices)): ?>
                    <li class="chamade-device-empty">
                        <?php p($l->t('No devices paired yet. Run `chamade_e2ee_enable` in your shim, then paste its pubkey below.')); ?>
                    </li>
                <?php else: foreach ($e2eeDevices as $dev): ?>
                    <li data-device-id="<?php p($dev['device_id']); ?>">
                        <div>
                            <strong><?php p(($dev['label'] ?: $l->t('(no label)'))); ?></strong>
                            <code style="font-size: 0.8em;"><?php p($dev['device_id']); ?></code>
                        </div>
                        <div class="chamade-help">
                            <?php p($l->t('Fingerprint:')); ?>
                            <code><?php p($dev['fingerprint']); ?></code>
                        </div>
                        <button type="button" class="button chamade-e2ee-device-remove" data-device-id="<?php p($dev['device_id']); ?>">
                            <?php p($l->t('Remove')); ?>
                        </button>
                    </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>

        <div class="chamade-field">
            <label for="chamade-e2ee-new-pubkey"><?php p($l->t('Add a shim pubkey')); ?></label>
            <input type="text" id="chamade-e2ee-new-pubkey" placeholder="base64-encoded curve25519 pubkey" />
            <input type="text" id="chamade-e2ee-new-label" placeholder="<?php p($l->t('label (optional, e.g. \'laptop\')')); ?>" />
            <button type="button" id="chamade-e2ee-add-device" class="button primary">
                <?php p($l->t('Add device')); ?>
            </button>
        </div>
        <?php endif; /* e2eeAvailable */ ?>
    </section>

    <!-- Diagnostic: display api_secret (read-only) for support/troubleshooting. -->
    <?php if ($_['api_secret'] !== ''): ?>
    <details class="chamade-diag">
        <summary><?php p($l->t('Diagnostic details')); ?></summary>
        <div class="chamade-field">
            <label><?php p($l->t('API Secret (HMAC)')); ?></label>
            <input type="text" value="<?php p($_['api_secret']); ?>" readonly class="chamade-readonly" />
            <em class="chamade-help">
                <?php p($l->t('Auto-generated at install. The gateway stores this to sign server-to-server webhooks.')); ?>
            </em>
        </div>
        <?php if ($_['backend_url'] !== ''): ?>
        <div class="chamade-field">
            <label><?php p($l->t('Backend URL (current)')); ?></label>
            <input type="text" value="<?php p($_['backend_url']); ?>" readonly class="chamade-readonly" />
            <em class="chamade-help">
                <?php p($l->t('Set by the last Connect flow. Not editable — the connect flow is the authoritative source.')); ?>
            </em>
        </div>
        <?php endif; ?>
    </details>
    <?php endif; ?>
</div>

<style>
.chamade-field { margin: 12px 0; }
.chamade-field label { display: block; font-weight: bold; margin-bottom: 4px; }
.chamade-field input { width: 400px; }
.chamade-help { display: block; color: var(--color-text-maxcontrast); font-size: 0.9em; margin-top: 4px; }
.chamade-readonly {
    background: var(--color-background-dark);
    cursor: default;
    font-family: var(--font-face-monospace, monospace);
    font-size: 0.85em;
}
/* Status blocks — use the documented NC CSS variable pairs:
   --color-{success,warning,error} for the background and the
   matching --color-{...}-text for foreground. These pairs are
   defined by NC core theming specifically so the text stays
   legible on that background on every theme. NC's own .notecard
   is a Vue component with scoped (data-v-*) styles that a PHP
   template can't inject, so we cannot reuse it directly — but
   the CSS variable pattern works the same everywhere.
   Ref: developer_manual/html_css_design/css. */
.chamade-note {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 8px 0;
    padding: 10px 14px;
    border-radius: 6px;
    line-height: 1.45;
    font-weight: 500;
}
.chamade-note-icon {
    flex: 0 0 auto;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 13px;
    background: rgba(255,255,255,0.25);
}
.chamade-note--success {
    background: var(--color-success, #46ba61);
    color: var(--color-success-text, #fff);
}
.chamade-note--warning {
    background: var(--color-warning, #c9930a);
    color: var(--color-warning-text, #fff);
}
.chamade-note--error {
    background: var(--color-error, #c92c2c);
    color: var(--color-error-text, #fff);
}
.chamade-connect-btn {
    padding: 8px 16px;
    text-decoration: none;
    display: inline-block;
}
.chamade-diag { margin-top: 24px; }
.chamade-diag summary {
    cursor: pointer;
    color: var(--color-text-maxcontrast);
    font-size: 0.9em;
}
</style>
