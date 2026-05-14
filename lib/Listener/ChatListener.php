<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Listener;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\ChamadeTalk\Service\E2eeService;
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
        private E2eeService $e2ee,
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

        // Extract file attachments from messageParameters, if any. NC Talk
        // represents file shares as a regular comment with `message = "{file}"`
        // and `parameters.file = {type, id, name, size, mimetype, path, …}`.
        // We forward the metadata so the backend can refetch the bytes via
        // WebDAV with the bot's credentials. Forward additional parameter
        // entries whose type === 'file' (e.g. multi-file shares expose
        // parameters.file, parameters.file2, …).
        $attachments = [];
        foreach (($decoded['parameters'] ?? []) as $pkey => $param) {
            if (!is_array($param) || ($param['type'] ?? '') !== 'file') {
                continue;
            }
            $attachments[] = [
                'file_id' => (string)($param['id'] ?? ''),
                'name' => (string)($param['name'] ?? 'file'),
                'mime' => (string)($param['mimetype'] ?? 'application/octet-stream'),
                'size' => (int)($param['size'] ?? 0),
                'path' => (string)($param['path'] ?? ''),
            ];
        }

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

        // Identify the bot that actually received this message by probing
        // each known bot user against the target room's OCS endpoint. The
        // first one that returns room info is (by NC's access model) a
        // participant/moderator of that room — i.e. the bot that was DMed
        // or added to the group. This is robust in setups where a single
        // NC hosts bots from multiple Chamade accounts (or a sender owns
        // multiple bots on the same NC) — the previous "first bot owned by
        // sender" heuristic picked the wrong bot there and Chamade fell
        // back to a stale room→bot mapping owned by someone else, silently
        // rejecting legitimate DMs.
        //
        // Side-effect: also returns the room type, so we fold the existing
        // lookupRoomType() call into the same iteration (saves one HTTP
        // round-trip per message for the common case).
        [$botUser, $roomType] = $this->resolveBotAndRoomType($roomToken);
        $botOwners = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_owners', '{}'),
            true
        ) ?: [];
        $bareSender = $this->bareUsername($actorId);
        $botOwner = $botUser !== '' ? ($botOwners[$botUser] ?? '') : '';

        // Legacy fallback: pre-2.2.0 installs that upgraded without
        // re-authorizing don't have `bot_passwords` populated for their
        // bot users, so the probe above returns nothing. In that case
        // fall back to the old sender-owner heuristic so those users
        // aren't suddenly locked out of their own bots on upgrade to
        // 2.4.1. New setups always hit the probe path.
        if ($botUser === '') {
            foreach ($botOwners as $candidateBot => $ownerUsername) {
                if ($ownerUsername === $bareSender) {
                    $botUser = (string) $candidateBot;
                    $botOwner = (string) $ownerUsername;
                    break;
                }
            }
        }

        $webhookUrl = rtrim($backendUrl, '/') . '/api/nctalk/chat';
        $payloadData = [
            'type' => $type,
            'room_token' => $roomToken,
            'sender' => $actorName,
            'sender_id' => $actorId,
            'message' => $message,
            'room_type' => $roomType,
            'bot_user' => $botUser,
            'bot_owner' => $botOwner,
        ];
        if (!empty($attachments)) {
            $payloadData['attachments'] = $attachments;
        }

        // E2EE encrypt fanout — only for real user messages. Commands
        // (`/*`) and attachment-carrying messages stay plaintext so
        // auto_messages.py, /activate, and attachment routing keep
        // working. docs/E2EE.md §5.3.
        $isCommand = str_starts_with($trimmedMessage, '/');
        $hasAttachments = !empty($attachments);
        if ($this->e2ee->isEnabled() && !$isCommand && !$hasAttachments && $message !== '') {
            try {
                $block = $this->e2ee->encrypt($message);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'E2EE encrypt failed, falling back to plaintext: ' . $e->getMessage(),
                    ['app' => Application::APP_ID],
                );
                $block = null;
            }
            if ($block !== null) {
                // Zero-knowledge: drop plaintext and transport only the
                // opaque block. Chamade treats a missing `message` plus a
                // non-empty `encrypted` as the E2EE path (see chamade
                // events.py passthrough).
                unset($payloadData['message']);
                $payloadData['encrypted'] = $block;
            }
            // If encrypt returned null (no devices paired), we fall back to
            // plaintext forwarding. The admin UI flags "no devices" so the
            // user knows they still need to paste a shim pubkey.
        }

        $payload = json_encode($payloadData);

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
        // Kept for backwards-compat with any future caller; the main flow
        // now goes through resolveBotAndRoomType() which computes both in
        // one pass.
        return $this->resolveBotAndRoomType($roomToken)[1];
    }

    /**
     * Probe each known bot user against the target room's OCS endpoint.
     * The first bot that returns room info is a participant there — that's
     * THE bot receiving this message. Returns [bot_username, room_type].
     * Returns ['', 0] if no bot is a member (shouldn't happen in practice,
     * since BotInvokeEvent fires only when one of our bots is addressed).
     */
    private function resolveBotAndRoomType(string $roomToken): array {
        $botPasswords = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_passwords', '{}'),
            true
        ) ?: [];
        if (!is_array($botPasswords) || empty($botPasswords)) {
            return ['', 0];
        }
        foreach ($botPasswords as $username => $password) {
            if (!is_string($username) || !is_string($password) || $password === '') {
                continue;
            }
            $info = $this->talkApi->getRoomInfo($roomToken, $username, $password);
            if ($info !== null) {
                return [(string) $username, (int) ($info['type'] ?? 0)];
            }
        }
        return ['', 0];
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
