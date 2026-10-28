<?php
declare(strict_types=1);

/**
 * Marlowe & Finch: a six-page Divi 5 theme pack for a boutique law firm in Boston.
 * Design language editorial with the double-frame kit (formal double-rule buttons), recoloured to cool grey and oxblood
 * and set in Spectral. Photographs pinned by Pexels id (every URL checked).
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$crumb = static fn ( string $page ): string => '<p><a href="#">Home</a> / ' . $page . '</p>';
$S = '&nbsp;&nbsp;';

return [
    'name'     => 'Marlowe & Finch',
    'language' => 'editorial',
    'kit'      => 'double-frame',
    'palette'  => [ 'bg' => '#eceff3', 'surface' => '#ffffff', 'text' => '#14202e', 'muted' => '#55616f', 'accent' => '#7a1e2c', 'accent_text' => '#ffffff', 'border' => '#cfd6de', 'inverse' => '#f4f6f9', 'overlay' => '#101a28' ],
    'fonts'    => [ 'Playfair Display' => 'Spectral' ],
    'pages'    => [
        'home' => [ 'title' => 'Home', 'sections' => [
            [ 'hero-editorial-minimal', [
                0 => 'Marlowe & Finch · Attorneys at law',
                1 => 'Counsel for the questions that keep you awake.',
                2 => $P( 'A small Boston firm advising founders, families and employers on the matters that matter most. We take fewer cases so each one gets a partner.' ),
                3 => $P( '<a href="#">Read about the firm</a>' ) ] ],
            [ 'stats-big-captions', [
                0 => 'In figures', 1 => 'Twenty years, counted',
                2 => $P( 'Taken from our own matter files, reviewed every January.' ),
                3 => $P( '1,400+' ), 4 => $P( 'matters closed since 2005' ),
                5 => $P( '92%' ), 6 => $P( 'of disputes settled without trial' ),
                7 => $P( '4' ), 8 => $P( 'partners on every matter, never more' ),
                9 => $P( '24h' ), 10 => $P( 'to reply to any enquiry' ) ] ],
            [ 'blurb-grid', [
                0 => 'Practice areas', 1 => 'Four areas of law, practised with care and without hurry.',
                2 => 'Business disputes', 3 => 'fa:f24e', 5 => $P( 'Contract, partnership and shareholder disputes resolved quickly, or fought properly.' ),
                6 => 'Employment', 7 => 'fa:f0b1', 9 => $P( 'Advice for employers and senior employees on contracts, exits and settlements.' ),
                10 => 'Family law', 11 => 'fa:f004', 13 => $P( 'Divorce, children and finances handled with calm and discretion.' ),
                14 => 'Wills and estates', 15 => 'fa:f15c', 17 => $P( 'Wills, trusts and probate, planned so that your wishes are kept.' ) ] ],
            [ 'quote-single-large', [
                1 => $P( 'I expected a large firm and a larger bill. Instead I had a partner who answered the phone, explained everything and settled our dispute in a month.' ),
                2 => 'px:30479361:800', 3 => 'Helena Voss portrait', 4 => $P( 'Helena Voss' ), 5 => $P( 'Founder, Voss Interiors' ) ] ],
            [ 'cta-band', [ 0 => 'Speak to a partner this week.', 1 => 'Request a consultation', 2 => $P( 'The first thirty minutes are free.' ) ] ],
        ] ],

        'practice' => [ 'title' => 'Practice Areas', 'sections' => [
            [ 'header-divider-intro', [
                0 => 'Practice areas', 1 => 'Clear advice in the matters that count',
                2 => $P( 'We work in a handful of areas and know them deeply. If a matter falls outside them, we will say so and point you to someone good.' ) ] ],
            [ 'list-icon-dividers', [
                0 => 'How we can help', 1 => 'Five kinds of problem we solve every week',
                2 => $P( 'Each begins with a plain conversation and a written plan.' ),
                3 => 'fa:f0e3', 5 => 'A dispute with a partner or supplier', 6 => $P( 'Negotiation first, mediation next and the courtroom only when it serves you.' ),
                7 => 'fa:f0b1', 9 => 'A difficult exit from a job', 10 => $P( 'Severance, restrictive covenants and settlement agreements, reviewed in a day.' ),
                11 => 'fa:f004', 13 => 'A separation or custody question', 14 => $P( 'Practical, child-focused advice and fair financial settlements.' ),
                15 => 'fa:f15c', 17 => 'Planning for the people you love', 18 => $P( 'Wills, lasting powers of attorney and trusts that protect your family.' ),
                19 => 'fa:f1ad', 21 => 'Buying or selling a business', 22 => $P( 'Due diligence, contracts and completion, with a partner at every meeting.' ) ] ],
            [ 'pricing-two-plan-highlight', [
                0 => 'Fees', 1 => 'Two ways to pay for advice',
                2 => $P( 'We agree the fee in writing before we start, and we tell you early if the estimate changes.' ),
                3 => $P( 'Hourly' ), 4 => 'Time and care', 5 => $P( '$425' ), 6 => $P( 'Per hour, partner rate' ),
                9 => $P( 'Best for open-ended disputes' ), 12 => $P( 'A monthly statement of time' ), 15 => $P( 'A cap agreed in advance' ), 18 => $P( 'No retainer on small matters' ), 21 => $P( 'Reviewed every quarter' ), 22 => 'Ask about hourly',
                23 => $P( 'Most requested' ), 24 => 'Fixed fee', 25 => $P( '$2,800' ), 26 => $P( 'From, for a defined piece of work' ),
                29 => $P( 'Wills, leases and settlements' ), 32 => $P( 'One price agreed up front' ), 35 => $P( 'A named partner throughout' ), 38 => $P( 'No surprises on the invoice' ), 41 => $P( 'Payable in two stages' ), 42 => 'Ask about fixed fees' ] ],
            [ 'checklist-two-column', [
                0 => 'First meeting', 1 => 'What to bring to your consultation',
                2 => $P( 'A short pile of paper saves an hour of questions.' ),
                5 => $P( 'Photo identification' ), 8 => $P( 'Any contract or agreement involved' ),
                11 => $P( 'Letters and emails from the other side' ), 14 => $P( 'A timeline in your own words' ),
                17 => $P( 'The names of anyone involved' ), 20 => $P( 'Questions you want answered' ),
                23 => $P( 'A note of your goals and limits' ), 26 => $P( 'A cup of tea, which we will provide' ) ] ],
            [ 'faq-two-column', [
                0 => 'Questions', 1 => 'Before you call',
                2 => $P( 'Short answers to what clients ask us first.' ), 3 => 'Ask a partner',
                4 => 'Is the first meeting really free?', 5 => $P( 'Yes. Thirty minutes with a partner, in person or by video, with no obligation to continue.' ),
                6 => 'Will you tell me my chances honestly?', 7 => $P( 'Always. We would rather lose a client than mislead one, and we say so on our engagement letter.' ),
                8 => 'Do you take cases on a no-win, no-fee basis?', 9 => $P( 'Occasionally, for strong commercial claims. We will tell you at the first meeting if that is possible.' ),
                10 => 'How long will it take?', 11 => $P( 'Most disputes settle in two to four months. We give you a realistic timetable in writing.' ),
                12 => 'Can you act for me out of state?', 13 => $P( 'Yes, in Massachusetts and, with trusted local counsel, anywhere in the United States.' ),
                14 => 'What if I cannot afford a long case?', 15 => $P( 'Tell us early. We can phase the work, cap the fees or help you settle before the costs rise.' ) ] ],
        ] ],

        'approach' => [ 'title' => 'Our Approach', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Our approach' ), 1 => 'Our approach',
                2 => $P( 'Four principles, one partner and a plan in writing before we begin.' ) ] ],
            [ 'steps-horizontal', [
                0 => 'How we work', 1 => 'From first call to final letter',
                2 => $P( 'Most matters follow these four steps, with a partner involved in every one.' ),
                4 => 'Listen', 5 => $P( 'A long first conversation to understand what happened and what you want.' ),
                7 => 'Plan', 8 => $P( 'A written plan with options, risks, timings and a fee agreed in advance.' ),
                10 => 'Act', 11 => $P( 'Negotiation, drafting or proceedings, with updates after every step.' ),
                13 => 'Close', 14 => $P( 'A clear final letter, an honest review and no surprise invoice.' ),
                15 => 'Request a consultation' ] ],
            [ 'features-accordion-image', [
                0 => 'The details', 1 => 'Four habits that set us apart',
                2 => 'One partner, always', 3 => $P( 'Your matter is handled by a partner from start to finish. You never get passed to a trainee.' ),
                4 => 'Plain English', 5 => $P( 'We explain every step without jargon, and we put the advice in a short letter you can keep.' ),
                6 => 'Settle when it makes sense', 7 => $P( 'Most disputes are better ended than won. We tell you early when that is the right call.' ),
                8 => 'Fees you can predict', 9 => $P( 'Capped or fixed fees wherever possible, and a quarterly review wherever not.' ),
                10 => 'px:9572540', 11 => 'Two colleagues reviewing papers at a table in a wood-panelled library' ] ],
            [ 'timeline-vertical', [
                0 => 'A typical matter', 1 => 'Month by month',
                2 => $P( 'A business dispute that settles, from the first call to the signed agreement.' ),
                3 => 'Week 1', 4 => 'Instructions', 5 => $P( 'Meeting, documents reviewed and a written plan and fee agreed.' ),
                6 => 'Weeks 2 to 3', 7 => 'Letter before claim', 8 => $P( 'A firm, polite letter sets out your position and invites a settlement.' ),
                9 => 'Weeks 4 to 8', 10 => 'Negotiation and mediation', 11 => $P( 'Offers exchanged and, where useful, a day with a mediator.' ),
                12 => 'Week 10', 13 => 'Settlement', 14 => $P( 'A signed agreement, funds transferred and a final review of the lessons.' ) ] ],
            [ 'values-list', [
                0 => 'Principles', 1 => 'Four promises we make to every client',
                3 => 'Candour', 4 => $P( 'We tell you what we think, including when it is not what you hoped to hear.' ),
                6 => 'Discretion', 7 => $P( 'Your matter stays between you and us, and our files are locked and encrypted.' ),
                9 => 'Proportion', 10 => $P( 'The cost of a fight should never exceed the value of winning it.' ),
                12 => 'Availability', 13 => $P( 'Questions answered within a working day, and an emergency number for the rest.' ) ] ],
        ] ],

        'partners' => [ 'title' => 'The Partners', 'sections' => [
            [ 'header-image-band', [
                0 => 'px:37224691:1920', 1 => $crumb( 'The partners' ), 2 => 'The partners',
                3 => $P( 'Four lawyers, a combined seventy years of practice and one shared standard.' ) ] ],
            [ 'team-grid', [
                0 => 'The firm', 1 => 'The people you will actually deal with',
                2 => $P( 'Every client works directly with a partner, from the first call to the last letter.' ),
                3 => 'px:26728092:800', 4 => 'Mei Tanaka portrait', 5 => 'Mei Tanaka', 6 => $P( 'Partner, business disputes' ),
                7 => 'px:14156504:800', 8 => 'Olivia Ward portrait', 9 => 'Olivia Ward', 10 => $P( 'Partner, family law' ),
                11 => 'px:12311572:800', 12 => 'Emmanuel Okoro portrait', 13 => 'Emmanuel Okoro', 14 => $P( 'Partner, employment' ),
                15 => 'px:28446973:800', 16 => 'Kenji Mori portrait', 17 => 'Kenji Mori', 18 => $P( 'Partner, estates and trusts' ) ] ],
            [ 'founder-note', [
                0 => 'px:4687549:1000', 1 => 'Andrew Marlowe portrait',
                2 => $P( 'Andrew Marlowe' ), 3 => $P( 'Founding partner' ),
                4 => 'A note from the founder', 5 => 'A firm should be small enough to care.',
                6 => $P( 'I founded this firm after twenty years in a large one, where clients rarely met the lawyers who worked for them. I wanted to build the opposite.' ),
                7 => $P( 'We keep the firm small on purpose. Each partner takes fewer matters than they could, so that every client gets our full attention and our honest opinion.' ),
                8 => $P( 'If that sounds like the kind of counsel you need, call us. The first conversation is on the house.' ),
                9 => $P( '&mdash; Andrew' ) ] ],
            [ 'proof-press-quotes', [
                0 => 'Recognition', 1 => 'What the directories say',
                2 => $P( 'The Boston Counsel' ), 3 => $P( '&ldquo;A rare boutique that treats clients as people, not matters.&rdquo;' ), 4 => $P( 'Firms to watch, 2025' ),
                5 => $P( 'Legal Quarterly' ), 6 => $P( '&ldquo;Direct, fair and impressively quick to settle.&rdquo;' ), 7 => $P( 'Disputes guide, 2024' ),
                8 => $P( 'The Practitioner' ), 9 => $P( '&ldquo;Clients describe a partner who answers the phone.&rdquo;' ), 10 => $P( 'Boutique ranking, 2024' ) ] ],
            [ 'cta-band', [ 0 => 'Meet the partner who will take your call.', 1 => 'Request a consultation', 2 => $P( 'Thirty minutes, free of charge.' ) ] ],
        ] ],

        'guides' => [ 'title' => 'Client Guides', 'sections' => [
            [ 'header-divider-intro', [
                0 => 'Client guides', 1 => 'Plain answers to the questions people search at midnight',
                2 => $P( 'Short, practical guides written by our partners, updated each year, free to read and share.' ) ] ],
            [ 'features-zigzag', [
                0 => 'Start here', 1 => 'Three guides clients ask for most',
                2 => $P( 'Each takes about ten minutes to read and ends with a checklist.' ),
                3 => 'px:9572664', 4 => 'A wall of law books in dark wooden shelves under white lamps',
                5 => 'What to do when a business partner goes quiet', 6 => $P( 'The first steps to protect your position, your records and your relationships before things escalate.' ), 7 => $P( '<a href="#">Read the guide</a>' ),
                8 => 'Settlement agreements, explained', 9 => $P( 'What the words mean, what to negotiate and what to check before you sign anything on your way out.' ), 10 => $P( '<a href="#">Read the guide</a>' ),
                11 => 'px:8112153', 12 => 'A lawyer in a suit seated at a wooden desk talking to two clients',
                13 => 'px:9572540', 14 => 'Two colleagues reviewing papers at a table in a wood-panelled library',
                15 => 'A first meeting with a divorce lawyer', 16 => $P( 'What to expect, what to bring and the questions worth asking, without the jargon or the drama.' ), 17 => $P( '<a href="#">Read the guide</a>' ) ] ],
            [ 'faq-toggle-list', [
                0 => 'Everyday law', 1 => 'Questions we are often asked',
                2 => 'Do I need a will if I own very little?', 3 => $P( 'Yes, particularly if you have children, a partner you are not married to or a business. It takes an hour.' ),
                4 => 'Can my employer change my contract?', 5 => $P( 'Only with your agreement, or on proper notice in limited cases. Keep a copy of every version.' ),
                6 => 'What is mediation?', 7 => $P( 'A confidential meeting with a neutral expert who helps both sides reach an agreement. It succeeds in most cases.' ),
                8 => 'How long do I have to bring a claim?', 9 => $P( 'It depends on the claim, but often as little as one to three years. Speak to us early.' ),
                10 => 'Can I represent myself?', 11 => $P( 'You can, but you rarely should for anything with real money or children at stake.' ),
                12 => $P( 'Another question? Write to advice@marlowefinch.com.' ) ] ],
            [ 'cta-band', [ 0 => 'Not sure where to start?', 1 => 'Speak to a partner', 2 => $P( 'A thirty-minute call is on us.' ) ] ],
        ] ],

        'contact' => [ 'title' => 'Contact', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Contact' ), 1 => 'Contact the firm',
                2 => $P( 'Send a short note about your matter. A partner will reply within one working day.' ) ] ],
            [ 'contact-details-form', [
                0 => 'Contact', 1 => 'Tell us what has happened.',
                5 => $P( 'advice@marlowefinch.com' ), 9 => $P( '(617) 555-0142' ), 13 => $P( '100 State Street, Suite 910, Boston, MA 02109' ),
                17 => $P( 'Mon to Fri, 8.30am to 6pm. Urgent matters, any time.' ), 18 => 'Send enquiry' ] ],
            [ 'faq-accordion', [
                0 => 'FAQ', 1 => 'Before you get in touch',
                2 => $P( 'Short answers to what clients ask first.' ),
                3 => 'Is my enquiry confidential?', 4 => $P( 'Completely. Anything you tell us is covered by legal privilege from the moment you write.' ),
                5 => 'How soon will someone reply?', 6 => $P( 'A partner replies within one working day, and usually the same morning.' ),
                7 => 'Can we meet by video?', 8 => $P( 'Of course. We meet by video, phone or in person, whichever you prefer.' ),
                9 => 'Should I send documents now?', 10 => $P( 'A short summary is enough at first. We will tell you what else we need.' ),
                11 => 'Is parking available?', 12 => $P( 'Yes. There is a public garage beside our building and we validate tickets.' ),
                13 => 'Do you work evenings and weekends?', 14 => $P( 'By arrangement. We understand some matters cannot wait for Monday.' ),
                15 => $P( 'Prefer to talk? Call (617) 555-0142.' ) ] ],
            [ 'cta-band', [ 0 => 'The first thirty minutes are free.', 1 => 'Request a consultation', 2 => $P( 'Every enquiry is confidential.' ) ] ],
        ] ],
    ],
];
