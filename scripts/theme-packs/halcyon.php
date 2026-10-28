<?php
declare(strict_types=1);

/**
 * Halcyon Dental: a six-page Divi 5 theme pack for a family dental practice in Denver.
 * Design language soft-saas with the soft-rounded kit, recoloured to mint and deep teal and set in Manrope and Nunito.
 * Photographs pinned by Pexels id (every URL checked).
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$Q = static fn ( string $t ): string => '<p>&ldquo;' . $t . '&rdquo;</p>';
$crumb = static fn ( string $page ): string => '<p><a href="#">Home</a> / ' . $page . '</p>';

return [
    'name'     => 'Halcyon Dental',
    'language' => 'soft-saas',
    'kit'      => 'soft-rounded',
    'palette'  => [ 'bg' => '#eaf6f3', 'surface' => '#ffffff', 'text' => '#10343a', 'muted' => '#4c6a6e', 'accent' => '#0f7c80', 'accent_text' => '#ffffff', 'border' => '#cfe6e1', 'inverse' => '#f1faf8', 'overlay' => '#0c2a30' ],
    'fonts'    => [ 'Plus Jakarta Sans' => 'Manrope', 'Figtree' => 'Nunito' ],
    'pages'    => [
        'home' => [ 'title' => 'Home', 'sections' => [
            [ 'hero-split-right', [
                0 => 'Family and cosmetic dentistry',
                1 => 'A dentist you will actually look forward to seeing.',
                2 => $P( 'Gentle, modern dental care for the whole family in Denver. Same-week appointments, honest advice and no lecture about flossing.' ),
                3 => 'Book a visit', 4 => $P( '<a href="#">Meet the team</a>' ),
                5 => 'px:9062525:1400', 6 => 'A bright white dental treatment room with a modern chair and window blinds' ] ],
            [ 'icon-values', [
                0 => $P( 'Why patients stay' ), 1 => $P( 'Three promises we keep at every appointment.' ),
                2 => 'fa:f004', 4 => $P( 'Gentle by default' ), 5 => $P( 'Numbing gel, warm blankets and a pause button you control, for every procedure.' ),
                6 => 'fa:f017', 8 => $P( 'On time' ), 9 => $P( 'We run to schedule, and if we are late we say sorry and take a little off the bill.' ),
                10 => 'fa:f155', 12 => $P( 'Priced up front' ), 13 => $P( 'You see a clear price before any treatment, and nothing is added without your yes.' ) ] ],
            [ 'features-carousel-strip', [
                0 => 'Treatments', 1 => 'Everything under one roof',
                2 => $P( 'From a six-month check-up to a full smile makeover, with the same friendly faces throughout.' ),
                3 => 'fa:f5c9', 5 => 'Check-ups', 6 => $P( 'A thorough look, a clean and honest advice.' ),
                7 => 'fa:f005', 9 => 'Whitening', 10 => $P( 'Up to eight shades brighter in an hour.' ),
                11 => 'fa:f0c8', 13 => 'Invisible braces', 14 => $P( 'Straighter teeth, no metal.' ),
                15 => 'fa:f1ae', 17 => 'Children\'s dentistry', 18 => $P( 'Friendly first visits, from age one.' ),
                19 => 'fa:f0f9', 21 => 'Emergency care', 22 => $P( 'Same-day slots for pain and breaks.' ),
                23 => 'fa:f004', 25 => 'Implants', 26 => $P( 'Permanent replacements that feel real.' ) ] ],
            [ 'testimonials-avatar-grid', [
                0 => 'Patients', 1 => 'Kind words from nervous people',
                2 => $P( 'Three patients on what it was like to finally come back.' ),
                3 => $Q( 'I had not seen a dentist in nine years. They never judged me, and I left with a plan and a smile.' ), 4 => 'px:12405185:800', 5 => 'Mateo portrait', 6 => $P( 'Mateo R.' ), 7 => $P( 'Patient since 2023' ),
                8 => $Q( 'My six-year-old asked when she could go back. I have never heard a child say that about a dentist.' ), 9 => 'px:19332236:800', 10 => 'Hana portrait', 11 => $P( 'Hana T.' ), 12 => $P( 'Parent of two' ),
                13 => $Q( 'Same-day emergency appointment, painless fix and a follow-up call. I would recommend them to anyone.' ), 14 => 'px:37458054:800', 15 => 'David portrait', 16 => $P( 'David K.' ), 17 => $P( 'Emergency patient' ) ] ],
            [ 'cta-split-form', [
                0 => 'New patients', 1 => 'Book your first visit this week.',
                2 => $P( 'Leave your details and our front desk will call you back the same day with a time that suits.' ),
                5 => $P( 'New-patient exam and clean from $89' ), 8 => $P( 'Same-week appointments' ), 11 => $P( 'Insurance claims handled for you' ),
                12 => 'Request a visit', 13 => $P( 'We reply the same working day.' ), 14 => 'Request visit' ] ],
        ] ],

        'treatments' => [ 'title' => 'Treatments', 'sections' => [
            [ 'header-image-band', [
                0 => 'px:305567:1920', 1 => $crumb( 'Treatments' ), 2 => 'Treatments',
                3 => $P( 'Preventive, cosmetic and restorative care, explained in plain English.' ) ] ],
            [ 'features-accordion-image', [
                0 => 'By need', 1 => 'Four reasons people walk through our door',
                2 => 'Keeping teeth healthy', 3 => $P( 'Six-monthly check-ups, a thorough clean and clear advice on brushing, diet and gums.' ),
                4 => 'Fixing a problem', 5 => $P( 'Fillings, crowns and root treatment, with numbing that works and a calm, unhurried pace.' ),
                6 => 'Improving a smile', 7 => $P( 'Whitening, veneers and bonding, planned with before-and-after previews on screen.' ),
                8 => 'Straightening teeth', 9 => $P( 'Clear aligners and traditional braces for children, teens and adults.' ),
                10 => 'px:6812463', 11 => 'A white dental room with an overhead lamp and instrument tray' ] ],
            [ 'list-icon-dividers', [
                0 => 'Included', 1 => 'What a new-patient visit includes',
                2 => $P( 'Sixty unhurried minutes, a full examination and a plan you understand.' ),
                3 => 'fa:f002', 5 => 'A full examination', 6 => $P( 'Teeth, gums, jaw and a screening for oral cancer.' ),
                7 => 'fa:f03e', 9 => 'Digital X-rays', 10 => $P( 'Low-dose images shown on screen so you can see what we see.' ),
                11 => 'fa:f5c9', 13 => 'A professional clean', 14 => $P( 'Scale and polish, with a stain removal if you want it.' ),
                15 => 'fa:f15c', 17 => 'A written plan', 18 => $P( 'Priorities, prices and timing, so there are no surprises.' ),
                19 => 'fa:f004', 21 => 'A calm conversation', 22 => $P( 'Questions answered without a lecture.' ) ] ],
            [ 'faq-two-column', [
                0 => 'Treatment questions', 1 => 'Things people ask before they sit down',
                2 => $P( 'Short answers from our dentists.' ), 3 => 'Ask a question',
                4 => 'Will it hurt?', 5 => $P( 'Almost never. We numb thoroughly, go slowly and stop the moment you raise a hand.' ),
                6 => 'How long does whitening last?', 7 => $P( 'Usually one to two years, depending on coffee, tea and red wine. A top-up kit is included.' ),
                8 => 'Are invisible braces for everyone?', 9 => $P( 'Most adults and teens, yes. A short scan tells us whether they will work for you.' ),
                10 => 'Do you treat anxious patients?', 11 => $P( 'Every day. We offer headphones, breaks and gentle sedation if you need it.' ),
                12 => 'Can I bring my child at age one?', 13 => $P( 'Please do. A first look at one is quick, fun and prevents trouble later.' ),
                14 => 'Do you offer payment plans?', 15 => $P( 'Yes. Spread the cost over three to twelve months with no interest on most treatments.' ) ] ],
            [ 'cta-band', [ 0 => 'Ready for a calmer dental visit?', 1 => 'Book a visit', 2 => $P( 'Same-week appointments.' ) ] ],
        ] ],

        'membership' => [ 'title' => 'Membership', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Membership' ), 1 => 'Membership plan',
                2 => $P( 'No insurance? Join our plan and pay one simple fee for your routine care.' ) ] ],
            [ 'pricing-two-plan-highlight', [
                0 => 'Plans', 1 => 'Two ways to cover your routine care',
                2 => $P( 'Both include two check-ups, two cleans and all the X-rays you need each year.' ),
                3 => $P( 'Adult' ), 4 => 'Adult plan', 5 => $P( '$34' ), 6 => $P( 'Per month, billed yearly' ),
                9 => $P( 'Two check-ups a year' ), 12 => $P( 'Two cleans a year' ), 15 => $P( 'All routine X-rays' ), 18 => $P( 'Ten percent off treatment' ), 21 => $P( 'Emergency exam included' ), 22 => 'Join as an adult',
                23 => $P( 'Best for families' ), 24 => 'Family plan', 25 => $P( '$79' ), 26 => $P( 'Per month, up to four people' ),
                29 => $P( 'Everything in the adult plan' ), 32 => $P( 'Children\'s check-ups and sealants' ), 35 => $P( 'Fifteen percent off treatment' ), 38 => $P( 'Fluoride varnish for kids' ), 41 => $P( 'Free whitening top-up kit' ), 42 => 'Join as a family' ] ],
            [ 'pricing-comparison-table', [
                0 => 'Compare', 1 => 'What is included in each plan', 2 => $P( 'Prices are per person unless stated. Cancel any time after the first year.' ),
                4 => $P( 'Pay as you go' ), 5 => $P( 'From $89' ), 6 => $P( 'Best value' ), 7 => $P( 'Adult plan' ), 8 => $P( '$34 / mo' ), 9 => $P( 'Family plan' ), 10 => $P( '$79 / mo' ),
                11 => $P( 'Check-ups' ), 18 => $P( 'Cleans' ), 22 => $P( 'X-rays' ), 28 => $P( 'Whitening kit' ), 33 => $P( 'Treatment discount' ),
                19 => $P( '1' ), 20 => $P( '2' ), 21 => $P( '2' ), 38 => 'Choose a plan' ] ],
            [ 'compare-two-panels', [
                0 => 'The difference', 1 => 'Insurance and a membership plan',
                2 => $P( 'Both are fine. Here is how to choose.' ),
                3 => 'Insurance', 4 => 'Good if your employer pays',
                7 => $P( 'Monthly premium on top of fees' ), 10 => $P( 'Annual limits and waiting periods' ), 13 => $P( 'Forms and approvals before treatment' ), 16 => $P( 'Best for large, planned treatments' ),
                17 => 'Our plan', 18 => 'Good if you pay yourself',
                21 => $P( 'One fixed monthly fee' ), 24 => $P( 'No limits, no waiting periods' ), 27 => $P( 'Book and be seen the same week' ), 30 => $P( 'Best for routine care and families' ),
                31 => 'See the plan' ] ],
            [ 'cta-band', [ 0 => 'Join the plan and book your first visit.', 1 => 'Join the plan', 2 => $P( 'Your first check-up is included.' ) ] ],
        ] ],

        'team' => [ 'title' => 'The Team', 'sections' => [
            [ 'header-divider-intro', [
                0 => 'The team', 1 => 'Small team, long relationships',
                2 => $P( 'Four dentists, six hygienists and a front desk that remembers your children\'s names.' ) ] ],
            [ 'team-grid', [
                0 => 'The dentists', 1 => 'The people looking after you',
                2 => $P( 'Every dentist works across the practice, so you always see a familiar face.' ),
                3 => 'px:5355860:800', 4 => 'Dr Elena Petrova portrait', 5 => 'Dr Elena Petrova', 6 => $P( 'Principal dentist' ),
                7 => 'px:37458054:800', 8 => 'Dr Marcus Bell portrait', 9 => 'Dr Marcus Bell', 10 => $P( 'Restorative dentist' ),
                11 => 'px:19332236:800', 12 => 'Dr Lin Zhao portrait', 13 => 'Dr Lin Zhao', 14 => $P( 'Children\'s dentist' ),
                15 => 'px:12405185:800', 16 => 'Dr Mateo Silva portrait', 17 => 'Dr Mateo Silva', 18 => $P( 'Orthodontics' ) ] ],
            [ 'founder-note', [
                0 => 'px:40436465:1000', 1 => 'Dr Alicia Fernandez portrait',
                2 => $P( 'Dr Alicia Fernandez' ), 3 => $P( 'Founder and clinical director' ),
                4 => 'A note from the founder', 5 => 'Dentistry should not feel like an ordeal.',
                6 => $P( 'I started Halcyon after years of watching patients delay care because they were afraid. I wanted a practice where nobody feels embarrassed, rushed or judged.' ),
                7 => $P( 'So we slow down. We explain every step, check you are comfortable and never recommend anything we would not do for our own family.' ),
                8 => $P( 'Come in for a visit and see whether we are the right fit. We would love to meet you.' ),
                9 => $P( '&mdash; Alicia' ) ] ],
            [ 'stats-big-numbers', [
                0 => 'In numbers', 1 => 'A practice built on repeat visits',
                2 => $P( '14,000' ), 3 => $P( 'patients cared for since 2012' ),
                4 => $P( '97%' ), 5 => $P( 'would recommend us to a friend' ),
                6 => $P( '9 days' ), 7 => $P( 'longest wait for a new-patient visit' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:6809642:1920', 1 => 'Join the team',
                2 => 'We are hiring a hygienist and a front-desk lead.',
                3 => $P( 'Four-day weeks, paid training and a team that really does eat lunch together.' ),
                4 => 'See roles' ] ],
        ] ],

        'patients' => [ 'title' => 'New Patients', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'New patients' ), 1 => 'New patients',
                2 => $P( 'Everything you need to know before your first visit, in one place.' ) ] ],
            [ 'steps-numbered', [
                0 => 'Your first visit', 1 => 'Three steps to a calmer appointment',
                2 => $P( 'About an hour, start to finish, with time for questions.' ),
                4 => 'Book online or by phone', 5 => $P( 'Choose a time that suits you. We will text a reminder and a short form to fill in.' ),
                7 => 'Meet your dentist', 8 => $P( 'A relaxed chat, a full examination and X-rays if needed, shown on screen.' ),
                10 => 'Leave with a plan', 11 => $P( 'A clear written plan with prices and priorities, and no pressure to book anything today.' ) ] ],
            [ 'checklist-two-column', [
                0 => 'Before you come', 1 => 'What to bring to your first visit',
                2 => $P( 'A short list that makes the paperwork painless.' ),
                5 => $P( 'Photo identification' ), 8 => $P( 'Insurance card, if you have one' ),
                11 => $P( 'A list of any medicines you take' ), 14 => $P( 'Names of previous dentists' ),
                17 => $P( 'Any recent X-rays, if you can' ), 20 => $P( 'Questions you would like answered' ),
                23 => $P( 'A friend, if you feel nervous' ), 26 => $P( 'Comfortable clothes and your favourite playlist' ) ] ],
            [ 'timeline-vertical', [
                0 => 'After the visit', 1 => 'What happens next',
                2 => $P( 'We stay in touch, gently.' ),
                3 => 'Same day', 4 => 'Your written plan', 5 => $P( 'Emailed within the hour, with priorities and costs.' ),
                6 => 'One week', 7 => 'A check-in call', 8 => $P( 'A short call to see how you are feeling and answer anything new.' ),
                9 => 'Six months', 10 => 'A friendly reminder', 11 => $P( 'A text when your check-up is due, with the next available times.' ),
                12 => 'Every year', 13 => 'A review of your plan', 14 => $P( 'We look again at your goals, your costs and anything we could do better.' ) ] ],
            [ 'faq-toggle-list', [
                0 => 'FAQ', 1 => 'First-visit questions',
                2 => 'How long is the first appointment?', 3 => $P( 'About sixty minutes, including the examination, X-rays and a clean where appropriate.' ),
                4 => 'Do you take my insurance?', 5 => $P( 'We work with most major plans and file claims for you. Ask us when you book.' ),
                6 => 'What if I am very anxious?', 7 => $P( 'Tell us when you book. We will plan extra time, go slowly and offer sedation if helpful.' ),
                8 => 'Can I come with my family?', 9 => $P( 'Yes. We often book back-to-back slots so a whole family can come together.' ),
                10 => 'Do you see emergencies?', 11 => $P( 'Yes. Call before ten and we will see you the same day.' ),
                12 => $P( 'Another question? Write to hello@halcyondental.com.' ) ] ],
        ] ],

        'contact' => [ 'title' => 'Contact', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Contact' ), 1 => 'Contact us',
                2 => $P( 'Book a visit, ask a question or tell us about an emergency. We answer the same day.' ) ] ],
            [ 'contact-details-form', [
                0 => 'Contact', 1 => 'How can we help?',
                5 => $P( 'hello@halcyondental.com' ), 9 => $P( '(303) 555-0119' ), 13 => $P( '2201 Larimer Street, Denver, CO 80205' ),
                17 => $P( 'Mon to Thu 8am to 6pm, Fri 8am to 2pm. Emergencies, any day.' ), 18 => 'Send message' ] ],
            [ 'faq-accordion', [
                0 => 'FAQ', 1 => 'Before you write',
                2 => $P( 'Short answers to what patients ask first.' ),
                3 => 'Is there parking?', 4 => $P( 'Yes. Free spaces behind the building and a bike rack by the door.' ),
                5 => 'How soon can I be seen?', 6 => $P( 'Most new patients are seen within a week. Emergencies, the same day.' ),
                7 => 'Do you offer evening appointments?', 8 => $P( 'Tuesday evenings until 7:30pm, and one Saturday morning a month.' ),
                9 => 'Can I book for my child?', 10 => $P( 'Of course. Children are welcome from their first birthday.' ),
                11 => 'Is the practice accessible?', 12 => $P( 'Yes. We are step-free throughout, with an accessible bathroom.' ),
                13 => 'What forms of payment do you accept?', 14 => $P( 'Cards, bank transfer, insurance and our monthly payment plans.' ),
                15 => $P( 'Prefer to talk? Call (303) 555-0119.' ) ] ],
            [ 'cta-band', [ 0 => 'Come in for a gentle first visit.', 1 => 'Book a visit', 2 => $P( 'Same-week appointments.' ) ] ],
        ] ],
    ],
];
