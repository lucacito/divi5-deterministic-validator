<?php
declare(strict_types=1);

/**
 * Kiln & Clay: a six-page Divi 5 theme pack for a pottery studio, shop and class space in Asheville.
 * Design language organic-warm with the rimmed-solid kit, recoloured to oatmeal, charcoal and terracotta and set in
 * DM Serif Display and Work Sans. Photographs pinned by Pexels id (every URL checked).
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$Q = static fn ( string $t ): string => '<p>&ldquo;' . $t . '&rdquo;</p>';
$crumb = static fn ( string $page ): string => '<p><a href="#">Home</a> / ' . $page . '</p>';

return [
    'name'     => 'Kiln & Clay',
    'language' => 'organic-warm',
    'kit'      => 'rimmed-solid',
    'palette'  => [ 'bg' => '#efe6d8', 'surface' => '#faf5ec', 'text' => '#2b2622', 'muted' => '#6a5d51', 'accent' => '#a8481f', 'accent_text' => '#ffffff', 'border' => '#d6c7b0', 'inverse' => '#f7f0e3', 'overlay' => '#241d17' ],
    'fonts'    => [ 'Fraunces' => 'DM Serif Display', 'DM Sans' => 'Work Sans' ],
    'pages'    => [
        'home' => [ 'title' => 'Home', 'sections' => [
            [ 'hero-cta', [
                0 => 'px:37078917:1920', 1 => 'Kiln & Clay · Asheville, North Carolina',
                2 => 'Pots made by hand, and by you.',
                3 => 'Book a class' ] ],
            [ 'icon-values', [
                0 => $P( 'What we do' ), 1 => $P( 'A working studio, a shop and a room full of wheels, open to anyone who wants to get their hands dirty.' ),
                2 => 'fa:f19d', 4 => $P( 'Classes' ), 5 => $P( 'Six-week courses and one-off evenings for complete beginners and people returning to the wheel.' ),
                6 => 'fa:f0f4', 8 => $P( 'The shop' ), 9 => $P( 'Mugs, bowls and vases made by our potters and by twenty independent makers from the mountains.' ),
                10 => 'fa:f015', 12 => $P( 'Studio membership' ), 13 => $P( 'A shelf, a wheel and the use of three kilns, for anyone who wants to practise between classes.' ) ] ],
            [ 'features-bento', [
                0 => 'Made here', 1 => 'Everything starts with a ball of clay',
                2 => $P( 'Four ways to spend time with our potters, from an afternoon to a whole year.' ),
                3 => $P( '<a href="#">See all offerings</a>' ),
                4 => 'px:30283531', 5 => 'Hands shaping a terracotta vase on a wheel',
                6 => 'Wheel throwing', 7 => $P( 'Learn to centre, open and pull a cylinder in a small group, with a potter at your elbow all session.' ),
                8 => 'fa:f1fc', 10 => 'Handbuilding', 11 => $P( 'Pinch pots, slabs and coils for beginners.' ),
                12 => 'fa:f06c', 14 => 'Glazing', 15 => $P( 'Colour, texture and a lot of surprises.' ),
                16 => 'Date night', 17 => $P( 'Clay, wine and a very kind teacher.' ),
                18 => 'Private parties',
                19 => $P( 'Birthdays, hen parties and team days in the studio, with all materials and a potter included.' ),
                20 => $P( '<a href="#">Plan a party</a>' ) ] ],
            [ 'before-after-compare', [
                0 => 'Raw and finished', 1 => 'From grey clay to a glazed bowl',
                2 => $P( 'A single bowl takes about five weeks, and four visits, to complete.' ),
                3 => $P( 'Raw' ), 4 => $P( 'A pale, fragile bowl, dried and ready for its first firing.' ),
                5 => $P( 'Glazed' ), 6 => $P( 'Fired twice, glazed in teal and ready for the table.' ),
                7 => 'px:6611417', 8 => 'Pale unglazed bowls and jugs on a shelf',
                9 => 'px:28271468', 10 => 'Teal glazed dishes and fish-shaped plates seen from above' ] ],
            [ 'testimonials-avatar-grid', [
                0 => 'Students', 1 => 'What it is like to make something',
                2 => $P( 'Three students on their first term at the wheel.' ),
                3 => $Q( 'I came for one evening and have not missed a Thursday since. My cupboard is full of wonky mugs and I love every one.' ), 4 => 'px:8727416:800', 5 => 'Ellen portrait', 6 => $P( 'Ellen R.' ), 7 => $P( 'Six-week course' ),
                8 => $Q( 'The teachers notice everything and never make you feel silly. I finally made a bowl I would be proud to serve in.' ), 9 => 'px:26834972:800', 10 => 'Tomás portrait', 11 => $P( 'Tomás G.' ), 12 => $P( 'Studio member' ),
                13 => $Q( 'I booked a date night as a joke. We went back four times. It is the calmest two hours of my week.' ), 14 => 'px:19332236:800', 15 => 'Yuna portrait', 16 => $P( 'Yuna K.' ), 17 => $P( 'Date night regular' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:37827264:1920', 1 => 'Come and make something',
                2 => 'Your first class is a moment of quiet.',
                3 => $P( 'No experience, no equipment and no judgement. Aprons, tea and a teacher are provided.' ),
                4 => 'See the classes' ] ],
        ] ],

        'classes' => [ 'title' => 'Classes', 'sections' => [
            [ 'header-image-band', [
                0 => 'px:33901300:1920', 1 => $crumb( 'Classes' ), 2 => 'Classes and workshops',
                3 => $P( 'Small groups, patient teachers and a very good kettle.' ) ] ],
            [ 'pricing-comparison-table', [
                0 => 'Compare', 1 => 'Pick the class that suits you', 2 => $P( 'All prices include clay, glazes, firing, tea and an apron. Groups are never larger than eight.' ),
                4 => $P( 'One evening' ), 5 => $P( '$65' ), 6 => $P( 'Most loved' ), 7 => $P( 'Six weeks' ), 8 => $P( '$285' ), 9 => $P( 'Studio year' ), 10 => $P( '$1,150' ),
                11 => $P( 'Wheel or handbuilding' ), 18 => $P( 'Pieces glazed and fired' ), 22 => $P( 'Open studio time' ), 28 => $P( 'Own shelf and wheel' ), 33 => $P( 'Kiln access' ),
                19 => $P( '1' ), 20 => $P( '6' ), 21 => $P( 'Unlimited' ), 38 => 'Book a class' ] ],
            [ 'steps-horizontal', [
                0 => 'How a course works', 1 => 'Six weeks, one finished set',
                2 => $P( 'A rhythm that lets clay dry, fire and glaze while you go home to sleep.' ),
                4 => 'Centre', 5 => $P( 'Weeks one and two: wedging, centring and pulling your first cylinders.' ),
                7 => 'Shape', 8 => $P( 'Week three: handles, rims and trimming, with a lot of laughing.' ),
                10 => 'Glaze', 11 => $P( 'Week four: choose your colours and paint, dip and pour.' ),
                13 => 'Collect', 14 => $P( 'Week six: your fired pieces are ready, with a small party to celebrate.' ),
                15 => 'See the dates' ] ],
            [ 'features-accordion-image', [
                0 => 'Good to know', 1 => 'What other students ask us',
                2 => 'Do I need experience?', 3 => $P( 'Not at all. Most of our students have never touched clay. We start at the very beginning.' ),
                4 => 'What should I wear?', 5 => $P( 'Clothes you do not mind getting dirty. We lend aprons, but clay finds a way.' ),
                6 => 'How long does firing take?', 7 => $P( 'About three weeks, because pots have to dry slowly and fire twice. We will text when yours are ready.' ),
                8 => 'Can I take pieces home on the day?', 9 => $P( 'Only in your head. Clay needs time, so your pieces are collected two to three weeks later.' ),
                10 => 'px:33912323', 11 => 'Hands rolling out clay on a table beside a cup' ] ],
            [ 'cta-split-form', [
                0 => 'Private groups', 1 => 'Book a clay party for your group.',
                2 => $P( 'Birthdays, team days and hen parties, from six to sixteen guests, in the studio or at your venue.' ),
                5 => $P( 'A potter for the whole session' ), 8 => $P( 'All materials and firing included' ), 11 => $P( 'Tea, cake and a very messy table' ),
                12 => 'Tell us about your group', 13 => $P( 'We reply within one working day.' ), 14 => 'Send request' ] ],
        ] ],

        'shop' => [ 'title' => 'The Shop', 'sections' => [
            [ 'header-divider-intro', [
                0 => 'The shop', 1 => 'Pieces made for everyday use',
                2 => $P( 'Mugs, bowls, vases and plates made by our potters and by twenty makers from the Blue Ridge, all of them food-safe and dishwasher-friendly.' ) ] ],
            [ 'portfolio-featured-grid', [
                0 => 'Current collection', 1 => 'Five pieces we would keep', 2 => 'Shop everything',
                3 => 'px:6611426', 4 => 'White speckled mugs and bowls on a dark tabletop', 5 => 'Speckle mugs', 6 => $P( 'Set of four · $96' ),
                7 => 'px:3847482', 8 => 'Teal, black and white plates and bowls seen from above', 9 => 'Tide dinner set', 10 => $P( 'Four place settings · $320' ),
                11 => 'px:12181252', 12 => 'Yellow ceramic mugs and bowls on a wooden tray', 13 => 'Marigold set', 14 => $P( 'Mug and bowl · $58' ),
                15 => 'px:8063803', 16 => 'Rows of cream stoneware mugs and tall vases on a workbench', 17 => 'Oatmeal stoneware', 18 => $P( 'Mugs from $28' ),
                19 => 'px:6611187', 20 => 'Cream and charcoal-dipped mugs on a studio shelf', 21 => 'Dipped mugs', 22 => $P( 'Set of two · $52' ) ] ],
            [ 'checklist-two-column', [
                0 => 'Care and delivery', 1 => 'What comes with every piece',
                2 => $P( 'Handmade does not have to be fragile.' ),
                5 => $P( 'Food-safe, lead-free glazes' ), 8 => $P( 'Microwave and dishwasher safe' ),
                11 => $P( 'Packed in recycled paper' ), 14 => $P( 'Free delivery over $75' ),
                17 => $P( 'Gift notes, handwritten' ), 20 => $P( 'Free replacement if it breaks in transit' ),
                23 => $P( 'A care card with every order' ), 26 => $P( 'A potter\'s thumbprint, if you look closely' ) ] ],
            [ 'faq-toggle-list', [
                0 => 'Shop questions', 1 => 'Before you buy',
                2 => 'Are the pieces dishwasher safe?', 3 => $P( 'Almost everything is. Pieces that need hand-washing are marked on the care card.' ),
                4 => 'Can you make a custom set?', 5 => $P( 'Yes. Commissions take four to six weeks and start at $150. Write to us with your idea.' ),
                6 => 'Do you ship internationally?', 7 => $P( 'We ship to Canada and the UK. Other countries on request, with a carefully packed box.' ),
                8 => 'What is your returns policy?', 9 => $P( 'If you do not love a piece, send it back within thirty days for a full refund.' ),
                10 => 'Do you sell gift cards?', 11 => $P( 'Yes, for the shop or for classes. They never expire.' ),
                12 => $P( 'Another question? Write to hello@kilnandclay.com.' ) ] ],
        ] ],

        'membership' => [ 'title' => 'Studio Membership', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Studio membership' ), 1 => 'Studio membership',
                2 => $P( 'A shelf, a wheel and three kilns, for anyone who wants to keep making.' ) ] ],
            [ 'pricing-three-tier', [
                0 => 'Membership', 1 => 'Choose your hours at the wheel', 2 => $P( 'All memberships include clay at cost, access to three kilns and a place in our Thursday potluck.' ),
                3 => 'Weekend', 4 => $P( 'Saturdays and Sundays.' ), 5 => $P( '$110' ), 6 => $P( 'per month' ),
                9 => $P( 'Eight hours a month' ), 12 => $P( 'A shelf for your pieces' ), 15 => $P( 'Wheel booking app' ), 16 => 'Join the studio',
                17 => $P( 'Most popular' ), 18 => 'Open studio', 19 => $P( 'Any day, any time we are open.' ), 20 => $P( '$185' ), 21 => $P( 'per month' ),
                24 => $P( 'Twenty-five hours a month' ), 27 => $P( 'A shelf and a locker' ), 30 => $P( 'Two firings included' ), 31 => 'Join the studio',
                32 => 'Resident', 33 => $P( 'For working potters.' ), 34 => $P( '$320' ), 35 => $P( 'per month' ),
                38 => $P( 'Unlimited access, day and night' ), 41 => $P( 'Your own wheel and bay' ), 44 => $P( 'Sell in our shop at 30%' ), 45 => 'Apply' ] ],
            [ 'team-grid', [
                0 => 'The potters', 1 => 'The people who run the studio',
                2 => $P( 'Four potters, one studio manager and a very tolerant kiln.' ),
                3 => 'px:30479361:800', 4 => 'Hazel Thorne portrait', 5 => 'Hazel Thorne', 6 => $P( 'Founder and head potter' ),
                7 => 'px:4687549:800', 8 => 'Marcus Webb portrait', 9 => 'Marcus Webb', 10 => $P( 'Glaze and kilns' ),
                11 => 'px:31241969:800', 12 => 'Leila Karimi portrait', 13 => 'Leila Karimi', 14 => $P( 'Teaching' ),
                15 => 'px:26834972:800', 16 => 'Jonah Park portrait', 17 => 'Jonah Park', 18 => $P( 'Shop and studio manager' ) ] ],
            [ 'cta-band', [ 0 => 'Come and see the studio.', 1 => 'Book a tour', 2 => $P( 'Free, any Saturday at ten.' ) ] ],
        ] ],

        'about' => [ 'title' => 'Our Story', 'sections' => [
            [ 'hero-editorial-minimal', [
                0 => 'Kiln & Clay · Since 2016',
                1 => 'We believe in slow things and wobbly mugs.',
                2 => $P( 'Kiln & Clay began in a garage with one second-hand wheel and a borrowed kiln. Today it is a studio of fourteen wheels, three kilns and a shop that has quietly become a neighbourhood meeting place.' ),
                3 => $P( '<a href="#">Read our story</a>' ) ] ],
            [ 'story-timeline', [
                0 => 'px:36731519:1920', 1 => 'Our story', 2 => 'Nine years of getting clay under our nails',
                3 => $P( 'A garage, a kiln and a lot of tea.' ),
                4 => $P( '2016' ), 5 => 'One wheel', 6 => $P( 'Hazel throws her first mugs in her father\'s garage and sells them at the farmers\' market.' ),
                7 => $P( '2019' ), 8 => 'The studio', 9 => $P( 'We move into an old paint factory on Haywood Road, with space for a dozen wheels.' ),
                10 => $P( '2022' ), 11 => 'The shop opens', 12 => $P( 'We start selling work by local makers and the shop quickly outgrows its corner.' ),
                13 => $P( '2025' ), 14 => 'Fourteen wheels', 15 => $P( 'Four hundred students a year and a waiting list for Thursday nights.' ) ] ],
            [ 'founder-note', [
                0 => 'px:30479361:1000', 1 => 'Hazel Thorne portrait',
                2 => $P( 'Hazel Thorne' ), 3 => $P( 'Founder and head potter' ),
                4 => 'A note from the wheel', 5 => 'Clay teaches patience, and kindness.',
                6 => $P( 'I started Kiln & Clay because I wanted a place where people could make something with their hands and not worry about being good at it. Clay does not care. It rewards attention.' ),
                7 => $P( 'We keep our classes small and our teachers kind. Mistakes are the whole curriculum, and most of our best pieces began as a disaster.' ),
                8 => $P( 'Come and make one. We will put the kettle on.' ),
                9 => $P( '&mdash; Hazel' ) ] ],
            [ 'values-list', [
                0 => 'How we work', 1 => 'Four principles by the kiln',
                3 => 'Make it useful', 4 => $P( 'Every piece should be happy on a table, in a hand or on a shelf. Beauty and use belong together.' ),
                6 => 'Welcome everyone', 7 => $P( 'No experience, no judgement and no minimum standard. Everyone makes something good enough to keep.' ),
                9 => 'Fire slowly', 10 => $P( 'Pots need time to dry and kilns need time to cool. We rush nothing that can crack.' ),
                12 => 'Share the studio', 13 => $P( 'The studio belongs to a community. Members teach each other, trade glazes and share a potluck every Thursday.' ) ] ],
        ] ],

        'visit' => [ 'title' => 'Visit', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Visit' ), 1 => 'Visit the studio',
                2 => $P( 'Find us at the old paint factory on Haywood Road. Look for the red door and the pots on the step.' ) ] ],
            [ 'contact-details-form', [
                0 => 'Contact', 1 => 'Come and see us, or send a note.',
                5 => $P( 'hello@kilnandclay.com' ), 9 => $P( '(828) 555-0146' ), 13 => $P( '118 Haywood Road, Asheville, NC 28806' ),
                17 => $P( 'Wed to Sun, 10am to 6pm. Classes in the evenings.' ), 18 => 'Send message' ] ],
            [ 'faq-accordion', [
                0 => 'FAQ', 1 => 'Before you visit',
                2 => $P( 'Short answers to what visitors ask first.' ),
                3 => 'Is there parking?', 4 => $P( 'Free parking behind the building, and a bus stop outside on Haywood Road.' ),
                5 => 'Can I just drop in?', 6 => $P( 'Of course. The shop is open to everyone, and we often have a wheel free for a quick try.' ),
                7 => 'Are children welcome?', 8 => $P( 'Children over eight can join most classes. We also run Saturday morning sessions for families.' ),
                9 => 'Is the studio accessible?', 10 => $P( 'The shop and one wheel station are step-free. Please tell us about any needs and we will plan around them.' ),
                11 => 'Do you offer gift vouchers?', 12 => $P( 'Yes, for classes and the shop, in any amount.' ),
                13 => 'Can I commission a piece?', 14 => $P( 'Yes. Talk to Hazel at the shop or write to us with a few ideas.' ),
                15 => $P( 'Prefer to talk? Call (828) 555-0146.' ) ] ],
            [ 'cta-band', [ 0 => 'The kettle is always on.', 1 => 'Book a class', 2 => $P( 'Open Wednesday to Sunday.' ) ] ],
        ] ],
    ],
];
