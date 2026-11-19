# AI Provider Perplexity

Connect Backdrop’s AI features to the Perplexity Agent API. The API can search the web as it answers questions. Use it for chat with live sources, tool calling, search embeddings, or structured decisions.

## Requirements

Backdrop CMS 1.x, PHP 8.2 or later, and the AI module, plus the Key module.

## Installation

1. Install and enable this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).
2. Open **AI settings** at `admin/config/ai/settings` and enable the provider. Select the service credential you saved with the Key module.
3. Review **Web search** and **Maximum steps**. To use embeddings or native decisions, enter the model IDs one per line in the corresponding settings.

Read the [module wiki](https://github.com/backdrop-contrib/ai_provider_perplexity/wiki) for setup details, supported operations, and troubleshooting.

## Issues

Bugs and feature requests should be reported in the [Issue Queue](https://github.com/backdrop-contrib/ai_provider_perplexity/issues).

## Current Maintainer

[Justin Keiser](https://github.com/keiserjb)

## Credits

- Created for Backdrop CMS by [Justin Keiser](https://github.com/keiserjb).

- Developed with AI assistance.

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
