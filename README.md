# AI Provider Perplexity

Perplexity AI provider for the Backdrop CMS AI module.

Adds Perplexity (https://www.perplexity.ai/) to the providers the `ai` module
can route to, through the Agent API (`POST /v1/agent`). The Agent API serves
Perplexity's own models and third-party frontier models (`provider/model` IDs)
with optional built-in web search, so answers can cite live sources.

Perplexity ended support for Sonar Chat Completions on 2026-09-27; this module
does not use it.

## Supported operations

| Operation | Supported | Notes |
|---|---|---|
| Chat | Yes | Streaming supported (`response.output_text.delta` events). JSON schema supported; JSON mode is sent as an open object schema, since the Agent API has no `json_object` format. |
| Completions | Yes | Sent as a single chat message. |
| Tool calling | Yes | Native custom functions. The Agent API has no `tool_choice`, so a required or named tool choice can't be forced. `thought_signature` is kept on tool calls so thinking models accept the replayed call. |
| Embeddings | Yes | `/v1/embeddings`; int8 vectors are decoded to floats. Models are set in the settings. |
| Decisions | Yes | Native `/v1/decisions` with a decision model from the settings; otherwise emulated through chat. |
| Vision | Yes | Image parts in user messages are sent as `input_image`. Assign vision models on the Model capabilities page. |
| Image generation | No | |
| Moderation | No | |
| Speech-to-text | No | |

Chat models come from `/v1/models`, which has no capability metadata: every
model is offered for chat and tool calling. Embedding and decision models have
no listing endpoint, so they come from the settings below. Nothing is
hardcoded.

## Settings

The provider settings at `admin/config/ai/settings` add:

- **Web search** — give every request the built-in `web_search` tool (on by
  default). Searches are billed per call.
- **Maximum steps** — search-and-reason steps per request when web search is on
  (default 3, at least 2: without a preset the API defaults to 1 step, which
  leaves no step to answer after searching).
- **Embedding models** / **Decision models** — one model ID per line.

The base URL is stored in `ai_provider_perplexity.settings` (`base_url`).

## Request options

Callers can pass these in the chat `context_extra` array:

- `web_search` — TRUE/FALSE to override the setting for one request.
- `search_domain_filter` — an array of domains to limit (or, prefixed with
  `-`, exclude from) the web search.
- `append_citations` — when TRUE, non-streaming replies get a `### Sources:`
  list of the URLs cited in the answer.

Some models reject a non-default temperature; that 400 is retried once without
it. `max_output_tokens` is always sent (4096 when the caller passes none),
because `anthropic/*` models require it.

## Installation

- Install this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).
- Create an authentication key with the Key module holding your Perplexity API
  key (https://console.perplexity.ai/project/keys).
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
