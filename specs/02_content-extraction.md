# Content Extraction

## Goal

Extract structured per-field rewrite targets from a page for translation. The extraction service supports dual-locale operation: source-locale targets provide prompt input, and target-locale targets provide current Draft values for diff generation and apply validation.

## Dual-locale extraction model

Translation requires content from two locales:

1. **Source locale** (default Fluent locale) - the content to translate. Used as AI prompt input.
2. **Target locale** (editor's active non-default locale) - the current Draft values in the target language. Used for diff previews and apply writes.

The extraction service switches Fluent state for each locale independently using `FluentState::singleton()->withState()`. Both extractions read Draft-stage content for CMS editing workflows.

The default locale is determined via Fluent's `Locale::getDefault()`.

## Extracted content value object

The extraction service returns a value object containing:

- `sourceContent` - flat text payload from the source locale (used to check for empty content)
- `sourceRewriteTargets` - structured per-field targets from the source locale
- `targetRewriteTargets` - structured per-field targets from the target locale (for diff generation)

## Structured rewrite target pipeline

The service builds a structured target list for each locale:

1. If the page has a non-empty `Title`, add a `page:title` target mapped to the page `Title` field.
2. If the page exposes `getElementalRelations()`, iterate each ElementalArea relation and collect supported text and HTML DB fields from each Elemental block.
3. Preserve the `element:{ID}:html` key for `ElementContent.HTML`. For other supported block fields, add `element:{ID}:field:{lowercaseFieldName}` targets.
4. Use `element_html` for HTML-capable block fields and `element_text` for plain text block fields.
5. If no supported element targets were found and the page has a `Content` field, add a `page:content` target mapped to the page `Content` field.
6. Run the `updateExtractedRewriteTargets` extension hook so project code can add or amend targets before prompt generation.

### Stable target keys

Target keys follow a consistent naming pattern:

- `page:title` - the page Title field
- `page:content` - the page Content field (non-Elemental pages only)
- `element:{ID}:html` - an ElementContent block's HTML field
- `element:{ID}:field:{lowercaseFieldName}` - other supported fields on Elemental blocks

These keys are stable across source and target locale extraction because they reference the same structural position in the page. The source and target locale extractions produce matching key sets.

### Rewrite target metadata

Each rewrite target carries:

- `targetKey` - stable server-known identifier
- `targetType` - one of `page_title`, `page_content`, `element_html`, or `element_text`
- `fieldName` - ORM field name to write back to
- `targetId` - page or element ID
- `fieldLabel` - human-readable label for the field (e.g. "Page name", "HTML")
- `targetTitle` - block title or empty string for page-level targets
- `content` - the raw field value for this target in the current locale context
- `contentFormat` - `text` or `html`

For source-locale targets, `content` holds the raw field value to translate. For target-locale targets, `content` holds the current Draft value in the target language, used for diff generation.

### Content normalisation

HTML targets carry their raw HTML in `content` so the AI can preserve structural markup (headings, lists, tables) during translation. This differs from writing style and tone rules refinement, which normalises HTML to plain text before prompting because the model is rewriting for style rather than preserving structure across languages.

Plain text fields have whitespace normalised (collapsing runs of whitespace to single spaces, trimming).

## Flat source content payload

In addition to structured targets, the service builds a flat text payload from the source locale to check for empty content:

1. Read the page `Title` and prepend it when non-empty.
2. Build the primary body text:
   - If the page exposes `getElementsForSearch()`, use that flattened Elemental search text.
   - If `getElementsForSearch()` throws `MissingTemplateException`, fall back to `getContentFromElementsForCmsSearch()` and normalise its delimiters to plain whitespace.
   - Otherwise, if the page has a `Content` field, strip HTML with `Convert::html2raw()`.
3. Join the title and body with blank lines.
4. Run the `updateExtractedContent` extension hook so project code can append more flat text.

This flat payload is used only for the empty-content check. The structured rewrite targets are what the AI actually receives.

## Non-elemental pages

Non-elemental pages are handled throughout:

- The flat payload uses the page `Title` plus stripped `Content`.
- The rewrite target list includes `page:title` when present.
- If there are no supported Elemental block field targets, the page `Content` field becomes the `page:content` rewrite target.

## Content length

No truncation. Modern AI models handle large context windows. If content exceeds the model's context window, the API returns an error handled as a provider exception.

## Versioned awareness

Content extraction wraps reads in `Versioned::withVersionedMode()`, reading Draft content. Both the source-locale and target-locale extractions read from Draft stage, because the editor is working with saved Draft content in the CMS.
