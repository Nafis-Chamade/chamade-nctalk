<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Controller;

use OCA\ChamadeTalk\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * NC-first (inverse) OAuth pairing flow.
 *
 * Replaces the original Chamade-first `AuthorizeController::show/approve`
 * path with a flow that starts from the Nextcloud admin side:
 *
 *   1. Admin clicks "Connect to Chamade" in the addon settings page (or
 *      lands here via a Chamade-dashboard deeplink that just did
 *      `window.location = {nc}/apps/{app}/connect-start?origin=dashboard`).
 *   2. connectStart() — verifies NC admin session, generates nc_state,
 *      stores it in appconfig with a short TTL, and redirects the
 *      browser to the Chamade gateway's /connect/nctalk endpoint.
 *   3. Chamade authenticates the user (login/signup inline if needed),
 *      shows a consent screen naming this NC instance by hostname,
 *      generates its own chamade_state, and redirects the browser
 *      back here to `authorizeFinish` with chamade_state + signed
 *      callback_url + backend_url.
 *   4. authorizeFinish() — re-verifies NC admin session, validates
 *      nc_state + admin_uid match, always overwrites backend_url and
 *      callback_url with the authoritative values from this flow
 *      (this is the miskov fix), delegates bot creation to
 *      AuthorizeController::processApproval, then redirects the
 *      browser to the origin endpoint.
 *
 * origin=dashboard => return to {chamade}/dashboard/platforms
 * origin=nc_admin  => return to {nc}/settings/admin/{app_id}
 *
 * Single code path, two entry points — same UX consistency as the
 * Chamade-first OAuth flows (Teams/Meet/Slack/Discord) for dashboard
 * entry, plus a natural entry point for admins who discover the
 * addon via the NC App Store.
 */
class ConnectController extends Controller {

    /** Window during which nc_state remains valid (seconds). */
    private const STATE_TTL = 600;

