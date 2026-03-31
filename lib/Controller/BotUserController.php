<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Controller;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\ChamadeTalk\Service\BotService;
use OCA\ChamadeTalk\Service\TalkApiService;
use OCA\ChamadeTalk\Traits\HmacVerification;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IAvatarManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * HMAC-authenticated API for bot user lifecycle.
 *
 * Manages NC users that act as bot identities: create, delete,
 * update display name, post messages, upload avatar, ensure in room.
 */
class BotUserController extends Controller {

    use HmacVerification;

    /** Group that holds all bot users for this brand. */
    private const BOT_GROUP = 'bots';

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private ISecureRandom $random,
        private IAvatarManager $avatarManager,
        private BotService $botService,
        private TalkApiService $talkApi,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    private function getBotGroupId(): string {
        $brandId = str_replace('_talk', '', Application::APP_ID);
        return $brandId . '-' . self::BOT_GROUP;
    }

    private function ensureBotGroup(): \OCP\IGroup {
        $groupId = $this->getBotGroupId();
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            $group = $this->groupManager->createGroup($groupId);
        }
        return $group;
    }

    /**
     * Store a bot user's password in appconfig (encrypted by NC).
     */
    private function storeBotPassword(string $username, string $password): void {
        $passwords = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_passwords', '{}'),
            true
        ) ?: [];
        $passwords[$username] = $password;
        $this->config->setAppValue(Application::APP_ID, 'bot_passwords', json_encode($passwords));
    }

    /**
     * Get stored password for a bot user.
     */
    private function getBotPassword(string $username): string {
        $passwords = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_passwords', '{}'),
            true
        ) ?: [];
        return $passwords[$username] ?? '';
    }

    /**
     * Remove a bot user's stored password.
     */
    private function removeBotPassword(string $username): void {
        $passwords = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_passwords', '{}'),
            true
        ) ?: [];
        unset($passwords[$username]);
        $this->config->setAppValue(Application::APP_ID, 'bot_passwords', json_encode($passwords));
    }

    // ========================================================================
    // create — POST /api/v1/bot-users
    // ========================================================================

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function create(): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $username = $this->request->getParam('username', '');
        $displayName = $this->request->getParam('display_name', '');
        $ownerNcUsername = $this->request->getParam('owner_nc_username', '');

        if (empty($username)) {
            return new JSONResponse(['error' => 'username required'], 400);
        }

        // Generate password
        $password = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $created = false;

        if (!$this->userManager->userExists($username)) {
            $this->userManager->createUser($username, $password);
            $created = true;
            $this->logger->info("Created bot user: {$username}", ['app' => Application::APP_ID]);
        } else {
            // Reset password
            $user = $this->userManager->get($username);
            if ($user !== null) {
                $user->setPassword($password);
            }
        }

        // Persist password for future OCS REST calls (postMessage, etc.)
        $this->storeBotPassword($username, $password);

        // Set display name
        if (!empty($displayName)) {
            $user = $this->userManager->get($username);
            if ($user !== null) {
                $user->setDisplayName($displayName);
            }
        }

        // Add to bot group
        $group = $this->ensureBotGroup();
        $user = $this->userManager->get($username);
        if ($user !== null && !$group->inGroup($user)) {
            $group->addUser($user);
        }

        // Create 1:1 DM room with owner (if owner specified)
        $dmToken = '';
        if (!empty($ownerNcUsername)) {
            // Use the service user credentials to create the room via OCS
            $serviceUser = $this->config->getAppValue(Application::APP_ID, 'service_user', '');
            $servicePass = $this->config->getAppValue(Application::APP_ID, 'service_user_password', '');

            // Create 1:1 as the bot user
            $room = $this->talkApi->createOneToOneRoom($ownerNcUsername, $username, $password);
            if ($room !== null) {
                $dmToken = $room['token'];

                // Enable default bot in the DM room
                $botId = (int) $this->config->getAppValue(Application::APP_ID, 'default_bot_id', '0');
                if ($botId > 0) {
                    $this->botService->enableBotInRoom($botId, $dmToken);
                }
            } else {
                $this->logger->warning("DM room creation failed for {$username} ↔ {$ownerNcUsername}", [
                    'app' => Application::APP_ID,
                ]);
            }
        }

        // Track bot username in appconfig for ChatListener filtering
        $botUsers = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_users', '[]'),
            true
        ) ?: [];
        if (!in_array($username, $botUsers)) {
            $botUsers[] = $username;
            $this->config->setAppValue(Application::APP_ID, 'bot_users', json_encode($botUsers));
        }

        return new JSONResponse([
            'status' => 'ok',
            'username' => $username,
            'password' => $password,
            'created' => $created,
            'dm_token' => $dmToken,
        ]);
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
    // updateDisplayName — PUT /api/v1/bot-users/{username}/display-name
    // ========================================================================

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function updateDisplayName(string $username): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $displayName = $this->request->getParam('display_name', '');
        if (empty($displayName)) {
            return new JSONResponse(['error' => 'display_name required'], 400);
        }

        $user = $this->userManager->get($username);
        if ($user === null) {
            return new JSONResponse(['error' => 'User not found'], 404);
        }

        $user->setDisplayName($displayName);
        $this->logger->info("Updated display name for {$username} to '{$displayName}'", [
            'app' => Application::APP_ID,
        ]);

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

    // ========================================================================
    // ensureInRoom — POST /api/v1/bot-users/ensure-in-room
    // ========================================================================

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function ensureInRoom(): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $roomToken = $this->request->getParam('room_token', '');
        $username = $this->request->getParam('username', '');

        if (empty($roomToken)) {
            return new JSONResponse(['error' => 'room_token required'], 400);
        }
        if (empty($username)) {
            return new JSONResponse(['error' => 'username required'], 400);
        }

        // Use service user to add the bot user to the room
        $serviceUser = $this->config->getAppValue(Application::APP_ID, 'service_user', '');
        $servicePass = $this->config->getAppValue(Application::APP_ID, 'service_user_password', '');

        if (!empty($serviceUser) && !empty($servicePass)) {
            $this->talkApi->addUserToRoom($roomToken, $username, $serviceUser, $servicePass);
        }

        return new JSONResponse(['status' => 'ok']);
    }
}
