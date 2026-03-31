<?php

declare(strict_types=1);

/**
 * Routes for the chamade_talk NC app.
 * All API routes are public (HMAC-verified at controller level).
 */

return [
    'routes' => [
        // Admin settings
        ['name' => 'settings#index',  'url' => '/settings',  'verb' => 'GET'],
        ['name' => 'settings#save',   'url' => '/settings',  'verb' => 'POST'],

        // HMAC API — called by bridge service
        ['name' => 'config#getConfig',           'url' => '/api/v1/config',                        'verb' => 'GET'],
        ['name' => 'config#getAuthorizedRooms',  'url' => '/api/v1/authorized-rooms',              'verb' => 'GET'],
        ['name' => 'config#setAuthorizedRooms',  'url' => '/api/v1/authorized-rooms',              'verb' => 'PUT'],
        ['name' => 'config#getSignalingTicket',   'url' => '/api/v1/signaling/{roomToken}',         'verb' => 'GET'],
        ['name' => 'config#joinRoom',             'url' => '/api/v1/rooms/{token}/join',             'verb' => 'POST'],
        ['name' => 'config#getRoomInfo',          'url' => '/api/v1/rooms/{token}/info',             'verb' => 'GET'],
        ['name' => 'config#createRoom',           'url' => '/api/v1/rooms',                          'verb' => 'POST'],
        ['name' => 'config#deleteRoom',           'url' => '/api/v1/rooms/{token}',                  'verb' => 'DELETE'],
        ['name' => 'config#createBot',            'url' => '/api/v1/bots',                           'verb' => 'POST'],
        ['name' => 'config#deleteBot',            'url' => '/api/v1/bots/{botId}',                   'verb' => 'DELETE'],
        ['name' => 'config#enableBotInRoom',      'url' => '/api/v1/bots/{botId}/rooms/{token}',     'verb' => 'POST'],
        // Bot user management — HMAC
        ['name' => 'botUser#create',             'url' => '/api/v1/bot-users',                      'verb' => 'POST'],
        ['name' => 'botUser#delete',             'url' => '/api/v1/bot-users/{username}',            'verb' => 'DELETE'],
        ['name' => 'botUser#updateDisplayName',  'url' => '/api/v1/bot-users/{username}/display-name', 'verb' => 'PUT'],
        ['name' => 'botUser#postMessage',        'url' => '/api/v1/bot-users/{username}/post',      'verb' => 'POST'],
        ['name' => 'botUser#uploadAvatar',       'url' => '/api/v1/bot-users/{username}/avatar',    'verb' => 'POST'],
        ['name' => 'botUser#ensureInRoom',       'url' => '/api/v1/bot-users/ensure-in-room',       'verb' => 'POST'],

        // Authorization flow — NC session + CSRF (user-facing)
        ['name' => 'authorize#show',             'url' => '/authorize',                             'verb' => 'GET'],
        ['name' => 'authorize#approve',          'url' => '/authorize',                             'verb' => 'POST'],
        // Automated authorize — HMAC (for e2e tests / provisioning)
        ['name' => 'authorize#autoApprove',      'url' => '/api/v1/authorize',                      'verb' => 'POST'],
    ],
];
