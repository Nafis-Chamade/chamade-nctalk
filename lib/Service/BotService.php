<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Service;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\Talk\Events\BotInstallEvent;
use OCA\Talk\Events\BotUninstallEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Install / uninstall the single Talk bot owned by this addon.
 *
 * Uses the public BotInstallEvent / BotUninstallEvent API for the
 * lifecycle. The bot's integer ID is looked up from the spreed table
 * `oc_talk_bots_server` by `url_hash` — this is a read-only query into
 * another app's table, which is a pragmatic compromise: spreed doesn't
 * expose the ID via any OCP or non-admin OCS endpoint, and we need it
 * for per-room enable (via `TalkApiService::enableBotInRoom`).
 */
class BotService {

    public function __construct(
        private IConfig $config,
        private IEventDispatcher $dispatcher,
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Spreed bot URL scheme for this addon. Stable — spreed hashes it
     * to derive the row key in oc_talk_bots_server, so all our install
     * / uninstall / lookup paths must use the exact same string.
     */
    public const BOT_URL = 'nextcloudapp://' . Application::APP_ID;

    public function installBot(string $name, string $secret): void {
        $event = new BotInstallEvent(
            $name,
            $secret,
            self::BOT_URL,
            "Cross-platform meeting bridge",
            4 | 2, // FEATURE_EVENT | FEATURE_RESPONSE
        );
        $this->dispatcher->dispatchTyped($event);

        $this->logger->info("Bot installed: {$name}", ['app' => Application::APP_ID]);

        // Backfill default_bot_id from spreed's table now that the row
        // exists (handleBotInstallEvent is synchronous and commits
        // before dispatchTyped returns).
        $botId = $this->lookupBotIdFromSpreedTable();
        if ($botId > 0) {
            $this->config->setAppValue(Application::APP_ID, 'default_bot_id', (string) $botId);
            $this->logger->info("Stored default_bot_id={$botId}", ['app' => Application::APP_ID]);
        }
    }

    /**
     * Dispatch BotUninstallEvent with the stored install secret so spreed
     * removes the row from oc_talk_bots_server (and cascading bot_conversation
     * rows). Silently no-op if we never successfully installed a bot.
     */
    public function uninstallBot(): void {
        $secret = $this->config->getAppValue(Application::APP_ID, 'default_bot_secret', '');

        if (empty($secret)) {
            $this->logger->info("Skipping bot uninstall: no stored secret", [
                'app' => Application::APP_ID,
            ]);
            return;
        }

        try {
            $event = new BotUninstallEvent($secret, self::BOT_URL);
            $this->dispatcher->dispatchTyped($event);
            $this->logger->info("Bot uninstalled", ['app' => Application::APP_ID]);
        } catch (\Exception $e) {
            $this->logger->warning("Bot uninstall failed: " . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Return the integer bot id for this addon's registered Talk bot.
     *
     * Uses the cached `default_bot_id` appconfig value if present, or
     * falls back to a fresh lookup from `oc_talk_bots_server` by
     * `url_hash`. Caches on success so subsequent callers skip the DB.
     * Returns 0 if no bot is registered (e.g. fresh install before
     * `InstallStep::ensureDefaultBot`).
     */
    public function getDefaultBotId(): int {
        $cached = (int) $this->config->getAppValue(Application::APP_ID, 'default_bot_id', '0');
        if ($cached > 0) {
            return $cached;
        }
        $botId = $this->lookupBotIdFromSpreedTable();
        if ($botId > 0) {
            $this->config->setAppValue(Application::APP_ID, 'default_bot_id', (string) $botId);
        }
        return $botId;
    }

    /**
     * Read-only query into spreed's `oc_talk_bots_server` table to find
     * this addon's bot row by URL hash. Returns 0 if not found (spreed
     * not installed, bot never registered, or already uninstalled).
     */
    private function lookupBotIdFromSpreedTable(): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
                ->from('talk_bots_server')
                ->where($qb->expr()->eq('url_hash', $qb->createNamedParameter(sha1(self::BOT_URL))))
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            if ($row && isset($row['id'])) {
                return (int) $row['id'];
            }
        } catch (\Throwable $e) {
            $this->logger->debug("lookupBotIdFromSpreedTable failed: " . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
        return 0;
    }
}
