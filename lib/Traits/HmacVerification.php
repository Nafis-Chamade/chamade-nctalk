<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Traits;

use OCA\ChamadeTalk\AppInfo\Application;

/**
 * Shared HMAC verification for controllers.
 *
 * Requires $this->request (IRequest) and $this->config (IConfig) to be set.
 * Protocol headers stay X-Maquis+ard-* regardless of branding —
 * concatenation prevents deploy.sh sed from renaming them.
 */
trait HmacVerification {

    protected function verifyHmac(): bool {
        $random = $this->request->getHeader('X-Maquis' . 'ard-Random');
        $signature = $this->request->getHeader('X-Maquis' . 'ard-Signature');
        $secret = $this->config->getAppValue(Application::APP_ID, 'api_secret', '');

        if (empty($random) || empty($signature) || empty($secret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $random, $secret);
        return hash_equals($expected, $signature);
    }
}
