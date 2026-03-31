<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Migration;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\ChamadeTalk\Service\BotService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Pre-uninstall cleanup — runs on app:disable.
 *
 * 1. Delete all bot users (from {brand_id}-bots group)
 * 2. Uninstall default bot from Talk
 * 3. Delete all app config values
 */
class UninstallStep implements IRepairStep {

    public function __construct(
        private IConfig $config,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private BotService $botService,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string {
        return 'Uninstall ' . Application::APP_ID;
    }

    public function run(IOutput $output): void {
        $this->logger->info('Running uninstall for ' . Application::APP_ID);
        $this->deleteBotUsers($output);
        $this->deleteDefaultBot($output);
        $this->deleteAppConfig($output);
        $this->logger->info('Uninstall completed for ' . Application::APP_ID);
    }

    private function deleteBotUsers(IOutput $output): void {
        // Delete bot users tracked in appconfig
        $botUsers = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_users', '[]'),
            true
        ) ?: [];

        $deleted = 0;
        foreach ($botUsers as $username) {
            $user = $this->userManager->get($username);
            if ($user !== null && $user->delete()) {
                $deleted++;
            }
        }

        // Also iterate the bot group
        $brandId = str_replace('_talk', '', Application::APP_ID);
        $groupId = $brandId . '-bots';
        $group = $this->groupManager->get($groupId);
        if ($group !== null) {
            foreach ($group->getUsers() as $user) {
                if ($user->delete()) {
                    $deleted++;
                }
            }
            $this->groupManager->get($groupId)?->delete();
        }

        if ($deleted > 0) {
            $output->info("Deleted {$deleted} bot user(s)");
        } else {
            $output->info('No bot users to delete');
        }
    }

    private function deleteDefaultBot(IOutput $output): void {
        $botIdStr = $this->config->getAppValue(Application::APP_ID, 'default_bot_id', '');
        if (empty($botIdStr)) {
            $output->info('No default bot configured');
            return;
        }

        $botId = (int) $botIdStr;
        $this->botService->uninstallBot($botId);
        $output->info("Uninstalled bot ID: {$botId}");
    }

    private function deleteAppConfig(IOutput $output): void {
        $this->config->deleteAppValues(Application::APP_ID);
        $output->info('Deleted all app config values');
    }
}
