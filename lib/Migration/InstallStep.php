<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Migration;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\ChamadeTalk\Service\BotService;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Installation step — runs on app:enable.
 *
 * 1. Ensure API secret exists
 * 2. Register default bot via BotInstallEvent
 *
 * The bot_id is discovered lazily when the bot first handles a
 * BotInvokeEvent (the event carries the bot ID). We store it in
 * appconfig at that point. This avoids depending on BotServerMapper.
 */
class InstallStep implements IRepairStep {

    public function __construct(
        private IConfig $config,
        private BotService $botService,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string {
        return 'Install ' . Application::APP_ID;
    }

    public function run(IOutput $output): void {
        $this->ensureApiSecret($output);
        $this->ensureDefaultBot($output);
    }

    private function ensureApiSecret(IOutput $output): void {
        $existing = $this->config->getAppValue(Application::APP_ID, 'api_secret', '');
        if (!empty($existing)) {
            $output->info('API secret already exists');
            return;
        }

        $secret = bin2hex(random_bytes(32));
        $this->config->setAppValue(Application::APP_ID, 'api_secret', $secret);
        $output->info('Generated API secret');
    }

    private function ensureDefaultBot(IOutput $output): void {
        $existingSecret = $this->config->getAppValue(Application::APP_ID, 'default_bot_secret', '');
        if (!empty($existingSecret)) {
            $output->info('Default bot already registered (secret exists)');
            return;
        }

        // Check if Talk (spreed) is available — bot registration requires it
        if (!class_exists(\OCA\Talk\Events\BotInstallEvent::class)) {
            $output->warning('Nextcloud Talk not installed — bot registration skipped (text relay unavailable)');
            return;
        }

        $secret = bin2hex(random_bytes(32));
        $brandName = $this->config->getAppValue(Application::APP_ID, 'brand_name', 'Chamade');

        try {
            $this->botService->installBot($brandName, $secret);
            $this->config->setAppValue(Application::APP_ID, 'default_bot_secret', $secret);
            $output->info("Registered default bot: {$brandName}");
        } catch (\Throwable $e) {
            // Most common cause: a legacy orphan row in oc_talk_bots_server
            // from a pre-2.2.0 install that never persisted default_bot_secret,
            // so the uninstall step (then OR now) couldn't dispatch a matching
            // BotUninstallEvent and the row survived. Spreed's BotListener now
            // refuses the new install with "Bot with the same URL and a
            // different secret is already registered".
            //
            // Recovery (one-time admin action — we can't do it from here
            // without touching private spreed APIs):
            //     occ talk:bot:uninstall --url=nextcloudapp://{app_id}
            // then re-run `occ app:enable {app_id}` to re-register cleanly.
            $msg = $e->getMessage();
            $output->warning("Bot registration failed: {$msg}");
            if (stripos($msg, 'same URL') !== false || stripos($msg, 'different secret') !== false) {
                $hint = "Legacy bot orphan detected. Run: occ talk:bot:uninstall --url=nextcloudapp://"
                    . Application::APP_ID
                    . " then re-enable the app.";
                $output->warning($hint);
                $this->logger->error($hint, ['app' => Application::APP_ID]);
            }
        }
    }
}
