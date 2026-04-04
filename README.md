# Chamade for Nextcloud Talk

[Chamade](https://chamade.io) is a gateway that connects AI agents to meetings and messaging platforms. This app lets you chat and talk with your AI agent directly from Nextcloud Talk.

![Screenshot](img/screenshot.png)

## Features

- **Chat & voice**: agents can read and reply to messages, and join voice calls (voice requires [High Performance Backend](https://nextcloud.com/talk/#scalability))
- **Owner-scoped bots**: each bot is tied to the Nextcloud user who authorized it
- **Room authorization**: bots only respond in DMs from their owner, or in group rooms where `/activate` was used
- **Text-only fallback**: works without HPB — chat only, no voice
- **HMAC-secured**: all communication between Chamade and this app is authenticated

## Requirements

- Nextcloud 28 or later
- PHP 8.1 or later
- A [Chamade](https://chamade.io) account

## Installation

### From the Nextcloud App Store

Search for **Chamade** in your Nextcloud app store and install it.

### Manual

1. Download the [latest release](https://codeberg.org/skilpa/chamade-nctalk/releases)
2. Extract to your Nextcloud `apps/` directory as `chamade_talk/`
3. Enable the app: `occ app:enable chamade_talk`

## Setup

1. Install this app on your Nextcloud instance
2. Go to **Administration Settings > Talk** and configure the Chamade backend URL and API key
3. In your [Chamade dashboard](https://chamade.io/dashboard), click **Connect Nextcloud Talk**
4. Approve the authorization request on your Nextcloud instance
5. Start chatting with your AI agent in Talk

## Commands

| Command | Where | Effect |
|---------|-------|--------|
| `/activate` | Group room | Authorize the bot to respond in this room |
| `/deactivate` | Group room | Revoke the bot's access to this room |

In DMs, the bot responds automatically to its owner — no activation needed.

## Documentation

Full documentation: [chamade.io/docs/nctalk](https://chamade.io/docs/nctalk)

## License

AGPL-3.0-or-later — see [LICENSE](LICENSE).
