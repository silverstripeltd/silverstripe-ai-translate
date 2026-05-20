# System Overview

One-page summary of the AI translation module architecture. Read this first, then dive into individual specs.

## What it does

Provides a "Translate" button in the Silverstripe CMS that uses an AI provider to translate page content into a target locale. The module extracts structured per-field rewrite targets from the source locale and aligns them with the current target-locale Draft content, sends both to an AI provider, and returns either a locked already-translated result or structured suggestions that editors can selectively review and apply to Draft content. The module writes to Draft only and never publishes.

This module requires `tractorcow/silverstripe-fluent` as a composer dependency. The AI provider HTTP infrastructure is copied from `SilverstripeLtd/silverstripe-ai-metadata` (only the parts needed - no metadata-specific code). There is no runtime dependency between the two modules.

## Architecture

```
+-----------------------------------------------------------------+
| CMS (Editor)                                                    |
|                                                                 |
|  Page Edit Form --> [Translate] button --> Modal (React)        |
|                      (shown when non-default locale active)     |
|                                              |                  |
|                                         Generate / Apply        |
|                                              |                  |
|                                              v                  |
|                              +------------------------------+   |
|                              | Per-target suggestion cards   |   |
|                              |                               |   |
|                              | [x] Page title               |   |
|                              |     Source: "About us"        |   |
|                              |     Diff: <del>/<ins>        |   |
|                              |                               |   |
|                              | [x] Content block #42        |   |
|                              |     Source: "Welcome to..."   |   |
|                              |     Diff: <del>/<ins>        |   |
|                              |                               |   |
|                              | [Apply Translation]           |   |
|                              +------------------------------+   |
|                              | Cached on Entwine instance    |   |
+-----------------------------------------------------------------+
                                |
              Schema / Translate / Apply XHR (specs/06)
                                v
+-----------------------------------------------------------------+
| AiTranslateController (specs/06)                                |
|                                                                 |
|  GET  /admin/ai-translate/schema/{ID}?fqcn=...                 |
|  POST /admin/ai-translate/translate/{ID}                        |
|  POST /admin/ai-translate/apply/{ID}                            |
+----------------+------------------------------------------------+
                 |
                 v
+----------------------------+   +---------------------------------+
| Content Extraction         |   | AI Provider (specs/03)          |
| (specs/02)                 |   |                                 |
|                            |   | Prompt (specs/04)               |
| Source-locale targets -+   |   | -> Gemini / OpenAI /            |
| Target-locale targets -+-> |   |    Anthropic                    |
|                        |   |   |                                 |
| Stable target keys     |   |   | -> Structured JSON              |
| per page + element     |   |   |    suggestions                  |
| field                  |   |   |                                 |
+------------------------+   +   +---------------------------------+
```

## Spec index

| # | Spec | What it covers |
|---|------|---------------|
| 00 | This file | System overview and architecture |
| 01 | `data-architecture` | Extension, Entwine cache shape, no persisted DataObject |
| 02 | `content-extraction` | Dual-locale extraction, structured rewrite targets, stable target keys |
| 03 | `ai-providers` | Reuse of ai-metadata provider abstraction |
| 04 | `prompts` | Structured JSON prompt, target-keyed translation contract |
| 05 | `generation-behaviour` | Generate and apply pipelines, validation |
| 06 | `api-endpoints` | Controller endpoints for schema, translate, and apply |
| 07 | `cms-ux` | Custom React modal, per-target review cards, diff preview, selective apply |
| 08 | `scope` | In scope, out of scope |

## Key design decisions

- **Structured per-field targets** - the module builds stable server-known rewrite targets for page fields and Elemental block fields, keyed by identifiers like `page:title` or `element:42:html`. The AI returns one suggestion per target.
- **Selective Draft-only apply** - editors review per-target suggestion cards with diff previews and apply only selected translations to Draft content. The module never publishes.
- **Dual-locale extraction** - prompt input includes both the source locale content and the current target-locale Draft content. This lets the AI detect already-translated copy, avoid rephrasing it, and only translate text that still needs work.
- **Non-persisted on-demand results** - translation results are cached on the Entwine instance for the editing session, not persisted to DB. Same pattern as the writing style and tone rules workflow. Applying suggestions writes directly to Draft records, and the cache is lost on page navigation or CMS reload.
- **No Fluent interaction on storage** - there is no persisted DataObject for translation results. Fluent only interacts with the page and element records being translated.
- **Reuses ai-metadata providers** - same AbstractAIProvider, same env var configuration, same error handling.
