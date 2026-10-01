# AI Provider Perplexity

Perplexity AI provider for the Backdrop CMS AI module.

Adds Perplexity (https://www.perplexity.ai/) to the providers the `ai` module
can route to, using the API at `https://api.perplexity.ai`. Perplexity's Sonar
models answer with live web search, which makes them suited to questions about
current events rather than site content.

## Supported operations

| Operation | Supported | Notes |
|---|---|---|
| Chat | Yes | Streaming supported. JSON mode and JSON schema responses supported. |
| Completions | Yes | Sent as a single chat message; uses the chat endpoint. |
| Thinking | Yes | `sonar-reasoning`, `sonar-reasoning-pro`. |
| Tool calling | Emulated | Perplexity has no function calling. Tools are described in a system prompt and a JSON reply is parsed as a single tool call. Not reported as a capability. |
| Vision | No | |
| Embeddings | No | |
| Image generation | No | |
| Moderation | No | |
| Speech-to-text | No | |

## Search options

Callers can pass these in the chat `context_extra` array:

- `search_domain_filter` — an array of domains to limit (or, prefixed with
  `-`, exclude from) the web search.
- `append_citations` — when TRUE, non-streaming replies get a `### Sources:`
  list of the citation URLs Perplexity returned.

The base URL is stored in `ai_provider_perplexity.settings` (`base_url`).

## Installation

- Install this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).
- Create an authentication key with the Key module holding your Perplexity API
  key (https://www.perplexity.ai/settings/api).
- Enable and configure the provider at `admin/config/ai/settings`.

## Issues

Bugs and feature requests should be reported in the [Issue Queue](https://github.com/backdrop-contrib/ai_provider_perplexity/issues).

## Current Maintainer

[Justin Keiser](https://github.com/keiserjb)

## Credits

- Created for Backdrop CMS by [Justin Keiser](https://github.com/keiserjb).

- Developed with AI assistance.

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
