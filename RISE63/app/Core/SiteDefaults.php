<?php
declare(strict_types=1);

namespace RISE63\Core;

/**
 * The landing page's editorial content, as submitted in the RISE 63 framework.
 *
 * Why this exists as code rather than only as database rows: a fresh installation must have a real
 * landing page the moment it is deployed — before anybody has opened Settings → Website, and before
 * migration 0005 has necessarily run. It is also the answer to "restore the original wording", which
 * an administrator will want the first time they save something by mistake.
 *
 * Everything here is overridden by a published row in `site_slides`, so this is a floor, not the
 * source of truth once the site is live. The wording is the submitted framework's own, kept close to
 * the original because it is a governance document and paraphrasing it would change what it says.
 */
final class SiteDefaults
{
    /**
     * The hero.
     *
     * Four slides, in the order a stranger should meet them: who this is for, what it is, the network
     * it is built on, and the offer. `fallback` points at a shipped photograph so a fresh install has
     * real imagery rather than a grey box.
     *
     * @return list<array<string,mixed>>
     */
    public static function slides(): array
    {
        return [
            [
                'id' => 0, 'kicker' => 'RCCG Lagos Province 63', 'tone' => 'ink',
                'headline' => 'No genuine need should stay invisible.',
                'subhead' => 'A youth educational and economic empowerment initiative across every parish in Lagos Province 63.',
                'body' => 'Recognise. Inspire. Support. Empower. A joint initiative of the LP63 YAYA CSR and the Young Professionals Club.',
                'image_path' => null,
                'fallback' => 'public/img/hero-recognise.jpg',
                'image_alt' => 'Young members of RCCG Lagos Province 63 standing together after a service',
                'cta_label' => 'Discover RISE 63', 'cta_page' => 'about',
            ],
            [
                'id' => 0, 'kicker' => 'RISE Academy', 'tone' => 'ink',
                'headline' => 'Learn it. Then build it.',
                'subhead' => 'Three months of entrepreneurship and financial literacy for young people who have shown diligence in service.',
                'body' => 'Business development, market research, financial management and a tested plan at the end of it — not a certificate.',
                'image_path' => null,
                'fallback' => 'public/img/hero-academy.jpg',
                'image_alt' => 'A RISE Academy workshop in progress around a table in a church hall',
                'cta_label' => 'See the four programmes', 'cta_page' => 'vision',
            ],
            [
                'id' => 0, 'kicker' => 'Education Bridge', 'tone' => 'ink',
                'headline' => 'Keep learning. Keep rising.',
                'subhead' => 'Fees support for Super Teens and tertiary students whose education is at risk for genuine financial reasons.',
                'body' => 'Verified, confidential, paid to the institution where possible — and never conditional on joining a business programme.',
                'image_path' => null,
                'fallback' => 'public/img/hero-education.jpg',
                'image_alt' => 'A secondary school student walking through a corridor carrying her textbooks',
                'cta_label' => 'How Education Bridge works', 'cta_page' => 'vision',
            ],
            [
                'id' => 0, 'kicker' => 'Parish Connect', 'tone' => 'ink',
                'headline' => 'A representative in every parish.',
                'subhead' => 'The network that makes the rest possible — so that a small congregation is never the reason an opportunity is missed.',
                'body' => 'Every parish, its area and its zone, with the people who can answer a question and verify a need.',
                'image_path' => null,
                'fallback' => 'public/img/about-network.jpg',
                'image_alt' => 'A parish representative reviewing figures with a young member in a church office',
                'cta_label' => 'About the network', 'cta_page' => 'parish-connect',
            ],
        ];
    }

    /**
     * The four programmes, from section 3 of the framework.
     *
     * `accent` is a single hue per programme so the four cards read as a set with four members rather
     * than four unrelated tiles. It is not the brand colour — that marks action, not category.
     *
     * @return list<array<string,mixed>>
     */
    public static function programmes(): array
    {
        return [
            [
                'letter' => 'A', 'tag' => 'Learn it', 'name' => 'RISE 63 Academy', 'accent' => '#3f6b52',
                'lede' => 'A structured three-month entrepreneurship and financial-literacy programme for youths who have demonstrated diligence, integrity and commitment in service.',
                'points' => [
                    'Business development and market research',
                    'Financial management and customer acquisition',
                    'Market testing and a written business plan',
                ],
                'outcome' => 'Practical business competence and a credible, tested opportunity — not a certificate.',
            ],
            [
                'letter' => 'B', 'tag' => 'Fund it', 'name' => 'RISE 63 Seed Grants', 'accent' => '#b5762c',
                'lede' => 'A non-repayable business support programme for diligent young workers who complete the Academy and demonstrate a viable business.',
                'points' => [
                    'Up to ₦100,000 combined each month, subject to resources',
                    'Assessed on service, training and market validation',
                    'Follow-up monitoring against the stated business need',
                ],
                'outcome' => 'Financial support to establish or expand a sustainable business.',
            ],
            [
                'letter' => 'C', 'tag' => 'Grow it', 'name' => 'RISE 63 Cooperative Access', 'accent' => '#39657f',
                'lede' => 'A partnership with an existing registered cooperative society, giving eligible youths structured savings and affordable loans.',
                'points' => [
                    'RISE 63 facilitates membership and screens applicants',
                    'The cooperative retains full approval and collection authority',
                    'RISE 63 never holds savings and offers no blanket guarantee',
                ],
                'outcome' => 'Responsible financial inclusion for members who meet the cooperative\'s requirements.',
            ],
            [
                'letter' => 'D', 'tag' => 'Keep learning', 'name' => 'RISE 63 Education Bridge', 'accent' => '#8a4b6b',
                'lede' => 'Targeted assistance for students whose continued education is threatened by genuine financial difficulty.',
                'points' => [
                    'Super Teens in SS1–SS3 with verified fee difficulty',
                    'Tertiary students in recognised institutions',
                    'Paid directly to the institution wherever practical',
                ],
                'outcome' => 'Financially vulnerable students stay in education and keep progressing.',
            ],
        ];
    }

