<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Controller;

use OCA\ChamadeTalk\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Admin settings save handler — handles POST from the settings form.
 */
class SettingsController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    public function save(): JSONResponse {
        $fields = ['backend_url', 'api_key', 'callback_url'];

        foreach ($fields as $field) {
            $value = $this->request->getParam($field);
            if ($value !== null) {
                $this->config->setAppValue(Application::APP_ID, $field, $value);
            }
        }

        return new JSONResponse(['status' => 'ok']);
    }
}
