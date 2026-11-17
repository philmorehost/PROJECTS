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

    /**
     * The bodies of the pages the footer links to.
     *
     * These live here, not in migration 0005, for the same reason the hero does: a page whose text
     * exists only in a migration is a page that says "This page is being written" on every
     * installation, including the one holding live applications — because nothing ever writes the
     * text. Migration 0005 seeds the rows so they can be edited; this is what they say until an
     * administrator changes them.
     *
     * Mapped slug => body. The markup is the four marks the CMS explains: ##, ###, -, > and **.
     *
     * @return array<string, string>
     */
    public static function pageBodies(): array
    {
        return [
            'about' => <<<'TXT'
## Why RISE 63 exists

Every province has young people who are doing everything right and still not getting anywhere. They finished secondary school and the fees for the next step are out of reach. They learned a trade and have no capital to start. They have a business idea that would work, and no way to test it.

RISE 63 — Recognise. Inspire. Support. Empower. — was set up because those situations are not mysteries. They are known, they are local, and in most cases someone in the member's own parish could name the person and describe the need. What was missing was a system for turning that local knowledge into support that arrives.

## What it is

A youth educational and economic empowerment initiative of the Redeemed Christian Church of God, Lagos Province 63, run jointly by the LP63 YAYA CSR and the Young Professionals Club under the oversight of provincial leadership.

> A deliberate shift from occasional help to a system that works.

RISE 63 is not a hardship fund that pays out until the money runs out. It is four programmes with defined rules, a network in every parish to find the people who qualify, and a record of what was spent and what it achieved.

## How it works

### Four programmes

- **RISE Academy** — a three-month entrepreneurship and employability programme. Learn it.
- **Seed Grants** — non-repayable capital for a verified business plan, capped at ₦100,000 per month across the province. Fund it.
- **Cooperative Access** — responsible access to savings and loan facilities through an existing registered cooperative. RISE 63 holds no member savings and gives no blanket guarantee. Grow it.
- **Education Bridge** — school fees for SS1 to SS3 and tertiary students, paid to the institution and never to an individual. Keep learning. Keep rising.

### Three separate funds

Enterprise grants, education support and the cooperative never share a pool. Money given for school fees cannot be spent on a grant, and the accounts show it.

### A representative in every parish

The initiative works because each parish has a YAYA CSR representative who knows the young people in it. They raise needs to the area and zonal levels, so a member of a small congregation is not invisible simply because the congregation is small.

## What it will not do

It will not promise what it cannot sustain. It will not give cash to an individual where a supplier or institution can be paid directly. It will not let a representative approve an award or take a personal payment. And it will not treat a young person's difficulty as a reason to publish their name — applications are confidential, and the people who see them are the people who must.

## Governance

Awards are assessed by a panel whose members did not apply, and the criteria are published before a round opens rather than explained afterwards. Approvals, disbursements and outcomes are recorded, and the province reports on them periodically.

## Getting involved

If you are a young person in Lagos Province 63, start with the parish representative where you worship, or use the contact page. If you are in a position to support the work — funding, mentoring, or professional expertise — the province would like to hear from you.
TXT,

            'vision' => <<<'TXT'
## Our vision

> No genuine need should remain invisible simply because the affected youth belongs to a small or less-connected parish.

That is the whole of it. Everything else in RISE 63 is machinery for making that sentence true.

## The problem it answers

Support for young people usually travels along relationships. A member whose family knows the provincial office, or whose parish is large enough to be noticed, gets heard. A member in a small congregation, or one who has never told anyone what they are carrying, does not — and their need does not disappear. It simply goes unrecorded, and an unrecorded need cannot be met.

The province is not short of goodwill. It is short of a way to see.

## What we are building

### Visibility

A representative in every parish, and a directory that holds the real membership and the real youth figures of zones, area churches and parishes alike. Not an estimate of a province, but a record of it.

### Recognition

Named programmes with published criteria, so that a young person can know before they apply whether they qualify, instead of finding out after they are turned down.

### Support that is proportionate

Grants sized to a plan rather than to how compelling the story is. Fees paid to the institution. Training that lasts long enough to be worth attending. Cooperative lending entered with eyes open, through a body that already exists and is already supervised.

### A record

Every approval, every disbursement and every outcome written down, so that the province can tell what worked and the next round can be better than this one.

## What success looks like

A young person in the smallest parish in the province can find out what is available, apply without having to know anybody, and be assessed on the same terms as everyone else. When that is unremarkable — when nobody is surprised by it — the vision has been met.

## What it asks of us

Patience, because a system built properly is slower at the start than a fund handed out quickly. Discipline, because separate funds stay separate even when one has money and another does not. Honesty, because reporting the failures is what makes the successes worth trusting.

## The line we come back to

Learn. Build. Rise. — and Recognise. Inspire. Support. Empower. Both halves matter. The first is what we want for a young person. The second is what the rest of us have to do first.
TXT,

            'privacy' => <<<'TXT'
## What we collect

We collect as little as we can and still run the programme honestly.

### When you create an account

Your name, e-mail address and telephone number, the church you worship in, and a password that is stored only as a one-way hash — nobody at RISE 63, including an administrator, can read it.

### When you apply for support

What you are applying for, the amount, and the circumstances that make you eligible: your course of study or business plan, your income, and any documents needed to verify them. Some of this is personal. All of it is treated as confidential.

### Automatically

We record the address a request came from, the browser it came from and the time it arrived. This exists to protect your account and the platform — to notice a password being guessed, for example — and is not used to profile you.

## Why we hold it

- To decide an application fairly, against published criteria.
- To pay an institution or a supplier on your behalf.
- To keep the record the province is accountable for: what was awarded, what was paid and what came of it.
- To reach you about your own application.

We do not sell your information. We do not give it to advertisers. We do not use it to contact you about anything other than RISE 63.

## Who can see it

- **Your parish representative** sees that you have an account and your application.
- **The area, zonal and provincial officers who review awards** see the applications before them.
- **The provincial administrator** can see all records, and every one of their accesses is logged.
- **Nobody else.** Other members cannot see your applications, your income or your circumstances.

A representative may not approve an award for themselves or for anyone they are related to, and the system records who approved what.

## Where it is kept

On servers controlled by the province, in a database that is not reachable from the public internet except through this application. Data is transmitted over an encrypted connection and passwords are stored with a slow one-way hashing algorithm.

## How long we keep it

Account and application records are kept for as long as the programme runs and for the period afterwards that the province must be able to account for its funds. If you ask us to close your account, we remove your personal details; the financial record of an award that was actually paid has to remain, because it is part of the province's accounts.

## Your rights

You can ask to see what we hold about you, ask us to correct it, and ask us to delete it. Write to the provincial office using the contact page and we will answer within a reasonable time. If we cannot delete something because it is part of a financial record, we will tell you that plainly rather than quietly ignoring the request.

## Children

The programme is for young adults. Where an applicant is under eighteen, we require the consent of a parent or guardian and apply the province's safeguarding procedures. We do not knowingly collect information about a child without it.

## Changes

If this policy changes in a way that affects what we hold or who can see it, we will say so on this page and, where it matters, tell account holders directly. The date of the last review is shown below.

## Contact

Questions about any of this are not an inconvenience. Use the contact page, and the provincial office will answer.
TXT,

            'terms' => <<<'TXT'
## Using this platform

By creating an account you agree to these terms. If you do not agree with them, please do not register.

## Your account

Give us accurate information. An application assessed on false information is void, and an award already paid on one may be recovered. You are responsible for keeping your password to yourself — we will never ask you for it, in this application or on WhatsApp.

One account per person. Accounts are not transferable, and one person may not apply twice in the same round.

## Applying for support

- Applications are assessed against criteria that are published before a round opens.
- An application is not an entitlement. The number of awards is limited by the funds available, and a strong application in a well-subscribed round may still not be funded this time.
- You may be asked for documents that verify what you have told us. An application that cannot be verified is not approved, which is not the same as being disbelieved.
- Decisions are recorded, and the reasons can be asked for.

## The programmes

### Seed Grants

Non-repayable capital for a verified business plan. The provincial ceiling is ₦100,000 per month across all recipients, which means an award may be smaller than the amount requested, or may be made in stages. Money is released against the plan, not against the request.

### Education Bridge

School fees for SS1 to SS3 and tertiary students, paid directly to the institution wherever that is practical. It does not cover living costs, equipment, or fees for a course the applicant has not been admitted to.

### Cooperative Access

Savings and loan facilities are provided by an existing registered cooperative, not by RISE 63. RISE 63 holds no member savings and gives no guarantee of a loan. Joining a cooperative creates a financial obligation to that cooperative, which the member carries. Enter it understanding the repayment terms.

### RISE Academy

Attendance is required. A place is limited, and a member who stops attending takes a place from someone who would have used it.

## What we expect of you

- Tell the truth, and tell us promptly if something changes — particularly if you receive the same support from elsewhere.
- Treat the representatives, officers and other members with courtesy.
- Do not attempt to access records that are not yours, or to interfere with the platform.
- Do not offer, and do not accept, a payment in exchange for an award. It is misconduct, and it ends both parties' involvement.

## What you can expect of us

- Criteria published before a round opens, and applied the same way to everyone.
- Confidential handling of your information, as set out in the privacy policy.
- Conservative financial management, published figures, and a periodic report on what the programme achieved.
- A clear reason if an application is declined, and a route to ask for a review.

## Suspension and removal

An account may be suspended where these terms are broken, where information is found to be false, or where an award is being misused. Where it is safe to do so we will tell you why and give you a chance to respond.

## Limits

RISE 63 supports a person's own effort; it does not replace it, and it does not create a continuing obligation on the province. Nothing here is a promise of future support, and no award creates an entitlement to another.

## Changes to these terms

These terms may change. A change that affects how applications are assessed will be announced before it applies to a round, not after. The date of the last review is shown below.

## Governing law

These terms are governed by the laws of the Federal Republic of Nigeria.
TXT,
        ];
    }
}
