<?php
/** @var array $_ */
style('core', 'server');
?>

<div id="authorize-page" style="max-width: 500px; margin: 60px auto; padding: 30px; background: var(--color-main-background); border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <h2 style="margin-bottom: 20px; text-align: center;">
        <?php p($l->t('Authorize %s', [$_['agent_name']])); ?>
    </h2>

    <p style="margin-bottom: 24px; text-align: center; color: var(--color-text-maxcontrast);">
        <strong><?php p($_['agent_name']); ?></strong>
        <?php p($l->t('wants to connect to your Nextcloud Talk instance.')); ?>
    </p>

    <p style="margin-bottom: 24px; text-align: center; font-size: 0.9em; color: var(--color-text-maxcontrast);">
        <?php p($l->t('A bot account will be created to allow the agent to join Talk conversations.')); ?>
    </p>

    <form method="POST" action="<?php print_unescaped(\OC::$server->getURLGenerator()->linkToRoute(
        \OCA\ChamadeTalk\AppInfo\Application::APP_ID . '.authorize.approve'
    )); ?>">
        <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
        <input type="hidden" name="callback_url" value="<?php p($_['callback_url']); ?>">
        <input type="hidden" name="state" value="<?php p($_['state']); ?>">
        <input type="hidden" name="agent_name" value="<?php p($_['agent_name']); ?>">

        <div style="display: flex; gap: 12px; justify-content: center; margin-top: 24px;">
            <button type="submit" class="primary" style="padding: 10px 32px; font-size: 1em;">
                <?php p($l->t('Authorize')); ?>
            </button>
        </div>
    </form>
</div>
