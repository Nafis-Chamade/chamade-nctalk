<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Controller;

use OCA\ChamadeTalk\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Legacy admin settings save handler.
 *
 * As of v2.5.0 the admin settings page has no editable fields — all
 * pairing state is set authoritatively by the inverse OAuth connect
 * flow (see ConnectController). This endpoint is retained so the
 * route registration doesn't 404 on a POST from stale client JS, but
 * it no longer writes anything. The structural fix for the miskov
 * incident closes the write path entirely: there's nowhere left for
 * an admin to manually set a wrong backend_url.
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
        return new JSONResponse([
            'status' => 'noop',
            'message' => 'Pairing is now managed via the Connect flow; no editable fields.',
        ]);
    }
}
