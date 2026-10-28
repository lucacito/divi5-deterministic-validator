<?php
declare(strict_types=1);

/**
 * Fernhill Botanicals: a six-page Divi 5 theme pack for a neighbourhood plant shop and florist in Portland.
 * Design language organic-warm with the soft-solid kit, recoloured to sage and rose and set in Newsreader and Karla.
 * Photographs pinned by Pexels id (every URL checked).
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$Q = static fn ( string $t ): string => '<p>&ldquo;' . $t . '&rdquo;</p>';
$crumb = static fn ( string $page ): string => '<p><a href="#">Home</a> / ' . $page . '</p>';
$tier = static function ( string $eyebrow, string $h, string $intro, array $a, array $b, array $c ) use ( $P ): array {
    $copy = [ 0 => $eyebrow, 1 => $h, 2 => $P( $intro ), 17 => $P( 'Most loved' ) ];
    foreach ( [ [ $a, 3 ], [ $b, 18 ], [ $c, 32 ] ] as [ $x, $o ] ) {
        $copy += [ $o => $x[0], $o + 1 => $P( $x[1] ), $o + 2 => $P( $x[2] ), $o + 3 => $P( $x[3] ), $o + 6 => $P( $x[4] ), $o + 9 => $P( $x[5] ), $o + 12 => $P( $x[6] ), $o + 13 => $x[7] ];
    }
    return $copy;
};

return [
    'name'     => 'Fernhill Botanicals',
    'language' => 'organic-warm',
    'kit'      => 'soft-solid',
    'palette'  => [ 'bg' => '#eef1e8', 'surface' => '#fbfcf8', 'text' => '#1f2b22', 'muted' => '#566558', 'accent' => '#b83d63', 'accent_text' => '#ffffff', 'border' => '#d4dccb', 'inverse' => '#f6f9f1', 'overlay' => '#18221a' ],
    'fonts'    => [ 'Fraunces' => 'Newsreader', 'DM Sans' => 'Karla' ],
    'pages'    => [
        'home' => [ 'title' => 'Home', 'sections' => [
            [ 'hero-split-left-overlap', [
                0 => 'px:9412404:1400', 1 => 'A shop owner in a cream cardigan holding a terracotta pot with a small green plant',
                2 => 'Plant shop and florist',
                3 => 'Bring something green home.',
                4 => $P( 'Fernhill is a neighbourhood plant shop in Portland. We grow, pot and arrange everything we sell, and we are happy to tell you how to keep it alive.' ),
                5 => 'Shop the plants' ] ],
            [ 'features-carousel-strip', [
                0 => 'Under one roof', 1 => 'Plants, flowers and the know-how',
                2 => $P( 'Everything is grown nearby, potted by hand and sold by people who water their own.' ),
                3 => 'fa:f06c', 5 => 'Houseplants', 6 => $P( 'From forgiving beginners to fussy beauties.' ),
                7 => 'fa:f4d8', 9 => 'Cut flowers', 10 => $P( 'Seasonal stems from farms within an hour.' ),
                11 => 'fa:f1b2', 13 => 'Pots and planters', 14 => $P( 'Hand-thrown clay with drainage that works.' ),
                15 => 'fa:f06b', 17 => 'Gift boxes', 18 => $P( 'A small plant, a card and tissue paper.' ),
                19 => 'fa:f19d', 21 => 'Workshops', 22 => $P( 'Terrariums, wreaths and repotting evenings.' ),
                23 => 'fa:f0d1', 25 => 'Local delivery', 26 => $P( 'Same-day by bike inside the city.' ) ] ],
            [ 'gallery-masonry-style', [
                0 => 'Fresh this week', 1 => 'Just in from the greenhouse',
                2 => $P( 'New arrivals land on Thursdays and are usually gone by Sunday.' ),
                3 => 'px:14723067', 4 => 'White shelves of potted plants with a wooden ladder',
                5 => 'px:35981805', 6 => 'Dried flowers and orchids on shelves against a bamboo wall',
                7 => 'px:3664342', 8 => 'A bright plant shop with hanging and shelved plants',
                9 => 'px:9157870', 10 => 'Cacti in terracotta pots on a lit shelf',
                11 => 'px:5409690', 12 => 'A florist wrapping pink roses in paper',
                13 => 'px:1982095', 14 => 'An orange flowering plant and cacti on a windowsill' ] ],
            [ 'testimonials-avatar-grid', [
                0 => 'Kind words', 1 => 'What the neighbourhood says',
                2 => $P( 'A few notes from regulars, mostly left in the shop jar.' ),
                3 => $Q( 'I killed every plant I ever owned until Fernhill. Three years on, my fiddle-leaf has a name.' ), 4 => 'px:7244715:800', 5 => 'Annika portrait', 6 => $P( 'Annika S.' ), 7 => $P( 'Regular since 2019' ),
                8 => $Q( 'They filled our whole venue for less than I feared, and everything was gorgeous and local.' ), 9 => 'px:12871465:800', 10 => 'Ben portrait', 11 => $P( 'Ben O.' ), 12 => $P( 'Wedding flowers' ),
                13 => $Q( 'I came for a terrarium and left with a new hobby. The teacher was endlessly patient.' ), 14 => 'px:4531837:800', 15 => 'Priya portrait', 16 => $P( 'Priya M.' ), 17 => $P( 'Workshop guest' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:36865074:1920', 1 => 'Visit the shop',
                2 => 'Come and walk through the greenhouse.',
                3 => $P( 'Open six days a week. Bring a photo of the corner you want to green and we will help you choose.' ),
                4 => 'Plan your visit' ] ],
        ] ],

        'plants' => [ 'title' => 'The Plants', 'sections' => [
            [ 'header-image-band', [
                0 => 'px:19524853:1920', 1 => $crumb( 'The plants' ), 2 => 'The plants',
                3 => $P( 'Everything we grow, repot and love, sorted by the light it likes.' ) ] ],
            [ 'portfolio-featured-grid', [
                0 => 'Best sellers', 1 => 'Five that practically look after themselves', 2 => 'See every plant',
                3 => 'px:31644650', 4 => 'A spiky aloe in a clay pot on a windowsill', 5 => 'Aloe', 6 => $P( 'Bright light · Water monthly' ),
                7 => 'px:13242093', 8 => 'A round-leaved pilea in a terracotta pot by a window', 9 => 'Chinese money plant', 10 => $P( 'Bright indirect · Water weekly' ),
                11 => 'px:5213571', 12 => 'A blue-green echeveria seen from above in a clay pot', 13 => 'Echeveria', 14 => $P( 'Full sun · Water fortnightly' ),
                15 => 'px:12935672', 16 => 'A leafy palm in a terracotta pot beside a pink curtain', 17 => 'Parlour palm', 18 => $P( 'Low to medium light · Water weekly' ),
                19 => 'px:7663974', 20 => 'A row of small cacti in clay pots on a white windowsill', 21 => 'Cactus trio', 22 => $P( 'Full sun · Water monthly' ) ] ],
            [ 'compare-two-panels', [
                0 => 'Match the light', 1 => 'Dim corner or sunny sill?',
                2 => $P( 'The easiest way to keep a plant happy is to choose one that already likes your room.' ),
                3 => 'Low light', 4 => 'For north windows and hallways',
                7 => $P( 'Parlour palm and snake plant' ), 10 => $P( 'ZZ plant and cast iron plant' ), 13 => $P( 'Pothos and heart-leaf philodendron' ), 16 => $P( 'Peace lily, with a weekly drink' ),
                17 => 'Bright light', 18 => 'For south windows and sun rooms',
                21 => $P( 'Aloe, echeveria and jade' ), 24 => $P( 'Cacti of every shape' ), 27 => $P( 'Citrus and olive trees' ), 30 => $P( 'Bird of paradise, if you have the room' ),
                31 => 'Ask about your room' ] ],
            [ 'faq-two-column', [
                0 => 'Plant questions', 1 => 'Before you carry one home',
                2 => $P( 'Short answers to what people ask us most at the till.' ), 3 => 'Ask in the shop',
                4 => 'Will it survive the journey?', 5 => $P( 'Yes. We wrap every plant in paper and give you a short care card. Cold days get an extra layer.' ),
                6 => 'How often should I water?', 7 => $P( 'Less than you think. Push a finger two knuckles into the soil, and water only when it feels dry.' ),
                8 => 'Are your plants pet safe?', 9 => $P( 'Many are. Ask us and we will tell you which ones are, and which are better kept out of reach.' ),
                10 => 'Do you offer a guarantee?', 11 => $P( 'If a plant fails inside thirty days, bring it back and we will swap it or repot it.' ),
                12 => 'Can you deliver?', 13 => $P( 'Same-day by bike inside the city, and by courier anywhere else, in sturdy plant-friendly boxes.' ),
                14 => 'Do you buy back plants?', 15 => $P( 'We take cuttings and healthy offsets in exchange for shop credit, on Saturday mornings.' ) ] ],
            [ 'cta-band', [ 0 => 'Find the right plant for your room.', 1 => 'Plan your visit', 2 => $P( 'Open Tuesday to Sunday.' ) ] ],
        ] ],

        'workshops' => [ 'title' => 'Workshops', 'sections' => [
            [ 'header-divider-intro', [
                0 => 'Workshops', 1 => 'Learn with your hands in the soil',
                2 => $P( 'Small evening and weekend classes, all materials included, and a plant (or a wreath) to take home.' ) ] ],
            [ 'pricing-three-tier', $tier( 'Tickets', 'Pick a class, bring a friend', 'Every class includes tea, materials and a welcome from the shop dog.',
                [ 'Terrarium', 'Build a tiny living world.', '$58', 'per person', 'Two hours', 'Jar, soil and plants included', 'Up to eight people', 'Book a seat' ],
                [ 'Wreath night', 'Dried flowers and wire.', '$72', 'per person', 'Two and a half hours', 'All stems and a base included', 'Wine or tea included', 'Book a seat' ],
                [ 'Repotting', 'Rescue a struggling plant.', '$34', 'per person', 'One hour', 'Bring your own plant', 'Pots and soil included', 'Book a seat' ] ) ],
            [ 'features-zigzag', [
                0 => 'What you will make', 1 => 'Three things to take home',
                2 => $P( 'Each workshop has a clear finish line, so you leave with something real.' ),
                3 => 'px:5409696', 4 => 'Two florists in aprons arranging flowers in a bright workshop',
                5 => 'A tabletop terrarium', 6 => $P( 'Layer stones, charcoal and soil, then plant mosses and ferns with long tweezers.' ), 7 => $P( '<a href="#">See dates</a>' ),
                8 => 'A winter wreath', 9 => $P( 'Wire, moss and dried stems become a wreath you will want to keep on the door all season.' ), 10 => $P( '<a href="#">See dates</a>' ),
                11 => 'px:35001381', 12 => 'A florist cutting stems at a table of dried autumn flowers',
                13 => 'px:5894095', 14 => 'A florist holding a large pink and peach hand-tied bouquet',
                15 => 'A hand-tied bouquet', 16 => $P( 'Learn the spiral technique florists use, then tie your own bunch of seasonal stems.' ), 17 => $P( '<a href="#">See dates</a>' ) ] ],
            [ 'steps-numbered', [
                0 => 'How it works', 1 => 'Three steps from sign-up to soil',
                2 => $P( 'Classes run most weeks. Seats are limited so everyone gets help.' ),
                4 => 'Choose a class', 5 => $P( 'Pick a date and book online or at the till. Gift vouchers are available.' ),
                7 => 'Turn up and get stuck in', 8 => $P( 'We provide aprons, tools and everything you need. Just bring curiosity.' ),
                10 => 'Take it home', 11 => $P( 'Your creation comes home in a box with a care card and a discount on your next class.' ) ] ],
            [ 'cta-split-form', [
                0 => 'Private events', 1 => 'Book a workshop for your group.',
                2 => $P( 'Birthdays, hen parties and team days for up to twelve guests, in the shop or at your venue.' ),
                5 => $P( 'A tailored class for your group' ), 8 => $P( 'Tea, coffee and cake included' ), 11 => $P( 'Every guest takes something home' ),
                12 => 'Tell us about your event', 13 => $P( 'We reply within one working day.' ), 14 => 'Send request' ] ],
        ] ],

        'care' => [ 'title' => 'Care Guides', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Care guides' ), 1 => 'Care guides',
                2 => $P( 'Light, water and a little patience: how to keep your plants thriving through the year.' ) ] ],
            [ 'features-accordion-image', [
                0 => 'By room', 1 => 'Where your plant will be happiest',
                2 => 'Living room', 3 => $P( 'Large leaves love bright, indirect light. Dust them monthly and turn the pot each week.' ),
                4 => 'Kitchen', 5 => $P( 'Herbs want sun. Ferns enjoy the steam. Keep plants away from the stove and the toaster.' ),
                6 => 'Bathroom', 7 => $P( 'Humidity lovers thrive here. Choose orchids, air plants and ferns, and open the window.' ),
                8 => 'Bedroom', 9 => $P( 'Snake plants and pothos are calm, forgiving companions for lower light and long weekends.' ),
                10 => 'px:9710609', 11 => 'Potted plants along a bright greenhouse windowsill' ] ],
            [ 'timeline-vertical', [
                0 => 'The year', 1 => 'A calendar for plant parents',
                2 => $P( 'What to do, and when, for a healthier shelf.' ),
                3 => 'Spring', 4 => 'Repot and refresh', 5 => $P( 'Move root-bound plants into fresh soil and start feeding every fortnight.' ),
                6 => 'Summer', 7 => 'Water and shade', 8 => $P( 'Check the soil twice a week and move sun-sensitive plants away from hot glass.' ),
                9 => 'Autumn', 10 => 'Slow down', 11 => $P( 'Water less often and stop feeding as the light fades.' ),
                12 => 'Winter', 13 => 'Rest and wait', 14 => $P( 'Keep plants away from radiators and drafts, and be patient until spring.' ) ] ],
            [ 'faq-accordion', [
                0 => 'Troubleshooting', 1 => 'What is wrong with my plant?',
                2 => $P( 'Most problems come down to water or light.' ),
                3 => 'The leaves are yellow', 4 => $P( 'Usually too much water. Let the soil dry out and check that the pot drains freely.' ),
                5 => 'The tips are brown', 6 => $P( 'Dry air or tap water with minerals. Try rainwater, and group plants together.' ),
                7 => 'It is leaning to one side', 8 => $P( 'It is reaching for the light. Turn the pot a quarter each week.' ),
                9 => 'There are tiny flies', 10 => $P( 'Fungus gnats like wet soil. Let the top inch dry and add a layer of grit.' ),
                11 => 'The leaves are dropping', 12 => $P( 'A shock from cold, drafts or a move. Keep conditions steady and it will recover.' ),
                13 => 'Nothing is growing', 14 => $P( 'Probably winter. Many plants rest in low light, so stay calm and wait for spring.' ),
                15 => $P( 'Still stuck? Send us a photo, we reply within a day.' ) ] ],
            [ 'cta-band', [ 0 => 'Bring us a photo of your plant.', 1 => 'Message the shop', 2 => $P( 'We reply to every message.' ) ] ],
        ] ],

        'about' => [ 'title' => 'Our Story', 'sections' => [
            [ 'header-divider-intro', [
                0 => 'Our story', 1 => 'Grown on a corner, shared with the street',
                2 => $P( 'Fernhill began with a windowsill and a stubborn fig. Nine years later it is a greenhouse, a shop and a small team who love their work.' ) ] ],
            [ 'story-timeline', [
                0 => 'px:19524853:1920', 1 => 'Timeline', 2 => 'Nine years of growing',
                3 => $P( 'A short history, with the fig still alive.' ),
                4 => $P( '2016' ), 5 => 'The windowsill', 6 => $P( 'Ada starts selling cuttings from a rented flat, with an honesty box on the step.' ),
                7 => $P( '2018' ), 8 => 'The first shop', 9 => $P( 'A tiny unit on Mill Lane opens with sixty plants and one shelf.' ),
                10 => $P( '2021' ), 11 => 'The greenhouse', 12 => $P( 'We take over a derelict glasshouse and begin growing our own.' ),
                13 => $P( '2025' ), 14 => 'A team of six', 15 => $P( 'Growers, florists and teachers, all sharing one very full watering rota.' ) ] ],
            [ 'founder-note', [
                0 => 'px:6787552:1000', 1 => 'Ada Fernhill portrait',
                2 => $P( 'Ada Fernhill' ), 3 => $P( 'Founder and head grower' ),
                4 => 'A note from the greenhouse', 5 => 'Plants are patient teachers.',
                6 => $P( 'I started Fernhill because a single fig changed how I paid attention. A plant asks only that you notice it, and that is a surprisingly generous thing.' ),
                7 => $P( 'We grow what we can, buy from growers we trust and never sell a plant we would not keep. Everything leaves the shop with a care card and an invitation to ask.' ),
                8 => $P( 'Come in, wander round and tell us about the room you want to green.' ),
                9 => $P( '&mdash; Ada' ) ] ],
            [ 'values-list', [
                0 => 'How we work', 1 => 'Four habits behind the shop',
                3 => 'Grow it ourselves', 4 => $P( 'Half of what we sell starts life in our own greenhouse, a hundred steps from the till.' ),
                6 => 'Choose peat-free', 7 => $P( 'Every pot is filled with peat-free compost, and we reuse and recycle every pot we can.' ),
                9 => 'Teach as we sell', 10 => $P( 'A plant that lives is better than a plant sold, so we explain everything.' ),
                12 => 'Stay local', 13 => $P( 'Flowers from farms within an hour, pots from local potters and deliveries by bike.' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:36865074:1920', 1 => 'Join us',
                2 => 'We are always hiring a good pair of hands.',
                3 => $P( 'Weekend help, workshop assistants and seasonal growers. Pop in with a plant you are proud of.' ),
                4 => 'See openings' ] ],
        ] ],

        'visit' => [ 'title' => 'Visit', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Visit' ), 1 => 'Visit the shop',
                2 => $P( 'Find us on Mill Lane, or send a message and we will get back to you the same day.' ) ] ],
            [ 'contact-location-form', [
                0 => 'px:36865074:1600', 1 => 'Fernhill Botanicals: the greenhouse from the inside',
                5 => $P( '48 Mill Lane, Portland, OR 97214' ), 9 => $P( 'Tue to Sun, 9am to 6pm. Closed Mondays.' ),
                10 => 'Visit or write', 11 => 'Come in, or send us a note.',
                12 => $P( 'Questions, special orders and workshop bookings are answered within a working day.' ), 13 => 'Send message' ] ],
            [ 'faq-toggle-list', [
                0 => 'FAQ', 1 => 'Before you visit',
                2 => 'Is there parking?', 3 => $P( 'Two free spaces at the back, and plenty of street parking on Mill Lane after 5pm.' ),
                4 => 'Can I bring my dog?', 5 => $P( 'Yes. Our shop dog, Moss, loves visitors. Please keep yours on a lead among the plants.' ),
                6 => 'Do you do gift vouchers?', 7 => $P( 'We do, in any amount, on card or by email. They never expire.' ),
                8 => 'Are the workshops suitable for children?', 9 => $P( 'Children over eight are welcome with an adult. We also run family terrarium mornings.' ),
                10 => 'Can I order flowers for a funeral or wedding?', 11 => $P( 'Of course. Call us on (503) 555-0126 and we will talk through what you need.' ),
                12 => $P( 'Another question? Write to hello@fernhillbotanicals.com.' ) ] ],
            [ 'cta-band', [ 0 => 'Come and say hello.', 1 => 'Get directions', 2 => $P( 'Tuesday to Sunday, 9am to 6pm.' ) ] ],
        ] ],
    ],
];
