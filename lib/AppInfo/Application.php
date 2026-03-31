<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\AppInfo;

use OCA\ChamadeTalk\Listener\ChatListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * chamade_talk — Nextcloud app bootstrap.
 *
 * Registers the ChatListener to handle BotInvokeEvent from Talk.
 * Gracefully skips if Talk (spreed) is not installed.
 */
class Application extends App implements IBootstrap {

    public const APP_ID = 'chamade_talk';

    public function __construct(array $params = []) {
        parent::__construct(self::APP_ID, $params);
    }

    public function register(IRegistrationContext $context): void {
        // Talk bot events — only register if Talk is installed
        if (class_exists(\OCA\Talk\Events\BotInvokeEvent::class)) {
            $context->registerEventListener(\OCA\Talk\Events\BotInvokeEvent::class, ChatListener::class);
        }
    }

    public function boot(IBootContext $context): void {
    }
}
