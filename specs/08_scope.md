# Scope

## In scope

- AI-powered translation of page content via a CMS modal
- Structured per-field translation suggestions with stable rewrite targets
- Selective Draft-only application of generated translations to target-locale fields
- Per-target diff previews comparing current target-locale Draft content to suggested translations
- Elemental suggestion headings that keep the content block identifier visible during review
- Reusable AI provider abstraction (copied from ai-metadata)
- Works with Fluent locales - button appears on non-default locales
- Content extraction via Elemental block field discovery and page field fallback
- Dirty-state protection - generate and apply disabled while the page form has unsaved changes

## Out of scope (Fluent-level concerns)

The following are translation/localisation management problems that belong to Fluent, not this module:

- **Missing translation detection** - identifying which pages/fields lack translations for a given locale is Fluent's responsibility
- **Stale translation detection** - detecting that source locale content has changed and translations may be outdated. This is a Fluent-level problem.
- **Translation status reporting** - no CMS report for translation coverage or staleness across the site. This would duplicate Fluent's domain.
- **Translation versioning/publishing** - managed entirely by Fluent's existing Draft/Live lifecycle. The module has no publish cascade or review gate.

## Elemental-Fluent inline edit workaround

The module replaces Fluent's `FluentVersionedExtension` on Elemental blocks with a custom subclass that strips the `RecordLocales` and `Locales` fields from inline edit forms. These Fluent fields cannot render inside Elemental's inline editing schema and break the form if left in place. The custom extension preserves all other Fluent localisation behaviour - it only removes the CMS locale controls that are unsupported in the Elemental context.

## Out of scope (general)

- **Persisted translation results** - results are cached on the Entwine instance for the session only. No DataObject storage.
- **Background job for bulk translation** - not needed. This is a manual, on-demand tool.
- **Auto-translate on page save** - explicitly avoided. Translation is editor-initiated.
- **Publish cascade** - applying suggestions writes to Draft only. Publishing is the editor's responsibility.
- **Translation memory / glossary management** - no persistence of preferred translations across pages. Projects can add glossary terms via the prompt extension hook if needed.
- **Quality assessment** - no validation of translation accuracy. If the output is poor, the editor regenerates.
- **Inline suggestion editing** - editors cannot modify suggestion text in the modal. They apply as-is and edit in the CMS fields afterward.
