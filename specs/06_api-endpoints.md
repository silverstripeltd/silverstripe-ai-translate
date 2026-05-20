# API Endpoints

## Controller

- Class: `AiTranslateController`
- Registered as an admin route (standard Silverstripe admin controller pattern)

## Endpoints

### GET `/admin/ai-translate/schema/{ID}?fqcn={FQCN}`

Fetch the FormSchema payload for the modal.

- **FQCN:** Fully qualified class name of the parent DataObject (URL-encoded). Validated - only classes with the translation extension applied are allowed.
- **ID:** DataObject ID
- **Auth:** CMS session (standard admin controller)
- **Behaviour:**
  1. Validate the page exists and user has `canEdit()` permission.
  2. Return the FormSchema JSON describing the modal layout plus schema meta.
- **Schema meta:**
  - `translateUrl` - URL for the translate endpoint
  - `applyUrl` - URL for the apply endpoint
  - `supportsApply` - whether apply is available
  - `labels` - UI labels for the modal
  - `messages` - status and warning messages
- **Response:** Standard Silverstripe FormSchema response
- **Error:** 400 if request parameters invalid, 403 if user cannot edit, 404 if record not found

The Entwine adapter fetches this schema when mounting the React component. The schema defines the modal metadata server-side so the React component remains a thin renderer.

### POST `/admin/ai-translate/translate/{ID}`

Trigger translation and return structured suggestions.

