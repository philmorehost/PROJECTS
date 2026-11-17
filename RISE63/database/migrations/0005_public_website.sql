-- 0005 — the public website: content tables and the pages the footer depends on.
--
-- The landing page was a single hard-coded template. Every word of it — headline, vision, privacy
-- statement — was a PHP string, so correcting a typo or adding a programme meant editing code and
-- shipping a release. This moves the published words into the database, where an administrator edits
-- them on Settings → Website.
--
-- Three tables' worth of intent, two tables: `site_pages` (addable pages plus the four the footer
-- links to) and `site_slides` (the hero carousel). Nothing here touches an existing table, so it is
-- safe on a database holding live applications and money.
--
-- The seeded bodies are deliberately empty: Site::page() fills an empty body from
-- SiteDefaults::pageBodies(), so a fresh installation shows the province's own words immediately and
-- an administrator's first edit takes over from them. Seeding the full text here instead would copy
-- the same paragraphs into a migration and into PHP, and the two would drift.
--
-- The four seeded pages are marked is_system: an administrator may rewrite and unpublish them, but
-- not delete them, because a footer link that 404s is worse than a page with stale copy.

CREATE TABLE IF NOT EXISTS site_pages (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(60)  NOT NULL,
    title       VARCHAR(150) NOT NULL,
    kicker      VARCHAR(120) NULL,
    summary     VARCHAR(300) NULL,
    body        MEDIUMTEXT   NULL,
    is_published TINYINT(1)  NOT NULL DEFAULT 1,
    is_system   TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order  INT          NOT NULL DEFAULT 100,
    updated_by  INT          NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_site_page_slug (slug),
    KEY idx_site_pages_order (sort_order)
);

CREATE TABLE IF NOT EXISTS site_slides (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    kicker       VARCHAR(120) NULL,
    headline     VARCHAR(180) NOT NULL,
    subhead      VARCHAR(400) NULL,
    body         VARCHAR(600) NULL,
    image_path   VARCHAR(120) NULL,
    image_alt    VARCHAR(200) NULL,
    cta_label    VARCHAR(60)  NULL,
    cta_page     VARCHAR(60)  NULL,
    tone         ENUM('ink','light') NOT NULL DEFAULT 'ink',
    is_published TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order   INT          NOT NULL DEFAULT 100,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_site_slides_order (sort_order)
);

INSERT INTO site_pages (slug, title, kicker, summary, body, is_published, is_system, sort_order)
SELECT 'about', 'About Us', 'Who we are', 'RISE 63 is a youth educational and economic empowerment initiative of RCCG Lagos Province 63 — a joint effort of the LP63 YAYA CSR and the Young Professionals Club.', '', 1, 1, 10
WHERE NOT EXISTS (SELECT 1 FROM site_pages WHERE slug = 'about');

INSERT INTO site_pages (slug, title, kicker, summary, body, is_published, is_system, sort_order)
SELECT 'vision', 'Our Vision', 'What we are building', 'A province-wide support system that identifies needs, recognises diligence, develops potential and creates sustainable opportunities for young people.', '', 1, 1, 20
WHERE NOT EXISTS (SELECT 1 FROM site_pages WHERE slug = 'vision');

INSERT INTO site_pages (slug, title, kicker, summary, body, is_published, is_system, sort_order)
SELECT 'privacy', 'Privacy Policy', 'Your information', 'What RISE 63 collects, why it is collected, who can see it, and how to ask for it to be removed.', '', 1, 1, 30
WHERE NOT EXISTS (SELECT 1 FROM site_pages WHERE slug = 'privacy');

INSERT INTO site_pages (slug, title, kicker, summary, body, is_published, is_system, sort_order)
SELECT 'terms', 'Terms & Conditions', 'Using this platform', 'The rules that apply when you register, apply for support, or use the RISE 63 platform.', '', 1, 1, 40
WHERE NOT EXISTS (SELECT 1 FROM site_pages WHERE slug = 'terms');

-- Parish Connect is Phase One of the blueprint, and two public links point at it: the contact page
-- and the closing slide's call to action. A slug with no row behind it would make both of them land
-- on "that page is not here".
INSERT INTO site_pages (slug, title, kicker, summary, body, is_published, is_system, sort_order)
SELECT 'parish-connect', 'Parish Connect', 'Phase One',
       'How every parish in the province becomes visible to the initiative, and what a parish representative does.',
       '## Why a parish representative

RISE 63 exists because a young person''s opportunity should not depend on how well connected their parish is. The programme is province-wide, but the first contact a member has with it is local.

Each parish has one representative. They are the person who knows which families are carrying school fees alone, who has finished secondary school and is waiting for something, and who has a skill but no capital. They raise those needs to the zonal and area levels rather than leaving them where they are.

## What a representative does

- Meets with the parish''s young people and hears what they actually need.
- Enters their details here, so the need is recorded once and can be acted on.
- Passes applications to the zonal and area churches for review.
- Explains what RISE 63 does and, as importantly, what it does not do.

## For members: how to reach one

If your parish representative has not been introduced to you yet, ask your parish office who holds the role, or contact the province office and we will connect you. Nobody should be left out because they did not know who to ask.

## The principle

No genuine need should remain invisible simply because the affected youth belongs to a small or less-connected parish. Parish Connect is how that principle stops being a sentence in a proposal and becomes a list of names that someone is responsible for.', 1, 1, 15
WHERE NOT EXISTS (SELECT 1 FROM site_pages WHERE slug = 'parish-connect');
