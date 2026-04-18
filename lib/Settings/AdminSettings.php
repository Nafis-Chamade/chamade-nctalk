<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Settings;

use OCA\ChamadeTalk\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

/**
 * Admin settings — displayed in NC admin > Talk section.
 *
 * As of v2.5.0 this page is diagnostic-only: no editable fields. All
 * pairing state (backend_url, callback_url, api_secret) is set
 * authoritatively by the NC-first inverse OAuth flow (see
 * ConnectController). The one action exposed here is the "Connect to
 * Chamade" button that initiates that flow.
 */
class AdminSettings implements ISettings {

    public function __construct(
        private IConfig $config,
        private IAppManager $appManager,
    ) {
    }

    public function getForm(): TemplateResponse {
        $botRegistered = !empty($this->config->getAppValue(Application::APP_ID, 'default_bot_secret', ''));
        $callbackUrl = $this->config->getAppValue(Application::APP_ID, 'callback_url', '');
        $backendUrl = $this->config->getAppValue(Application::APP_ID, 'backend_url', '');
        $apiSecret = $this->config->getAppValue(Application::APP_ID, 'api_secret', '');
        $isPaired = $callbackUrl !== '' && $backendUrl !== '';

        return new TemplateResponse(Application::APP_ID, 'settings', [
            'has_bot' => $botRegistered,
            'is_paired' => $isPaired,
            'backend_url' => $backendUrl,
            'api_secret' => $apiSecret,
            'gateway_url' => $this->resolveGatewayUrl(),
            'app_id' => Application::APP_ID,
        ]);
    }

    public function getSection(): string {
        return 'talk';
    }

    public function getPriority(): int {
        return 90;
    }

    private function resolveGatewayUrl(): string {
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
