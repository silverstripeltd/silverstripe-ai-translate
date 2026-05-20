# AI Providers

## Copied from ai-metadata

The AI provider classes are copied from `SilverstripeLtd/silverstripe-ai-metadata` into this module. No dependency between the two modules. The copied classes are:

- `AbstractAIProvider` — base class with shared HTTP/error handling
- `GeminiProvider` — Gemini `generateContent` endpoint
- `OpenAIProvider` — OpenAI Chat Completions API
- `AnthropicProvider` — Anthropic Messages API
- `ProviderFactory` — instantiates the configured provider
- `AIProviderException` — error type with transient/blocking flags

These are standalone classes with no dependencies beyond Guzzle (bundled with Silverstripe framework).

If a shared provider package is extracted in the future, both modules can switch to it. Until then, duplication is fine.

## Provider interface

The copied providers are stripped down to a single generic method:

```php
public function generate(string $systemPrompt, string $userPrompt): string
```

Returns the raw string response from the AI provider. The translation module constructs its own prompts (see `specs/04_prompts.md`) and parses the response as JSON in the service layer.

The `generateMetadata()` method and `AiMetadataResult` value object from ai-metadata are not copied — they are specific to the metadata use case. Only the HTTP request infrastructure (`performRequest`, `extractResponseContent`, `isTransientStatus`, `getDefaultModel`) and error handling are retained.

## Configuration

Same environment variables as ai-metadata:

| Environment variable | Description | Default |
|---|---|---|
| `AI_TRANSLATE_PROVIDER` | Active provider (`gemini`, `openai`, `anthropic`) | `gemini` |
| `AI_TRANSLATE_API_KEY` | API key for the active provider | (required) |
| `AI_TRANSLATE_MODEL` | Model to use | Provider-specific default |
| `AI_TRANSLATE_THINKING_LEVEL` | Thinking level for Gemini | `low` |
| `AI_TRANSLATE_TEMPERATURE` | Temperature for generation | `1.0` |
| `AI_TRANSLATE_MAX_TOKENS` | Max tokens in response | `2000` |
| `AI_TRANSLATE_REQUEST_TIMEOUT` | Request timeout in seconds | `15` |

**Note on max_tokens:** Translation responses contain one suggestion per rewrite target as structured JSON. Long pages with many fields may need `AI_TRANSLATE_MAX_TOKENS` increased. This is the existing env var - no new configuration needed.

## Error handling

Same pattern as ai-metadata:

- **Transient failures** (network timeout, rate limit, 5xx): `AIProviderException`
- **Permanent failures** (invalid API key, 4xx): `AIProviderException`
- **Callers** (controller) catch the exception and show an error toast in the modal
