# Enfold to UCLA

WordPress plugin that converts legacy Enfold shortcodes into UCLA Design System-ready Gutenberg blocks for the `ucla-wordpress` theme and `ucla-wordpress-plugin`.

Reference docs: <https://designsystem.brand.ucla.edu>

## Goals

- Convert legacy Enfold shortcodes into UCLA-compatible block markup.
- Preserve author intent (layout, hierarchy, spacing, content order).
- Prefer native UCLA plugin blocks when available.
- Fall back to core Gutenberg blocks with UCLA utility classes where a UCLA custom block does not exist.

## UCLA Component Inventory

The UCLA plugin provides the following custom blocks (`ucla-wordpress-plugin/blocks/src`):

- `accordion`, `accordion-item`
- `alert`
- `button`
- `callout`
- `card`, `card-event`, `card-people`, `card-story`
- `carousel`, `carousel-slide`
- `icons`
- `ribbon`
- `tabs`, `tab-item`
- `table`
- `tile`

Reusable patterns (`ucla-wordpress-plugin/patterns`):

- `box-banner`, `quote-banner`, `ribbon-banner`, `ribbon-text-banner`, `text-banner`

## Block Editor UX

1. User selects a shortcode block and opens the block options menu (three-dots).
2. Plugin adds action: **"Convert Enfold shortcode"**.
3. On click, a conversion summary panel shows:
   - Detected shortcodes in the block.
   - Planned mapping for each (Enfold → UCLA block/class).
   - Warnings for unsupported attrs/content.
4. User confirms conversion.
5. Plugin replaces the shortcode block with the mapped UCLA block tree.
6. Plugin displays a completion notice with converted item count and any manual-review flags.
