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
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * HMAC-authenticated API for bridge service.
 *
 * All methods verify HMAC before processing.
 * Endpoints: config, signaling ticket, room join/create/delete, bot CRUD.
 */
class ConfigController extends Controller {

    use HmacVerification;

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IUserManager $userManager,
        private ISecureRandom $random,
        private BotService $botService,
        private TalkApiService $talkApi,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ========================================================================
    // Config
    // ========================================================================

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function getConfig(): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        // Get HPB URL from Talk config
        $signalingServers = json_decode(
            $this->config->getAppValue('spreed', 'signaling_servers', '{}'),
            true
        );
        $hpbUrl = '';
        if (!empty($signalingServers['servers'])) {
            $hpbUrl = $signalingServers['servers'][0]['server'] ?? '';
        }

        // ICE servers
        $stunServers = json_decode(
            $this->config->getAppValue('spreed', 'stun_servers', '[]'),
            true
        );
        $turnServers = json_decode(
            $this->config->getAppValue('spreed', 'turn_servers', '[]'),
            true
        );

        return new JSONResponse([
            'hpb_url' => $hpbUrl,
            'stun_servers' => $stunServers,
            'turn_servers' => $turnServers,
        ]);
    }

    // ========================================================================
    // Authorized rooms — used by bridge to sync after restart
    // ========================================================================

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function getAuthorizedRooms(): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $rooms = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'authorized_rooms', '{}'),
            true
        ) ?: [];

        return new JSONResponse(['authorized_rooms' => $rooms]);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function setAuthorizedRooms(): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $rooms = $this->request->getParam('authorized_rooms', []);
        if (!is_array($rooms)) {
            return new JSONResponse(['error' => 'authorized_rooms must be an object'], 400);
        }

        $this->config->setAppValue(
            Application::APP_ID,
            'authorized_rooms',
            json_encode((object) $rooms)
        );

        return new JSONResponse(['status' => 'ok', 'count' => count($rooms)]);
    }

    // ========================================================================
    // Signaling Ticket
    // ========================================================================

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function getSignalingTicket(string $roomToken): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $username = $this->request->getParam('username', '');
        if (empty($username)) {
            return new JSONResponse(['error' => 'username parameter required'], 400);
        }

        // Signaling ticket generation requires TalkConfig (internal Talk class).
        // This is the ONE remaining use of a Talk internal — it has no OCS
        // equivalent. We generate a compatible ticket ourselves using the
        // user's signaling secret stored in NC preferences.
        $signalingSecret = $this->config->getUserValue($username, 'spreed', 'signaling_secret', '');
        if (empty($signalingSecret)) {
            // Generate one — Talk creates these lazily too
            $signalingSecret = $this->random->generate(32);
            $this->config->setUserValue($username, 'spreed', 'signaling_secret', $signalingSecret);
        }

        // V1 ticket format: {userid}:{timestamp}:{hmac}
        $now = time();
        $hmac = hash_hmac('sha256', "{$username}:{$now}", $signalingSecret);
        $fullTicket = "{$username}:{$now}:{$hmac}";

        // HPB URL
        $signalingServers = json_decode(
            $this->config->getAppValue('spreed', 'signaling_servers', '{}'),
            true
        );
        $hpbUrl = '';
        if (!empty($signalingServers['servers'])) {
            $hpbUrl = $signalingServers['servers'][0]['server'] ?? '';
        }

        // Bot secret
        $botSecret = $this->config->getAppValue(Application::APP_ID, 'default_bot_secret', '');

        // ICE servers
        $stunServers = json_decode(
            $this->config->getAppValue('spreed', 'stun_servers', '[]'),
            true
        );
        $turnServers = json_decode(
            $this->config->getAppValue('spreed', 'turn_servers', '[]'),
            true
        );

        return new JSONResponse([
            'ticket' => $fullTicket,
            'userid' => $username,
            'bot_secret' => $botSecret,
            'hpb_url' => $hpbUrl,
            'stun_servers' => $stunServers,
            'turn_servers' => $turnServers,
        ]);
    }

    // ========================================================================
    // Room Management — via OCS REST (TalkApiService)
    // ========================================================================

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function joinRoom(string $token): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $username = $this->request->getParam('username', '');
        if (empty($username)) {
            return new JSONResponse(['error' => 'username parameter required'], 400);
        }

        $password = $this->getUserPassword($username);
        if ($password === null) {
            return new JSONResponse(['error' => "User '{$username}' not found"], 404);
        }

        $this->talkApi->addUserToRoom($token, $username, $username, $password);

        return new JSONResponse(['status' => 'ok', 'sessionId' => '']);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function getRoomInfo(string $token): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        [$adminUser, $adminPass] = $this->getServiceCredentials();
        if ($adminUser === null) {
            return new JSONResponse(['error' => 'No service user configured'], 500);
        }

        $info = $this->talkApi->getRoomInfo($token, $adminUser, $adminPass);
        if ($info === null) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        return new JSONResponse($info);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function createRoom(): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $name = $this->request->getParam('name', 'Bridge Room');
        $username = $this->request->getParam('username', '');
        if (empty($username)) {
            return new JSONResponse(['error' => 'username parameter required'], 400);
        }

        $password = $this->getUserPassword($username);
        if ($password === null) {
            return new JSONResponse(['error' => "User '{$username}' not found"], 404);
        }

        $room = $this->talkApi->createGroupRoom($name, $username, $password);
        if ($room === null) {
            return new JSONResponse(['error' => 'Failed to create room'], 500);
        }

        // Enable default bot
        $botId = (int) $this->config->getAppValue(Application::APP_ID, 'default_bot_id', '0');
        if ($botId > 0) {
            $this->botService->enableBotInRoom($botId, $room['token']);
        }

        return new JSONResponse($room);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function deleteRoom(string $token): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        [$adminUser, $adminPass] = $this->getServiceCredentials();
        if ($adminUser === null) {
            return new JSONResponse(['error' => 'No service user configured'], 500);
        }

        $ok = $this->talkApi->deleteRoom($token, $adminUser, $adminPass);
        if (!$ok) {
            return new JSONResponse(['error' => 'Room not found'], 404);
        }

        return new JSONResponse(['status' => 'ok']);
    }

    // ========================================================================
    // Bot Management
    // ========================================================================

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function createBot(): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $name = $this->request->getParam('name', 'Bridge Bot');
        $secret = bin2hex(random_bytes(32));

        $this->botService->installBot($name, $secret);

        return new JSONResponse(['status' => 'ok', 'secret' => $secret]);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function deleteBot(int $botId): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $this->botService->uninstallBot($botId);
        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function enableBotInRoom(int $botId, string $token): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        $this->botService->enableBotInRoom($botId, $token);
        return new JSONResponse(['status' => 'ok']);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Get a temporary password for a bot user (reset + return).
     * For service user, use the stored password.
     */
    private function getUserPassword(string $username): ?string {
        $user = $this->userManager->get($username);
        if ($user === null) {
            return null;
        }

        // If this is the service user, use stored password
        $serviceUser = $this->config->getAppValue(Application::APP_ID, 'service_user', '');
        if ($username === $serviceUser) {
            return $this->config->getAppValue(Application::APP_ID, 'service_user_password', '');
        }

        // For bot users, use their stored app password
        $storedPasswords = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_passwords', '{}'),
            true
        ) ?: [];
        return $storedPasswords[$username] ?? null;
    }

    /**
     * @return array{0: ?string, 1: ?string} [username, password]
     */
    private function getServiceCredentials(): array {
        $user = $this->config->getAppValue(Application::APP_ID, 'service_user', '');
        $pass = $this->config->getAppValue(Application::APP_ID, 'service_user_password', '');
        if (empty($user) || empty($pass)) {
            return [null, null];
        }
        return [$user, $pass];
    }
}
