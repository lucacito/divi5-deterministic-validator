<?php
declare(strict_types=1);

/**
 * Evermore Weddings: a six-page Divi 5 theme pack for a boutique wedding planner in Charleston.
 * Design language playful-pastel with the dotted-pill kit, recoloured to dusty blue, blush and champagne and set in
 * Cormorant and Lora. Photographs pinned by Pexels id (every URL checked).
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$Q = static fn ( string $t ): string => '<p>&ldquo;' . $t . '&rdquo;</p>';
$crumb = static fn ( string $page ): string => '<p><a href="#">Home</a> / ' . $page . '</p>';

return [
    'name'     => 'Evermore Weddings',
    'language' => 'playful-pastel',
    'kit'      => 'dotted-pill',
    'palette'  => [ 'bg' => '#f6f1ec', 'surface' => '#ffffff', 'text' => '#2f3a4a', 'muted' => '#5a6372', 'accent' => '#8da7c4', 'accent_text' => '#2f3a4a', 'border' => '#e8dccf', 'inverse' => '#ffffff', 'overlay' => '#25303f' ],
    'fonts'    => [ 'Fredoka' => 'Cormorant Garamond', 'Nunito' => 'Lora' ],
    'pages'    => [
        'home' => [ 'title' => 'Home', 'sections' => [
            [ 'hero-split-left-overlap', [
                0 => 'px:28201891:1400', 1 => 'A couple holding hands under a tall arch covered in orange and pink flowers',
                2 => 'Boutique wedding planners · Charleston',
                3 => 'Your day, exactly as you imagined it.',
                4 => $P( 'Evermore plans small, personal weddings in the Lowcountry and beyond. One planner, one calm inbox and a day that feels entirely like you.' ),
                5 => 'Begin planning' ] ],
            [ 'proof-rating-strip', [
                10 => $P( '5.0 out of 5' ), 11 => $P( 'From 142 reviews on The Knot and Google' ),
                14 => $P( 'A planner on call all wedding week' ), 17 => $P( 'Replies within a few hours' ), 20 => $P( 'Over 300 weddings planned' ) ] ],
            [ 'features-carousel-strip', [
                0 => 'What we plan', 1 => 'Every detail, from the first call to the last dance',
                2 => $P( 'Full planning, partial planning or just the weekend itself: you choose how much to hand over.' ),
                3 => 'fa:f004', 5 => 'Full planning', 6 => $P( 'We take care of everything, start to finish.' ),
                7 => 'fa:f06b', 9 => 'Design and styling', 10 => $P( 'Flowers, colour, tables and a look that is yours.' ),
                11 => 'fa:f024', 13 => 'Venue search', 14 => $P( 'The right place, found and negotiated.' ),
                15 => 'fa:f0c0', 17 => 'Guest care', 18 => $P( 'Invitations, hotels and who sits next to whom.' ),
                19 => 'fa:f017', 21 => 'Day-of coordination', 22 => $P( 'We run the day so you can enjoy it.' ),
                23 => 'fa:f005', 25 => 'Destination weddings', 26 => $P( 'Charleston, the coast or a tiny island.' ) ] ],
            [ 'image-gallery', [
                0 => 'px:15865466', 1 => 'A couple laughing in a wheat field at sunset',
                2 => '<h3>Start with a conversation</h3><p>Tell us your story, your guest list and the feeling you want. We will reply with ideas, a rough budget and a date to talk.</p>',
                3 => '<h3>Design a day that is yours</h3><p>We build a plan, a palette and a timeline, and we manage every supplier so you do not have to.</p>',
                4 => 'px:289519', 5 => 'A bridal bouquet of blush roses held against a white dress',
                6 => 'px:30298624', 7 => 'A white canopy draped with flowers on a green lawn',
                8 => '<h3>Relax, it is handled</h3><p>On the day our team arrives early, sets up and stays until the last guest has gone home.</p>' ] ],
            [ 'quote-over-image', [
                0 => 'px:17206082:1920', 2 => $P( 'We said yes to a dozen details in a single afternoon and never worried again. On the day it felt like a party, not an event.' ),
                3 => $P( 'Hannah and James' ), 4 => $P( 'Married in Charleston, May' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:14703685:1920', 1 => 'Say hello', 2 => 'Tell us about your wedding.',
                3 => $P( 'A few lines are enough. We reply within one working day with a free thirty-minute call.' ),
                4 => 'Book a free call' ] ],
        ] ],

        'weddings' => [ 'title' => 'Real Weddings', 'sections' => [
            [ 'header-image-band', [
                0 => 'px:13891755:1920', 1 => $crumb( 'Real weddings' ), 2 => 'Real weddings',
                3 => $P( 'Small guest lists, big feelings and a lot of flowers.' ) ] ],
            [ 'portfolio-featured-grid', [
                0 => 'Recent', 1 => 'Five weddings we are proud of', 2 => 'See every wedding',
                3 => 'px:13891755', 4 => 'A flower-covered arch beside the sea', 5 => 'Hannah and James', 6 => $P( 'Seaside terrace · 60 guests' ),
                7 => 'px:30298624', 8 => 'A white draped canopy on a lawn', 9 => 'Priya and Daniel', 10 => $P( 'Garden lawn · 90 guests' ),
                11 => 'px:11845552', 12 => 'Chairs on a green lawn beside a petal aisle', 13 => 'Maya and Theo', 14 => $P( 'Clifftop ceremony · 40 guests' ),
                15 => 'px:16625608', 16 => 'A wooden triangle arch in a dry hilltop field', 17 => 'Lena and Marco', 18 => $P( 'Hilltop · 70 guests' ),
                19 => 'px:4234545', 20 => 'Pink flower arrangements on plinths in a golf-course garden', 21 => 'Ines and Adam', 22 => $P( 'Garden estate · 110 guests' ) ] ],
            [ 'gallery-masonry-style', [
                0 => 'Moments', 1 => 'Little things we love',
                2 => $P( 'The details guests remember long after the cake.' ),
                3 => 'px:7107856', 4 => 'A bouquet, shoes and a dress by a bright window',
                5 => 'px:2765703', 6 => 'A black lantern and candles among white flowers on a table',
                7 => 'px:11434576', 8 => 'A long wooden table set with greenery in a barn',
                9 => 'px:12303270', 10 => 'A table set with napkins and glasses under draped curtains',
                11 => 'px:19870041', 12 => 'A round table with white flowers and green glasses',
                13 => 'px:18364067', 14 => 'A bride in a lace dress holding a pale rose bouquet' ] ],
            [ 'testimonials-avatar-grid', [
                0 => 'Newlyweds', 1 => 'Notes from the couples',
                2 => $P( 'Words we keep in a very full box.' ),
                3 => $Q( 'Our parents cried, our friends danced and we never once checked the time. Thank you for giving us the day.' ), 4 => 'px:28201891:800', 5 => 'Hannah and James under their arch', 6 => $P( 'Hannah and James' ), 7 => $P( 'Seaside terrace' ),
                8 => $Q( 'The weather turned and the team moved sixty chairs in ten minutes. We did not notice until we saw the photos.' ), 9 => 'px:15865466:800', 10 => 'Priya and Daniel in a wheat field', 11 => $P( 'Priya and Daniel' ), 12 => $P( 'Garden lawn' ),
                13 => $Q( 'Forty guests, one very windy cliff and the calmest planner we have ever met.' ), 14 => 'px:289519:800', 15 => 'Maya and Theo bouquet', 16 => $P( 'Maya and Theo' ), 17 => $P( 'Clifftop' ) ] ],
            [ 'cta-band', [ 0 => 'Ready to start your own story?', 1 => 'Book a free call', 2 => $P( 'Thirty minutes, no obligation.' ) ] ],
        ] ],

        'planning' => [ 'title' => 'Planning', 'sections' => [
            [ 'header-divider-intro', [
                0 => 'Planning', 1 => 'Three ways to plan, one very calm planner',
                2 => $P( 'Every package includes a named planner, a shared timeline and our supplier book.' ) ] ],
            [ 'pricing-three-tier', [
                0 => 'Packages', 1 => 'How much would you like to hand over?',
                2 => $P( 'Prices are for weddings of up to 100 guests in the Charleston area. Travel is billed at cost.' ),
                3 => 'Day-of', 4 => $P( 'You plan it, we run it.' ), 5 => $P( '$2,400' ), 6 => $P( 'flat fee' ),
                9 => $P( 'A planner for the whole day' ), 12 => $P( 'Two meetings and a rehearsal' ), 15 => $P( 'A full run sheet and supplier brief' ), 16 => 'Ask about day-of',
                17 => $P( 'Most loved' ), 18 => 'Partial planning', 19 => $P( 'We take over a few months before.' ), 20 => $P( '$5,800' ), 21 => $P( 'flat fee' ),
                24 => $P( 'Planning from three months out' ), 27 => $P( 'Supplier sourcing and contracts' ), 30 => $P( 'Day-of team of three' ), 31 => 'Ask about partial',
                32 => 'Full planning', 33 => $P( 'Everything, start to finish.' ), 34 => $P( '$9,800' ), 35 => $P( 'flat fee' ),
                38 => $P( 'Planning from the first call' ), 41 => $P( 'Design, styling and venue search' ), 44 => $P( 'Day-of team of five' ), 45 => 'Ask about full' ] ],
            [ 'steps-numbered', [
                0 => 'How it works', 1 => 'Three steps to an easy engagement',
                2 => $P( 'Most couples feel calmer after the first conversation.' ),
                4 => 'Tell us your story', 5 => $P( 'A free call about your guest list, your style and the feeling you want.' ),
                7 => 'Choose a package', 8 => $P( 'We send a clear proposal with a fixed fee and a timeline you can edit.' ),
                10 => 'Enjoy the wedding', 11 => $P( 'We handle everything and send a weekly note so you always know where things stand.' ) ] ],
            [ 'before-after-compare', [
                0 => 'Before and after', 1 => 'From empty field to wedding feast',
                2 => $P( 'A Saturday transformation, set up in six hours.' ),
                3 => $P( 'Before' ), 4 => $P( 'A bare barn floor and a stack of folding chairs at nine in the morning.' ),
                5 => $P( 'After' ), 6 => $P( 'Candlelit tables, greenery and a room the couple cried to walk into.' ),
                7 => 'px:11434576', 8 => 'A long wooden table under barn rafters',
                9 => 'px:12303270', 10 => 'A dressed table under draped curtains' ] ],
            [ 'faq-accordion', [
                0 => 'Questions', 1 => 'What couples ask us first',
                2 => $P( 'Short answers from our planners.' ),
                3 => 'How far ahead should we book?', 4 => $P( 'Twelve to eighteen months for peak dates, six for weekday and winter weddings. We can sometimes help sooner.' ),
                5 => 'Do you work with any budget?', 6 => $P( 'We plan weddings from $20,000 to $200,000. We will tell you early if a plan is not realistic.' ),
                7 => 'Can you plan from another state?', 8 => $P( 'Yes. Most couples live elsewhere, and we plan through video calls and a shared timeline.' ),
                9 => 'What if it rains?', 10 => $P( 'Every plan includes a weather plan B, and we make the call together, in good time.' ),
                11 => 'Will we meet our suppliers?', 12 => $P( 'Always. We introduce you to a short list, and you choose.' ),
                13 => 'Do you plan small weddings?', 14 => $P( 'Gladly. Some of our favourite days have had twenty guests and a picnic.' ),
                15 => $P( 'More questions? Write to hello@evermoreweddings.com.' ) ] ],
        ] ],

        'venues' => [ 'title' => 'Venues', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Venues' ), 1 => 'Venues we love',
                2 => $P( 'Gardens, barns, beaches and one remarkable library, all visited and tested by our team.' ) ] ],
            [ 'features-zigzag', [
                0 => 'Favourites', 1 => 'Three places we would marry in',
                2 => $P( 'Each has a story, a quirk and a very good backup plan.' ),
                3 => 'px:17206082', 4 => 'A tall floral arch above a garden aisle and wooden chairs',
                5 => 'The garden estate', 6 => $P( 'Terraced lawns and a rose walk, with a glass pavilion for rainy days and room for 150 guests.' ), 7 => $P( '<a href="#">See the venue</a>' ),
                8 => 'The old barn', 9 => $P( 'Beams, brick and candles. It seats 120 at long tables and the acoustics are made for a ceilidh.' ), 10 => $P( '<a href="#">See the venue</a>' ),
                11 => 'px:3038119', 12 => 'A long tented hall with string lights and rows of tables',
                13 => 'px:14148087', 14 => 'A candlelit arch of dried flowers glowing orange at night',
                15 => 'The coastal pavilion', 16 => $P( 'A sea-view terrace and a lantern-lit ceremony space, magical at dusk for up to 90 guests.' ), 17 => $P( '<a href="#">See the venue</a>' ) ] ],
            [ 'compare-two-panels', [
                0 => 'Choosing', 1 => 'Indoor or outdoor ceremony?',
                2 => $P( 'Both can be beautiful. These are the questions we ask.' ),
                3 => 'Indoors', 4 => 'Dry, warm and predictable',
                7 => $P( 'Weather is never a worry' ), 10 => $P( 'Lighting and sound are easy to control' ), 13 => $P( 'Often cheaper for smaller numbers' ), 16 => $P( 'Needs more decoration to feel special' ),
                17 => 'Outdoors', 18 => 'Open, airy and photogenic',
                21 => $P( 'Natural light and a beautiful backdrop' ), 24 => $P( 'Room for many guests' ), 27 => $P( 'Needs a firm plan for rain' ), 30 => $P( 'Seasonal timing matters, so book early' ),
                31 => 'Plan my ceremony' ] ],
            [ 'faq-toggle-list', [
                0 => 'Venue questions', 1 => 'Before you book a date',
                2 => 'Do you take a commission?', 3 => $P( 'Never. We are paid only by you, so our advice is always on your side.' ),
                4 => 'Can you find venues outside Charleston?', 5 => $P( 'Yes. We have worked in Savannah, the Blue Ridge and the Caribbean.' ),
                6 => 'How many venues will we visit?', 7 => $P( 'Usually three to five, on a single carefully planned day.' ),
                8 => 'What if our date is taken?', 9 => $P( 'We keep a waitlist and a list of equally lovely alternatives, and we negotiate hard.' ),
                10 => 'Can we visit without committing?', 11 => $P( 'Of course. Our first venue day is free to any couple who books a call.' ),
                12 => $P( 'Another question? Write to hello@evermoreweddings.com.' ) ] ],
            [ 'cta-band', [ 0 => 'Let us find your place.', 1 => 'Book a free call', 2 => $P( 'First venue day free.' ) ] ],
        ] ],

        'about' => [ 'title' => 'About', 'sections' => [
            [ 'hero-editorial-minimal', [
                0 => 'Evermore Weddings · Since 2014',
                1 => 'Small team, enormous love of a good party.',
                2 => $P( 'We are five planners who met as bridesmaids, fell in love with the logistics and never looked back. Every wedding is planned by someone who has cried at one of their own.' ),
                3 => $P( '<a href="#">Meet the planners</a>' ) ] ],
            [ 'team-grid', [
                0 => 'The planners', 1 => 'The people on your side',
                2 => $P( 'Every couple works with a lead planner and a small team, from the first call to the last dance.' ),
                3 => 'px:8727416:800', 4 => 'Clara Bennett portrait', 5 => 'Clara Bennett', 6 => $P( 'Founder and lead planner' ),
                7 => 'px:19332236:800', 8 => 'Mei Lin portrait', 9 => 'Mei Lin', 10 => $P( 'Design director' ),
                11 => 'px:26834972:800', 12 => 'Rafael Costa portrait', 13 => 'Rafael Costa', 14 => $P( 'Logistics and venues' ),
                15 => 'px:12871465:800', 16 => 'Nadia Hassan portrait', 17 => 'Nadia Hassan', 18 => $P( 'Guest care' ) ] ],
            [ 'founder-note', [
                0 => 'px:30479361:1000', 1 => 'Clara Bennett portrait',
                2 => $P( 'Clara Bennett' ), 3 => $P( 'Founder and lead planner' ),
                4 => 'A note from the planner', 5 => 'A wedding is a day, a marriage is a life.',
                6 => $P( 'I started Evermore after planning my sister\'s wedding on a very tight budget. I discovered I loved the logistics, the late-night spreadsheets and the moment the couple stops being nervous.' ),
                7 => $P( 'We keep our list small so that every couple has my full attention. Your wedding should feel personal, calm and a little bit magic, and that takes time.' ),
                8 => $P( 'Tell us about your day. We would love to hear it.' ),
                9 => $P( '&mdash; Clara' ) ] ],
            [ 'values-list', [
                0 => 'How we plan', 1 => 'Four promises we make every couple',
                3 => 'Honest about money', 4 => $P( 'We show every cost, name every fee and never take a commission from a supplier.' ),
                6 => 'Always a plan B', 7 => $P( 'Rain, delays and surprises are expected, and every one has a calm answer.' ),
                9 => 'Your day, your way', 10 => $P( 'We suggest, but you decide. Even when it is a very unusual cake.' ),
                12 => 'Never a stranger', 13 => $P( 'The same planner answers every message, from the first call to the thank-you cards.' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:15865466:1920', 1 => 'Join us', 2 => 'We are always looking for kind, organised people.',
                3 => $P( 'Planners, florists and stylists. Send us a note and a photograph of a table you set.' ),
                4 => 'See openings' ] ],
        ] ],

        'contact' => [ 'title' => 'Contact', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Contact' ), 1 => 'Say hello',
                2 => $P( 'Tell us your date, your guest list and anything you have already dreamed up.' ) ] ],
            [ 'contact-location-form', [
                0 => 'px:33136449:1600', 1 => 'Evermore Weddings: white chairs framed by flowers in a garden',
                5 => $P( '12 Church Street, Charleston, SC 29401' ), 9 => $P( 'Mon to Fri 9am to 5pm. Studio visits by appointment.' ),
                10 => 'Visit or write', 11 => 'Tell us about your wedding.',
                12 => $P( 'We reply within one working day, usually by lunchtime.' ), 13 => 'Send message' ] ],
            [ 'faq-two-column', [
                0 => 'FAQ', 1 => 'Before you write',
                2 => $P( 'Short answers to what couples ask first.' ), 3 => 'Ask us anything',
                4 => 'What should I include?', 5 => $P( 'Your date or date range, rough guest list and anything you have already decided. A mood board is a bonus.' ),
                6 => 'How soon will you reply?', 7 => $P( 'A planner replies within one working day, often within a few hours.' ),
                8 => 'Is the first call free?', 9 => $P( 'Yes. Thirty minutes, no obligation and no sales pitch.' ),
                10 => 'Can we meet in person?', 11 => $P( 'Of course. Our studio is in downtown Charleston, and we travel to meet couples.' ),
                12 => 'Do you plan same-sex and elopement weddings?', 13 => $P( 'Always, and with great joy. Every love story is welcome here.' ),
                14 => 'Can we bring our parents?', 15 => $P( 'Please do. Many of our best planning sessions include grandparents and a lot of tea.' ) ] ],
            [ 'cta-band', [ 0 => 'We would love to hear your story.', 1 => 'Book a free call', 2 => $P( 'Thirty minutes, no obligation.' ) ] ],
        ] ],
    ],
];