- **FQCN:** Passed via request params, validated as above
- **ID:** DataObject ID
- **Auth:** CMS session + CSRF token
- **Behaviour:** Extracts source and target locale content, calls AI provider, validates output, builds response with diff previews
- **Response:**
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
      },
      {
        "targetKey": "element:42:html",
        "targetType": "element_html",
        "targetId": 42,
        "fieldName": "HTML",
        "fieldLabel": "HTML",
        "targetTitle": "Content",
        "sourceLocaleContent": "<p>Welcome to our website.</p>",
        "currentTargetContent": "",
        "suggestedContent": "<p>Nau mai haere mai ki to matou whaarangi.</p>",
        "contentFormat": "html",
        "diffHtml": "<ins><p>Nau mai haere mai ki to matou whaarangi.</p></ins>"
      }
    ]
  }
  ```
- **Error:** Provider errors returned as error response for toast display

Each suggestion includes both `sourceLocaleContent` (from the default locale, for editor context) and `currentTargetContent` (from the target locale Draft, used for diff). The `diffHtml` field compares `currentTargetContent` against `suggestedContent`.

Already-translated response:

```json
{
  "alreadyMatchesLocale": true,
  "suggestions": []
}
```

When `alreadyMatchesLocale` is `true`, the modal must not show apply controls and must disable further generate requests for that cached result.

### POST `/admin/ai-translate/apply/{ID}`

Apply selected translation suggestions to target-locale Draft content.

- **FQCN:** Passed via request params, validated as above
- **ID:** DataObject ID
- **Auth:** CMS session + CSRF token
- **Request body:** JSON payload containing a `suggestions` array. The modal sends only the selected suggestions. Each entry must have a truthy `apply` flag to be processed - entries without it are ignored as a server-side safety check.
- **Behaviour:**
  1. Validate the page exists and user has `canEdit()` permission.
  2. Parse the payload and require `suggestions` to be an array.
  3. Re-extract the page's target-locale **Draft** rewrite targets and index them by `targetKey`.
  4. Ignore any suggestion that is not explicitly opted in via a truthy `apply` flag.
  5. For each opted-in suggestion, validate:
     - the payload entry is an object
     - `targetKey` is present and unique in the request
     - `suggestedContent` is a string
     - the target exists in the current Draft rewrite target list
     - any supplied `targetType`, `fieldName`, and `targetId` still match the server-known target metadata
  6. Apply page field suggestions directly to the Draft page record in the target Fluent locale and write it once if any page fields changed.
  7. Apply `element_html` and `element_text` suggestions only after loading the Elemental block record by ID and verifying its `ParentID` belongs to one of the target page's ElementalAreas.
  8. Skip invalid, deleted, duplicated, foreign, or mismatched suggestions, log the skip reason, and continue processing the rest.
  9. Return counts so the modal can show full-success or partial-success messaging and decide whether to reload.
- **Response:**
  ```json
  {
    "appliedCount": 2,
    "skippedCount": 1,
    "reloadRequired": true
  }
  ```
- **Error responses:**
  - 400 - invalid request parameters or missing `suggestions` payload
  - 403 - user cannot edit the page or CSRF token invalid
  - 404 - page not found

Applying suggestions writes to Draft records only. It never publishes content.

### Apply suggestion sanitisation

AI-generated suggestions are sanitised before being written to Draft fields, replicating the server-side protections of a normal CMS save:

- **HTML fields** (`DBHTMLText`, `DBHTMLVarchar`) - the suggestion is run through Silverstripe's `HTMLEditorSanitiser` (using the active `HTMLEditorConfig` allowlist) followed by the framework's `XssSanitiser` with default settings. This strips dangerous elements (`script`, `embed`, `object`, `style`, `svg`), event handler attributes (`on*`), and dangerous URL schemes (`javascript:`, `data:text/html`, `vbscript:`).
- **Plain text fields** - all HTML tags are stripped entirely via `strip_tags()`.

## Diff HTML sanitisation

The `diffHtml` field in translate responses is a read-only diff preview generated using `SilverStripe\View\Parsers\HtmlDiff`, which is built into Silverstripe framework. It is aggressively sanitised in two stages to prevent XSS and keep rendering predictable:

1. **Pre-diff flattening** - before the source content enters `HtmlDiff::compareHtml()`, it is flattened to plain `<p>` tags. All other elements are unwrapped (their text content is kept, the tag is removed) and all attributes on `<p>` tags are stripped. This prevents any original markup - including stray `<del>` or `<ins>` tags that could be confused with diff markers - from reaching the diff library.

2. **Post-diff sanitisation** - the diff library output is processed through:
   - Silverstripe's `XssSanitiser` with inner-HTML removal for dangerous elements
   - An element allowlist limited to `<p>`, `<del>`, and `<ins>` only. All other elements are unwrapped.
   - Attribute stripping on all remaining elements - no attributes are ever returned.

The result is that `diffHtml` only ever contains `<p>`, `<del>`, and `<ins>` tags with no attributes.

## FQCN validation

Same as ai-metadata:

1. Must be a valid, existing class
2. Must have the translation extension applied
3. Current user must have `canEdit()` on the specific record

## Locale context

The target locale is read from the current Fluent state (i.e. whichever locale the editor has selected in the CMS locale switcher). It is not passed as a request parameter - Fluent's state management handles this via the existing CMS session.

The source locale is always Fluent's default locale, determined server-side.

### Default locale guard

All three endpoints (`schema`, `translate`, and `apply`) reject requests when the active Fluent locale is the default locale or when the record is not already localised in the active locale, returning a 400 error. There is nothing to translate when viewing the source language, and unlocalised records have no target-locale Draft to write to. The UI hides the button on the default locale and for unlocalised records, but the server-side guard protects against direct requests that bypass the UI.

## CSRF protection

The `translate` and `apply` endpoints require a valid CSRF token, which is standard for Silverstripe admin controller POST requests. The React component includes the token in the XHR request header.

## FormSchema

The modal uses Silverstripe's FormSchema mechanism to define its layout server-side. This keeps the React component thin, and the returned schema meta carries the action URLs, labels, and messaging for the review and apply flow.

This module intentionally uses FormSchema only for schema meta, not as a full record-editing form. The modal evaluates content and applies selected suggestions back to Draft records owned by other modules, so the real work happens through the JSON `translate` and `apply` controller endpoints rather than FormSchema submit actions.

## No GET endpoint for previous results

There is no GET endpoint to fetch previous results. Translation results are cached on the Entwine instance rather than persisted to DB. The modal does not need to load stored data from the server on open, and the apply flow always revalidates against fresh Draft rewrite targets on the server before writing.

## Error response format

```json
{
  "error": "Human-readable error message"
}
```
