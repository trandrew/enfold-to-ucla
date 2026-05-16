# Enfold to UCLA Conversion Guide

This document is the implementation guide for the `enfold-to-ucla` plugin.
Use it as the source of truth when converting Enfold shortcodes into UCLA Design System-ready WordPress blocks for the `ucla-wordpress` theme and `ucla-wordpress-plugin`.

Reference docs: <https://designsystem.brand.ucla.edu>

## Goals

- Convert legacy Enfold shortcodes into UCLA-compatible block markup.
- Preserve author intent (layout, hierarchy, spacing, content order).
- Prefer native UCLA plugin blocks when available.
- Fall back to core Gutenberg blocks with UCLA utility classes where a UCLA custom block does not exist.

## Target UCLA Component Inventory (Current)

The UCLA plugin currently provides the following custom blocks (from `wp-content/plugins/ucla-wordpress-plugin/blocks/src`):

- `accordion`
- `accordion-item`
- `alert`
- `button`
- `callout`
- `card`
- `card-event`
- `card-people`
- `card-story`
- `carousel`
- `carousel-slide`
- `icons`
- `ribbon`
- `tabs`
- `tab-item`
- `table`
- `tile`

The UCLA plugin also includes reusable patterns (from `wp-content/plugins/ucla-wordpress-plugin/patterns`):

- `box-banner`
- `quote-banner`
- `ribbon-banner`
- `ribbon-text-banner`
- `text-banner`

## Developer Instructions Per Component

When mapping Enfold shortcodes, use these priorities:

1. If a UCLA custom block exists, map to that block.
2. If no UCLA block exists, map to core block(s) with UCLA classes.
3. If no direct equivalent exists, wrap converted output in `core/group` and flag for manual review.

### Layout Components

- **`section` wrapper**
  - Preferred output: `core/group` with UCLA section classes.
  - Required classes: `ucla-section` plus spacing/background utility classes derived from shortcode attrs.
  - Notes: Use a stable class-only mapping so style updates can be handled in CSS.

- **Grid row**
  - Preferred output: `core/group` or `core/columns` with `ucla-grid`.
  - Notes: Use row wrappers to preserve nesting order before creating columns.

- **Grid columns**
  - Preferred output: `core/column` when inside `core/columns`; otherwise `core/group` with `ucla-grid__col-*`.
  - Notes: Normalize Enfold fraction-based widths into 12-column classes.

### Content Components (UCLA plugin blocks)

- **Accordion content** -> `ucla/accordion` + `ucla/accordion-item`.
- **Alert/banner messaging** -> `ucla/alert`, optionally `ucla/ribbon`.
- **Buttons / CTA links** -> `ucla/button`.
- **Callouts / highlighted content** -> `ucla/callout`.
- **Card-based listings** -> `ucla/card`, `ucla/card-event`, `ucla/card-people`, `ucla/card-story`.
- **Carousels** -> `ucla/carousel` + `ucla/carousel-slide`.
- **Icon collections** -> `ucla/icons`.
- **Tabbed content** -> `ucla/tabs` + `ucla/tab-item`.
- **Data tables** -> `ucla/table`.
- **Feature tiles** -> `ucla/tile`.

### Theme/Design System Utility Mapping

- Typography and spacing should use UCLA theme utility classes where available.
- Background and color attrs should map to approved UCLA token/class equivalents.
- Any legacy inline styles in Enfold shortcodes should be converted to class-based styling whenever possible.

## Layout Shortcode Mapping (Phase 1)

| Enfold Shortcode | Key Attributes | UCLA Mapping | Notes |
|---|---|---|---|
| `[av_section]` | `color`, `padding`, `fullwidth` | `<section class="ucla-section">` | Map background + spacing to utility classes |
| `[av_row]` | — | `<div class="ucla-grid">` | Sometimes implicit in Enfold |
| `[av_one_full]` | — | `<div class="ucla-grid__col-12">` | Full width column |
| `[av_one_half]` | — | `<div class="ucla-grid__col-6">` | 1/2 -> 6/12 |
| `[av_one_third]` | — | `<div class="ucla-grid__col-4">` | 1/3 -> 4/12 |
| `[av_two_third]` | — | `<div class="ucla-grid__col-8">` | 2/3 -> 8/12 |
| `[av_one_fourth]` | — | `<div class="ucla-grid__col-3">` | 1/4 -> 3/12 |
| `[av_three_fourth]` | — | `<div class="ucla-grid__col-9">` | 3/4 -> 9/12 |
| `[av_flex_column]` | `width` | `<div class="ucla-grid__col-*">` | Normalize widths |
| `[av_flex_row]` | — | `<div class="ucla-grid">` | Flex row to grid row |

## Width Normalization Rules

Normalize all Enfold width expressions to a 12-column scale:

- `100%` -> `12`
- `75%` -> `9`
- `66.666%` -> `8`
- `50%` -> `6`
- `33.333%` -> `4`
- `25%` -> `3`

If the width is not an exact known value:

- Round to nearest valid 12-column span.
- Add conversion note: `normalized-width` in conversion summary.

## Block Editor UX Requirements

When a shortcode block is selected in WordPress block editor:

1. User opens block options (three-dots menu).
2. Plugin adds action: **"Convert Enfold shortcode"**.
3. On click, plugin opens a conversion summary panel/modal containing:
   - Detected shortcode(s) in current block.
   - Planned mapping for each shortcode (Enfold -> UCLA block/class).
   - Warnings for unsupported attrs/content.
4. User confirms conversion.
5. Plugin replaces shortcode block with mapped UCLA block tree.
6. Plugin displays completion notice with:
   - Converted item count.
   - Any partial/fallback/manual-review items.

## Conversion Summary Schema (Recommended)

Use this shape for deterministic UI output:

- `sourceShortcode`: original shortcode tag
- `targetType`: `ucla-block` | `core-block` | `html-wrapper`
- `targetName`: block name or wrapper class
- `attributesMapped`: key/value list of attrs converted
- `warnings`: list of warning codes/messages
- `requiresManualReview`: boolean

## Unsupported/Partial Conversion Policy

- Keep all source content (never drop text/media silently).
- If exact mapping is unavailable, convert to nearest semantic block and apply UCLA classes.
- Always surface unsupported attrs in conversion summary.
- Add HTML comment marker in output for manual follow-up where needed.

## Implementation Checklist (Next)

- Build shortcode parser for Enfold layout shortcodes.
- Implement shortcode -> block transform pipeline for Phase 1 layout mappings.
- Add block editor menu extension for "Convert Enfold shortcode".
- Add conversion summary UI and confirmation flow.
- Add unit tests for nested section/row/column combinations.
- Add fixture tests for legacy Enfold content samples.

## Notes

- Keep this file updated as new shortcode mappings are added.
- Treat any new mapping as incomplete until it has:
  - mapping rule,
  - summary output behavior,
  - fallback behavior,
  - test coverage.
