# Generation Behaviour

## Two operations

The module supports two distinct operations:

1. **Generate** - extract source-locale content, call the AI provider, validate structured suggestions, return to the modal
2. **Apply** - write selected suggestions to target-locale Draft content

Neither operation persists results to the database. Generation returns suggestions to the client, where they are cached on the Entwine instance. Apply writes directly to Draft records.

## Generate pipeline

1. **Determine locales** - source locale is Fluent's default locale. Target locale is the editor's currently active Fluent locale.
2. **Extract content** - extract structured rewrite targets from both source and target locales, plus flat source content, using the pipeline in `specs/02_content-extraction.md`.
3. **Check content threshold** - if extracted source content is empty, return an error message to the modal.
4. **Build prompts** - construct system and user prompts per `specs/04_prompts.md`, including the serialised source-locale and current target-locale rewrite target content plus language names.
5. **Call AI provider** - single API call. The provider first decides whether the current target-locale content already matches the locale, then either returns a locked no-op result or per-target translation suggestions.
6. **Validate provider output** - require the `translationRequired` flag, reject any no-op response that still includes suggestions, and when translation is required check that every server-known rewrite target was returned exactly once. Reject missing, unexpected, or duplicate targets.
7. **Build response** - if the page already matches the locale, return `alreadyMatchesLocale: true` with no suggestions. Otherwise resolve the validated suggestions against the current target-locale Draft rewrite targets to generate `diffHtml` and attach full metadata for the modal.
8. **Return to modal** - the structured result payload is returned to the frontend and cached on the Entwine instance.

## Apply pipeline

1. **Capture source-locale draft values** - before switching to the target locale, snapshot the default-locale Draft content for every rewrite target keyed by `targetKey`. This snapshot is used to restore source-locale values after writing (see step 7).
2. **Re-extract target-locale targets** - rebuild the current target-locale Draft rewrite targets and index them by `targetKey`.
3. **Validate selected suggestions** - for each opted-in suggestion from the request:
   - the `targetKey` must exist in the current target-locale rewrite target list
   - any supplied `targetType`, `fieldName`, and `targetId` must still match the server-known target metadata
4. **Apply page field suggestions** - write matching page-level suggestions (e.g. `page:title`, `page:content`) directly to the Draft page record in the target locale. Write the page once if any page fields changed.
5. **Apply element suggestions** - for Elemental block suggestions, load the block record by ID and verify its `ParentID` belongs to one of the target page's ElementalAreas. Write each block independently.
6. **Skip invalid suggestions** - skip suggestions that are invalid, reference deleted targets, are duplicated in the request, reference foreign Elemental blocks, or have mismatched metadata. Log the skip reason and continue processing the rest.
7. **Restore source-locale draft values** - after writing target-locale suggestions, switch back to the default locale and re-write the captured source-locale values for every target that was applied. This prevents Fluent from propagating the target-locale write back to the source-locale Draft, which can happen when fields share underlying database columns across locales.
8. **Return counts** - return `appliedCount`, `skippedCount`, and `reloadRequired` so the modal can show appropriate messaging and trigger a CMS reload.

All apply writes target the Draft stage in the target locale. The module never publishes content.

## Regeneration

Clicking Generate again discards the previous cached result and replaces it with the new response. The Entwine cache holds only the latest result. If that result says the page already matches the locale, regeneration is disabled until the cached result is cleared by a draft change or the modal session ends.

## Permissions

Deferred to the parent DataObject - the editor must have `canEdit()` on the page. Same pattern as ai-metadata.

## Error handling

- **Empty content:** Error message displayed in the modal. No API call made.
- **Provider failure:** `AIProviderException` caught by the controller, error toast shown in the modal. Any previously cached result remains displayed.
- **Malformed response:** `AIProviderException` thrown if the JSON is invalid or suggestions do not match the expected target list. Error toast shown.
- **Already translated:** The modal shows "This page content already matches the target locale.", hides apply, and disables regeneration so the current target-locale Draft content cannot be overwritten by a no-op result.

## Concurrency

If two editors translate the same page to the same locale simultaneously, each gets their own cached result on their Entwine instance. Apply writes are independent Draft writes - last write wins, same as normal CMS editing.
