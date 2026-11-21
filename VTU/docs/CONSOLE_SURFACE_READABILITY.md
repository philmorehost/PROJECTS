# Console surface/readability correction

## Reported issue and root cause

The Super Admin Account Settings security-policy panel uses `bg-primary bg-opacity-10` and dark labels. The shared console CSS first implemented the opacity utility correctly, but a later `.bg-primary { background: var(--brand) !important; }` shorthand replaced the tint with a solid fill. Dark/brand-coloured labels then blended into that fill. The same shared rules affect customer and back-office pages in both editions.

There were also unconditional on-brand descendant overrides, solid-colour gradients/hover filters that could defeat computed contrast, white-only active tabs, and status badges whose text/background combinations were not appropriate for a faint tint.

## Changes

- Removed the conflicting late solid `.bg-primary` shorthand.
- Added explicit foreground/link contexts for filled and tinted surfaces, with nested neutral cards and inputs resetting their own context.
- Resolve primary/status opacity utilities to their appearance composited against the neutral white card surface, then calculate foreground/link colours for that actual rendered surface. This preserves the pale-panel appearance while avoiding unpredictable inherited backgrounds. `bg-opacity-0` through the supported 10/20/25/50/75/100 levels are covered.
- Restored the reported teal 10% panel to `#E7F1F1`, with readable labels and descriptions.
- Updated similarly affected secondary/success/danger/warning/info/dark status tints and matching badge text.
- Made filled primary buttons and active settings tabs use matching on-brand foregrounds. Removed brand gradients/brightness changes from those controls where they could invalidate contrast calculations.
- Kept all settings fields, submitted values, authentication, authenticator unlock requirements and security-policy handlers unchanged. This is a styling correction, not a security-policy change.

## Tests

Install test dependencies with `npm ci --prefix VTU/tests/harness`.

- `node VTU/tests/harness/readability.test.mjs`: **1,584 real Chromium contrast checks**, using CSS rendered by the actual PHP shell helpers and the security-panel markup extracted from Account Settings. Covers eight primary colours, seven opacity levels, semantic status badges, nested controls/cards, icons, filled buttons and selected tabs at desktop/mobile widths. Also checks for horizontal overflow. The bundled npm Chromium binary is for compatible Linux test hosts; its support libraries are extracted only into a temporary test directory.
- `node VTU/tests/harness/run-test.mjs tests/surface_readability_test.php`: **8,388 checks**, including a 4,096-colour RGB sweep for the reported 10% panel and helper/shell parity across editions.
- Existing app/admin shell (156/731), public-home (2,855), public links (4,153), dynamic-theme (45,176) and hidden-modal (28) suites passed.
- PHP parser and git diff/whitespace checks passed during implementation.

Tests use offline markup, not a logged-in production account. No live settings or financial state were changed. Deploy the PHP helpers for the relevant edition, invalidate any HTML/CDN or PHP opcode cache as required by the hosting environment, then verify Account Settings and other affected pages with the actual configured brand.
