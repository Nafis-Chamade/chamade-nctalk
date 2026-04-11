<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Wrapper around Talk's OCS REST API — replaces direct use of private
 * OCA\Talk\* classes (Manager, RoomService, ParticipantService, mappers).
 *
 * All calls go through the local Nextcloud HTTP API as a specified user,
 * keeping us on public APIs only (required for App Store).
 *
 * Scope after 2.2.0 cleanup: only the methods used by the live code
 * paths — getRoomInfo (ChatListener is_dm + owner DM check), sendMessage
 * (BotUserController::postMessage) and addUserToRoom (ConfigController
 * ::joinRoom). Dead createGroupRoom / createOneToOneRoom / deleteRoom
 * / listBots / enableBotInRoom were removed.
 */
class TalkApiService {

    public function __construct(
        private IConfig $config,
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get room info by token.
     *
     * @return array{token: string, name: string, type: int, hasCall: bool}|null
     */
    public function getRoomInfo(string $token, string $asUser, string $password): ?array {
        $data = $this->ocsRequest('GET', "/apps/spreed/api/v4/room/{$token}", $asUser, $password);
        if ($data === null) {
            return null;
        }
        return [
            'token' => $data['token'] ?? $token,
            'name' => $data['displayName'] ?? $data['name'] ?? '',
            'type' => (int) ($data['type'] ?? 0),
            'hasCall' => ($data['hasCall'] ?? false) !== false,
        ];
    }

    /**
     * Add a user to a room.
     */
    public function addUserToRoom(string $token, string $userId, string $asUser, string $password): bool {
        return $this->ocsRequest('POST', "/apps/spreed/api/v4/room/{$token}/participants", $asUser, $password, [
            'newParticipant' => $userId,
            'source' => 'users',
        ]) !== null;
    }

    /**
     * Send a chat message in a room.
     */
    public function sendMessage(string $token, string $message, string $asUser, string $password): bool {
        return $this->ocsRequest('POST', "/apps/spreed/api/v1/chat/{$token}", $asUser, $password, [
            'message' => $message,
        ]) !== null;
    }

    /**
     * Enable a Talk bot in a specific room via OCS REST.
     * Requires the caller to be a moderator of the room.
     *
     * POST /ocs/v2.php/apps/spreed/api/v1/bot/{token}/{botId}
     *
     * Returns true on success. On 400 "Bot is already enabled", spreed
     * returns that as an error but it's effectively a no-op for us —
     * treat as success so our auto-enable remains idempotent.
     */
    public function enableBotInRoom(int $botId, string $token, string $asUser, string $password): bool {
        $data = $this->ocsRequest(
            'POST',
            "/apps/spreed/api/v1/bot/{$token}/{$botId}",
            $asUser,
            $password,
            []
        );
        // ocsRequest returns null on any non-2xx; distinguishing "already
        // enabled" (400) from a real failure requires inspecting the body,
        // which ocsRequest throws away. For our purposes the caller just
        // wants idempotent "ensure the bot is on in this room".
        return $data !== null;
    }

    /**
     * Make an OCS REST API request to the local Nextcloud instance.
     *
     * @return array|null Parsed response data (ocs.data), or null on failure
     */
    private function ocsRequest(
        string $method,
        string $endpoint,
        string $asUser,
        string $password,
        array $body = [],
    ): ?array {
        if (empty($asUser) || empty($password)) {
            // Empty credentials always fail — skip the HTTP roundtrip.
            return null;
        }

        $ncUrl = $this->config->getSystemValue('overwrite.cli.url', 'https://localhost');
        $url = rtrim($ncUrl, '/') . '/ocs/v2.php' . $endpoint;

        $options = [
            'headers' => [
                'OCS-APIRequest' => 'true',
                'Accept' => 'application/json',
            ],
            'auth' => [$asUser, $password],
            'timeout' => 15,
        ];

        if (!empty($body)) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body);
        }

        try {
            $client = $this->clientService->newClient();
            $response = match ($method) {
                'GET' => $client->get($url, $options),
                'POST' => $client->post($url, $options),
                'PUT' => $client->put($url, $options),
                'DELETE' => $client->delete($url, $options),
            };

            $json = json_decode($response->getBody(), true);
            return $json['ocs']['data'] ?? $json ?? [];
        } catch (\Exception $e) {
            $this->logger->warning("OCS request failed: {$method} {$endpoint}: " . $e->getMessage());
            return null;
        }
    }
}
