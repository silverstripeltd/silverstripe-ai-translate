# CMS UX

## JS framework

The modal is rendered as a custom React component with an Entwine adapter for integration into the CMS.

## Translate button

A button on the page edit form, placed in the page actions area (bottom toolbar). Same positioning pattern as ai-metadata's "AI Metadata" button.

**Visibility:** Only shown when the editor's current Fluent locale is **not** the default locale. There is nothing to translate when viewing the source language. The button is hidden (not disabled) when on the default locale.

**Label:** "Translate"

## Modal behaviour

### Opening the modal

- Clicking the button opens a custom React modal (not FormBuilderModal)
- The Entwine adapter mounts the React component and passes schema meta from the server
- If a previous translation was done during this page editing session, the modal restores the cached result
- If the page edit form later becomes dirty while the modal is open, Entwine clears the cached result and re-renders the modal so stale suggestions disappear immediately
- If no previous result exists, the modal opens with an empty state prompting the editor to generate

### Draft content notice

When the edit form is dirty, the modal shows an informational warning banner:

> "Translation uses your saved draft content. Save the page to draft before translating if you have unsaved changes."

Both **Generate** and **Apply** are disabled while the form is dirty, because both operations work with saved Draft content from the server rather than unsaved inline edits.

### Empty state

When no previous result is cached:

- Message: "Click the button below to translate this page's content."
- A "Translate Content" button is prominently displayed and uses the CMS info button style

## Running a translation

1. Editor clicks "Translate Content" or "Retranslate" if a previous result exists.
2. Loading spinner shown while the XHR is in progress.
3. Button disabled during the request.
4. Translation is also disabled while the page form is dirty.
5. If the page already matches the target locale, the modal shows "This page content already matches the target locale.", keeps the current Draft content untouched, hides apply, and disables the generate button for that cached result.
6. Otherwise, per-target suggestion cards are displayed.
7. On failure, error toast is shown and any previous result remains displayed.

## Result layout

Top to bottom:

1. **Header** - "Translate to {locale title}" (e.g. "Translate to Te Reo Maori")
2. **Per-target suggestion cards** - one card per suggestion returned by the AI:
   - Heading derived from the target type (`Page title`, `Page content`, or `Content block #{ID}` plus block title or field label when helpful). Elemental suggestions keep the content block identifier in this heading.
   - `diffHtml` preview comparing the current target-locale Draft value to the suggested translation
   - Cards are only shown for suggestions that contain a meaningful change based on `diffHtml` or a fallback content comparison
   - Checkbox per card to opt that suggestion into the apply request
3. **Action buttons:**
    - **"Retranslate"** - triggers a new AI translation, replacing the cached result. This button uses the CMS info button style.
    - **"Apply Translation"** - applies only selected suggestions to Draft content. Disabled when no suggestion is selected, while requests are in flight, or while the page form is dirty. This button uses the CMS info button style.

### Already-translated result state

- If the provider reports that the current target-locale Draft content already matches the locale, the modal shows the message "This page content already matches the target locale."
- In this state the generate button stays visible but disabled, so editors cannot repeatedly regenerate different phrasing for already-localised copy.
- The apply button is not shown.
- No suggestion payloads are sent back for apply, so the existing page content cannot be replaced by blank or accidental no-op data.

### Suggestion card fields

Each suggestion card shows only the card heading, the apply checkbox, and the server-generated diff preview. The visible `Source content ({locale})` heading, source snippet, and `Draft diff` field label are not shown.

## Result lifecycle

- Results are cached on the **Entwine instance** rather than React state so they survive modal close and reopen.
- The cached result shape is `{alreadyMatchesLocale, suggestions}` (see `specs/01_data-architecture.md` for the full shape).
- Results are **lost** when the editor navigates to a different page, because Entwine reinitialises, or when the CMS reloads after apply.
- The cached result is **flushed when the page edit form becomes dirty**, and it is also discarded on the next open if a manual save or publish changed the Draft content before the browser reloaded.

## Toast notifications

- **Translation success** - "Translation generated successfully"
- **Apply success** - "Translations applied to draft content"
- **Apply partial** - "Some translations could not be applied"
- **Apply failure** - "Unable to apply translations"
- **Error toast** - on provider failure or request error. In development, shows actual error. In production, generic message with server-side logging.
- **No content** - "This page has no content to translate" when extracted content is empty
- **Already translated** - no toast required; the modal itself shows "This page content already matches the target locale."

### Toast persistence across reload

Apply-success and partial-success toasts must survive the forced CMS reload that follows a successful apply. The Entwine adapter writes a pending toast descriptor to `sessionStorage` before triggering the reload. On the next page load, the adapter reads `sessionStorage`, replays the toast via the CMS toast API, and clears the stored entry. This handoff ensures the editor sees confirmation of their action even though the page was fully reloaded.

## Loading states

- **Schema load in progress:** A loading indicator is shown while the modal fetches its schema metadata on open, and action buttons remain disabled until that request completes.
- **Translation in progress:** Loading spinner replaces the result area. Generate and apply buttons are disabled.
- **Apply in progress:** The same loading state is shown with "Applying translations..." while the Draft write request is in flight.

## Dirty-state protection

- The modal clears any cached result and shows the saved-draft warning banner while the page edit form has Silverstripe admin's `.changed` class.
- Both **Translate Content** and **Apply Translation** are disabled in this state.
- The warning copy is the same saved-draft notice shown above, rather than a separate dedicated dirty-state message.

## Apply behaviour

- Applying suggestions sends only the selected suggestion payloads to the server.
- The server writes changes to Draft records only in the target Fluent locale and never publishes.
- If at least one suggestion is applied, the browser reloads the CMS so Elemental and other inline editors fetch fresh Draft data from the server.
- The modal does not try to mutate Elemental's client-side state directly.

## Modal actions

The modal has:

- A close control (standard modal close button and escape key)
- A translate or retranslate action
- An apply translation action for selected suggestions

It does **not** support editing suggestion text inline. Suggestion application is handled through the server-side review and apply flow.

## No rating or reasoning

Unlike the writing style and tone rules workflow, the translate modal does not display a compliance rating or reasoning summary. These concepts do not fit the translation workflow.

## No publish cascade

Applying suggestions writes to Draft only. There is no publish-on-page-publish hook.
