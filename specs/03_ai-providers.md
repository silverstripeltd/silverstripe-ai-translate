# AI Providers

## Shared ai-core package

The provider layer comes from `silverstripeltd/silverstripe-ai-core`, shared with the other AI modules and Content Engineer. This module no longer ships its own provider classes. It uses:

- `SilverstripeLtd\AiCore\Settings\EnvProviderSettings::forModule('TRANSLATE')` - reads the `AI_TRANSLATE_*` variables, then the shared `AI_*` variables, then the module YAML defaults
- `SilverstripeLtd\AiCore\Completion\SimpleCompletion` - one system prompt and one user message in, the model's text out
- `SilverstripeLtd\AiCore\Provider\ProviderException` - error type with transient/blocking flags

Gemini, OpenAI and Anthropic are supported by ai-core. Other providers are added through `ProviderFactory.providers` in ai-core.

## Provider call

`TranslationGenerationService` builds its own prompts (see `specs/04_prompts.md`) and calls:

```php
SimpleCompletion::create(EnvProviderSettings::forModule('TRANSLATE'))->complete($systemPrompt, $userPrompt);
```

The raw text is parsed as JSON in the service layer.

## Configuration

| Environment variable | Shared fallback | Description | Default |
|---|---|---|---|
| `AI_TRANSLATE_PROVIDER` | `AI_PROVIDER` | Active provider (`gemini`, `openai`, `anthropic`) | `gemini` |
| `AI_TRANSLATE_API_KEY` | `AI_API_KEY` | API key for the active provider | (required) |
| `AI_TRANSLATE_MODEL` | `AI_MODEL` | Model to use | `gemini-3.1-flash-lite`, `gpt-5-mini` or `claude-haiku-4-5` |
| `AI_TRANSLATE_THINKING_LEVEL` | `AI_THINKING_LEVEL` | Thinking level, sent to whichever provider is active | `low` for Gemini, unset otherwise |
| `AI_TRANSLATE_TEMPERATURE` | `AI_TEMPERATURE` | Temperature for generation | `1.0` |
| `AI_TRANSLATE_MAX_TOKENS` | `AI_MAX_TOKENS` | Max tokens in response | `2000` |
| `AI_TRANSLATE_REQUEST_TIMEOUT` | `AI_REQUEST_TIMEOUT` | Request timeout in seconds | `15` |

The defaults live in `_config/config.yml` under `SilverstripeLtd\AiCore\Settings\EnvProviderSettings.modules.TRANSLATE` and can be overridden in project YAML. The shared `AI_API_KEY` and `AI_MODEL` are ignored while `AI_TRANSLATE_PROVIDER` names a different provider than `AI_PROVIDER`.

**Note on max_tokens:** Translation responses contain one suggestion per rewrite target as structured JSON. Long pages with many fields may need `AI_TRANSLATE_MAX_TOKENS` increased.

## Error handling

- **Transient failures** (network timeout, rate limit, 5xx): `ProviderException` with `isTransient()`
- **Blocking failures** (missing or invalid API key, unknown provider): `ProviderException` with `isBlocking()`
- **Permanent failures** (other 4xx, malformed responses): `ProviderException`
- **Callers** (controller) catch the exception and show an error toast in the modal
