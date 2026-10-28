<?php
declare(strict_types=1);

/**
 * Perihelion Observatory: a six-page Divi 5 theme pack for a public observatory and planetarium in Tucson.
 * Design language glass-dark with the hairline-halo kit, recoloured to deep-space violet with an amber accent and set in
 * Sora and Outfit. Photographs pinned by Pexels id (every URL checked).
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$Q = static fn ( string $t ): string => '<p>&ldquo;' . $t . '&rdquo;</p>';
$crumb = static fn ( string $page ): string => '<p><a href="#">Home</a> / ' . $page . '</p>';

return [
    'name'     => 'Perihelion Observatory',
    'language' => 'glass-dark',
    'kit'      => 'hairline-halo',
    'palette'  => [ 'bg' => '#080714', 'surface' => '#12102a', 'text' => '#f1eefc', 'muted' => '#a8a3c8', 'accent' => '#ffb454', 'accent_text' => '#080714', 'border' => '#2b2750', 'inverse' => '#f6f3ff', 'overlay' => '#060512' ],
    'fonts'    => [ 'Inter' => 'Sora' ],
    'pages'    => [
        'home' => [ 'title' => 'Home', 'sections' => [
            [ 'hero-gradient-stats', [
                0 => 'Open to the public every clear night',
                1 => 'Look up. Then look closer.',
                2 => $P( 'Perihelion is a public observatory and planetarium in the Arizona desert, with guided telescope nights, star parties and a dome that turns the whole sky into a lesson.' ),
                3 => 'See tonight\'s sky',
                4 => $P( '14' ), 5 => $P( 'telescopes open to visitors' ),
                6 => $P( '9 in 10' ), 7 => $P( 'nights clear enough to observe' ),
                8 => $P( '60k' ), 9 => $P( 'visitors under our dark skies each year' ) ] ],
            [ 'portfolio-featured-grid', [
                0 => 'On view', 1 => 'What you might see tonight', 2 => 'See the sky calendar',
                3 => 'px:27567353', 4 => 'The full moon with craters and dark seas in sharp detail', 5 => 'The Moon', 6 => $P( 'Every month · Naked eye and scope' ),
                7 => 'px:20464631', 8 => 'The Milky Way arching above a mountain lake', 9 => 'The Milky Way', 10 => $P( 'Summer · Dark-sky walk' ),
                11 => 'px:27796994', 12 => 'Observatory domes on a hill under a starry sky', 13 => 'The domes', 14 => $P( 'Year round · Guided tour' ),
                15 => 'px:5428304', 16 => 'A telescope silhouetted against a field of stars and a pink nebula', 17 => 'Deep space', 18 => $P( 'Autumn · Big telescope' ),
                19 => 'px:18567815', 20 => 'A white observatory dome open to the night with its slit lit orange', 21 => 'The great dome', 22 => $P( 'Saturdays · Open dome' ) ] ],
            [ 'features-bento', [
                0 => 'Plan your night', 1 => 'Three ways to spend an evening with the stars',
                2 => $P( 'Every visit is led by an astronomer and begins with a short talk in the dome.' ),
                3 => $P( '<a href="#">See all events</a>' ),
                4 => 'px:12389877', 5 => 'The inside of a planetarium dome with ribbed ceiling and a circular stage',
                6 => 'The dome show', 7 => $P( 'A forty-minute, full-dome journey from our desert to the edge of the universe, narrated live.' ),
                8 => 'fa:f186', 10 => 'Telescope night', 11 => $P( 'Twelve scopes, twelve guides.' ),
                12 => 'fa:f005', 14 => 'Star party', 15 => $P( 'Bring a blanket and a flask.' ),
                16 => 'Kids\' club', 17 => $P( 'Planets, rockets and hot chocolate.' ),
                18 => 'Private hire',
                19 => $P( 'Book the dome or the telescope field for a birthday, a team day or a proposal under the stars.' ),
                20 => $P( '<a href="#">Enquire about hire</a>' ) ] ],
            [ 'testimonial', [
                0 => 'From the guest book', 1 => 'Priya Raman', 2 => 'Visitor, March', 3 => 'Perihelion', 4 => 'px:16152597:800',
                5 => 'Priya portrait', 6 => $P( 'My daughter saw Saturn\'s rings for the first time and went completely quiet. Then she asked if we could come back tomorrow.' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:9202295:1920', 1 => 'Tonight\'s sky',
                2 => 'Come and see it for yourself.',
                3 => $P( 'Doors open at dusk. Wrap up warm, leave your phone torch behind and let your eyes adjust.' ),
                4 => 'Book a night' ] ],
        ] ],

        'tonight' => [ 'title' => 'Sky Tonight', 'sections' => [
            [ 'header-image-band', [
                0 => 'px:14105168:1920', 1 => $crumb( 'Sky tonight' ), 2 => 'The sky this month',
                3 => $P( 'What is overhead, when it rises and the best way to see it.' ) ] ],
            [ 'features-carousel-strip', [
                0 => 'This month', 1 => 'Six things to look for',
                2 => $P( 'Times are for Tucson. Our astronomers add a new tip to every event.' ),
                3 => 'fa:f186', 5 => 'The Moon', 6 => $P( 'First quarter on the 9th, full on the 24th.' ),
                7 => 'fa:f005', 9 => 'Jupiter', 10 => $P( 'Rises at dusk. Four moons visible in binoculars.' ),
                11 => 'fa:f135', 13 => 'Saturn', 14 => $P( 'Best placed after nine. Rings tilted well open.' ),
                15 => 'fa:f0e7', 17 => 'Meteors', 18 => $P( 'A shower peaks before dawn on the 14th.' ),
                19 => 'fa:f06e', 21 => 'Andromeda', 22 => $P( 'A faint smudge, and 2.5 million light years away.' ),
                23 => 'fa:f0eb', 25 => 'Satellites', 26 => $P( 'The space station crosses at 8:42pm on Friday.' ) ] ],
            [ 'before-after-compare', [
                0 => 'What a telescope does', 1 => 'The same sky, through a window and through our eyepiece',
                2 => $P( 'Even a small telescope turns a pale dot into a world.' ),
                3 => $P( 'Naked eye' ), 4 => $P( 'A bright, steady point of light, easy to miss among the stars.' ),
                5 => $P( 'Through the telescope' ), 6 => $P( 'Craters, shadows and the edge of the terminator, sharp enough to count.' ),
                7 => 'px:31001498', 8 => 'A dark night sky with faint clouds above a distant ridge',
                9 => 'px:27567353', 10 => 'The full moon with craters in sharp detail' ] ],
            [ 'steps-numbered', [
                0 => 'Tips', 1 => 'How to get the most from a night',
                2 => $P( 'Eyes take twenty minutes to adapt to the dark, so a little patience goes a long way.' ),
                4 => 'Arrive at dusk', 5 => $P( 'Come early for the talk and let your eyes adjust to red light while we set up.' ),
                7 => 'Dress for the desert', 8 => $P( 'Nights fall cold even in summer. Layers, hats and a flask are all welcome.' ),
                10 => 'Ask the guides', 11 => $P( 'Every telescope has an astronomer beside it, and no question is too small.' ) ] ],
            [ 'faq-accordion', [
                0 => 'Sky questions', 1 => 'Asked every night at the eyepiece',
                2 => $P( 'Short answers from our astronomers.' ),
                3 => 'What if it is cloudy?', 4 => $P( 'The dome show runs in any weather. Telescope nights are rebooked free if cloud ends the evening.' ),
                5 => 'Can I bring my own telescope?', 6 => $P( 'Yes. On star party nights our guides will help you set it up and find your first target.' ),
                7 => 'Is it suitable for young children?', 8 => $P( 'Absolutely. Children from five are welcome, and our kids\' club runs earlier in the evening.' ),
                9 => 'Can I take photographs?', 10 => $P( 'Phone photos through the eyepiece work well with a guide\'s help. Flash and white torches are not allowed.' ),
                11 => 'How dark is the site?', 12 => $P( 'Among the darkest in the state. On a moonless night the Milky Way casts a faint shadow.' ),
                13 => 'Is the site accessible?', 14 => $P( 'Yes. The dome, the terrace and two telescopes are step-free, with an accessible viewing deck.' ),
                15 => $P( 'Still curious? Message our astronomers.' ) ] ],
        ] ],

        'tickets' => [ 'title' => 'Visit and Tickets', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Visit and tickets' ), 1 => 'Visit and tickets',
                2 => $P( 'Book ahead for dome shows and telescope nights. Under-fives and carers go free.' ) ] ],
            [ 'pricing-three-tier', [
                0 => 'Tickets', 1 => 'Pick your evening', 2 => $P( 'Prices per person. Every ticket includes the opening talk and a guided tour of the domes.' ),
                3 => 'Dome show', 4 => $P( 'Full-dome journey.' ), 5 => $P( '$16' ), 6 => $P( 'per adult' ),
                9 => $P( 'Forty-minute live show' ), 12 => $P( 'Every day, rain or shine' ), 15 => $P( 'Under-fives free' ), 16 => 'Book the dome',
                17 => $P( 'Most popular' ), 18 => 'Telescope night', 19 => $P( 'Eyes on the real thing.' ), 20 => $P( '$24' ), 21 => $P( 'per adult' ),
                24 => $P( 'Dome show included' ), 27 => $P( 'Twelve telescopes and guides' ), 30 => $P( 'Hot drinks on arrival' ), 31 => 'Book a night',
                32 => 'Season pass', 33 => $P( 'Come back all year.' ), 34 => $P( '$120' ), 35 => $P( 'per year' ),
                38 => $P( 'Unlimited telescope nights' ), 41 => $P( 'Priority booking' ), 44 => $P( 'Two guest tickets' ), 45 => 'Get the pass' ] ],
            [ 'checklist-two-column', [
                0 => 'On the night', 1 => 'What is included and what to bring',
                2 => $P( 'Everything you need is on site, apart from a warm coat.' ),
                5 => $P( 'Free parking on site' ), 8 => $P( 'Red torches to borrow' ),
                11 => $P( 'Hot chocolate and coffee' ), 14 => $P( 'Printed star charts' ),
                17 => $P( 'Step-free access to the dome' ), 20 => $P( 'Seats and blankets' ),
                23 => $P( 'Warm clothes, please bring' ), 26 => $P( 'Patience for the clouds' ) ] ],
            [ 'cta-split-form', [
                0 => 'Groups and schools', 1 => 'Bring a class, a club or a crowd.',
                2 => $P( 'Private dome shows and telescope sessions for groups of ten to a hundred and twenty.' ),
                5 => $P( 'A talk pitched to your age group' ), 8 => $P( 'Free teacher or group-leader place' ), 11 => $P( 'Flexible dates, including weekdays' ),
                12 => 'Tell us about your group', 13 => $P( 'We reply within one working day.' ), 14 => 'Send enquiry' ] ],
        ] ],

        'learn' => [ 'title' => 'Learn', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Learn' ), 1 => 'Astronomy for everyone',
                2 => $P( 'Courses, clubs and talks for every age, led by working astronomers.' ) ] ],
            [ 'blurb-grid', [
                0 => 'Programmes', 1 => 'Three ways to keep learning. Pick a format that fits your week.',
                2 => 'Evening courses', 3 => 'fa:f19d', 5 => $P( 'Six-week courses on the night sky, planets and cosmology, with no maths required.' ),
                6 => 'Youth club', 7 => 'fa:f135', 9 => $P( 'Saturday mornings of rockets, telescopes and moon maps for ages eight to fourteen.' ),
                10 => 'Public talks', 11 => 'fa:f130', 13 => $P( 'Monthly lectures from visiting researchers, free with a telescope night ticket.' ),
                14 => 'Observing clinics', 15 => 'fa:f002', 17 => $P( 'Bring your telescope and we will help you collimate, align and find your first object.' ) ] ],
            [ 'features-zigzag', [
                0 => 'Featured', 1 => 'Three things we teach best',
                2 => $P( 'Each runs a few times a year and fills quickly.' ),
                3 => 'px:5428302', 4 => 'Children on beanbags pointing a small telescope at a projected planet',
                5 => 'Astronomy for kids', 6 => $P( 'Hands-on sessions that turn a curious eight-year-old into a confident stargazer.' ), 7 => $P( '<a href="#">See dates</a>' ),
                8 => 'Astrophotography', 9 => $P( 'Learn to capture the Moon, planets and the Milky Way with a camera you already own.' ), 10 => $P( '<a href="#">See dates</a>' ),
                11 => 'px:39732913', 12 => 'The Milky Way rising over a dark mountain ridge',
                13 => 'px:12389877', 14 => 'The inside of a planetarium dome with a ribbed ceiling',
                15 => 'The big picture', 16 => $P( 'A six-week journey from the Solar System to galaxies, with no equations and plenty of time for questions.' ), 17 => $P( '<a href="#">See dates</a>' ) ] ],
            [ 'team-grid', [
                0 => 'The astronomers', 1 => 'The people at the eyepiece',
                2 => $P( 'A small team of researchers, teachers and very enthusiastic volunteers.' ),
                3 => 'px:12871465:800', 4 => 'Dr Rashid Malik portrait', 5 => 'Dr Rashid Malik', 6 => $P( 'Director and planetary scientist' ),
                7 => 'px:16152597:800', 8 => 'Dr Amara Okoye portrait', 9 => 'Dr Amara Okoye', 10 => $P( 'Head of education' ),
                11 => 'px:7322232:800', 12 => 'Walt Pryce portrait', 13 => 'Walt Pryce', 14 => $P( 'Telescope technician' ),
                15 => 'px:8727416:800', 16 => 'Dr Ingrid Larsen portrait', 17 => 'Dr Ingrid Larsen', 18 => $P( 'Outreach astronomer' ) ] ],
            [ 'cta-band', [ 0 => 'Ready to start your own journey?', 1 => 'See courses', 2 => $P( 'New courses begin every season.' ) ] ],
        ] ],

        'about' => [ 'title' => 'About', 'sections' => [
            [ 'hero-editorial-minimal', [
                0 => 'Perihelion Observatory · Since 1989',
                1 => 'A small observatory with a very large view.',
                2 => $P( 'We are a non-profit observatory and planetarium that opens the sky to anyone willing to stand outside in the dark. Everything we earn goes back into the telescopes and the teaching.' ),
                3 => $P( '<a href="#">Read our story</a>' ) ] ],
            [ 'story-timeline', [
                0 => 'px:13751185:1920', 1 => 'Our story', 2 => 'Thirty-five years of dark skies',
                3 => $P( 'From a borrowed telescope to a full public observatory.' ),
                4 => $P( '1989' ), 5 => 'One borrowed telescope', 6 => $P( 'A retired physics teacher sets up an eight-inch reflector in a car park and invites the street.' ),
                7 => $P( '2001' ), 8 => 'The first dome', 9 => $P( 'Volunteers raise the money to build a small dome on the hill above the town.' ),
                10 => $P( '2014' ), 11 => 'The planetarium', 12 => $P( 'A full-dome theatre opens, powered by a very patient team of volunteers.' ),
                13 => $P( '2025' ), 14 => 'A dark-sky reserve', 15 => $P( 'Our valley is formally recognised for the quality of its night sky.' ) ] ],
            [ 'list-icon-dividers', [
                0 => 'What we believe', 1 => 'Five principles under every dome',
                2 => $P( 'Written on a card by the door and read aloud at every staff meeting.' ),
                3 => 'fa:f06e', 5 => 'Free to look', 6 => $P( 'Nobody is turned away from a telescope night for lack of money.' ),
                7 => 'fa:f0c3', 9 => 'Real science', 10 => $P( 'Our talks are led by working astronomers, and we correct ourselves in public.' ),
                11 => 'fa:f186', 13 => 'Protect the dark', 14 => $P( 'We campaign for better lighting, because a lost sky is hard to get back.' ),
                15 => 'fa:f0c0', 17 => 'Everyone belongs', 18 => $P( 'The universe is for everyone, so our site, shows and staff are too.' ),
                19 => 'fa:f004', 21 => 'Share the wonder', 22 => $P( 'Every guest should leave wanting to look up again tomorrow.' ) ] ],
            [ 'quote-over-image', [
                0 => 'px:35120611:1920', 2 => $P( 'The first time I saw Saturn through a telescope I laughed out loud. I have spent thirty years trying to give that moment to everyone else.' ),
                3 => $P( 'Dr Rashid Malik' ), 4 => $P( 'Director, Perihelion Observatory' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:12290473:1920', 1 => 'Support the sky', 2 => 'Help keep the dome open to everyone.',
                3 => $P( 'Donate, volunteer or sponsor a telescope. Every contribution keeps nights free for local families.' ),
                4 => 'Give or volunteer' ] ],
        ] ],

        'contact' => [ 'title' => 'Contact', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Contact' ), 1 => 'Contact the observatory',
                2 => $P( 'Questions about tickets, school visits or a private evening? We answer within a day.' ) ] ],
            [ 'contact-details-form', [
                0 => 'Contact', 1 => 'Send us your question.',
                5 => $P( 'hello@perihelion.observatory' ), 9 => $P( '(520) 555-0171' ), 13 => $P( 'Sentinel Ridge Road, Tucson, AZ 85745' ),
                17 => $P( 'Open dusk until 11pm, Thursday to Sunday.' ), 18 => 'Send message' ] ],
            [ 'faq-toggle-list', [
                0 => 'FAQ', 1 => 'Before you visit',
                2 => 'How do I find you?', 3 => $P( 'Follow Sentinel Ridge Road for six miles, past the last streetlight. Our sign is a small red lamp.' ),
                4 => 'Do I need to book?', 5 => $P( 'Dome shows and telescope nights sell out in summer, so we recommend it. Walk-ups are welcome when space allows.' ),
                6 => 'What happens if it rains?', 7 => $P( 'The dome show goes on. If a telescope night is clouded off, you can rebook free within a year.' ),
                8 => 'Is there food on site?', 9 => $P( 'Hot drinks and snacks are on sale. You are welcome to bring a picnic to the terrace.' ),
                10 => 'Can I volunteer?', 11 => $P( 'Yes. We train guides each spring. No astronomy background needed, only enthusiasm.' ),
                12 => $P( 'Another question? Write to hello@perihelion.observatory.' ) ] ],
            [ 'cta-band', [ 0 => 'See you under the stars.', 1 => 'Book a night', 2 => $P( 'Open Thursday to Sunday, dusk until 11pm.' ) ] ],
        ] ],
    ],
];
