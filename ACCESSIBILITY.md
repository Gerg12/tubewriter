# Accessibility changes and verification

## Typography

Body copy, navigation, controls, labels, status badges, video metadata, feature descriptions, footer copy, and form content use at least 1rem (16px at the browser default). The shared theme and plugin styles no longer shrink these on mobile. The only smaller shared annotations are the illustrative Draft preview label and its character counter, at 0.875rem (14px).

Text remains responsive to the browser's default font preference. The header, action rows, filters, statistics, and preview card wrap rather than clipping when text grows. The smallest header hides the decorative .com suffix while retaining the full accessible home-link name.

## Interaction and semantics

- Added a working Skip to content link and focusable main landmark to app pages.
- Visible, contrasting keyboard focus for links, buttons, inputs, selects, summaries, and custom focus targets.
- Standalone controls use targets at least 44px high and wide.
- Mobile navigation supports keyboard activation, Escape, focus return, outside clicks, and closing when focus leaves.
- Visibility filters are labelled navigation links with aria-current, rather than an incomplete ARIA tab widget.
- Calendar dates expose labels, selected states, and keyboard activation. The editor calendar now supports Enter and Space, including focus retention after rendering.
- Thumbnail dialogs move focus inside, keep background content inert, contain forward and reverse Tab navigation, close on Escape, and restore the opener's focus.
- Existing reduced-motion support remains. Entry fades no longer make app content briefly invisible.

## Checks completed

- Homepage: computed text sizes and text contrast, desktop and narrow mobile layouts. Only the two documented 14px annotations remain below 16px.
- Signed-in library: a noninteractive render of the actual local WordPress library, including filter state and skip-link destination. No undersized visible text or measured text-contrast failures remained.
- Library reflow: no horizontal overflow at a 320px viewport; no horizontal overflow with a doubled base text size at 1280px.
- Login: labelled fields, no undersized visible text, no measured text-contrast failures, and no horizontal overflow at 320px.
- Keyboard: Skip to content focuses main; mobile menu opens from the keyboard and closes on Escape with focus returned.
- Dialog: isolated browser check using the production open/close functions and key handler confirmed initial focus, forward/reverse Tab wrapping, Escape, and opener focus restoration.
- PHP and JavaScript syntax validation for changed source files.

Contrast checks calculated foreground/background luminance against the rendered solid backgrounds, using 4.5:1 for normal text and 3:1 for large text. This targeted implementation pass is not a full assistive-technology or WCAG conformance certification. Live YouTube writes were not used for testing. The editor calendar and dialog changes should also receive a manual screen-reader pass in the normal signed-in workflow.

## Shared workspace palette and calendar refresh

Videos and Schedule share global `--uw-workspace-*` palette tokens and the existing `--uw-primary` brand red. Scheduled states use the shared red and a darker red for normal text; published states use shared semantic teal tokens. Page rules consume these tokens instead of defining their own colors.

Calendar cards now separate thumbnails from padded 16px titles, status labels, and times. Full titles remain available in the selected-day panel. At narrow widths the month shows dates and counts, with readable full cards above it. Desktop and 320px browser checks used cached channel metadata without making YouTube writes. Both pages passed the targeted computed text-size and solid-background contrast checks; calendar keyboard selection and month navigation were checked.

The editor's Go live step now uses the shared `uw-calendar` component. Publishing choices sit above the full-width calendar; upload cards include cached thumbnails, explicit status, times, and padded 16px titles. A labelled, focusable horizontal scroll region preserves readable seven-day cards on narrow screens. Isolated browser verification used the production step markup and scheduling JavaScript with cached events; date selection, default time, month navigation, and Next available were checked without saving a draft or publishing.

## Homepage image carousel

The full-width hero has three editable ACF slides with responsive photographs. Only the active slide is visible and available to assistive technology. Controls have accessible names and 48px targets; numbered slide choices expose the current slide. Previous/next, arrow keys, Home, and End work from the controls. Manual navigation stops autoplay. Eight-second rotation pauses on hover, keyboard focus, and hidden documents, with an explicit Play/Pause control. Reduced-motion preference disables initial autoplay and visual transitions. Slides share a grid row to prevent layout shifts between different copy lengths. Normal hero text remains at least 16px. Desktop and narrow-screen browser checks cover navigation, imagery, reflow, and targeted text contrast; photographic backgrounds use a dark scrim and still benefit from human visual review.

## Photographic homepage sections and floating SVGs

Features, workflow, and the closing call to action share the hero's photographic direction and global brand/workspace color tokens. Six custom inline SVG decorations are hidden from assistive technology, do not accept focus, and cannot intercept clicks. Decorative movement follows reduced-motion preferences, and a visible Pause floating artwork control pauses all floating SVGs and stickers. Main copy and controls remain at least 16px. Desktop, 390px, and 320px browser checks found no page-level horizontal overflow, undersized text, or targeted solid-background text contrast failures. The pause control was verified against each SVG's computed animation state. Existing ACF copy, card entries, images, anchors, and CTA destinations remain editable.

Shared container expansion was checked at 1920px, 2560px, 3440px, 1440px, and 320px. Header, hero, feature section, workflow, closing section, and footer align to the same global container; narrow-screen gutters remain 16px. No page-level horizontal overflow or undersized normal text was found in the targeted browser checks.

The closing CTA no longer layers a rotated circular text badge over photography. It uses separate grid columns for copy and a photo card, with its caption and decorative spark in normal flow. Below 900px the card stacks below the text. The retired overlay and badge styles were removed.
