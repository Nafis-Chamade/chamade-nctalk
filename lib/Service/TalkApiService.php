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
 * All calls go through the local Nextcloud HTTP API as the service user,
 * keeping us on public APIs only (required for App Store).
 */
class TalkApiService {

    public function __construct(
        private IConfig $config,
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    // ========================================================================
    // Room operations
    // ========================================================================

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
     * Create a group conversation.
     *
     * @return array{token: string, name: string}|null
     */
    public function createGroupRoom(string $name, string $asUser, string $password): ?array {
        $data = $this->ocsRequest('POST', '/apps/spreed/api/v4/room', $asUser, $password, [
            'roomType' => 2, // TYPE_GROUP
            'roomName' => $name,
        ]);
        if ($data === null) {
            return null;
        }
        return [
            'token' => $data['token'] ?? '',
            'name' => $data['displayName'] ?? $data['name'] ?? $name,
        ];
    }

    /**
     * Create (or reuse) a 1:1 conversation.
     *
     * @return array{token: string}|null
     */
    public function createOneToOneRoom(string $targetUserId, string $asUser, string $password): ?array {
        $data = $this->ocsRequest('POST', '/apps/spreed/api/v4/room', $asUser, $password, [
            'roomType' => 1, // TYPE_ONE_TO_ONE
            'invite' => $targetUserId,
        ]);
        if ($data === null) {
            return null;
        }
        return [
            'token' => $data['token'] ?? '',
        ];
    }

    /**
     * Delete a room.
     */
    public function deleteRoom(string $token, string $asUser, string $password): bool {
        return $this->ocsRequest('DELETE', "/apps/spreed/api/v4/room/{$token}", $asUser, $password) !== null;
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

    // ========================================================================
    // Chat operations
    // ========================================================================

    /**
     * Send a chat message in a room.
     */
    public function sendMessage(string $token, string $message, string $asUser, string $password): bool {
        return $this->ocsRequest('POST', "/apps/spreed/api/v1/chat/{$token}", $asUser, $password, [
            'message' => $message,
        ]) !== null;
    }

    // ========================================================================
    // Bot operations (via OCS — Talk 21+ / NC 31+)
    // ========================================================================

    /**
     * List bots visible to current user (server-wide).
     *
     * @return array<array{id: int, name: string, state: int}>
     */
    public function listBots(string $token, string $asUser, string $password): array {
        $data = $this->ocsRequest('GET', "/apps/spreed/api/v1/bot/{$token}", $asUser, $password);
        return is_array($data) ? $data : [];
    }

    /**
     * Enable a bot in a conversation via OCS.
     * POST /ocs/v2.php/apps/spreed/api/v1/bot/{token}/admin
     */
    public function enableBotInRoom(int $botId, string $token, string $asUser, string $password): bool {
        return $this->ocsRequest('POST', "/apps/spreed/api/v1/bot/{$token}/admin", $asUser, $password, [
            'botId' => $botId,
        ]) !== null;
    }

    // ========================================================================
    // Internal HTTP helper
    // ========================================================================

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
