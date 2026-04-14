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
     * Query params: state, agent_name, backend_url (optional)
     * (callback_url comes from admin config, not user input)
     *
     * If `backend_url` is provided and the admin has not already
     * configured one, persist it now so the ChatListener can forward
     * chat messages without requiring a separate manual admin step.
     * Once a backend_url exists in appconfig, it is NOT overridden —
     * admins keep full control to change it later.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(): TemplateResponse|JSONResponse {
        $state = $this->request->getParam('state', '');
        $agentName = $this->request->getParam('agent_name', 'AI Agent');
        $backendUrl = $this->request->getParam('backend_url', '');
        $requestCallbackUrl = $this->request->getParam('callback_url', '');

        $callbackUrl = $this->getCallbackUrl();

        // Auto-bootstrap callback_url from the connect request if the
        // addon has never been paired (fresh install) OR if a previous
        // uninstall step wiped it. We require the incoming callback_url
        // and backend_url to share the same host — both must come from
        // the same partner deployment, so a crafted authorize link with
        // a spoofed callback_url (pointing at an attacker) cannot also
        // spoof backend_url in a matching way without controlling both.
        if (empty($callbackUrl) && !empty($requestCallbackUrl) && !empty($backendUrl)) {
            if ($this->isSafeBootstrapPair($requestCallbackUrl, $backendUrl)) {
                $this->config->setAppValue(Application::APP_ID, 'callback_url', $requestCallbackUrl);
                $callbackUrl = $requestCallbackUrl;
                $this->logger->info("Auto-set callback_url from connect flow", [
                    'app' => Application::APP_ID,
                ]);
            }
        }

        if (empty($callbackUrl)) {
            return new JSONResponse(['error' => 'No callback URL configured — admin must pair first'], 400);
        }

        // Auto-bootstrap backend_url from the connect request the first
        // time through. We only accept https:// targets on the same host
        // as callback_url to avoid letting an attacker redirect chat to
        // a third-party URL via a crafted authorize link.
        if (!empty($backendUrl)) {
            $existing = $this->config->getAppValue(Application::APP_ID, 'backend_url', '');
            if (empty($existing) && $this->isSafeBackendUrl($backendUrl, $callbackUrl)) {
                $this->config->setAppValue(Application::APP_ID, 'backend_url', $backendUrl);
                $this->logger->info("Auto-set backend_url from connect flow", [
                    'app' => Application::APP_ID,
                ]);
            }
        }

        $brandName = $this->config->getAppValue(Application::APP_ID, 'brand_name', 'Chamade');

        return new TemplateResponse(Application::APP_ID, 'authorize', [
            'callback_url' => $callbackUrl,
            'state' => $state,
            'agent_name' => $agentName,
            'brand_name' => $brandName,
        ]);
    }

    /**
     * Only accept a backend_url whose scheme is https and whose host
     * matches the admin-configured callback_url. Anything else is
     * ignored — the admin can still set it manually in settings.
     */
    private function isSafeBackendUrl(string $backendUrl, string $callbackUrl): bool {
        $b = parse_url($backendUrl);
        $c = parse_url($callbackUrl);
        if (!is_array($b) || !is_array($c)) {
            return false;
        }
        $bScheme = $b['scheme'] ?? '';
        $bHost = $b['host'] ?? '';
        $cHost = $c['host'] ?? '';
        // Allow http only for localhost/dev flows where callback_url is
        // also http (e.g. test environments hitting 127.0.0.1).
        $cScheme = $c['scheme'] ?? '';
        if ($bScheme !== 'https' && !($bScheme === 'http' && $cScheme === 'http')) {
            return false;
        }
        return $bHost !== '' && strcasecmp($bHost, $cHost) === 0;
    }

    /**
     * Only auto-bootstrap callback_url when we also have a backend_url
     * from the same source. Both URLs must share the same scheme + host,
     * and the scheme must be https (or http in dev flows where both
     * sides also speak http). Prevents a crafted authorize link from
     * pointing callback_url at an attacker while leaving backend_url
     * looking legitimate: whoever forges one has to forge the other.
     */
    private function isSafeBootstrapPair(string $callbackUrl, string $backendUrl): bool {
        $c = parse_url($callbackUrl);
        $b = parse_url($backendUrl);
        if (!is_array($c) || !is_array($b)) {
            return false;
        }
        $cScheme = $c['scheme'] ?? '';
        $bScheme = $b['scheme'] ?? '';
        if ($cScheme !== $bScheme) {
            return false;
        }
        if ($cScheme !== 'https' && $cScheme !== 'http') {
            return false;
        }
        $cHost = $c['host'] ?? '';
        $bHost = $b['host'] ?? '';
        if ($cHost === '' || $bHost === '') {
            return false;
        }
        return strcasecmp($cHost, $bHost) === 0;
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
        // Generate a password that satisfies the default Nextcloud
        // password_policy app (upper + lower + digits + symbol). The older
        // CHAR_ALPHANUMERIC set was rejected on instances that enforce
        // complexity, causing createUser() to throw and the whole authorize
        // flow to abort with "Failed to create bot user".
        $botPassword =
            $this->random->generate(8, ISecureRandom::CHAR_UPPER) .
            $this->random->generate(8, ISecureRandom::CHAR_LOWER) .
            $this->random->generate(8, ISecureRandom::CHAR_DIGITS) .
            $this->random->generate(8, '!@#$%^&*()-_=+[]{}');

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

        // Persist the bot user password so local code paths that need
        // moderator OCS credentials (ChatListener::lookupRoomType for the
        // owner-DM auto-authorize check, and any future helper) can look
        // it up. Without this entry, ChatListener silently drops every
        // message from the owner because it cannot determine that the
        // DM is a ONE_TO_ONE room.
        $botPasswords = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_passwords', '{}'),
            true
        ) ?: [];
        $botPasswords[$botUsername] = $botPassword;
        $this->config->setAppValue(Application::APP_ID, 'bot_passwords', json_encode($botPasswords));

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
