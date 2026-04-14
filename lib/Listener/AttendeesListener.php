<?php

declare(strict_types=1);

namespace OCA\ChamadeTalk\Listener;

use OCA\ChamadeTalk\AppInfo\Application;
use OCA\ChamadeTalk\Service\BackendWebhookClient;
use OCA\ChamadeTalk\Service\BotService;
use OCA\ChamadeTalk\Service\TalkApiService;
use OCA\Talk\Events\AttendeesAddedEvent;
use OCA\Talk\Events\AttendeesRemovedEvent;
use OCA\Talk\Model\Attendee;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Forwards attendee-added and attendee-removed events to the Chamade
 * backend when a bot user is the affected attendee (so Chamade can
 * keep its room→bot mapping fresh without polling), AND auto-enables
 * the addon's Talk bot in any newly-joined room.
 *
 * The auto-enable is critical: a freshly-authorized bot user creates
 * its own 1:1 DM with the owner via `_send_nctalk_welcome` in Chamade
 * Python. The Talk bot (registered globally by InstallStep) does NOT
 * automatically appear in the new room — spreed requires an explicit
 * per-room enable via `POST /api/v1/bot/{token}/{botId}`. Without this,
 * `BotInvokeEvent` never fires for owner messages and the chat relay
 * is silently dead. Before 2.2.4, the only workaround was a manual
 * `occ talk:bot:setup` per room. Now we do it automatically using the
 * bot user's own credentials (they are the room's moderator since
 * they opened the DM).
 *
 * @template-implements IEventListener<Event>
 */
class AttendeesListener implements IEventListener {

    public function __construct(
        private IConfig $config,
        private BackendWebhookClient $webhook,
        private BotService $botService,
        private TalkApiService $talkApi,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if ($event instanceof AttendeesAddedEvent) {
            $this->dispatch($event->getRoom(), $event->getAttendees(), 'attendee_added', '/api/nctalk/attendee-added');
            $this->autoEnableBotForNewAttendees($event->getRoom(), $event->getAttendees());
            return;
        }
        if ($event instanceof AttendeesRemovedEvent) {
            $this->dispatch($event->getRoom(), $event->getAttendees(), 'attendee_removed', '/api/nctalk/attendee-removed');
            return;
        }
    }

    /**
     * @param Attendee[] $attendees
     */
    private function dispatch(\OCA\Talk\Room $room, array $attendees, string $type, string $path): void {
        $botUsers = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_users', '[]'),
            true
        ) ?: [];

        if (empty($botUsers)) {
            return;
        }

        foreach ($attendees as $attendee) {
            if ($attendee->getActorType() !== Attendee::ACTOR_USERS) {
                continue;
            }
            $actorId = $attendee->getActorId();
            if (!in_array($actorId, $botUsers, true)) {
                continue;
            }

            $this->webhook->post($path, [
                'type' => $type,
                'room_token' => $room->getToken(),
                'room_type' => $room->getType(),
                'room_name' => $room->getName(),
                'bot_user_id' => $actorId,
            ]);
        }
    }

    /**
     * When one of our bot users is added to a room, make sure the
     * Chamade Talk bot is enabled in that room so `BotInvokeEvent` can
     * fire on subsequent messages. Idempotent — spreed's enable call is
     * a no-op if the bot is already active in the room.
     *
     * @param Attendee[] $attendees
     */
    private function autoEnableBotForNewAttendees(\OCA\Talk\Room $room, array $attendees): void {
        $botUsers = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_users', '[]'),
            true
        ) ?: [];
        if (empty($botUsers)) {
            return;
        }
        $botPasswords = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'bot_passwords', '{}'),
            true
        ) ?: [];

        $botId = $this->botService->getDefaultBotId();
        if ($botId <= 0) {
            $this->logger->debug('autoEnableBot: no default_bot_id, cannot enable bot in room', [
                'app' => Application::APP_ID,
            ]);
            return;
        }

        $roomToken = $room->getToken();
        foreach ($attendees as $attendee) {
            if ($attendee->getActorType() !== Attendee::ACTOR_USERS) {
                continue;
            }
            $actorId = $attendee->getActorId();
            if (!in_array($actorId, $botUsers, true)) {
                continue;
            }
            $password = $botPasswords[$actorId] ?? '';
            if ($password === '') {
                $this->logger->debug("autoEnableBot: no password for bot user {$actorId}", [
                    'app' => Application::APP_ID,
                ]);
                continue;
            }
            $ok = $this->talkApi->enableBotInRoom($botId, $roomToken, $actorId, $password);
            if ($ok) {
                $this->logger->info("Auto-enabled Talk bot {$botId} in room {$roomToken} via {$actorId}", [
                    'app' => Application::APP_ID,
                ]);
            } else {
                $this->logger->warning("Failed to auto-enable Talk bot {$botId} in room {$roomToken} via {$actorId}", [
                    'app' => Application::APP_ID,
                ]);
            }
            // One bot user is enough — the bot is now enabled for the whole room.
            return;
        }
    }
}
