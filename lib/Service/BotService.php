<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Service;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\Talk\Events\BotInstallEvent;
use OCA\Talk\Events\BotUninstallEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Bot registration and room management for the {brand_id}_talk app.
 *
 * Uses BotInstallEvent/BotUninstallEvent (official Talk API for NC apps)
 * and TalkApiService for per-room enablement (OCS REST).
 */
class BotService {

    public function __construct(
        private IConfig $config,
        private IEventDispatcher $dispatcher,
        private TalkApiService $talkApi,
        private LoggerInterface $logger,
    ) {
    }

    public function installBot(string $name, string $secret): void {
        $event = new BotInstallEvent(
            $name,
            $secret,
            'nextcloudapp://' . Application::APP_ID,
            "Cross-platform meeting bridge",
            4 | 2, // FEATURE_EVENT | FEATURE_RESPONSE
        );
        $this->dispatcher->dispatchTyped($event);

        $this->logger->info("Bot installed: {$name}", ['app' => Application::APP_ID]);
    }

    public function uninstallBot(int $botId): void {
        // Use the stored secret + known URL to dispatch BotUninstallEvent
        // (no need for BotServerMapper — we stored the secret at install time)
        $secret = $this->config->getAppValue(Application::APP_ID, 'default_bot_secret', '');
        $url = 'nextcloudapp://' . Application::APP_ID;

        if (empty($secret)) {
            $this->logger->warning("Cannot uninstall bot {$botId}: no stored secret", [
                'app' => Application::APP_ID,
            ]);
            return;
        }

        try {
            $event = new BotUninstallEvent($secret, $url);
            $this->dispatcher->dispatchTyped($event);
        } catch (\Exception $e) {
            $this->logger->warning("Bot uninstall failed: " . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Enable the bot in a room via OCS REST API.
     *
     * Uses admin credentials stored in appconfig (service_user/service_user_password).
     */
    public function enableBotInRoom(int $botId, string $roomToken): void {
        $adminUser = $this->getAdminUser();
        $adminPass = $this->getAdminPassword();

        if (empty($adminUser) || empty($adminPass)) {
            $this->logger->warning("Cannot enable bot in room: no admin credentials configured", [
                'app' => Application::APP_ID,
            ]);
            return;
        }

        $ok = $this->talkApi->enableBotInRoom($botId, $roomToken, $adminUser, $adminPass);
        if ($ok) {
            $this->logger->info("Bot {$botId} enabled in room {$roomToken}", [
                'app' => Application::APP_ID,
            ]);
        }
    }

    private function getAdminUser(): string {
        return $this->config->getAppValue(Application::APP_ID, 'service_user', '');
    }

    private function getAdminPassword(): string {
        return $this->config->getAppValue(Application::APP_ID, 'service_user_password', '');
    }
}