    /** appconfig key for the pending nc_state blob. */
    private const CONFIG_KEY = 'pending_nc_state';

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IAppManager $appManager,
        private LoggerInterface $logger,
        private AuthorizeController $authorize,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Entry point. Admin clicks "Connect to Chamade" (or the Chamade
     * dashboard deeplinks here). Generates nc_state, stores it with the
     * admin UID + origin + expiry, redirects to the gateway.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function connectStart(): RedirectResponse|JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            // Anonymous — bounce through NC login first.
            $loginUrl = $this->ncUrl() . '/login?redirect_url='
                . urlencode($this->request->getRequestUri());
            return new RedirectResponse($loginUrl);
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            return new JSONResponse(['error' => 'Admin required'], 403);
        }

        $origin = $this->request->getParam('origin', 'nc_admin');
        if (!in_array($origin, ['dashboard', 'nc_admin'], true)) {
            $origin = 'nc_admin';
        }

        $ncState = bin2hex(random_bytes(16));
        $this->config->setAppValue(Application::APP_ID, self::CONFIG_KEY, json_encode([
            'state' => $ncState,
            'origin' => $origin,
            'admin_uid' => $user->getUID(),
            'expires' => time() + self::STATE_TTL,
        ]));

        $chamadeUrl = $this->getChamadeUrl();
        $params = http_build_query([
            'nc_state' => $ncState,
            'nc_url' => $this->ncUrl(),
            'origin' => $origin,
        ]);

        $this->logger->info("connect-start: redirecting to gateway (origin={$origin})", [
            'app' => Application::APP_ID,
        ]);

        return new RedirectResponse("{$chamadeUrl}/connect/nctalk?{$params}");
    }

    /**
     * Chamade redirects here after consent. Validates the pending nc_state,
     * overwrites backend_url + callback_url with the authoritative values
     * from this flow, runs the approval (bot creation + credential POST
     * via AuthorizeController::processApproval), redirects to origin.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function authorizeFinish(): RedirectResponse|JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            $loginUrl = $this->ncUrl() . '/login?redirect_url='
                . urlencode($this->request->getRequestUri());
            return new RedirectResponse($loginUrl);
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            return new JSONResponse(['error' => 'Admin required'], 403);
        }

        $chamadeState = (string) $this->request->getParam('state', '');
        $ncStateClaim = (string) $this->request->getParam('nc_state', '');
        $callbackUrl = (string) $this->request->getParam('callback_url', '');
        $backendUrl = (string) $this->request->getParam('backend_url', '');
        $origin = (string) $this->request->getParam('origin', 'nc_admin');

        if ($chamadeState === '' || $ncStateClaim === '' || $callbackUrl === '' || $backendUrl === '') {
            return $this->redirectWithError($origin, 'missing_params');
        }

        // Validate nc_state — must match the one we stored in connectStart,
        // must not be expired, must come from the same admin UID.
        $storedJson = $this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, '');
        if ($storedJson === '') {
            return $this->redirectWithError($origin, 'state_missing');
        }
        $stored = json_decode($storedJson, true);
        if (!is_array($stored) || empty($stored['state'])
            || !hash_equals((string) $stored['state'], $ncStateClaim)) {
            return $this->redirectWithError($origin, 'state_mismatch');
        }
        if (time() > (int) ($stored['expires'] ?? 0)) {
            $this->config->deleteAppValue(Application::APP_ID, self::CONFIG_KEY);
            return $this->redirectWithError($origin, 'state_expired');
        }
        if (($stored['admin_uid'] ?? '') !== $user->getUID()) {
            return $this->redirectWithError($origin, 'admin_mismatch');
        }

        // Consume nc_state (one-time use).
        $this->config->deleteAppValue(Application::APP_ID, self::CONFIG_KEY);

        // Both URLs must share scheme + host — same defense as the
        // legacy isSafeBootstrapPair, prevents a split-origin spoof.
        if (!$this->areUrlsSafe($callbackUrl, $backendUrl)) {
            return $this->redirectWithError($origin, 'unsafe_urls');
        }

        // Authoritatively overwrite — the connect flow is the single
        // source of truth for these values. This is the structural fix
        // for the miskov incident: a manually-edited stale backend_url
        // is no longer retained across reconnects.
        $this->config->setAppValue(Application::APP_ID, 'backend_url', rtrim($backendUrl, '/'));
        $this->config->setAppValue(Application::APP_ID, 'callback_url', $callbackUrl);

        // Bot creation + server-to-server callback POST lives in
        // AuthorizeController — reuse it verbatim.
        $result = $this->authorize->processApproval(
            $callbackUrl,
            $chamadeState,
            'AI Agent',
            $user->getUID()
        );
        if (($result['error'] ?? null) !== null) {
            $this->logger->warning("authorize-finish: processApproval failed", [
                'app' => Application::APP_ID,
            ]);
            return $this->redirectWithError($origin, 'bot_creation_failed');
        }

        $this->logger->info("authorize-finish: paired (origin={$origin}, admin={$user->getUID()})", [
            'app' => Application::APP_ID,
        ]);

        if ($origin === 'dashboard') {
            return new RedirectResponse($this->getChamadeUrl() . '/dashboard/platforms?connected=nctalk');
        }
        // NC admin settings are grouped by `section`, not by app_id —
        // `/settings/admin/{section}` is the correct path. For this
        // addon the section is "talk" (AdminSettings::getSection). A
        // path like `/settings/admin/chamade_talk` is treated as an
        // unknown section and NC responds "Access forbidden".
        return new RedirectResponse($this->ncUrl() . '/settings/admin/talk?connected=1');
    }

    /** Same host + scheme check as AuthorizeController::isSafeBootstrapPair. */
    private function areUrlsSafe(string $callbackUrl, string $backendUrl): bool {
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
        if (!in_array($cScheme, ['http', 'https'], true)) {
            return false;
        }
        $cHost = $c['host'] ?? '';
        $bHost = $b['host'] ?? '';
        if ($cHost === '' || $bHost === '') {
            return false;
        }
        return strcasecmp($cHost, $bHost) === 0;
    }

    private function redirectWithError(string $origin, string $error): RedirectResponse {
        if ($origin === 'dashboard') {
            return new RedirectResponse(
                $this->getChamadeUrl() . '/dashboard/platforms?error=nctalk_' . urlencode($error)
            );
        }
        return new RedirectResponse(
            $this->ncUrl() . '/settings/admin/talk?error=' . urlencode($error)
        );
    }

    private function ncUrl(): string {
        return rtrim(
            $this->request->getServerProtocol() . '://' . $this->request->getServerHost(),
            '/'
        );
    }

    /**
     * Resolve the Chamade gateway URL. Priority:
     *   1. appconfig `gateway_url` (admin override, useful for dev)
     *   2. info.xml <website> (branded at build time — chamade.io for Chamade)
     *   3. Hardcoded fallback
     */
    private function getChamadeUrl(): string {
        $stored = $this->config->getAppValue(Application::APP_ID, 'gateway_url', '');
        if ($stored !== '') {
            return rtrim($stored, '/');
        }
        $info = $this->appManager->getAppInfo(Application::APP_ID);
        $website = is_array($info) ? ($info['website'] ?? '') : '';
        if (is_string($website) && $website !== '') {
            return rtrim($website, '/');
        }
        return 'https://chamade.io';
    }
}
