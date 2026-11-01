<?php
declare(strict_types=1);

/**
 * Signal Summit 2027: a dark glass landing page for a fictional two-day design and engineering conference in Rotterdam.
 * Centred pattern hero, glass-dark language with the glass-edge kit, recoloured to near-black, electric violet and
 * ice white and set in Space Grotesk. Photographs pinned by Pexels id. Fictional event, speakers and venue.
 */
$P = static fn ( string $t ): string => "<p>$t</p>";

return [
    'slug'     => 'dark-glass-two-day-conference-landing-page-for-divi-5-signal-summit',
    'title'    => 'Dark Glass Conference Landing Page for Divi 5: Signal Summit',
    'niche'    => 'events',
    'style'    => 'dark',
    'color'    => 'purple',
    'language' => 'glass-dark',
    'kit'      => 'glass-edge',
    'palette'  => [ 'bg' => '#07050f', 'surface' => '#16112a', 'text' => '#f4f1ff', 'muted' => '#a9a2c8', 'accent' => '#9b7bff', 'accent_text' => '#07050f', 'border' => '#2d2552', 'inverse' => '#f8f6ff', 'overlay' => '#07050f' ],
    'fonts'    => [ 'Inter' => 'Space Grotesk' ],
    'sections' => [
        [ 'hero-pattern-centered', [
            0 => 'Two days on the work and the tools behind it.',
            1 => $P( 'A conference for designers and engineers, in Rotterdam, 18 and 19 March 2027.' ),
            2 => 'Get tickets' ] ],
        [ 'stats-big-numbers', [
            0 => 'In numbers', 1 => 'Last year, in figures',
            2 => $P( '1,200+' ), 3 => $P( 'designers, engineers and founders in the room' ),
            4 => $P( '38%' ), 5 => $P( 'of talks were given by first-time speakers' ),
            6 => $P( '9h' ), 7 => $P( 'of programme each day, with two real breaks' ) ] ],
        [ 'icon-features', [
            0 => 'Venue and getting there',
            1 => 'fa:f3c5',
            3 => "<h3>Step-free and central</h3>\n" . $P( 'Level access from the street, a lift to every floor, and the station a three-minute walk away.' ),
            4 => 'fa:f236',
            6 => "<h3>Stay nearby</h3>\n" . $P( 'Forty rooms held at the conference rate, all within walking distance of the venue.' ),
            7 => 'fa:f1eb',
            9 => "<h3>Wi-Fi that works</h3>\n" . $P( 'A dedicated network for every talk room, and a charging bar in the foyer.' ),
            10 => 'fa:f2e7',
            12 => "<h3>Food for everyone</h3>\n" . $P( 'Lunch and coffee are included, and every menu marks vegetarian, vegan and allergen options.' ) ] ],
        [ 'timeline-vertical', [
            0 => 'Day one', 1 => 'Four steps, 18 March',
            2 => $P( 'Day two, 19 March, keeps the same rhythm with the workshops moved to the morning.' ),
            3 => '08:30 to 10:00', 4 => 'Doors and coffee', 5 => $P( 'Badges, coffee and the first conversations in the atrium.' ),
            6 => '10:30 to 12:30', 7 => 'The opening talks', 8 => $P( 'Two talks on how the tools changed the work, and what they did not change.' ),
            9 => '13:00 to 17:00', 10 => 'Workshops and the expo hall', 11 => $P( 'Four hands-on sessions run in parallel. Book your place at the desk.' ),
            12 => '18:00 onward', 13 => 'The evening on the terrace', 14 => $P( 'Demos, a long supper by the river and the last talk of the day.' ) ] ],
        [ 'team-grid', [
            0 => 'Speakers', 1 => 'Four people worth an early start',
            2 => $P( 'Confirmed speakers so far. The full programme follows in January.' ),
            3 => 'px:35721594:800', 4 => 'Ines Carvalho, a principal designer with dark hair and a maroon top',
            5 => 'Ines Carvalho', 6 => $P( 'Principal designer, Tallow Studio' ),
            7 => 'px:31420959:800', 8 => 'Omar Haddad, a machine learning engineer in a black top against black',
            9 => 'Omar Haddad', 10 => $P( 'Machine learning engineer, Quarry Labs' ),
            11 => 'px:33204142:800', 12 => 'Mei Lin Oda, a product lead in a black collar against green',
            13 => 'Mei Lin Oda', 14 => $P( 'Product lead, Brightwater' ),
            15 => 'px:6102841:800', 16 => 'Theo Brandt, a researcher laughing in a black hat',
            17 => 'Theo Brandt', 18 => $P( 'Researcher, Field Office' ) ] ],
        [ 'pricing-three-tier', [
            0 => 'Passes', 1 => 'Pick your pass',
            2 => $P( 'Prices include VAT and lunch on both days. Early rates end on 31 January.' ),
            3 => 'Standard pass', 4 => $P( 'For individuals who want the full programme.' ), 5 => $P( '$340' ), 6 => $P( '/ two days' ),
            9 => $P( 'Both days, every talk' ), 12 => $P( 'Lunch and coffee, both days' ), 15 => $P( 'Recordings within two weeks' ), 16 => 'Get a standard pass',
            17 => $P( 'Most popular' ),
            18 => 'Workshop pass', 19 => $P( 'For people who want to build something on day two.' ), 20 => $P( '$520' ), 21 => $P( '/ two days and a workshop' ),
            24 => $P( 'Everything in the standard pass' ), 27 => $P( 'One hands-on workshop of four' ), 30 => $P( 'Dinner on the river terrace' ), 31 => 'Get the workshop pass',
            32 => 'Team of five', 33 => $P( 'For five people who go home with one plan.' ), 34 => $P( '$1,450' ), 35 => $P( '/ five people' ),
            38 => $P( 'Five standard passes' ), 41 => $P( 'A private debrief room on day two' ), 44 => $P( 'One invoice and a group rate' ), 45 => 'Book a team' ] ],
        [ 'cta-split-form', [
            0 => 'Get the January programme', 1 => 'Speakers and sessions, first, by email',
            2 => $P( 'We send the full programme, the speaker list and the early-rate reminder in January, and nothing else.' ),
            5 => $P( 'The full session list, with room numbers' ), 8 => $P( 'The speaker list, by name and talk title' ), 11 => $P( 'One reminder before the early rate ends' ),
            12 => 'Send me the programme', 13 => $P( 'A person from the team replies within two working days.' ), 14 => 'Send me the programme' ] ],
        [ 'faq-accordion', [
            0 => 'FAQ', 1 => 'Questions, answered',
            2 => $P( 'The practical answers, before you book.' ),
            3 => 'Can I get a refund?', 4 => $P( 'Yes, in full until 31 January. After that, a pass can move to a colleague or to the next summit.' ),
            5 => 'Can someone else use my pass?', 6 => $P( 'Yes. A pass moves to a colleague with one email, up to the day before the event.' ),
            7 => 'Is the venue accessible?', 8 => $P( 'Yes, step-free from the street, with a lift to every floor and accessible toilets on each level.' ),
            9 => 'Will the talks be recorded?', 10 => $P( 'Every talk is recorded and sent to pass holders within two weeks. Workshops are not recorded.' ),
            11 => 'Can I speak at Signal Summit?', 12 => $P( 'We open a call for talks in September. Send a short abstract and one sentence on what the room will learn.' ),
            13 => 'Do you offer group tickets?', 14 => $P( 'Yes, from five people. Team bookings get one invoice and a private debrief room.' ),
            15 => $P( 'Still have a question? Write to the organisers at the address on the ticket page.' ) ] ],
        [ 'cta-band', [ 0 => 'See you on the river in March.', 1 => 'Get tickets', 2 => $P( 'Refunds until 31 January, no questions asked.' ) ] ],
    ],
];
