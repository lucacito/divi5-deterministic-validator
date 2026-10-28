<?php
declare(strict_types=1);

/**
 * Folio & Fable: a six-page Divi 5 theme pack for an independent bookshop and reading café in Edinburgh.
 * Design language editorial with the underline-link kit, recoloured to deep forest green, mustard and parchment and set
 * in Libre Baskerville and Karla. Photographs pinned by Pexels id (every URL checked).
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$Q = static fn ( string $t ): string => '<p>&ldquo;' . $t . '&rdquo;</p>';
$crumb = static fn ( string $page ): string => '<p><a href="#">Home</a> / ' . $page . '</p>';

return [
    'name'     => 'Folio & Fable',
    'language' => 'editorial',
    'kit'      => 'underline-link',
    'palette'  => [ 'bg' => '#f4efe3', 'surface' => '#fffdf7', 'text' => '#17301f', 'muted' => '#5a6a5c', 'accent' => '#9a5f00', 'accent_text' => '#fffdf7', 'border' => '#ddd3bc', 'inverse' => '#f8f3e6', 'overlay' => '#10241a' ],
    'fonts'    => [ 'Playfair Display' => 'Libre Baskerville', 'Source Sans 3' => 'Karla' ],
    'pages'    => [
        'home' => [ 'title' => 'Home', 'sections' => [
            [ 'hero-split-right', [
                0 => 'Independent bookshop · Edinburgh',
                1 => 'A bookshop for people who still browse.',
                2 => $P( 'Folio & Fable has been selling new and second-hand books on Candlemaker Row since 2006, with a reading café at the back and a very strong opinion about the ending of every novel.' ),
                3 => 'Browse the shop', 4 => $P( '<a href="#">See upcoming events</a>' ),
                5 => 'px:6892331:1400', 6 => 'A person browsing the shelves of a warm, timber-roofed bookshop' ] ],
            [ 'blurb-grid', [
                0 => 'Inside the shop', 1 => 'Four reasons to come in out of the rain.',
                2 => 'New books', 3 => 'fa:f02d', 5 => $P( 'Fiction, nonfiction and children\'s books, chosen by the people who sell them.' ),
                6 => 'Second-hand', 7 => 'fa:f0c5', 9 => $P( 'Thousands of used and rare books, priced fairly and shelved by mood.' ),
                10 => 'The reading café', 11 => 'fa:f0f4', 13 => $P( 'Coffee, cake and a sofa that has seen a thousand chapters.' ),
                14 => 'Events', 15 => 'fa:f073', 17 => $P( 'Readings, book clubs and a very loud quiz on the last Friday.' ) ] ],
            [ 'portfolio-featured-grid', [
                0 => 'Staff picks', 1 => 'Five books we cannot stop recommending', 2 => 'See all picks',
                3 => 'px:10323774', 4 => 'An open book, a cup of tea and dried flowers on a dark table', 5 => 'The Salt Path Home', 6 => $P( 'Memoir · Recommended by Isla' ),
                7 => 'px:7095568', 8 => 'An open book beside a stack of hardbacks and a white cup', 9 => 'A Slow Country', 10 => $P( 'Essays · Recommended by Tom' ),
                11 => 'px:10050656', 12 => 'Stacked books and a cappuccino on striped cloth', 13 => 'Winter at Fieldmouse Farm', 14 => $P( 'Novel · Recommended by Priya' ),
                15 => 'px:15361528', 16 => 'A woman reading in front of a wall of orange-spined paperbacks', 17 => 'Every Quiet Thing', 18 => $P( 'Short stories · Recommended by Ben' ),
                19 => 'px:7034586', 20 => 'A woman in an orange sweater reading in a window seat', 21 => 'The Lantern Year', 22 => $P( 'Poetry · Recommended by Anna' ) ] ],
            [ 'quote-over-image', [
                0 => 'px:32517434:1920', 2 => $P( 'The kind of shop where the bookseller asks what you last loved, listens properly and then hands you something you did not know you wanted.' ),
                3 => $P( 'Iona Mackay' ), 4 => $P( 'Regular since 2009' ) ] ],
            [ 'cta-split-form', [
                0 => 'Newsletter', 1 => 'One letter a month, written by a bookseller.',
                2 => $P( 'New arrivals, event dates and one book we cannot stop talking about. No tracking, no shouting.' ),
                5 => $P( 'A monthly letter, not a weekly one' ), 8 => $P( 'First sight of rare arrivals' ), 11 => $P( 'Unsubscribe in one click' ),
                12 => 'Join the letter', 13 => $P( 'We never share your address.' ), 14 => 'Subscribe' ] ],
        ] ],

        'shop' => [ 'title' => 'The Shop', 'sections' => [
            [ 'header-image-band', [
                0 => 'px:34149049:1920', 1 => $crumb( 'The shop' ), 2 => 'The shop',
                3 => $P( 'Three floors of books, a staircase that creaks and a cat called Proofread.' ) ] ],
            [ 'features-accordion-image', [
                0 => 'Floor by floor', 1 => 'Where to find what you want',
                2 => 'Ground floor', 3 => $P( 'New fiction, children\'s books, cards and the staff-pick table by the door.' ),
                4 => 'First floor', 5 => $P( 'Nonfiction, poetry, art and the sofas, with the reading café at the back.' ),
                6 => 'Second floor', 7 => $P( 'Second-hand and rare books, maps, prints and a desk for anyone who wants to sit quietly.' ),
                8 => 'The basement', 9 => $P( 'The overflow, the bargain trolley and the best smell in Edinburgh.' ),
                10 => 'px:22717411', 11 => 'A long arched bookshop corridor lined with lit shelves' ] ],
            [ 'gallery-masonry-style', [
                0 => 'Corners', 1 => 'Little places to disappear',
                2 => $P( 'Photographs by regulars, taken between the stacks.' ),
                3 => 'px:6892331', 4 => 'A bright bookshop with timber beams and tall shelves',
                5 => 'px:4865724', 6 => 'A young woman unpacking books at a white shop table',
                7 => 'px:8012259', 8 => 'A woman in a green dress reading in an armchair by shelves',
                9 => 'px:5698371', 10 => 'A woman reading by a lamp and houseplants',
                11 => 'px:3978531', 12 => 'A reader laughing in a red armchair beside a window',
                13 => 'px:13278839', 14 => 'A long perspective down shelves of old books' ] ],
            [ 'checklist-two-column', [
                0 => 'Good to know', 1 => 'Things we are happy to do for you',
                2 => $P( 'We are a bookshop, so most things start with a very good conversation.' ),
                5 => $P( 'Order any book in print, usually in two days' ), 8 => $P( 'Gift-wrap anything, free of charge' ),
                11 => $P( 'Buy your unwanted books for credit' ), 14 => $P( 'Post books anywhere in the world' ),
                17 => $P( 'Hold a book at the till for a week' ), 20 => $P( 'Help you find a gift for anyone' ),
                23 => $P( 'Reserve a seat at any event' ), 26 => $P( 'Introduce you to the cat' ) ] ],
            [ 'faq-two-column', [
                0 => 'Shop questions', 1 => 'Before you visit',
                2 => $P( 'Short answers from the till.' ), 3 => 'Ask a bookseller',
                4 => 'Do you buy second-hand books?', 5 => $P( 'Yes. Bring a box on Tuesday or Thursday morning and we will make an offer in cash or credit.' ),
                6 => 'Can you order a book for me?', 7 => $P( 'Of course, and without fuss. Most arrive within two working days, and we ring when they are in.' ),
                8 => 'Do you sell e-books?', 9 => $P( 'We sell e-books and audiobooks through our website, and every sale supports the shop.' ),
                10 => 'Is the shop accessible?', 11 => $P( 'The ground floor is step-free. Upstairs, we are happy to bring books down to you.' ),
                12 => 'Are children welcome?', 13 => $P( 'Very much so. The back of the ground floor is for them, with cushions and a standing invitation to be loud.' ),
                14 => 'Can I sell my own book?', 15 => $P( 'If it is published, yes. Ask to speak to Tom, who looks after local authors.' ) ] ],
            [ 'cta-band', [ 0 => 'Come and browse for an hour.', 1 => 'Plan a visit', 2 => $P( 'Open every day, ten until six.' ) ] ],
        ] ],

        'events' => [ 'title' => 'Events', 'sections' => [
            [ 'header-divider-intro', [
                0 => 'Events', 1 => 'Readings, clubs and the loudest quiz in the city',
                2 => $P( 'Most events are free. All of them involve wine, cake or both.' ) ] ],
            [ 'timeline-vertical', [
                0 => 'This season', 1 => 'Five evenings worth leaving the house for',
                2 => $P( 'Doors open at half past six. Seats are limited, so please book.' ),
                3 => 'First Tuesday', 4 => 'New voices', 5 => $P( 'Three debut authors read for ten minutes each, then take questions from a friendly room.' ),
                6 => 'Second Thursday', 7 => 'The slow book club', 8 => $P( 'One book, three months, no pressure. Come and say you have not finished it.' ),
                9 => 'Third Saturday', 10 => 'Children\'s story morning', 11 => $P( 'Stories, songs and a very small amount of chaos from ten till eleven.' ),
                12 => 'Last Friday', 13 => 'The bookshop quiz', 14 => $P( 'Eight rounds, two hundred pounds of prizes and a trophy shaped like a very old paperback.' ) ] ],
            [ 'pricing-two-plan-highlight', [
                0 => 'Clubs', 1 => 'Two book clubs, two kinds of reader',
                2 => $P( 'Both include a monthly pick, a ten percent discount and a glass of something at every meeting.' ),
                3 => $P( 'In person' ), 4 => 'The reading room', 5 => $P( '$8' ), 6 => $P( 'Per month' ),
                9 => $P( 'A monthly pick, chosen by a bookseller' ), 12 => $P( 'Meet at the shop on Thursdays' ), 15 => $P( 'Ten percent off every book' ), 18 => $P( 'Wine and cake at each meeting' ), 21 => $P( 'First meeting free' ), 22 => 'Join the reading room',
                23 => $P( 'Most popular' ), 24 => 'The postal club', 25 => $P( '$16' ), 26 => $P( 'Per month' ),
                29 => $P( 'A new hardback by post each month' ), 32 => $P( 'A bookseller\'s note inside' ), 35 => $P( 'Free postage in the UK' ), 38 => $P( 'Ten percent off every other book' ), 41 => $P( 'Cancel or pause any time' ), 42 => 'Join the postal club' ] ],
            [ 'testimonials-avatar-grid', [
                0 => 'Members', 1 => 'What the clubs say',
                2 => $P( 'Three members on why they keep coming back.' ),
                3 => $Q( 'I have read more in two years of the club than in the ten before. And made friends, which was not the plan.' ), 4 => 'px:19332236:800', 5 => 'Ailsa portrait', 6 => $P( 'Ailsa M.' ), 7 => $P( 'Reading room member' ),
                8 => $Q( 'The postal club has introduced me to authors I would never have picked up. Every parcel feels like a present.' ), 9 => 'px:26834972:800', 10 => 'Rohan portrait', 11 => $P( 'Rohan P.' ), 12 => $P( 'Postal club member' ),
                13 => $Q( 'Our evening at the shop is the highlight of my month. The cake does not hurt either.' ), 14 => 'px:8727416:800', 15 => 'Greta portrait', 16 => $P( 'Greta L.' ), 17 => $P( 'Reading room member' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:4865515:1920', 1 => 'Book a seat', 2 => 'Reserve your place at the next reading.',
                3 => $P( 'Seats are free, but they go quickly. Book at the till, by email or on the day if there is room.' ),
                4 => 'See the calendar' ] ],
        ] ],

        'cafe' => [ 'title' => 'The Café', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'The café' ), 1 => 'The reading café',
                2 => $P( 'Coffee, cake and a quiet table among the second-hand shelves.' ) ] ],
            [ 'features-zigzag', [
                0 => 'On the menu', 1 => 'Three small pleasures',
                2 => $P( 'Everything is made in the shop or by bakers within ten minutes\' walk.' ),
                3 => 'px:10050656', 4 => 'A cappuccino on striped cloth beside an old hardback',
                5 => 'Coffee, properly made', 6 => $P( 'Beans roasted in Leith, pulled by people who care. Flat whites, filters and a hot chocolate that has won arguments.' ), 7 => $P( '<a href="#">See the menu</a>' ),
                8 => 'Cakes and scones', 9 => $P( 'Lemon drizzle, ginger loaf and scones warm from the oven at eleven. Vegan and gluten-free options every day.' ), 10 => $P( '<a href="#">See the menu</a>' ),
                11 => 'px:7095568', 12 => 'A cup and an open book on a table beside a stack of hardbacks',
                13 => 'px:4620786', 14 => 'A tray with tea, candles and flowers on a round black table',
                15 => 'A table to yourself', 16 => $P( 'Seven tables, two sofas and a quiet corner for anyone who wants to sit and read the book they just bought.' ), 17 => $P( '<a href="#">Plan a visit</a>' ) ] ],
            [ 'steps-numbered', [
                0 => 'How it works', 1 => 'The café etiquette, in three steps',
                2 => $P( 'A very short guide for first-time visitors.' ),
                4 => 'Order at the counter', 5 => $P( 'Tell us what you fancy and take a number. Cake goes first, as is traditional.' ),
                7 => 'Find a seat', 8 => $P( 'Anywhere is fine. The sofa by the poetry shelf is the most popular, and the quietest.' ),
                10 => 'Read as long as you like', 11 => $P( 'No time limit and no pressure to buy. If you do, we will give you a bookmark.' ) ] ],
            [ 'faq-toggle-list', [
                0 => 'Café questions', 1 => 'Before you sit down',
                2 => 'Do I need to book a table?', 3 => $P( 'No. It is first come, first served. Groups of six or more should write to us.' ),
                4 => 'Can I bring my laptop?', 5 => $P( 'Yes, though our Wi-Fi is deliberately slow. Our books are faster.' ),
                6 => 'Are dogs allowed?', 7 => $P( 'Well-behaved ones are welcome, with a bowl of water and an occasional biscuit.' ),
                8 => 'Do you have vegan options?', 9 => $P( 'Always, with oat milk and a dairy-free cake every day.' ),
                10 => 'Can I eat my own lunch?', 11 => $P( 'If you buy a drink, we are happy to look the other way.' ),
                12 => $P( 'Another question? Write to hello@foliofable.co.uk.' ) ] ],
        ] ],

        'about' => [ 'title' => 'About', 'sections' => [
            [ 'hero-editorial-minimal', [
                0 => 'Folio & Fable · Since 2006',
                1 => 'We sell books. Mostly, we sell time to read them.',
                2 => $P( 'Folio & Fable began in a single room with a thousand books and a borrowed kettle. Twenty years later it fills three floors, a basement and a very good argument about whether the cat counts as staff.' ),
                3 => $P( '<a href="#">Read our story</a>' ) ] ],
            [ 'story-timeline', [
                0 => 'px:4865512:1920', 1 => 'Our story', 2 => 'Twenty years between the shelves',
                3 => $P( 'From a borrowed kettle to a landmark on Candlemaker Row.' ),
                4 => $P( '2006' ), 5 => 'One room', 6 => $P( 'Ruth and Calum open with a thousand second-hand books and a handwritten sign in the window.' ),
                7 => $P( '2012' ), 8 => 'The first floor', 9 => $P( 'We take over the room upstairs, add sofas and begin serving coffee, mostly for ourselves.' ),
                10 => $P( '2018' ), 11 => 'Events begin', 12 => $P( 'A single reading on a rainy Tuesday turns into a calendar that has not stopped since.' ),
                13 => $P( '2025' ), 14 => 'Three floors', 15 => $P( 'Eight booksellers, one cat and a lease we hope to keep for another twenty years.' ) ] ],
            [ 'team-grid', [
                0 => 'The booksellers', 1 => 'The people who will recommend you something',
                2 => $P( 'Eight of us take turns at the till. These four are in most days.' ),
                3 => 'px:30479361:800', 4 => 'Ruth Calder portrait', 5 => 'Ruth Calder', 6 => $P( 'Co-founder, second-hand' ),
                7 => 'px:26834972:800', 8 => 'Calum Reid portrait', 9 => 'Calum Reid', 10 => $P( 'Co-founder, events' ),
                11 => 'px:31241969:800', 12 => 'Isla Menon portrait', 13 => 'Isla Menon', 14 => $P( 'Fiction and children\'s' ),
                15 => 'px:4687549:800', 16 => 'Tom Adeyemi portrait', 17 => 'Tom Adeyemi', 18 => $P( 'Nonfiction and local authors' ) ] ],
            [ 'founder-note', [
                0 => 'px:12485084:1000', 1 => 'Ruth Calder among the bookshop shelves',
                2 => $P( 'Ruth Calder' ), 3 => $P( 'Co-founder and head bookseller' ),
                4 => 'A note from the till', 5 => 'A bookshop is a promise to a neighbourhood.',
                6 => $P( 'I started the shop because I wanted somewhere that I could trust to put a book in my hands. The internet is wonderful at giving you what you already know you want. We try to give you what you did not.' ),
                7 => $P( 'We read what we sell, we argue about it over cake and we pay our staff properly. It is not the fastest way to run a shop, but it is the only way I know.' ),
                8 => $P( 'Come in. Tell us the last book you loved. We will do the rest.' ),
                9 => $P( '&mdash; Ruth' ) ] ],
            [ 'values-list', [
                0 => 'How we run the shop', 1 => 'Four rules by the till',
                3 => 'Read it first', 4 => $P( 'If we recommend a book, one of us has finished it, and has an opinion.' ),
                6 => 'Never rush a browser', 7 => $P( 'Nobody has ever been made to feel they should have bought something by now.' ),
                9 => 'Pay people properly', 10 => $P( 'Booksellers are skilled people, so everyone here earns more than the living wage.' ),
                12 => 'Support the street', 13 => $P( 'We buy from local bakers and printers, host local authors and recommend the shop next door.' ) ] ],
        ] ],

        'contact' => [ 'title' => 'Contact', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Contact' ), 1 => 'Find the shop',
                2 => $P( 'We are on Candlemaker Row, a two-minute walk from the Grassmarket, up a very slight hill.' ) ] ],
            [ 'contact-location-form', [
                0 => 'px:22717411:1600', 1 => 'Folio & Fable: a lit arched aisle between tall bookshelves',
                5 => $P( '21 Candlemaker Row, Edinburgh EH1 2QA' ), 9 => $P( 'Every day, 10am to 6pm. Fridays until 8pm.' ),
                10 => 'Visit or write', 11 => 'Ask us for a book, a seat or a recommendation.',
                12 => $P( 'Questions about orders, events or second-hand offers are answered within one working day.' ), 13 => 'Send message' ] ],
            [ 'faq-accordion', [
                0 => 'FAQ', 1 => 'Before you come',
                2 => $P( 'Short answers to what customers ask first.' ),
                3 => 'How do I get there?', 4 => $P( 'Walk up from the Grassmarket, take the first left and look for the green door and the cat in the window.' ),
                5 => 'Is there parking?', 6 => $P( 'Not at the shop, but there is a car park on Greyfriars and the bus stops outside.' ),
                7 => 'Do you do gift vouchers?', 8 => $P( 'Yes, in any amount, on paper or by email. They never expire.' ),
                9 => 'Can I order online?', 10 => $P( 'Of course. Our website ships worldwide, and every order is packed by a bookseller.' ),
                11 => 'Do you host private events?', 12 => $P( 'The first floor holds forty for book launches, birthdays and quiet celebrations.' ),
                13 => 'Can I volunteer?', 14 => $P( 'We take on two volunteers each autumn, with a free book a month as thanks.' ),
                15 => $P( 'Prefer to talk? Call 0131 555 0118.' ) ] ],
            [ 'cta-band', [ 0 => 'Come in. The kettle is on.', 1 => 'Plan a visit', 2 => $P( 'Open every day, ten until six.' ) ] ],
        ] ],
    ],
];
