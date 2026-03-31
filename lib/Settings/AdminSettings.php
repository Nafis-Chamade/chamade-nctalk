<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Settings;

use OCA\ChamadeTalk\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

/**
 * Admin settings — displayed in NC admin > Talk section.
 */
class AdminSettings implements ISettings {

    public function __construct(
        private IConfig $config,
    ) {
    }

    public function getForm(): TemplateResponse {
        $botId = $this->config->getAppValue(Application::APP_ID, 'default_bot_id', '');
        $backendUrl = $this->config->getAppValue(Application::APP_ID, 'backend_url', '');
        $apiSecret = $this->config->getAppValue(Application::APP_ID, 'api_secret', '');
        $apiKey = $this->config->getAppValue(Application::APP_ID, 'api_key', '');
        $callbackUrl = $this->config->getAppValue(Application::APP_ID, 'callback_url', '');

        return new TemplateResponse(Application::APP_ID, 'settings', [
            'bot_id' => $botId,
            'backend_url' => $backendUrl,
            'api_secret' => $apiSecret,
            'api_key' => $apiKey,
            'callback_url' => $callbackUrl,
            'has_bot' => !empty($botId),
        ]);
    }

    public function getSection(): string {
        return 'talk';
    }

    public function getPriority(): int {
        return 90;
    }
}
