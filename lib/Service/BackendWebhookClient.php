<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Service;

use OCA\ChamadeTalk\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Fire-and-forget HMAC-authenticated POST to the Chamade backend.
 *
 * Reads backend_url + api_secret from appconfig. Used by event listeners
 * that push notifications (attendee added, call started/ended, ...) to
 * Chamade. The ChatListener uses its own inline POST because it needs
 * to read the response body to relay the bot reply back.
 *
 * Protocol headers stay X-Maquis+ard-* regardless of branding (string
 * concatenation prevents deploy.sh sed from renaming them).
 */
class BackendWebhookClient {

    public function __construct(
        private IConfig $config,
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * POST a payload to a Chamade webhook endpoint.
     *
     * @param string $path e.g. "/api/nctalk/attendee-added"
     * @param array<string,mixed> $payload
     */
    public function post(string $path, array $payload): void {
        $backendUrl = $this->config->getAppValue(Application::APP_ID, 'backend_url', '');
        $apiSecret = $this->config->getAppValue(Application::APP_ID, 'api_secret', '');

        if ($backendUrl === '' || $apiSecret === '') {
            return;
        }

        $url = rtrim($backendUrl, '/') . $path;
        $body = json_encode($payload);
        if ($body === false) {
            $this->logger->warning("Webhook encode failed for {$path}", [
                'app' => Application::APP_ID,
            ]);
            return;
        }

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
                'body' => $body,
                'timeout' => 10,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning("Webhook POST {$path} failed: " . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
    }
}
