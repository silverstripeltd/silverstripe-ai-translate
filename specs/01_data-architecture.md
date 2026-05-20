# Data Architecture

## Dependencies

- `tractorcow/silverstripe-fluent` - composer requirement. The module will not install without Fluent.

## No persisted DataObject

Translation results are cached on the Entwine instance for the editing session only, not persisted to the database. This matches the writing style and tone rules on-demand pattern.

Rationale: the selective-apply workflow is a single-session interaction. The editor generates, reviews, applies selected suggestions, and is done. Once suggestions are applied, the target-locale Draft content changes and the suggestions become stale. Persisting results across sessions adds complexity (stale detection, hash management, stored suggestion loading) for little practical benefit.

The existing `GeneratedTranslation` DataObject and `AiTranslation` table from the previous architecture should be removed. The migration path is to drop the table on deployment.

## Extension on SiteTree

An Extension is applied to `SiteTree` that:

- Adds the "Translate" button to the CMS edit form (only shown when the current Fluent locale is not the default locale)
- Seeds the Entwine adapter with record context (page ID, FQCN) for the React modal

## Entwine cache shape

The Entwine adapter caches the last translation result so it survives modal close and reopen within the same editing session:

```json
{
  "alreadyMatchesLocale": false,
  "suggestions": [
    {
      "targetKey": "page:title",
      "targetType": "page_title",
      "targetId": 123,
      "fieldName": "Title",
      "fieldLabel": "Page name",
      "targetTitle": "",
      "sourceLocaleContent": "About us",
      "currentTargetContent": "He korero mo matou",
      "suggestedContent": "Ko matou",
      "contentFormat": "text",
      "diffHtml": "<del>He korero mo matou</del><ins>Ko matou</ins>"
    }
  ]
}
```

When the page is already fully written in the target language, the cached result becomes:

```json
{
  "alreadyMatchesLocale": true,
  "suggestions": []
}
```

### Cache lifecycle

- Results are cached when the translate endpoint returns successfully.
- The cached result is **flushed when the page edit form becomes dirty**, and discarded on the next open if a save or publish changed the Draft content before the browser reloaded.
- Results are **lost** when the editor navigates to a different page (Entwine reinitialises) or when the CMS reloads after apply.
- The cache does not survive browser refresh or page navigation.
