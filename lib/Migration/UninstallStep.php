<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Migration;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\ChamadeTalk\Service\BackendWebhookClient;
use OCA\ChamadeTalk\Service\BotService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IURLGenerator;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Uninstall cleanup — runs on `occ app:disable` (AppManager calls the
 * uninstall repair steps before dispatching AppDisableEvent) and on
 * `occ app:remove` (which internally calls disableApp first). In both
 * cases we fully wind down: notify the backend, delete bot users,
 * uninstall the Talk bot, and wipe appconfig.
 *
 * ORDERING IS CRITICAL:
 *   1. notifyBackendDisabled() — needs backend_url + api_secret, must
 *      run BEFORE appconfig is wiped. Best-effort, short timeout.
 *   2. deleteBotUsers()        — remove NC bot accounts so Chamade
 *      hard-fails on the next call and the dashboard flips to
 *      "Reconnect" even if the webhook above was lost.
 *   3. uninstallBot()          — dispatch BotUninstallEvent so spreed
 *      drops the row in oc_talk_bots_server (+ cascading bot_conversation).
 *   4. deleteAppConfig()       — wipe our appconfig last.
 */
class UninstallStep implements IRepairStep {

    public function __construct(
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private BotService $botService,
        private BackendWebhookClient $webhook,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string {
        return 'Uninstall ' . Application::APP_ID;
    }

    public function run(IOutput $output): void {
        $this->logger->info('Running uninstall for ' . Application::APP_ID);
        $this->notifyBackendDisabled($output);
        $this->deleteBotUsers($output);
        $this->uninstallBot($output);
        $this->deleteAppConfig($output);
        $this->logger->info('Uninstall completed for ' . Application::APP_ID);
    }

    /**
     * Tell the Chamade backend that this addon is going away, so it can
     * flip matching connections to `addon_disabled` in its own DB and
     * surface a "Reconnect" hint in the user dashboard.
     *
     * Best-effort: we swallow any failure (network down, backend 5xx,
     * never paired) — the passive 401 fallback on the next call path
     * eventually catches what we miss.
     */
    private function notifyBackendDisabled(IOutput $output): void {
        $backendUrl = $this->config->getAppValue(Application::APP_ID, 'backend_url', '');
        $apiSecret = $this->config->getAppValue(Application::APP_ID, 'api_secret', '');
        if ($backendUrl === '' || $apiSecret === '') {
            $output->info('Skipping backend disable notification (never paired)');
            return;
        }

        // Derive the NC instance URL from the URL generator (which reads
        // overwrite.cli.url when running under occ, and the current host
        // when serving HTTP). Matches the public nc_url Chamade stored
        // in its `connections.credentials.nc_url` at authorize time.
        $ncUrl = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');

        $this->webhook->post('/api/nctalk/addon-disabled', [
            'nc_url' => $ncUrl,
            'app_id' => Application::APP_ID,
        ]);
        $output->info('Backend disable notification dispatched');
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

        // Also iterate the bot group (catches stragglers missed in appconfig)
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

    /**
     * Dispatch BotUninstallEvent if we have a stored install secret.
     * The BotService is the single source of truth for that check —
     * no `default_bot_id` lookup here (that value was never populated
     * in the first place; it's the bug that blocked the whole path
     * before 2.2.0).
     */
    private function uninstallBot(IOutput $output): void {
        $this->botService->uninstallBot();
        $output->info('Talk bot uninstall dispatched');
    }

    /**
     * Drop the appconfig entries tied to the Talk bot and NC bot users
     * we just cleaned up, while KEEPING the admin-configured pairing
     * state so `app:disable` → `app:enable` is idempotent at the Chamade
     * pairing level (no need to re-pair, only individual users need to
     * re-authorize so a fresh bot NC user is created for them).
     *
     * Wiped:
     *   - default_bot_secret / default_bot_id      (Talk bot registration)
     *   - bot_users / bot_passwords / bot_owners   (NC bot user accounts)
     *   - pair_code / pair_code_expires            (ephemeral)
     *
     * Preserved (partner service coordinates — admins explicitly set these
     * once and expect them to survive a troubleshooting disable cycle):
     *   - api_secret            (HMAC shared with the Chamade backend)
     *   - callback_url          (Chamade redirect target on authorize)
     *   - backend_url           (ChatListener webhook target)
     *   - partner_url           (partner service user-links sync target)
     *   - api_key               (optional HTTP auth shared secret)
     *   - brand_name            (cosmetic)
     *   - authorized_rooms      (group rooms the owner explicitly /activate'd —
     *                            keyed on NC username which survives)
     *   - user_links            (partner side user link cache)
     */
    private function deleteAppConfig(IOutput $output): void {
        $keysToDelete = [
            'default_bot_secret',
            'default_bot_id',
            'bot_users',
            'bot_passwords',
            'bot_owners',
            'pair_code',
            'pair_code_expires',
        ];
        foreach ($keysToDelete as $key) {
            $this->config->deleteAppValue(Application::APP_ID, $key);
        }
        $output->info('Wiped bot-related app config (pairing state preserved)');
    }
}
