<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Listener;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\ChamadeTalk\Service\BackendWebhookClient;
use OCA\Talk\Events\CallEndedEvent;
use OCA\Talk\Events\CallStartedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;

/**
 * Forwards call lifecycle events to Chamade.
 *
 * CallStartedEvent fires when the FIRST participant joins a call in a
 * room (transition 0 -> 1 in-call). CallEndedEvent fires when the LAST
 * participant leaves (1 -> 0). These are ARoomModifiedEvent subclasses
 * that don't carry the participant list, so we cannot cheaply filter
 * per-bot here: we POST unconditionally when bot_users is non-empty
 * and let Chamade filter against its own bot registry. Chamade
 * already knows which bots it manages, so this is correct and cheap.
 *
 * @template-implements IEventListener<Event>
 */
class CallStateListener implements IEventListener {

    public function __construct(
        private IConfig $config,
        private BackendWebhookClient $webhook,
    ) {
    }

    public function handle(Event $event): void {
        if ($event instanceof CallStartedEvent) {
            $this->dispatch($event->getRoom(), 'call_started', '/api/nctalk/call-started', $event->getActor());
            return;
        }
        if ($event instanceof CallEndedEvent) {
            $this->dispatch($event->getRoom(), 'call_ended', '/api/nctalk/call-ended', $event->getActor());
            return;
        }
    }

    private function dispatch(
        \OCA\Talk\Room $room,
        string $type,
        string $path,
        ?\OCA\Talk\Participant $actor,
    ): void {
        $botUsers = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_users', '[]'),
            true
        ) ?: [];

        if (empty($botUsers)) {
            return;
        }

        $actorId = '';
        if ($actor !== null) {
            try {
                $actorId = $actor->getAttendee()->getActorId();
            } catch (\Throwable) {
                $actorId = '';
            }
        }

        $this->webhook->post($path, [
            'type' => $type,
            'room_token' => $room->getToken(),
            'room_type' => $room->getType(),
            'room_name' => $room->getName(),
            'actor_id' => $actorId,
        ]);
    }
}
