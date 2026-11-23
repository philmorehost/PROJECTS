# Supplied homepage: responsive navigation increment

The current default PHP homepage already contains the supplied design's major sections and artwork (hero/converter, wallets, geographic coverage, app/cards/gift cards, university illustrations and footer). This increment does not claim a new exact visual adaptation or complete all the requested homepage work.

Both editions now have a scoped, progressive-enhancement mobile menu at <=800px. Desktop keeps the existing flex arrangement. Long tenant names wrap without horizontal overflow; menu links have >=44px mobile targets. Enter/Space work through a native button; Escape closes and restores focus; outside/link clicks close; crossing into mobile does not leave focus in a hidden link. Without JavaScript all links remain accessible. The enhancement does not depend on the converter or a network response. Reduced-motion users get no smooth scroll.

The new navigation assets have no storage/session/API/provider or wallet calls. Other saved-template layouts and their dispatch map are unchanged. Tenant logo/title escaping is retained. Nothing enables paused cards, withdrawals, crypto or settlement, and no deployment is performed.

Automated verification: actual server-rendered PHP fixture via PHP-WASM, Chromium at320/375/390/600/768/800/801/1024/1440px, page overflow, visible mobile link bounds/targets, keyboard/Escape, focus on breakpoint change, no-JS fallback, reduced motion and edition asset parity. External resources and financial/provider calls are blocked in this browser test; it does not verify external rate feeds. CI runs the browser check alongside native PHP public-page contracts.

Remaining: exact-reference visual comparison across sections, remaining reference interactions/artwork review, all other template design requirements, native-device checks and the separately tracked financial completion scope. Browser layout tests are not real-device or provider verification. Production serving is PHP plus static CSS/JS; Node is used only for development/CI tests, not required on the vendor's PHP host.
