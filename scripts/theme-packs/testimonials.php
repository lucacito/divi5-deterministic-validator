<?php
declare(strict_types=1);

/**
 * Twenty free Divi 5 testimonial layouts for the divi5lab.com catalogue: avatar grids, large single quotes, quotes over
 * a photograph, single testimonial cards, press quotes, rating strips and sliders, each a different business, design
 * language and tone. Photographs pinned by Pexels id (every URL checked). Same entry shape as heroes.php.
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$Q = static fn ( string $t ): string => '<p>&ldquo;' . $t . '&rdquo;</p>';
$meta = static fn ( string $slug, string $title, string $niche, string $style, string $color, string $lang, ?string $kit, string $recipe ) =>
    [ 'slug' => $slug, 'title' => $title, 'niche' => $niche, 'style' => $style, 'color' => $color, 'language' => $lang, 'kit' => $kit, 'recipe' => $recipe ];

/** Avatar grid: eyebrow, heading, intro, then three [quote, photo id, name, role]. */
$grid = static function ( array $m, string $e, string $h, string $i, array $a, array $b, array $c ) use ( $P, $Q ): array {
    $copy = [ 0 => $e, 1 => $h, 2 => $P( $i ) ];
    foreach ( [ [ $a, 3 ], [ $b, 8 ], [ $c, 13 ] ] as [ $x, $o ] ) {
        $copy += [ $o => $Q( $x[0] ), $o + 1 => "px:{$x[1]}:800", $o + 2 => "$x[2] portrait", $o + 3 => $P( $x[2] ), $o + 4 => $P( $x[3] ) ];
    }
    return $m + [ 'copy' => $copy ];
};
/** Large single quote: quote, photo id, name, role. */
$single = static fn ( array $m, string $q, string $id, string $n, string $r ): array =>
    $m + [ 'copy' => [ 1 => $P( $q ), 2 => "px:$id:800", 3 => "$n portrait", 4 => $P( $n ), 5 => $P( $r ) ] ];
/** Quote over a photograph: background photo id, quote, name, role. */
$over = static fn ( array $m, string $bg, string $q, string $n, string $r ): array =>
    $m + [ 'copy' => [ 0 => "px:$bg:1920", 2 => $P( $q ), 3 => $P( $n ), 4 => $P( $r ) ] ];
/** One testimonial card: heading, author, job title, company, photo id, quote. */
$card = static fn ( array $m, string $h, string $a, string $job, string $co, string $id, string $q ): array =>
    $m + [ 'copy' => [ 0 => $h, 1 => $a, 2 => $job, 3 => $co, 4 => "px:$id:800", 5 => "$a portrait", 6 => $P( $q ) ] ];
/** Press quotes: heading, intro heading, then three [publication, quote, article]. */
$press = static function ( array $m, string $e, string $h, array $a, array $b, array $c ) use ( $P, $Q ): array {
    $copy = [ 0 => $e, 1 => $h ];
    foreach ( [ [ $a, 2 ], [ $b, 5 ], [ $c, 8 ] ] as [ $x, $o ] ) { $copy += [ $o => $P( $x[0] ), $o + 1 => $Q( $x[1] ), $o + 2 => $P( $x[2] ) ]; }
    return $m + [ 'copy' => $copy ];
};
/** Rating strip: score, source line, then three trust lines. */
$rating = static fn ( array $m, string $score, string $src, string $t1, string $t2, string $t3 ): array =>
    $m + [ 'copy' => [ 10 => $P( $score ), 11 => $P( $src ), 14 => $P( $t1 ), 17 => $P( $t2 ), 20 => $P( $t3 ) ] ];
/** Slider: eyebrow, heading, intro, then three [name / role, quote]. */
$slider = static function ( array $m, string $e, string $h, string $i, array $a, array $b, array $c ) use ( $P, $Q ): array {
    $copy = [ 0 => $e, 1 => $h, 2 => $P( $i ) ];
    foreach ( [ [ $a, 3 ], [ $b, 5 ], [ $c, 7 ] ] as [ $x, $o ] ) { $copy += [ $o => $x[0], $o + 1 => $Q( $x[1] ) ]; }
    return $m + [ 'copy' => $copy ];
};

