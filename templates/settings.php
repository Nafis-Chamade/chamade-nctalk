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
