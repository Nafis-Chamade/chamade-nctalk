<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Controller;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\ChamadeTalk\Service\TalkApiService;
use OCA\ChamadeTalk\Traits\HmacVerification;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * HMAC-authenticated API for the Chamade Python service.
 *
 * Scope after 2.2.0 cleanup: only the endpoints that are actually hit
 * by the Chamade NextcloudTalkConnector or Chamade's nctalk api.
 *
 * - getConfig                — HPB URL + ICE servers for the connector
 * - getAuthorizedRooms / setAuthorizedRooms — room auth sync on reboot
 * - getSignalingTicket       — V1 HPB signaling ticket for a user/room
 * - joinRoom                 — add a user to a room via OCS
 *
 * Dead createRoom/deleteRoom/getRoomInfo/createBot/deleteBot/enableBotInRoom
 * endpoints were removed in 2.2.0 — nothing on the Python side called them.
 */
class ConfigController extends Controller {

    use HmacVerification;

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private ISecureRandom $random,
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
    // Authorized rooms — used by Chamade to sync after restart
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

        // Bot secret — used by the connector for the /ocs/v2.php/apps/spreed
        // /api/v1/bot/{token}/message HMAC path when posting from the bridge.
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
    // Room participation — join room as bot user
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

        $password = $this->getBotUserPassword($username);
        if ($password === null) {
            return new JSONResponse(['error' => "User '{$username}' not found"], 404);
        }

        $this->talkApi->addUserToRoom($token, $username, $username, $password);

        return new JSONResponse(['status' => 'ok', 'sessionId' => '']);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Lookup a bot user's stored password (written by AuthorizeController
     * when the user authorizes the addon).
     */
    private function getBotUserPassword(string $username): ?string {
        $storedPasswords = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_passwords', '{}'),
            true
        ) ?: [];
        return $storedPasswords[$username] ?? null;
    }
}
