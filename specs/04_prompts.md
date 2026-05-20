# Prompts

## Approach

A single AI call translates all page content at once. The prompt sends structured rewrite targets from the source locale alongside the current target-locale Draft content so the AI can detect existing target-language copy and avoid rewriting it.

Prompt templates live in the module-root `prompts/` directory and are loaded by `PromptService`.

## Prompt structure

### System prompt

Short role statement:

```
You are a professional translator. You translate web page content between languages.
Preserve the original meaning, tone, and structure. Return only valid JSON, no markdown fences or commentary.
```

### User prompt

Contains:

1. Source and target language names
2. Early language-detection and already-translated decision steps
3. Content format rules for text and HTML targets
4. The serialised rewrite target list with both source-locale and current target-locale content
5. Output format specification

Key instructions:

- Translate from `{sourceLanguage}` to `{targetLanguage}`
- Inspect `currentTargetContent` first and detect which languages are present
- If every non-empty `currentTargetContent` already matches `{targetLanguage}`, return `"translationRequired": false` and an empty `suggestions` array
- If translation is required, return one suggestion object for each rewrite target, in the same order as the supplied target list
- Each suggestion object must include the exact `targetKey` and `targetType` from the matching rewrite target, plus `suggestedContent`
- Preserve meaning and information - do not add new content or remove existing content
- Use `sourceLocaleContent` as the authoritative meaning reference
- Preserve any existing target-language wording from `currentTargetContent` and do not rephrase it
- If `currentTargetContent` mixes languages, translate only the non-target-language parts and keep the target-language parts unchanged
- For `page_title` and `element_text` targets (contentFormat `text`), return plain text only with no HTML tags
- For `page_content` and `element_html` targets (contentFormat `html`), return clean HTML suitable for writing directly to the mapped Silverstripe field
- Content delimiters: `=== REWRITE_TARGETS_START/END ===` to separate prompt instructions from content
- "Return only the JSON object" reinforced at the end

### Serialised rewrite targets

The user prompt includes the source-locale rewrite targets as a JSON array:

```json
[
  {
    "targetKey": "page:title",
    "targetType": "page_title",
    "contentFormat": "text",
    "sourceLocaleContent": "About us",
    "currentTargetContent": "Uber uns"
  },
  {
    "targetKey": "element:42:html",
    "targetType": "element_html",
    "contentFormat": "html",
    "sourceLocaleContent": "<p>Welcome to our website. We provide building consent services for residential and commercial projects.</p>",
    "currentTargetContent": "<p>Welcome to our website. Wir bieten building consent services fur Wohn- und Gewerbeprojekte.</p>"
  }
]
```

HTML targets include raw HTML so the model can preserve structural markup (headings, lists, tables) in the translation. Plain text targets include normalised plain text. `currentTargetContent` can be blank, partially translated, or already fully localised.

### Expected JSON output

```json
{
  "translationRequired": true,
  "suggestions": [
    {
      "targetKey": "page:title",
      "targetType": "page_title",
      "suggestedContent": "Ko matou"
    },
    {
      "targetKey": "element:42:html",
      "targetType": "element_html",
      "suggestedContent": "<p>Nau mai haere mai ki to matou whaarangi. Ka whakarato matou i nga ratonga whakaaetanga hanga mo nga kaupapa noho me nga kaupapa arumoni.</p>"
    }
  ]
}
```

Already-translated response:

```json
{
  "translationRequired": false,
  "suggestions": []
}
```

## Language names

Source and target language names are derived from Fluent's `Locale` records. Fluent stores a `Title` field on each locale (e.g. "English (New Zealand)", "Te Reo Maori"). The prompt uses these human-readable names rather than locale codes.

If the locale title is not descriptive enough (e.g. just "English"), the locale code is appended: "English (en_NZ)".

## Output parsing

The provider response is parsed as JSON. The parser requires:

- A boolean `translationRequired` field
- A `suggestions` array
- When `translationRequired` is `true`, every suggestion must include a non-empty `targetKey`, a valid `targetType`, and non-empty `suggestedContent`
- When `translationRequired` is `false`, the `suggestions` array must be empty

When `translationRequired` is `true`, the generation service then validates that every server-known rewrite target was returned exactly once and rejects missing, unexpected, or duplicate targets.

`diffHtml` is always server-generated from the current target-locale Draft content and the suggested translation. It never comes from the model.

## Extension hook

```php
$this->extend('updateTranslationPrompts', $systemPrompt, $userPrompt, $sourceLocale, $targetLocale);
```

Allows projects to:
- Add site-specific translation context (e.g. "Use formal register", "This is a government website")
- Add glossary terms or preferred translations for domain-specific terminology

Projects must not change the output format. The JSON response schema is fixed and the parser rejects any response that does not match the expected structure.

## Empty or thin content

If the extracted source content is an empty string, the AI is not called. The modal shows an error message: "This page has no content to translate."
