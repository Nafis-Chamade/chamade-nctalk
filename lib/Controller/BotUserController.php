<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Controller;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\ChamadeTalk\Service\TalkApiService;
use OCA\ChamadeTalk\Traits\HmacVerification;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * HMAC-authenticated API for bot user operations actually hit in prod:
 * delete (hard cleanup from Chamade disconnect), postMessage (Chamade
 * chat relay), uploadAvatar (one-shot branding from Chamade callback).
 *
 * Dead endpoints (create / updateDisplayName / ensureInRoom) were removed
 * in 2.2.0 — they survived from the legacy provisioning flow and nothing
 * in the Python side ever called them. Bot users are now created only by
 * the user-facing AuthorizeController flow.
 */
class BotUserController extends Controller {

    use HmacVerification;

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IUserManager $userManager,
        private IAvatarManager $avatarManager,
        private TalkApiService $talkApi,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ========================================================================
    // Bot password store (shared with AuthorizeController)
    // ========================================================================

    private function getBotPassword(string $username): string {
        $passwords = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_passwords', '{}'),
            true
        ) ?: [];
        return $passwords[$username] ?? '';
    }

    private function removeBotPassword(string $username): void {
        $passwords = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_passwords', '{}'),
            true
        ) ?: [];
        unset($passwords[$username]);
        $this->config->setAppValue(Application::APP_ID, 'bot_passwords', json_encode($passwords));
    }

    // ========================================================================
    // delete — DELETE /api/v1/bot-users/{username}
    // ========================================================================

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function delete(string $username): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $user = $this->userManager->get($username);
        if ($user === null) {
            return new JSONResponse(['error' => 'User not found'], 404);
        }

        $user->delete();
        $this->removeBotPassword($username);

        // Remove from tracked bot users
        $botUsers = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_users', '[]'),
            true
        ) ?: [];
        $botUsers = array_values(array_filter($botUsers, fn($u) => $u !== $username));
        $this->config->setAppValue(Application::APP_ID, 'bot_users', json_encode($botUsers));

        // Clean up bot_owners and authorized_rooms
        $botOwners = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_owners', '{}'),
            true
        ) ?: [];
        if (isset($botOwners[$username])) {
            $ownerUsername = $botOwners[$username];
            // Remove authorized rooms for this owner
            $authorizedRooms = json_decode(
                $this->config->getAppValue(Application::APP_ID, 'authorized_rooms', '{}'),
                true
            ) ?: [];
            $authorizedRooms = array_filter($authorizedRooms, fn($owner) => $owner !== $ownerUsername);
            $this->config->setAppValue(Application::APP_ID, 'authorized_rooms', json_encode((object) $authorizedRooms));

            unset($botOwners[$username]);
            $this->config->setAppValue(Application::APP_ID, 'bot_owners', json_encode((object) $botOwners));
        }

        $this->logger->info("Deleted bot user: {$username}", ['app' => Application::APP_ID]);

        return new JSONResponse(['status' => 'ok']);
    }

    // ========================================================================
    // postMessage — POST /api/v1/bot-users/{username}/post
    // ========================================================================

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function postMessage(string $username): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $roomToken = $this->request->getParam('room_token', '');
        $message = $this->request->getParam('message', '');

        if (empty($roomToken) || empty($message)) {
            return new JSONResponse(['error' => 'room_token and message required'], 400);
        }

        $user = $this->userManager->get($username);
        if ($user === null) {
            return new JSONResponse(['error' => 'User not found'], 404);
        }

        // Use stored password (no more password regeneration per request)
        $password = $this->getBotPassword($username);
        if (empty($password)) {
            $this->logger->warning("No stored password for bot user {$username}", [
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Bot user has no stored credentials'], 500);
        }

        $ok = $this->talkApi->sendMessage($roomToken, $message, $username, $password);
        if (!$ok) {
            return new JSONResponse(['error' => 'Failed to post message'], 500);
        }

        return new JSONResponse(['status' => 'ok']);
    }

    // ========================================================================
    // uploadAvatar — POST /api/v1/bot-users/{username}/avatar
    // ========================================================================

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function uploadAvatar(string $username): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $user = $this->userManager->get($username);
        if ($user === null) {
            return new JSONResponse(['error' => 'User not found'], 404);
        }

        // Get avatar file from multipart upload
        $avatarFile = $this->request->getUploadedFile('avatar');
        if ($avatarFile === null || empty($avatarFile['tmp_name'])) {
            return new JSONResponse(['error' => 'No avatar file uploaded'], 400);
        }

        try {
            $avatar = $this->avatarManager->getAvatar($username);
            $imageData = file_get_contents($avatarFile['tmp_name']);
            $image = new \OCP\Image();
            $image->loadFromData($imageData);
            $avatar->set($image);

            $this->logger->info("Uploaded avatar for {$username}", ['app' => Application::APP_ID]);
            return new JSONResponse(['status' => 'ok']);
        } catch (\Exception $e) {
            $this->logger->warning("Avatar upload failed for {$username}: " . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to upload avatar'], 500);
        }
    }
}
