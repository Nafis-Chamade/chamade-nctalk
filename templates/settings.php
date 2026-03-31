<?php
/**
 * Admin settings template for chamade_talk.
 * Displayed in NC admin > Talk section.
 */
script(\OCA\ChamadeTalk\AppInfo\Application::APP_ID, 'settings');
?>

<div id="chamade-talk-settings" class="section">
    <h2><?php p($l->t('Chamade — AI Agent')); ?></h2>

    <p class="settings-hint">
        <?php p($l->t('Configure the connection to the Chamade bridge service.')); ?>
    </p>

    <?php if ($_['has_bot']): ?>
    <div class="chamade-status chamade-ok">
        ✓ <?php p($l->t('Bot registered (ID: %s)', [$_['bot_id']])); ?>
    </div>
    <?php else: ?>
    <div class="chamade-status chamade-warning">
        ⚠ <?php p($l->t('Bot not registered. Re-enable the app to register.')); ?>
    </div>
    <?php endif; ?>

    <form id="chamade-settings-form">
        <div class="chamade-field">
            <label for="chamade-backend-url">
                <?php p($l->t('Backend URL')); ?>
            </label>
            <input type="url"
                   id="chamade-backend-url"
                   name="backend_url"
                   value="<?php p($_['backend_url']); ?>"
                   placeholder="https://bridge.example.com"
            />
            <em class="chamade-help">
                <?php p($l->t('The URL of the bridge service.')); ?>
            </em>
        </div>

        <div class="chamade-field">
            <label for="chamade-api-key">
                <?php p($l->t('API Key')); ?>
            </label>
            <input type="password"
                   id="chamade-api-key"
                   name="api_key"
                   value="<?php p($_['api_key']); ?>"
                   placeholder="<?php p($l->t('API key for the bridge service')); ?>"
            />
        </div>

        <div class="chamade-field">
            <label for="chamade-callback-url">
                <?php p($l->t('Callback URL')); ?>
            </label>
            <input type="url"
                   id="chamade-callback-url"
                   name="callback_url"
                   value="<?php p($_['callback_url']); ?>"
                   placeholder="https://chamade.io/api/nctalk/callback"
            />
            <em class="chamade-help">
                <?php p($l->t('Optional. Set automatically when connecting from Chamade.')); ?>
            </em>
        </div>

        <div class="chamade-field">
            <label><?php p($l->t('API Secret (HMAC)')); ?></label>
            <input type="text"
                   value="<?php p($_['api_secret']); ?>"
                   readonly
                   class="chamade-readonly"
            />
            <em class="chamade-help">
                <?php p($l->t('Auto-generated. Configure this in the bridge service.')); ?>
            </em>
        </div>

        <button type="submit" class="primary">
            <?php p($l->t('Save')); ?>
        </button>
        <span id="chamade-save-status"></span>
    </form>
</div>

<style>
.chamade-field { margin: 12px 0; }
.chamade-field label { display: block; font-weight: bold; margin-bottom: 4px; }
.chamade-field input { width: 400px; }
.chamade-help { display: block; color: var(--color-text-maxcontrast); font-size: 0.9em; margin-top: 2px; }
.chamade-readonly { background: var(--color-background-dark); cursor: default; }
.chamade-status { margin: 8px 0; padding: 6px 12px; border-radius: 4px; }
.chamade-ok { background: var(--color-success); color: white; }
.chamade-warning { background: var(--color-warning); }
</style>
