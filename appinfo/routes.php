<?php

declare(strict_types=1);

/**
 * Routes for the {brand_id}_talk NC app.
 * All API routes are public (HMAC-verified at controller level).
 */

return [
    'routes' => [
        // Admin settings
        ['name' => 'settings#index',  'url' => '/settings',  'verb' => 'GET'],
        ['name' => 'settings#save',   'url' => '/settings',  'verb' => 'POST'],

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
