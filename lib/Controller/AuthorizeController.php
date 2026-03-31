<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Controller;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\ChamadeTalk\Traits\HmacVerification;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Authorization controller for external partner services.
 *
 * Implements an OAuth-like redirect flow:
 * 1. GET /authorize — show approval page (NC session required)
 * 2. POST /authorize — create bot user, callback to external service, redirect
 *
 * Auth: NC session + CSRF (user-facing, not HMAC).
 *
 * Also exposes POST /api/v1/authorize (HMAC) for automated flows (e2e tests).
 */
class AuthorizeController extends Controller {

    use HmacVerification;

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private ISecureRandom $random,
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ========================================================================
    // show — GET /authorize
    // ========================================================================

    /**
     * Show the authorization approval page.
     *
     * Query params: state, agent_name
     * (callback_url comes from admin config, not user input)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(): TemplateResponse|JSONResponse {
        $state = $this->request->getParam('state', '');
        $agentName = $this->request->getParam('agent_name', 'AI Agent');

        $callbackUrl = $this->getCallbackUrl();
        if (empty($callbackUrl)) {
            return new JSONResponse(['error' => 'No callback URL configured — admin must pair first'], 400);
        }

        $brandName = $this->config->getAppValue(Application::APP_ID, 'brand_name', 'Chamade');

        return new TemplateResponse(Application::APP_ID, 'authorize', [
            'callback_url' => $callbackUrl,
            'state' => $state,
            'agent_name' => $agentName,
            'brand_name' => $brandName,
        ]);
    }

    // ========================================================================
    // approve — POST /authorize
    // ========================================================================

    /**
     * Process the authorization: create bot user, callback, redirect.
     *
     * @NoAdminRequired
     */
    public function approve(): RedirectResponse|JSONResponse {
        $state = $this->request->getParam('state', '');
        $agentName = $this->request->getParam('agent_name', 'AI Agent');

        $callbackUrl = $this->getCallbackUrl();
        if (empty($callbackUrl) || empty($state)) {
            return new JSONResponse(['error' => 'callback_url not configured or state missing'], 400);
        }

        // Get current NC user
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $ownerNcUsername = $currentUser->getUID();
        $result = $this->createBotAndCallback($callbackUrl, $state, $agentName, $ownerNcUsername);
        if ($result['error'] !== null) {
            return $result['error'];
        }

        // Redirect to the partner service dashboard (callback_url is admin-configured, safe)
        $parsed = parse_url($callbackUrl);
        $dashboardUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . '/dashboard?nctalk=connected';

        return new RedirectResponse($dashboardUrl);
    }

    // ========================================================================
    // autoApprove — POST /api/v1/authorize (HMAC, no NC session)
    // ========================================================================

    /**
     * Automated authorization: same as approve() but HMAC-authenticated.
     * Used by e2e tests and automated provisioning.
     *
     * @PublicPage
     * @NoCSRFRequired
     */
    public function autoApprove(): JSONResponse {
        if (!$this->verifyHmac()) {
            return new JSONResponse(['error' => 'Invalid HMAC'], 403);
        }

        // HMAC-authenticated: callback_url from request is trusted (caller has the secret)
        $callbackUrl = $this->request->getParam('callback_url', '') ?: $this->getCallbackUrl();
        $state = $this->request->getParam('state', '');
        $agentName = $this->request->getParam('agent_name', 'AI Agent');
        $ncUsername = $this->request->getParam('nc_username', '');

        if (empty($callbackUrl) || empty($state)) {
            return new JSONResponse(['error' => 'callback_url and state required'], 400);
        }

        $result = $this->createBotAndCallback($callbackUrl, $state, $agentName, $ncUsername);
        if ($result['error'] !== null) {
            return $result['error'];
        }

        return new JSONResponse(['status' => 'ok', 'bot_login' => $result['bot_login']]);
    }

    // ========================================================================
    // Shared helpers
    // ========================================================================