    /**
     * Nothing textual in the landing template is hard-coded; every string below is replaceable. The
     * administrator edits the pages and slides; this supplies the connective tissue between them.
     *
     * @return array<string,mixed>
     */
    public static function copy(): array
    {
        return [
            'statement' => 'Our vision is simple: no genuine need should remain invisible simply because the '
                . 'affected youth belongs to a small or less-connected parish.',
            'statement_source' => 'RISE 63 — proposed framework for leadership consideration',

            'intro_title' => 'A deliberate shift from occasional help to a system that works.',
            'intro_paragraphs' => [
                'RISE 63 moves beyond isolated welfare interventions towards coordinated, sustainable youth '
                . 'empowerment — combining education support, entrepreneurial training, business grants and '
                . 'responsible access to cooperative financing.',
                'It is run jointly by the LP63 YAYA CSR and the Young Professionals Club, under the oversight of '
                . 'the appropriate Lagos Province 63 leadership.',
            ],
            'intro_facts' => [
                ['Every parish', 'A YAYA CSR representative in each congregation, appointed with parish leadership.'],
                ['Four programmes', 'Academy, Seed Grants, Cooperative Access and Education Bridge.'],
                ['Three funds', 'Enterprise grants, education support and the cooperative never share a pool.'],
            ],

            'phase_lede' => 'Before a single naira moves, the province needs to know who is where, what they need and '
                . 'who can verify it. Phase One builds that network.',

            'phase_steps' => [
                ['Identify and appoint', 'At least one YAYA CSR representative in every parish, reporting through '
                    . 'the area and zonal structures to the provincial office.'],
                ['Collate and verify', 'A central parish directory and needs assessment: membership, youth figures, '
                    . 'educational needs, entrepreneurial interest and available mentors.'],
                ['Channel opportunity', 'Information reaches every parish — including the smaller and less visible '
                    . 'congregations — and confidential applications can be referred and followed up.'],
            ],

            'promises' => [
                'Fair and transparent selection, assessed by a panel that did not apply.',
                'Confidential handling of personal and financial information.',
                'Independent verification of need and of loan affordability.',
                'Safeguarding procedures for minors, with parent or guardian consent.',
                'Clear separation of cooperative funds, grants and education support.',
                'Direct institutional or supplier payments wherever practical.',
                'Documented approvals, financial records and periodic impact reports.',
                'No representative approves an award or receives a personal payment.',
            ],

            'closing_line' => 'Learn. Build. Rise.',
            'closing_sub' => 'Recognise. Inspire. Support. Empower. If you are a young person in Lagos Province 63, '
                . 'this was built for you.',
        ];
    }

    /**
     * Split a page body into a table of contents.
     *
     * Only `## ` headings, and only when there are at least two, because a single-item contents list is
     * noise. Anchors are derived from the heading text, de-duplicated, so they survive an edit.
     *
     * @return list<array{title:string, anchor:string}>
     */
    public static function toc(?string $body): array
    {
        $body = (string) $body;
        if ($body === '') {
            return [];
        }
        $entries = [];
        $seen    = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (!str_starts_with(trim($line), '## ')) {
                continue;
            }
            $title = trim(substr(trim($line), 3));
            if ($title === '') {
                continue;
            }
            $anchor = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
            if ($anchor === '') {
                $anchor = 'section';
            }
            $base = $anchor;
            $n = 2;
            while (isset($seen[$anchor])) {
                $anchor = $base . '-' . $n;
                $n++;
            }
            $seen[$anchor] = true;
            $entries[] = ['title' => $title, 'anchor' => $anchor];
        }
        return count($entries) >= 2 ? $entries : [];
    }
}