return [
    // ------------------------------------------------------------ avatar grids (5)
    $grid( $meta( 'corporate-home-sellers-testimonials-layout-for-divi-5-three-avatar-cards', 'Corporate Home Sellers Testimonials Layout for Divi 5: Three Avatar Cards', 'real_estate', 'corporate', 'blue', 'corporate-trust', null, 'testimonials-avatar-grid' ),
        'Seller stories', 'Three homes, three happy moves', 'Recent sellers on how the sale went and what they would tell a neighbour.',
        [ 'We had three offers in the first weekend and sold above asking. I could not believe how calm it all was.', '37148308', 'Daniel Foster', 'Sold in Westbrook, 5 days on the market' ],
        [ 'They told us honestly what to fix and what to leave. The advice saved us thousands and the house looked beautiful.', '6077664', 'Marcus Johnson', 'Sold in Lakeview, 11 days on the market' ],
        [ 'After a hard year, they made moving feel easy. Phone calls returned, questions answered, no surprises.', '7244715', 'Rachel Cohen', 'Sold in Old Town, 8 days on the market' ] ),

    $grid( $meta( 'soft-project-management-testimonials-layout-for-divi-5-three-avatar-cards', 'Soft Project Management Testimonials Layout for Divi 5: Three Avatar Cards', 'saas', 'minimal', 'purple', 'soft-saas', null, 'testimonials-avatar-grid' ),
        'Customer love', 'Teams that finally stopped chasing updates', 'Three team leads on what changed after the first month.',
        [ 'Our weekly status meeting went from an hour to ten minutes. Everyone simply knows where things stand.', '12871449', 'Arman Rahimi', 'Head of product, Fieldwork' ],
        [ 'It is the first tool the whole studio actually uses. Even our freelancers kept it open all day.', '4531837', 'Tamika Reed', 'Studio director, Paperplane' ],
        [ 'We shipped a quarter early. I put half of that down to everyone seeing the same board.', '8727416', 'Ingrid Larsen', 'Operations lead, Nordlys' ] ),

    $grid( $meta( 'playful-music-school-testimonials-layout-for-divi-5-three-parent-quotes', 'Playful Music School Testimonials Layout for Divi 5: Three Parent Quotes', 'coaching', 'playful', 'pastel', 'playful-pastel', null, 'testimonials-avatar-grid' ),
        'Happy families', 'What parents tell us at pickup', 'A few kind words from our after-school music families.',
        [ 'My daughter used to hate practising. Now she plays the ukulele to the cat and asks for her next lesson.', '16152597', 'Amara Okoye', 'Mum of Lila, age 8' ],
        [ 'The teachers are patient, funny and brilliant with nervous beginners. My son went from silent to singing in a term.', '12871465', 'Rashid Malik', 'Dad of Zayn, age 10' ],
        [ 'Our twins joined the choir last autumn. Their Christmas concert made me cry, in the best way.', '6625914', 'Karin Berg', 'Mum of Ella and Noah, age 9' ] ),

    $grid( $meta( 'organic-neighbourhood-bakery-testimonials-layout-for-divi-5-three-regulars', 'Organic Neighbourhood Bakery Testimonials Layout for Divi 5: Three Regulars', 'restaurant', 'minimal', 'orange', 'organic-warm', null, 'testimonials-avatar-grid' ),
        'Our regulars', 'Why they come back every morning', 'Three neighbours on their favourite loaf and the people behind the counter.',
        [ 'The sourdough is the best I have eaten, and the staff remember my name and my order. That counts for a lot.', '5335405', 'Margit Holm', 'Regular since 2018' ],
        [ 'I walk past two other bakeries to get here. The cardamom buns are the reason, and the coffee is the excuse.', '6688614', 'Henrik Dahl', 'Regular since 2020' ],
        [ 'They made our wedding cake and a hundred tiny pastries. Every guest asked where it came from.', '36322504', 'Fatima Idris', 'Wedding order, June 2024' ] ),

    $grid( $meta( 'editorial-design-studio-testimonials-layout-for-divi-5-three-client-quotes', 'Editorial Design Studio Testimonials Layout for Divi 5: Three Client Quotes', 'agency', 'minimal', 'monochrome', 'editorial', null, 'testimonials-avatar-grid' ),
        'Kind words', 'What clients say after the launch', 'Three founders on the identities and sites we built together.',
        [ 'They listened longer than anyone else and then made something we could never have described. It is still the best decision we made.', '22678083', 'Claire Whitmore', 'Founder, Whitmore Ceramics' ],
        [ 'Our rebrand tripled direct bookings in a season. The work is calm, confident and entirely us.', '33323689', 'Rafael Costa', 'Owner, Casa Costa Hotel' ],
        [ 'A rare studio that tells you the truth early. The final site is the clearest thing we have ever published.', '39567150', 'Noor Haddad', 'Director, Meridian Arts' ] ),

    // ------------------------------------------------------------ large single quotes (3)
    $single( $meta( 'organic-running-club-testimonial-layout-for-divi-5-large-single-quote', 'Organic Running Club Testimonial Layout for Divi 5: Large Single Quote', 'fitness', 'minimal', 'green', 'organic-warm', null, 'quote-single-large' ),
        'At sixty-four I ran my first 10K, and finished smiling. Nobody here ever made me feel too old or too slow.', '7322312', 'Walter Pryce', 'Member since 2023, Saturday long-run group' ),

    $single( $meta( 'corporate-freelancer-tool-testimonial-layout-for-divi-5-large-single-quote', 'Corporate Freelancer Tool Testimonial Layout for Divi 5: Large Single Quote', 'saas', 'corporate', 'blue', 'corporate-trust', null, 'quote-single-large' ),
        'I used to lose a day every month to invoicing. Now it takes twenty minutes, and clients pay a week sooner.', '31372294', 'Selin Aydin', 'Freelance brand designer, Istanbul' ),

    $single( $meta( 'luxe-boutique-jeweller-testimonial-layout-for-divi-5-large-single-quote', 'Luxe Boutique Jeweller Testimonial Layout for Divi 5: Large Single Quote', 'ecommerce', 'elegant', 'monochrome', 'luxe', null, 'quote-single-large' ),
        'The ring arrived in a box I wanted to keep, and fitted as if it had always been mine. A very rare kind of care.', '9592569', 'Adaeze Nwosu', 'Customer since 2021, bespoke commission' ),

    // ------------------------------------------------------------ quotes over a photograph (3)
    $over( $meta( 'organic-wellness-retreat-testimonial-layout-for-divi-5-quote-over-photo', 'Organic Wellness Retreat Testimonial Layout for Divi 5: Quote Over Photo', 'coaching', 'minimal', 'green', 'organic-warm', null, 'quote-over-image' ),
        '34431332', 'Three days by the lake and I came home sleeping through the night for the first time in years. I did not know I needed the quiet.', 'Elena Marchetti', 'Guest, spring retreat' ),

    $over( $meta( 'luxe-restaurant-testimonial-layout-for-divi-5-quote-over-photo', 'Luxe Restaurant Testimonial Layout for Divi 5: Quote Over Photo', 'restaurant', 'elegant', 'monochrome', 'luxe', null, 'quote-over-image' ),
        '39047893', 'Every course felt considered and unhurried. We stayed three hours and never once checked the time.', 'Jonathan and Eleanor Price', 'Anniversary dinner, October' ),

    $over( $meta( 'dark-conservation-charity-testimonial-layout-for-divi-5-quote-over-photo', 'Dark Conservation Charity Testimonial Layout for Divi 5: Quote Over Photo', 'nonprofit', 'dark', 'blue', 'glass-dark', null, 'quote-over-image' ),
        '37786590', 'I gave once and kept receiving letters about what my gift had done. Two years later the wetland is full of birds.', 'Priya Raman', 'Monthly donor since 2022' ),

    // ------------------------------------------------------------ single testimonial cards (3)
    $card( $meta( 'soft-analytics-tool-testimonial-layout-for-divi-5-single-card', 'Soft Analytics Tool Testimonial Layout for Divi 5: Single Card', 'saas', 'minimal', 'purple', 'soft-saas', null, 'testimonial' ),
        'Loved by growth teams', 'Samir Qureshi', 'Head of growth', 'Brightside', '15568718',
        'Within a week we found three funnel leaks we had missed for a year. It paid for itself before the trial ended.' ),

    $card( $meta( 'corporate-recruitment-agency-testimonial-layout-for-divi-5-single-card', 'Corporate Recruitment Agency Testimonial Layout for Divi 5: Single Card', 'agency', 'corporate', 'blue', 'corporate-trust', null, 'testimonial' ),
        'What hiring managers say', 'Oliver Bennett', 'Head of engineering', 'Stackline', '26834972',
        'They sent us three exceptional candidates in the first fortnight. We hired the second one and never looked back.' ),

    $card( $meta( 'dark-infrastructure-testimonial-layout-for-divi-5-single-card', 'Dark Infrastructure Testimonial Layout for Divi 5: Single Card', 'saas', 'dark', 'green', 'tech-mono', null, 'testimonial' ),
        'From the on-call rota', 'Victor Lindgren', 'Principal engineer', 'Northgrid', '34253629',
        'Our pager went from twenty alerts a night to two. I have not been woken at 3am since March.' ),

    // ------------------------------------------------------------ press quotes (2)
    $press( $meta( 'editorial-studio-press-quotes-layout-for-divi-5-three-publications', 'Editorial Studio Press Quotes Layout for Divi 5: Three Publications', 'agency', 'minimal', 'monochrome', 'editorial', null, 'proof-press-quotes' ),
        'As seen in', 'What the press has said',
        [ 'The Monocle Review', 'The most quietly confident studio working today.', 'Studios to watch, March 2025' ],
        [ 'Design Weekly', 'They make the complicated feel inevitable.', 'The year in branding, December 2024' ],
        [ 'The Pattern Journal', 'A masterclass in restraint.', 'Interview with the founders, June 2025' ] ),

    $press( $meta( 'brutalist-sneaker-brand-press-quotes-layout-for-divi-5-three-publications', 'Brutalist Sneaker Brand Press Quotes Layout for Divi 5: Three Publications', 'ecommerce', 'bold', 'monochrome', 'brutalist-flat', null, 'proof-press-quotes' ),
        'Press', 'They said it, not us',
        [ 'Kicks Daily', 'The best-built sneaker under two hundred dollars.', 'Buyer\'s guide, February 2025' ],
        [ 'The Sole Report', 'Ugly in the best possible way.', 'Drop of the month, August 2024' ],
        [ 'Street Notes', 'We have stopped wearing anything else.', 'Editor\'s picks, May 2025' ] ),

    // ------------------------------------------------------------ one more grid and one more quote over a photograph (replacing two thin rating strips)
    $grid( $meta( 'dark-on-call-engineers-testimonials-layout-for-divi-5-three-avatar-cards', 'Dark On-Call Engineers Testimonials Layout for Divi 5: Three Avatar Cards', 'saas', 'dark', 'green', 'tech-mono', null, 'testimonials-avatar-grid' ),
        'From the rota', 'What the people on call say', 'Three engineers on what quieter nights did for their teams.',
        [ 'Our pager went from twenty alerts a night to two. I have not been woken at 3am since March.', '34253629', 'Victor Lindgren', 'Principal engineer, Northgrid' ],
        [ 'Incident reviews used to take a week. The timeline now writes itself and we spend the time fixing things.', '15568718', 'Samir Qureshi', 'Staff SRE, Brightside' ],
        [ 'It is the first alerting setup our juniors do not fear. Handovers take minutes instead of an afternoon.', '4531837', 'Tamika Reed', 'Engineering manager, Paperplane' ] ),

    $over( $meta( 'bold-festival-testimonial-layout-for-divi-5-quote-over-photo', 'Bold Festival Testimonial Layout for Divi 5: Quote Over Photo', 'events', 'bold', 'orange', 'bold-vibrant', null, 'quote-over-image' ),
        '5156686', 'Best weekend of my year. Great sound, great people, and the confetti cannon at midnight was unreal.', 'Maya Okonkwo', 'Weekend ticket holder, 2025' ),

    // ------------------------------------------------------------ sliders (2)
    $slider( $meta( 'dark-software-reviews-testimonial-slider-layout-for-divi-5-three-quotes', 'Dark Software Reviews Testimonial Slider Layout for Divi 5: Three Quotes', 'saas', 'dark', 'purple', 'glass-dark', null, 'testimonials-slider-quote' ),
        'In their words', 'What engineering teams say', 'Three leads on shipping faster with fewer fires.',
        [ 'Mei Tanaka / Staff engineer, Orbit', 'It is the first deployment tool my whole team trusts on a Friday afternoon.' ],
        [ 'Jonas Weber / CTO, Pixelhaus', 'We cut our release time from two hours to eleven minutes. Nobody wants to go back.' ],
        [ 'Aisha Bello / Platform lead, Kora', 'Rollbacks used to be a panic. Now they are a button, and a boring one.' ] ),

    $slider( $meta( 'playful-pottery-class-testimonial-slider-layout-for-divi-5-three-quotes', 'Playful Pottery Class Testimonial Slider Layout for Divi 5: Three Quotes', 'coaching', 'playful', 'pastel', 'playful-pastel', null, 'testimonials-slider-quote' ),
        'Kind words', 'What our students say', 'Three students on their first term at the wheel.',
        [ 'Beth / Beginner, Tuesday class', 'I have never been so messy or so calm. My wobbly mug is on the shelf and I love it.' ],
        [ 'Tomás / Intermediate, Thursday class', 'The teachers notice everything and never make you feel silly. Worth every Thursday evening.' ],
        [ 'Yuki / Weekend workshop', 'I brought a friend as a gift and we both signed up for the next term.' ] ),
];