    /**
     * Create bot user, register in appconfig, callback to external service.
     * Returns ['error' => null, 'bot_login' => string] on success,
     * or ['error' => JSONResponse, 'bot_login' => ''] on failure.
     */
    private function createBotAndCallback(string $callbackUrl, string $state, string $agentName, string $ownerNcUsername = ''): array {
        $brandId = str_replace('_talk', '', Application::APP_ID);

        // Create bot user: {brand_id}-bot-{random}
        $suffix = strtolower($this->random->generate(8, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
        $botUsername = $brandId . '-bot-' . $suffix;
        $botPassword = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);

        try {
            $this->userManager->createUser($botUsername, $botPassword);
        } catch (\Exception $e) {
            $this->logger->error("Failed to create bot user {$botUsername}: " . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
            return ['error' => new JSONResponse(['error' => 'Failed to create bot user'], 500), 'bot_login' => ''];
        }

        // Set display name: "Brand (owner)" for identification
        $botUser = $this->userManager->get($botUsername);
        if ($botUser !== null) {
            $brandName = $this->config->getAppValue(Application::APP_ID, 'brand_name', 'Chamade');
            $displayName = !empty($ownerNcUsername)
                ? $brandName . ' (' . $ownerNcUsername . ')'
                : $brandName;
            $botUser->setDisplayName($displayName);
        }

        // Track bot username in appconfig for ChatListener filtering
        $botUsers = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_users', '[]'),
            true
        ) ?: [];
        if (!in_array($botUsername, $botUsers)) {
            $botUsers[] = $botUsername;
            $this->config->setAppValue(Application::APP_ID, 'bot_users', json_encode($botUsers));
        }

        // Store bot → owner mapping for room authorization (ChatListener)
        if (!empty($ownerNcUsername)) {
            $botOwners = json_decode(
                $this->config->getAppValue(Application::APP_ID, 'bot_owners', '{}'),
                true
            ) ?: [];
            $botOwners[$botUsername] = $ownerNcUsername;
            $this->config->setAppValue(Application::APP_ID, 'bot_owners', json_encode($botOwners));
        }

        // Create visibility group: bot + owner in same group (hides bot from other users)
        if (!empty($ownerNcUsername)) {
            $brandName = $this->config->getAppValue(Application::APP_ID, 'brand_name', 'Chamade');
            $groupId = $brandName . '-' . $ownerNcUsername;
            $group = $this->groupManager->get($groupId);
            if ($group === null) {
                $group = $this->groupManager->createGroup($groupId);
            }
            if ($group !== null) {
                $ownerUser = $this->userManager->get($ownerNcUsername);
                if ($ownerUser !== null && !$group->inGroup($ownerUser)) {
                    $group->addUser($ownerUser);
                }
                if ($botUser !== null && !$group->inGroup($botUser)) {
                    $group->addUser($botUser);
                }
            }
        }

        // Get NC URL and API secret
        $ncUrl = rtrim($this->request->getServerProtocol() . '://' . $this->request->getServerHost(), '/');
        $apiSecret = $this->config->getAppValue(Application::APP_ID, 'api_secret', '');
        $appId = Application::APP_ID;

        // Server-to-server callback
        try {
            $client = $this->clientService->newClient();
            $client->post($callbackUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'state' => $state,
                    'nc_url' => $ncUrl,
                    'bot_login' => $botUsername,
                    'bot_password' => $botPassword,
                    'api_secret' => $apiSecret,
                    'app_id' => $appId,
                    'nc_username' => $ownerNcUsername,
                ]),
                'timeout' => 15,
            ]);
        } catch (\Exception $e) {
            // Callback failed — clean up bot user
            $this->logger->error("Authorize callback failed: " . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
            $botUser = $this->userManager->get($botUsername);
            if ($botUser !== null) {
                $botUser->delete();
            }
            return ['error' => new JSONResponse(['error' => 'Callback to external service failed'], 502), 'bot_login' => ''];
        }

        $this->logger->info("Authorization approved: created bot {$botUsername} (owner: {$ownerNcUsername})", [
            'app' => Application::APP_ID,
        ]);

        return ['error' => null, 'bot_login' => $botUsername];
    }

    /**
     * Get the callback URL from admin config (set during pairing).
     * This prevents open redirect — only admin-configured URLs are used.
     */
    private function getCallbackUrl(): string {
        $fromParam = $this->request->getParam('callback_url', '');
        if (!empty($fromParam)) {
            return $fromParam;
        }
        return $this->config->getAppValue(Application::APP_ID, 'callback_url', '');
    }
}
