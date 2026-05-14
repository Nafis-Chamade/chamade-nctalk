<?php

declare(strict_types=1);

/**
 * Routes for the {brand_id}_talk NC app.
 * All API routes are public (HMAC-verified at controller level).
 */

return [
    'routes' => [
        // Admin settings. Both verbs target SettingsController::save()
        // (a noop returning a status JSON) since v2.5.0 removed all
        // editable fields. The GET originally pointed at settings#index,
        // which never existed in this controller — NC fell through to a
        // 500 for any admin who visited the addon settings page directly.
        // The GET route below sends visitors to the same noop save()
        // handler so they see informational JSON instead of an error.
        ['name' => 'settings#save',   'url' => '/settings',          'verb' => 'POST'],
        ['name' => 'settings#save',   'url' => '/settings',          'verb' => 'GET',  'postfix' => 'get'],

        // E2EE admin UI — NC admin session + CSRF, same auth surface as
        // SettingsController. See docs/E2EE.md §6.
        ['name' => 'e2eeAdmin#toggle',       'url' => '/settings/e2ee/toggle',       'verb' => 'POST'],
        ['name' => 'e2eeAdmin#regenerate',   'url' => '/settings/e2ee/regenerate',   'verb' => 'POST'],
        ['name' => 'e2eeAdmin#addDevice',    'url' => '/settings/e2ee/devices',      'verb' => 'POST'],
        ['name' => 'e2eeAdmin#removeDevice', 'url' => '/settings/e2ee/devices/{deviceId}', 'verb' => 'DELETE'],

        // HMAC API — called by Chamade Python service
        ['name' => 'config#getConfig',           'url' => '/api/v1/config',                        'verb' => 'GET'],
        ['name' => 'config#getAuthorizedRooms',  'url' => '/api/v1/authorized-rooms',              'verb' => 'GET'],
        ['name' => 'config#setAuthorizedRooms',  'url' => '/api/v1/authorized-rooms',              'verb' => 'PUT'],
        ['name' => 'config#getSignalingTicket',  'url' => '/api/v1/signaling/{roomToken}',         'verb' => 'GET'],
        ['name' => 'config#joinRoom',            'url' => '/api/v1/rooms/{token}/join',            'verb' => 'POST'],

        // Bot user management — HMAC (only endpoints actually called by Python)
        ['name' => 'botUser#delete',             'url' => '/api/v1/bot-users/{username}',            'verb' => 'DELETE'],
        ['name' => 'botUser#postMessage',        'url' => '/api/v1/bot-users/{username}/post',      'verb' => 'POST'],
        ['name' => 'botUser#uploadAvatar',       'url' => '/api/v1/bot-users/{username}/avatar',    'verb' => 'POST'],

        // Unified chat send (v3.0+) — HMAC. Supersedes OCS `POST /chat/{token}`
        // for Chamade when the `unified_send` capability is announced.
        // Accepts either plaintext `content` or an opaque `encrypted` block
        // (docs/E2EE.md). Fallback to OCS stays in place for legacy addons.
        ['name' => 'message#send',               'url' => '/api/v1/messages/{token}',               'verb' => 'POST'],

        // Authorization flow — NC session + CSRF (user-facing, legacy
        // Chamade-first path — kept for backward compat with already-
        // deployed gateway builds that haven't shipped the inverse flow
        // yet. New installs should use the NC-first flow below.)
        ['name' => 'authorize#show',             'url' => '/authorize',                             'verb' => 'GET'],
        ['name' => 'authorize#approve',          'url' => '/authorize',                             'verb' => 'POST'],
        // Automated authorize — HMAC (e2e tests / programmatic provisioning)
        ['name' => 'authorize#autoApprove',      'url' => '/api/v1/authorize',                      'verb' => 'POST'],

        // NC-first inverse OAuth flow — admin clicks "Connect to Chamade"
        // in the addon settings page (or a Chamade-dashboard deeplink
        // redirects here). See ConnectController phpdoc for the full
        // state machine.
        ['name' => 'connect#connectStart',       'url' => '/connect-start',                         'verb' => 'GET'],
        ['name' => 'connect#authorizeFinish',    'url' => '/authorize/finish',                      'verb' => 'GET'],
    ],
];
