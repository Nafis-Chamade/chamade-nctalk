<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\AppInfo;

use OCA\ChamadeTalk\Listener\AttendeesListener;
use OCA\ChamadeTalk\Listener\CallStateListener;
use OCA\ChamadeTalk\Listener\ChatListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * {brand_id}_talk — Nextcloud app bootstrap.
 *
 * Registers event listeners that push Talk activity to Chamade:
 * - ChatListener: BotInvokeEvent (messages addressed to bot)
 * - AttendeesListener: bot added/removed from a room
 * - CallStateListener: call started/ended in a room
 *
 * All Talk event listeners are gated behind a single class_exists guard
 * on BotInvokeEvent — it's the newest Talk event we use (NC 31+), and
 * all other events we need exist in the same range or earlier.
 * Gracefully skips if Talk (spreed) is not installed.
 */
class Application extends App implements IBootstrap {

    public const APP_ID = 'chamade_talk'; // Replaced with {brand_id}_talk on deploy

    /**
     * Used by ChatListener to detect an incoming event-dispatch probe
     * (posted by the backend into a dedicated solo-bot room). The bracketed
     * brand placeholder is filled in at match time. Any change to the nonce
     * character set, length bounds, or surrounding literal must be mirrored
     * on the Python side in the backend or probe round-trips break silently.
     * probe round-trips.
     */
    public const PROBE_MARKER_REGEX_TEMPLATE = '/^__%s_probe:([A-Za-z0-9_-]{8,64})__$/';

    public function __construct(array $params = []) {
        parent::__construct(self::APP_ID, $params);
    }

    public function register(IRegistrationContext $context): void {
        // Talk events — registered unconditionally. We pass FQCN strings, not
        // class references, so no autoload happens at registration time. If
        // Talk (spreed) is not installed at runtime, the events simply never
        // fire and our listeners are never instantiated. A class_exists guard
        // here would be a bug: at bootstrap time the spreed app's autoloader
        // is not yet registered, so class_exists returns false even when
        // Talk is installed, silently breaking all listener registrations.
        $context->registerEventListener('OCA\Talk\Events\BotInvokeEvent', ChatListener::class);
        $context->registerEventListener('OCA\Talk\Events\AttendeesAddedEvent', AttendeesListener::class);
        $context->registerEventListener('OCA\Talk\Events\AttendeesRemovedEvent', AttendeesListener::class);
        $context->registerEventListener('OCA\Talk\Events\CallStartedEvent', CallStateListener::class);
        $context->registerEventListener('OCA\Talk\Events\CallEndedEvent', CallStateListener::class);
    }

    public function boot(IBootContext $context): void {
    }
}
