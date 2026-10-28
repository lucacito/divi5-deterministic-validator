<?php
declare(strict_types=1);

/**
 * The Lantern House: a six-page Divi 5 theme pack for a twelve-room boutique hotel in an old European town.
 * Design language luxe with the gilt-hairline kit, recoloured to bottle green, brass and ivory and set in Cormorant
 * Garamond and Jost. Photographs pinned by Pexels id (every URL checked).
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$Q = static fn ( string $t ): string => '<p>&ldquo;' . $t . '&rdquo;</p>';
$crumb = static fn ( string $page ): string => '<p><a href="#">Home</a> / ' . $page . '</p>';

return [
    'name'     => 'The Lantern House',
    'language' => 'luxe',
    'kit'      => 'gilt-hairline',
    'palette'  => [ 'bg' => '#0f1a15', 'surface' => '#16241d', 'text' => '#f2ede1', 'muted' => '#b4b9a8', 'accent' => '#c9a45c', 'accent_text' => '#14110a', 'border' => '#2b3d33', 'inverse' => '#f8f3e6', 'overlay' => '#0a120e' ],
    'fonts'    => [],
    'pages'    => [
        'home' => [ 'title' => 'Home', 'sections' => [
            [ 'hero-image-scrim', [
                0 => 'px:18720261:1920', 1 => 'A twelve-room hotel · Old Town',
                2 => 'A quiet house on a cobbled street.',
                3 => $P( 'The Lantern House is a family-run hotel in an eighteenth-century townhouse, with twelve rooms, a walled courtyard and a breakfast people talk about for years.' ),
                4 => 'Reserve a room', 5 => 'See the rooms',
                6 => $P( '14 Ropewalk Lane, Old Town' ), 7 => $P( 'Reception open 24 hours' ), 8 => $P( 'Breakfast served until eleven' ) ] ],
            [ 'proof-press-quotes', [
                0 => 'In the press', 1 => 'What the guides say',
                2 => $P( 'Voyage Quarterly' ), 3 => $Q( 'The most charming twelve rooms in the city.' ), 4 => $P( 'Where to stay, spring 2025' ),
                5 => $P( 'The Slow Traveller' ), 6 => $Q( 'Breakfast alone is worth the journey.' ), 7 => $P( 'Editor\'s choice, 2024' ),
                8 => $P( 'Inn Review' ), 9 => $Q( 'A house that feels like a friend\'s home.' ), 10 => $P( 'Small hotels guide, 2025' ) ] ],
            [ 'founder-note', [
                0 => 'px:12284845:1000', 1 => 'The green velvet sofa in the Lantern House lounge',
                2 => $P( 'Marta Okafor' ), 3 => $P( 'Owner and host' ),
                4 => 'The house', 5 => 'Twelve rooms, one courtyard and a very good kitchen.',
                6 => $P( 'Built in 1768 for a merchant, restored by our family over two years, with every beam and tile kept where we found it.' ),
                7 => $P( 'Staying with us feels like staying with a friend who happens to have a wine cellar. We know your name before you arrive.' ),
                8 => $P( 'Come for a night and see whether you want to stay for four.' ),
                9 => $P( '&mdash; Marta' ) ] ],
            [ 'features-carousel-strip', [
                0 => 'Your stay', 1 => 'Small things done properly',
                2 => $P( 'We have twelve rooms, so we can afford to notice everything.' ),
                3 => 'fa:f236', 5 => 'Rooms with character', 6 => $P( 'No two the same, all with proper beds.' ),
                7 => 'fa:f0f4', 9 => 'Breakfast till eleven', 10 => $P( 'Eggs, bread and jam, all made in the house.' ),
                11 => 'fa:f06c', 13 => 'The walled courtyard', 14 => $P( 'Coffee, wine and shade at any hour.' ),
                15 => 'fa:f2e7', 17 => 'Local suppers', 18 => $P( 'Booked at the desk, cooked down the road.' ),
                19 => 'fa:f1b9', 21 => 'Bicycles to borrow', 22 => $P( 'To the market and along the river.' ),
                23 => 'fa:f004', 25 => 'A lighthouse of advice', 26 => $P( 'Walks, tables and quiet corners, by name.' ) ] ],
            [ 'quote-single-large', [
                1 => $P( 'We came for a weekend and stayed four nights. The breakfast, the courtyard and the people who greet you by name made leaving very difficult.' ),
                2 => 'px:19332236:800', 3 => 'Camille Laurent portrait', 4 => $P( 'Camille Laurent' ), 5 => $P( 'Guest, October' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:14535911:1920', 1 => 'Stay with us',
                2 => 'Book direct, and we will take care of the rest.',
                3 => $P( 'Direct bookings include breakfast, a late checkout and a bottle of the house wine on arrival.' ),
                4 => 'Reserve a room' ] ],
        ] ],

        'rooms' => [ 'title' => 'Rooms', 'sections' => [
            [ 'header-image-band', [
                0 => 'px:39836703:1920', 1 => $crumb( 'Rooms' ), 2 => 'Rooms and suites',
                3 => $P( 'Twelve rooms, each with its own character and a very good bed.' ) ] ],
            [ 'pricing-three-tier', [
                0 => 'Rates', 1 => 'Three kinds of room', 2 => $P( 'Prices are per night for two guests and include breakfast. Taxes are added at checkout.' ),
                3 => 'Garden room', 4 => $P( 'Quiet, ground floor, courtyard view.' ), 5 => $P( '$180' ), 6 => $P( 'per night' ),
                9 => $P( 'Queen bed and writing desk' ), 12 => $P( 'Direct courtyard access' ), 15 => $P( 'Rain shower' ), 16 => 'Reserve',
                17 => $P( 'Guest favourite' ), 18 => 'Merchant room', 19 => $P( 'Bigger, brighter, beams overhead.' ), 20 => $P( '$260' ), 21 => $P( 'per night' ),
                24 => $P( 'King bed and sofa' ), 27 => $P( 'Freestanding bath' ), 30 => $P( 'Street and rooftop view' ), 31 => 'Reserve',
                32 => 'The attic suite', 33 => $P( 'Our best room, under the roof.' ), 34 => $P( '$390' ), 35 => $P( 'per night' ),
                38 => $P( 'Separate sitting room' ), 41 => $P( 'Private terrace' ), 44 => $P( 'Champagne on arrival' ), 45 => 'Reserve' ] ],
            [ 'gallery-masonry-style', [
                0 => 'Gallery', 1 => 'A look around the house',
                2 => $P( 'Photographed in daylight, with the guests\' permission.' ),
                3 => 'px:37098141', 4 => 'A wooden headboard with pillows and bedside lamps',
                5 => 'px:13871310', 6 => 'A bed with grey bedding and a swan towel',
                7 => 'px:19689227', 8 => 'A hotel lounge with blue sofas and an orchid',
                9 => 'px:12934594', 10 => 'A spacious lobby with round chairs and tall windows',
                11 => 'px:3769122', 12 => 'A guest having breakfast in a courtyard with arched windows',
                13 => 'px:14705594', 14 => 'Guests sharing a long breakfast in a stone courtyard' ] ],
            [ 'checklist-two-column', [
                0 => 'Every room', 1 => 'What is included in every stay',
                2 => $P( 'The things we would want ourselves.' ),
                5 => $P( 'Breakfast for every guest' ), 8 => $P( 'Fast, free Wi-Fi' ),
                11 => $P( 'Linen sheets and goose-down pillows' ), 14 => $P( 'A mini bar of local drinks' ),
                17 => $P( 'Tea, coffee and fresh milk' ), 20 => $P( 'Bicycles to borrow' ),
                23 => $P( 'Late checkout, by arrangement' ), 26 => $P( 'A very warm welcome' ) ] ],
            [ 'faq-two-column', [
                0 => 'Rooms', 1 => 'Questions about staying',
                2 => $P( 'Short answers from the front desk.' ), 3 => 'Ask the desk',
                4 => 'Which room is best for a first visit?', 5 => $P( 'Most first-time guests love the Merchant rooms: bright, beamed and the perfect size for two.' ),
                6 => 'Do you allow dogs?', 7 => $P( 'Well-behaved dogs are welcome in the Garden rooms, with a bed, a bowl and a biscuit.' ),
                8 => 'Is there a lift?', 9 => $P( 'The house is old, so no. Garden rooms are step-free, and we will gladly carry your bags.' ),
                10 => 'What time is check-in?', 11 => $P( 'From three, though we often have a room ready earlier. Ask us and we will try.' ),
                12 => 'Can we have an extra bed?', 13 => $P( 'Merchant rooms can take a child\'s bed. Please ask when you book.' ),
                14 => 'Do you offer parking?', 15 => $P( 'A small car park behind the hotel, plus a garage two streets away.' ) ] ],
        ] ],

        'dining' => [ 'title' => 'Breakfast and Dining', 'sections' => [
            [ 'header-divider-intro', [
                0 => 'The kitchen', 1 => 'Breakfast, and everything after',
                2 => $P( 'We do not run a restaurant. We cook breakfast and, three nights a week, a supper for the people in the house.' ) ] ],
            [ 'features-zigzag', [
                0 => 'What we serve', 1 => 'Three tables, three moods',
                2 => $P( 'All of it is cooked in our own small kitchen, from things grown or made nearby.' ),
                3 => 'px:7245411', 4 => 'A table in a courtyard garden with peonies and breakfast',
                5 => 'Breakfast in the courtyard', 6 => $P( 'Eggs from the farm up the road, bread from the oven at seven and jam made in the autumn. Served until eleven, in all weathers.' ), 7 => $P( '<a href="#">See the menu</a>' ),
                8 => 'The long table', 9 => $P( 'Three nights a week, a single set supper at one long table, with the cook explaining each plate. Twelve seats, booked at the desk.' ), 10 => $P( '<a href="#">See the menu</a>' ),
                11 => 'px:6466304', 12 => 'A server pouring juice beside a tiered stand of pastries',
                13 => 'px:3771844', 14 => 'A guest having tea and fruit in a bedroom with an ornate bed',
                15 => 'Breakfast in bed', 16 => $P( 'For mornings that need it. A tray of coffee, pastries and fruit, brought to your door at any time you choose.' ), 17 => $P( '<a href="#">See the menu</a>' ) ] ],
            [ 'timeline-vertical', [
                0 => 'A day', 1 => 'How a day at the house tends to go',
                2 => $P( 'Nothing is compulsory, but this is the rhythm we like.' ),
                3 => '7:00', 4 => 'Bread from the oven', 5 => $P( 'The kitchen wakes, the courtyard door opens and the first coffee goes out.' ),
                6 => '11:00', 7 => 'The last croissant', 8 => $P( 'Breakfast ends, and the sitting room fills with people with books.' ),
                9 => '17:00', 10 => 'Drinks in the courtyard', 11 => $P( 'Our wine of the week and a few snacks, with the lanterns coming on.' ),
                12 => '20:00', 13 => 'Supper at the long table', 14 => $P( 'On Thursday, Friday and Saturday. Booked at the desk, set at eight.' ) ] ],
            [ 'faq-toggle-list', [
                0 => 'Dining questions', 1 => 'Before you eat with us',
                2 => 'Is breakfast included?', 3 => $P( 'Always, for every guest, and every room.' ),
                4 => 'Can you cater for allergies?', 5 => $P( 'Of course. Tell us when you book and the cook will plan around it.' ),
                6 => 'Can non-residents book supper?', 7 => $P( 'Occasionally, when seats are free. Ask at the desk or write to us.' ),
                8 => 'Do you serve wine?', 9 => $P( 'A short, interesting list of small growers, by the glass or the bottle.' ),
                10 => 'Is there a children\'s menu?', 11 => $P( 'We cook simply and happily for small guests. Just ask.' ),
                12 => $P( 'Another question? Write to stay@lanternhouse.eu.' ) ] ],
            [ 'cta-band', [ 0 => 'Book a room, and breakfast comes with it.', 1 => 'Reserve a room', 2 => $P( 'Direct guests get a late checkout.' ) ] ],
        ] ],

        'explore' => [ 'title' => 'The Town', 'sections' => [
            [ 'header-image-band', [
                0 => 'px:5848160:1920', 1 => $crumb( 'The town' ), 2 => 'The old town',
                3 => $P( 'Cobbles, bakeries and a river, all within ten minutes of the front door.' ) ] ],
            [ 'steps-numbered', [
                0 => 'Our favourites', 1 => 'Three things to do on your first day',
                2 => $P( 'Honest recommendations from people who live here.' ),
                4 => 'Walk the river', 5 => $P( 'A flat, shaded path begins behind the hotel and reaches the old bridge in twenty minutes.' ),
                7 => 'Breakfast at the market', 8 => $P( 'Saturday mornings in the square: cheese, fruit, flowers and a very strong coffee.' ),
                10 => 'Climb the tower', 11 => $P( 'Two hundred steps for a view of every roof in town. Go at sunset, and take a jacket.' ) ] ],
            [ 'features-accordion-image', [
                0 => 'By season', 1 => 'When to come, and why',
                2 => 'Spring', 3 => $P( 'Blossom in the courtyard, quiet streets and long, mild evenings. A very good time for walkers.' ),
                4 => 'Summer', 5 => $P( 'Festivals, late dinners and a river that is lovely to swim in. Book early for July.' ),
                6 => 'Autumn', 7 => $P( 'The harvest, the markets and soft light. Our favourite season, and the best for food.' ),
                8 => 'Winter', 9 => $P( 'Candles, woodsmoke and empty streets. The cosiest time to be inside the house.' ),
                10 => 'px:2040914', 11 => 'An old town tower lit at night above lantern-lit streets' ] ],
            [ 'proof-rating-strip', [
                10 => $P( '4.9 out of 5' ), 11 => $P( 'From 612 guest reviews on Google and Tripadvisor' ),
                14 => $P( 'Best price when you book direct' ), 17 => $P( 'Reception open around the clock' ), 20 => $P( 'Family-run since 1998' ) ] ],
            [ 'cta-band', [ 0 => 'Come and see the town for yourself.', 1 => 'Reserve a room', 2 => $P( 'Direct guests receive a free bottle of wine.' ) ] ],
        ] ],

        'about' => [ 'title' => 'About', 'sections' => [
            [ 'hero-editorial-minimal', [
                0 => 'The Lantern House · Since 1998',
                1 => 'A family, a house and a very long staircase.',
                2 => $P( 'We bought the house in 1998 as a ruin, lived in the kitchen for a year and opened with four rooms. Twenty-six years later it has twelve, and we still carry the bags ourselves.' ),
                3 => $P( '<a href="#">Read our story</a>' ) ] ],
            [ 'story-timeline', [
                0 => 'px:38221758:1920', 1 => 'Our story', 2 => 'Twenty-six years of quiet improvement',
                3 => $P( 'A small hotel grows slowly, with a great many conversations.' ),
                4 => $P( '1998' ), 5 => 'The ruin', 6 => $P( 'We buy a roofless townhouse, with a fig tree growing through the kitchen floor.' ),
                7 => $P( '2001' ), 8 => 'Four rooms', 9 => $P( 'The first guests arrive. Breakfast is eaten in the kitchen, with the builders.' ),
                10 => $P( '2010' ), 11 => 'The courtyard', 12 => $P( 'We take down a wall, plant a vine and discover the best room in the house.' ),
                13 => $P( '2024' ), 14 => 'Twelve rooms', 15 => $P( 'The attic opens as our last suite. We promise ourselves that is enough.' ) ] ],
            [ 'team-grid', [
                0 => 'The people', 1 => 'The family and the friends who run it',
                2 => $P( 'The faces you will meet at the desk, the table and the top of the stairs.' ),
                3 => 'px:8727416:800', 4 => 'Marta portrait', 5 => 'Marta Okafor', 6 => $P( 'Owner and host' ),
                7 => 'px:26834972:800', 8 => 'Luis portrait', 9 => 'Luis Moreno', 10 => $P( 'Owner and cook' ),
                11 => 'px:31241969:800', 12 => 'Anya portrait', 13 => 'Anya Volkova', 14 => $P( 'Front desk manager' ),
                15 => 'px:4687549:800', 16 => 'Henri portrait', 17 => 'Henri Dubois', 18 => $P( 'Night porter' ) ] ],
            [ 'values-list', [
                0 => 'The house rules', 1 => 'Four things we try to get right',
                3 => 'Know the guest', 4 => $P( 'We learn names, preferences and the type of pillow, and we remember them next year.' ),
                6 => 'Stay small', 7 => $P( 'Twelve rooms is the number at which everything still works, and we plan to stop there.' ),
                9 => 'Cook properly', 10 => $P( 'Breakfast is the one meal we will never compromise, and we buy the eggs ourselves.' ),
                12 => 'Be good neighbours', 13 => $P( 'We hire locally, buy locally and send guests to the people who deserve the business.' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:12934594:1920', 1 => 'Work with us', 2 => 'We are looking for warm, tidy people.',
                3 => $P( 'Housekeeping, front desk and kitchen roles, with room to grow and a very good staff lunch.' ),
                4 => 'See openings' ] ],
        ] ],

        'contact' => [ 'title' => 'Contact', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Contact' ), 1 => 'Contact the house',
                2 => $P( 'Reservations, questions and requests for a particular pillow. We reply within a few hours.' ) ] ],
            [ 'contact-location-form', [
                0 => 'px:18720261:1600', 1 => 'The Lantern House: a cobbled street with half-timbered houses',
                5 => $P( '14 Ropewalk Lane, Old Town' ), 9 => $P( 'Reception open 24 hours. Breakfast 7 to 11.' ),
                10 => 'Visit or write', 11 => 'Tell us when you would like to stay.',
                12 => $P( 'Dates, number of guests and anything we should know. A person replies within a few hours.' ), 13 => 'Send enquiry' ] ],
            [ 'faq-accordion', [
                0 => 'FAQ', 1 => 'Before you arrive',
                2 => $P( 'Short answers to what guests ask first.' ),
                3 => 'How do we get to the hotel?', 4 => $P( 'From the station it is a twelve-minute walk, or a short taxi ride. We will send directions.' ),
                5 => 'Can you arrange a taxi?', 6 => $P( 'Always. Tell us the time and we will have one at the door.' ),
                7 => 'What is your cancellation policy?', 8 => $P( 'Free until seven days before arrival. After that we will always try to be flexible.' ),
                9 => 'Is the hotel suitable for children?', 10 => $P( 'Yes, and we are very fond of small guests. Merchant rooms suit families best.' ),
                11 => 'Can we host a small event?', 12 => $P( 'The courtyard holds up to forty for birthdays, wedding lunches and quiet celebrations.' ),
                13 => 'Do you have a lift?', 14 => $P( 'No. The house is old, but we carry the bags and Garden rooms are step-free.' ),
                15 => $P( 'Prefer to talk? Call +33 1 555 0192.' ) ] ],
            [ 'cta-band', [ 0 => 'We keep a light on for you.', 1 => 'Reserve a room', 2 => $P( 'Direct guests get a late checkout.' ) ] ],
        ] ],
    ],
];
