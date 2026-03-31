<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Listener;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\ChamadeTalk\Service\TalkApiService;
use OCA\Talk\Events\BotInvokeEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Listens for BotInvokeEvent and either:
 * 1. Handles /activate and /deactivate commands (room authorization)
 * 2. Forwards chat messages to the bridge webhook (if room is authorized)
 */
class ChatListener implements IEventListener {

    public function __construct(
        private IConfig $config,
        private IClientService $clientService,
        private TalkApiService $talkApi,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof BotInvokeEvent)) {
            return;
        }

        // Parse ActivityStreams format
        $data = $event->getMessage();
        $type = $data['type'] ?? '';
        $actorName = $data['actor']['name'] ?? '';
        $actorId = $data['actor']['id'] ?? '';
        $content = $data['object']['content'] ?? '';
        $roomToken = $data['target']['id'] ?? '';

        // Decode message content (JSON-encoded)
        $decoded = json_decode($content, true);
        $message = $decoded['message'] ?? $content;

        // Skip messages from bot users (dynamic list maintained by BotUserController)
        $botUsers = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_users', '[]'),
            true
        ) ?: [];
        if (in_array($actorId, $botUsers)) {
            return;
        }
        // Also filter by known bot prefixes
        $brandId = str_replace('_talk', '', Application::APP_ID);
        if (str_starts_with($actorId, $brandId . '-bot-')
            || str_starts_with($actorId, $brandId . '-')
        ) {
            return;
        }

        // Skip non-chat types
        if (!in_array($type, ['Activity', 'Create'])) {
            return;
        }

        // ── Room authorization check ──
        // Note: /activate and /deactivate are NOT intercepted here — they are
        // forwarded to the webhook so TalkService can handle them (it manages
        // both in-memory state and addon persistence). The ChatListener only
        // gates regular messages.
        $trimmedMessage = trim($message);
        if ($trimmedMessage !== '/activate' && $trimmedMessage !== '/deactivate') {
            $authResult = $this->checkRoomAuth($roomToken, $actorId, $trimmedMessage);
            if ($authResult === 'denied') {
                return;
            }
        }
        // 'allowed' or command → continue to forwarding

        // Forward chat to bridge webhook
        $backendUrl = $this->config->getAppValue(Application::APP_ID, 'backend_url', '');
        $apiSecret = $this->config->getAppValue(Application::APP_ID, 'api_secret', '');

        if (empty($backendUrl) || empty($apiSecret)) {
            return;
        }

        // Get room type for is_dm detection via OCS REST
        $roomType = 0;
        $serviceUser = $this->config->getAppValue(Application::APP_ID, 'service_user', '');
        $servicePass = $this->config->getAppValue(Application::APP_ID, 'service_user_password', '');
        if (!empty($serviceUser) && !empty($servicePass)) {
            $info = $this->talkApi->getRoomInfo($roomToken, $serviceUser, $servicePass);
            if ($info !== null) {
                $roomType = $info['type'];
            }
        }

        $webhookUrl = rtrim($backendUrl, '/') . '/api/nctalk/chat';
        $payload = json_encode([
            'type' => $type,
            'room_token' => $roomToken,
            'sender' => $actorName,
            'sender_id' => $actorId,
            'message' => $message,
            'room_type' => $roomType,
        ]);

        // HMAC auth
        $random = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $random, $apiSecret);

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($webhookUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    // Protocol headers stay X-Maquis+ard-* regardless of branding.
                    'X-Maquis' . 'ard-Random' => $random,
                    'X-Maquis' . 'ard-Signature' => $signature,
                ],
                'body' => $payload,
                'timeout' => 120,
            ]);

            // If bridge returned a message, reply via bot
            $responseBody = json_decode($response->getBody(), true);
            if (is_array($responseBody) && !empty($responseBody['message'])) {
                $event->addAnswer($responseBody['message']);
            }

        } catch (\Exception $e) {
            $this->logger->warning("Chat webhook failed: " . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Check if the room is authorized for this sender.
     *
     * Returns:
     * - 'allowed': message should be forwarded
     * - 'denied': message should be silently dropped
     */
    private function checkRoomAuth(string $roomToken, string $actorId, string $message): string {
        // Get bot owners mapping: {"bot_username": "owner_nc_username"}
        $botOwners = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_owners', '{}'),
            true
        ) ?: [];

        // No owners registered → legacy mode, allow all (backward compat)
        if (empty($botOwners)) {
            return 'allowed';
        }

        $allOwners = array_values($botOwners);
        $bareActorId = $this->bareUsername($actorId);
        $isOwner = in_array($bareActorId, $allOwners);

        // Handle /activate command (owner only)
        if ($message === '/activate' && $isOwner) {
            $this->setRoomAuthorized($roomToken, $bareActorId, true);
            return 'activate';
        }

        // Handle /deactivate command (owner only)
        if ($message === '/deactivate' && $isOwner) {
            $this->setRoomAuthorized($roomToken, $bareActorId, false);
            return 'deactivate';
        }

        // Check if room is explicitly authorized
        $authorizedRooms = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'authorized_rooms', '{}'),
            true
        ) ?: [];

        if (isset($authorizedRooms[$roomToken])) {
            return 'allowed';
        }

        // DM from owner → auto-authorized (room type 1 = ONE_TO_ONE)
        if ($isOwner) {
            $serviceUser = $this->config->getAppValue(Application::APP_ID, 'service_user', '');
            $servicePass = $this->config->getAppValue(Application::APP_ID, 'service_user_password', '');
            if (!empty($serviceUser) && !empty($servicePass)) {
                $info = $this->talkApi->getRoomInfo($roomToken, $serviceUser, $servicePass);
                if ($info !== null && $info['type'] === 1) {
                    return 'allowed';
                }
            }
        }

        // Not authorized
        return 'denied';
    }

    /**
     * Authorize or deauthorize a room for a given owner.
     */
    private function setRoomAuthorized(string $roomToken, string $ownerUsername, bool $authorized): void {
        $rooms = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'authorized_rooms', '{}'),
            true
        ) ?: [];

        if ($authorized) {
            $rooms[$roomToken] = $ownerUsername;
            $this->logger->info("Room {$roomToken} authorized by {$ownerUsername}", ['app' => Application::APP_ID]);
        } else {
            if (isset($rooms[$roomToken]) && $rooms[$roomToken] === $ownerUsername) {
                unset($rooms[$roomToken]);
                $this->logger->info("Room {$roomToken} deauthorized by {$ownerUsername}", ['app' => Application::APP_ID]);
            }
        }

        $this->config->setAppValue(Application::APP_ID, 'authorized_rooms', json_encode($rooms));
    }

    /**
     * Extract bare username from actor ID (strip "users/" prefix if present).
     */
    private function bareUsername(string $actorId): string {
        return str_contains($actorId, '/') ? substr($actorId, strrpos($actorId, '/') + 1) : $actorId;
    }
}
