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
 * 1. Handles /activate and /deactivate commands (room authorization)
 * 2. Forwards chat messages to the Chamade webhook (if room is authorized)
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

        // actorId from BotInvokeEvent is always in ActivityStreams format
        // ("users/<username>"), so normalize before comparing against bare
        // username lists. Prior to 2.3.0 this normalization was missing and
        // the bot-user self-filter silently never matched — every self-post
        // (welcome DM, goodbye, etc.) was forwarded to the backend as if it
        // came from the owner.
        $brandId = str_replace('_talk', '', Application::APP_ID);
        $bareActor = $this->bareUsername($actorId);

        // Probe bypass: Chamade backend posts `__{brandId}_probe:<nonce>__`
        // (silent=1) as the bot into the owner DM right after connect, to
        // verify end-to-end that BotInvokeEvent dispatch actually reaches
        // this listener on the target Nextcloud. Gated to known bot users
        // only — an unprivileged NC user cannot spoof a probe.
        $botUsers = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_users', '[]'),
            true
        ) ?: [];
        // Prefix fallback is narrowly scoped to `{brand}-bot-*` — the bare
        // `{brand}-*` match used to be in here too but would silently drop
        // legitimate usernames like `chamade-beta-tester`. Callers that
        // rely on the bot_users appconfig still work identically.
        $isBotUser = in_array($bareActor, $botUsers, true)
            || str_starts_with($bareActor, $brandId . '-bot-');
        $probeRegex = sprintf(Application::PROBE_MARKER_REGEX_TEMPLATE, preg_quote($brandId, '/'));
        if ($isBotUser && preg_match($probeRegex, trim($message), $m) === 1) {
            $this->forwardProbe($m[1], $bareActor, $roomToken);
            return;
        }

        // Skip other messages from bot users.
        if ($isBotUser) {
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


        // Forward chat to Chamade webhook
        $backendUrl = $this->config->getAppValue(Application::APP_ID, 'backend_url', '');
        $apiSecret = $this->config->getAppValue(Application::APP_ID, 'api_secret', '');

        if (empty($backendUrl) || empty($apiSecret)) {
            return;
        }

        // Get room type for is_dm detection via OCS REST. Uses a bot user's
        // credentials (any bot user is a participant/moderator of every room
        // we care about — DMs, /activate'd groups), so we don't need the
        // legacy `service_user` path which was never populated in the first
        // place.
        $roomType = $this->lookupRoomType($roomToken);

        // Identify which bot this message is for by matching the sender against
        // bot_owners (populated by AuthorizeController::createBotAndCallback).
        // For 1:1 DMs this is unambiguous; for group rooms the first bot owned
        // by the sender wins (acceptable for single-user scenarios).
        $botOwners = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_owners', '{}'),
            true
        ) ?: [];
        $bareSender = $this->bareUsername($actorId);
        $botUser = '';
        $botOwner = '';
        foreach ($botOwners as $botUsername => $ownerUsername) {
            if ($ownerUsername === $bareSender) {
                $botUser = (string) $botUsername;
                $botOwner = (string) $ownerUsername;
                break;
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
            'bot_user' => $botUser,
            'bot_owner' => $botOwner,
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

            // If Chamade returned a message, reply via bot
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

        // DM from owner → auto-authorized (room type 1 = ONE_TO_ONE).
        // We query the room type using the bot user's credentials (the
        // bot user is a moderator of the DM, so it can call OCS REST),
        // which replaces the legacy `service_user` path that was never
        // populated in the addon appconfig.
        if ($isOwner) {
            $roomType = $this->lookupRoomType($roomToken);
            if ($roomType === 1) {
                return 'allowed';
            }
        }

        // Not authorized
        return 'denied';
    }

    /**
     * Query the Talk room type via OCS REST using any available bot user's
     * credentials. Returns 0 if we cannot determine the type (no bot user
     * stored, no room access). Caller can still treat 0 as "unknown" and
     * proceed without DM-specific behavior.
     */
    private function lookupRoomType(string $roomToken): int {
        $botPasswords = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_passwords', '{}'),
            true
        ) ?: [];
        if (!is_array($botPasswords) || empty($botPasswords)) {
            return 0;
        }
        foreach ($botPasswords as $username => $password) {
            if (!is_string($username) || !is_string($password) || $password === '') {
                continue;
            }
            $info = $this->talkApi->getRoomInfo($roomToken, $username, $password);
            if ($info !== null) {
                return (int) ($info['type'] ?? 0);
            }
        }
        return 0;
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

    /**
     * Forward a probe event to the Chamade backend. HMAC-signed (same scheme
     * as the chat webhook). Best-effort: any failure just means the backend
     * will time out its probe and fall back to polling mode.
     */
    private function forwardProbe(string $nonce, string $botLogin, string $roomToken): void {
        $backendUrl = $this->config->getAppValue(Application::APP_ID, 'backend_url', '');
        $apiSecret = $this->config->getAppValue(Application::APP_ID, 'api_secret', '');
        if (empty($backendUrl) || empty($apiSecret)) {
            return;
        }

        $url = rtrim($backendUrl, '/') . '/api/nctalk/probe';
        $payload = json_encode([
            'nonce' => $nonce,
            'bot_login' => $botLogin,
            'room_token' => $roomToken,
        ]);

        $random = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $random, $apiSecret);

        try {
            $client = $this->clientService->newClient();
            $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Maquis' . 'ard-Random' => $random,
                    'X-Maquis' . 'ard-Signature' => $signature,
                ],
                'body' => $payload,
                'timeout' => 10,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning("Probe forward failed: " . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
    }
}
