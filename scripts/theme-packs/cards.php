<?php
declare(strict_types=1);

/**
 * Ten free Divi 5 card layouts for the divi5lab.com catalogue: blurb cards, icon-feature cards, value cards, bento
 * tiles, carousel cards and profile cards, each in a different design language, style kit, niche and tone.
 * Photographs pinned by Pexels id (every URL checked). Same entry shape as heroes.php.
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$H = static fn ( string $h, string $t ): string => "<h3>$h</h3>\n<p>$t</p>";

return [
    [ 'slug' => 'minimal-design-studio-services-cards-layout-for-divi-5-four-icon-blurbs',
      'title' => 'Minimal Design Studio Services Cards Layout for Divi 5: Four Icon Blurbs',
      'niche' => 'agency', 'style' => 'minimal', 'color' => 'monochrome', 'language' => 'editorial', 'kit' => null,
      'recipe' => 'blurb-grid', 'copy' => [
        0 => 'Studio services', 1 => 'Four disciplines under one roof, run by the same small team from first sketch to final file.',
        2 => 'Brand identity', 3 => 'fa:f1fc', 5 => $P( 'Marks, palettes and type systems built to last longer than a trend.' ),
        6 => 'Editorial design', 7 => 'fa:f02d', 9 => $P( 'Books, magazines and long-form layouts with room to breathe.' ),
        10 => 'Packaging', 11 => 'fa:f1b2', 13 => $P( 'Boxes, labels and materials that feel right in the hand.' ),
        14 => 'Art direction', 15 => 'fa:f030', 17 => $P( 'Shoots and campaigns directed from first concept to final edit.' ) ] ],

    [ 'slug' => 'bold-fitness-programs-cards-layout-for-divi-5-four-class-blurbs',
      'title' => 'Bold Fitness Programs Cards Layout for Divi 5: Four Class Blurbs',
      'niche' => 'fitness', 'style' => 'bold', 'color' => 'orange', 'language' => 'bold-vibrant', 'kit' => 'neo-brutal',
      'recipe' => 'blurb-grid', 'copy' => [
        0 => 'Programs', 1 => 'Pick the class that fits your week. Every session is coached, scaled to you and over in under an hour.',
        2 => 'Strength', 3 => 'fa:f44b', 5 => $P( 'Barbell basics and smart progressions, coached in small groups.' ),
        6 => 'Conditioning', 7 => 'fa:f21e', 9 => $P( 'Fast, friendly circuits that raise your engine without wrecking your week.' ),
        10 => 'Recovery', 11 => 'fa:f004', 13 => $P( 'Mobility, breathing and foam-roller time to keep you training for years.' ),
        14 => 'Open gym', 15 => 'fa:f015', 17 => $P( 'Free floor time for members, with a coach on hand if you want a hand.' ) ] ],

    [ 'slug' => 'organic-restaurant-feature-cards-bento-layout-for-divi-5-tasting-table',
      'title' => 'Organic Restaurant Feature Cards Bento Layout for Divi 5: Tasting Table',
      'niche' => 'restaurant', 'style' => 'elegant', 'color' => 'orange', 'language' => 'organic-warm', 'kit' => null,
      'recipe' => 'features-bento', 'copy' => [
        0 => 'Why guests return', 1 => 'Small kitchen, big attention',
        2 => $P( 'Everything on the menu is grown, baked or cured within thirty miles of the door.' ),
        3 => $P( '<a href="#">See the menu</a>' ),
        4 => 'px:19986457', 5 => 'A long wooden dining table set with plates, glasses and candles',
        6 => 'The tasting table', 7 => $P( 'Seven courses at one long table, with the chef explaining each plate as it lands.' ),
        8 => 'fa:f2e7', 10 => 'Seasonal menu', 11 => $P( 'Changes every fortnight with the harvest.' ),
        12 => 'fa:f06c', 14 => 'Natural wines', 15 => $P( 'Small growers, poured by the glass.' ),
        16 => 'Private dining', 17 => $P( 'A room for twelve, by arrangement.' ),
        18 => 'The kitchen garden',
        19 => $P( 'Herbs, leaves and edible flowers picked an hour before service, a short walk from the pass.' ),
        20 => $P( '<a href="#">Book a table</a>' ) ] ],

    [ 'slug' => 'soft-case-study-cards-layout-for-divi-5-project-grid',
      'title' => 'Soft Case Study Cards Layout for Divi 5: Project Grid',
      'niche' => 'portfolio', 'style' => 'minimal', 'color' => 'purple', 'language' => 'soft-saas', 'kit' => null,
      'recipe' => 'portfolio-featured-grid', 'copy' => [
        0 => 'Case studies', 1 => 'Work that moved the numbers', 2 => 'View all work',
        3 => 'px:29501720', 4 => 'A wall of overlapping street posters in every colour',
        5 => 'Poster Wall', 6 => $P( 'Campaign · Retail' ),
        7 => 'px:33774247', 8 => 'A blue gift box wrapped with a yellow ribbon',
        9 => 'Blue Box', 10 => $P( 'Packaging · Snacks' ),
        11 => 'px:40242694', 12 => 'A colourful mural of a fish-like creature on a city wall',
        13 => 'Mural Project', 14 => $P( 'Mural · Community' ),
        15 => 'px:6620992', 16 => 'A hand inking a letterpress roller with orange ink',
        17 => 'Letterpress', 18 => $P( 'Identity · Print studio' ),
        19 => 'px:4483218', 20 => 'Yellow illustrated prints pinned to a white wall',
        21 => 'Small Press', 22 => $P( 'Illustration · Zines' ) ] ],

    [ 'slug' => 'soft-nonprofit-values-cards-layout-for-divi-5-three-icon-values',
      'title' => 'Soft Nonprofit Values Cards Layout for Divi 5: Three Icon Values',
      'niche' => 'nonprofit', 'style' => 'minimal', 'color' => 'purple', 'language' => 'soft-saas', 'kit' => null,
      'recipe' => 'icon-values', 'copy' => [
        0 => $P( 'What guides our work' ), 1 => $P( 'Three promises we keep to every community we serve.' ),
        2 => 'fa:f004', 4 => $P( 'Dignity first' ), 5 => $P( 'Everyone we help is treated as a neighbour, never as a case number.' ),
        6 => 'fa:f0c0', 8 => $P( 'Local by design' ), 9 => $P( 'Every project is led by people who live in the community it serves.' ),
        10 => 'fa:f15c', 12 => $P( 'Open books' ), 13 => $P( 'Every dollar is accounted for, and the full report is published each spring.' ) ] ],

    [ 'slug' => 'dark-developer-platform-feature-cards-bento-layout-for-divi-5',
      'title' => 'Dark Developer Platform Feature Cards Bento Layout for Divi 5',
      'niche' => 'saas', 'style' => 'dark', 'color' => 'green', 'language' => 'tech-mono', 'kit' => null,
      'recipe' => 'features-bento', 'copy' => [
        0 => 'Platform', 1 => 'Everything between commit and customer',
        2 => $P( 'One workflow from the first push to the last request, with nothing to stitch together.' ),
        3 => $P( '<a href="#">See the full platform</a>' ),
        4 => 'px:34803985', 5 => 'A laptop showing code in an editor, lit by blue and pink light',
        6 => 'Deploy previews', 7 => $P( 'Every pull request gets its own live URL, built in under a minute and deleted when you merge.' ),
        8 => 'fa:f121', 10 => 'Instant rollbacks', 11 => $P( 'Revert to any build in one click.' ),
        12 => 'fa:f0e7', 14 => 'Secrets vault', 15 => $P( 'Encrypted and scoped per environment.' ),
        16 => 'Edge functions', 17 => $P( 'Run code within 30 ms of your users.' ),
        18 => 'Observability built in',
        19 => $P( 'Logs, metrics and traces for every deploy, searchable without leaving the dashboard.' ),
        20 => $P( '<a href="#">Read the docs</a>' ) ] ],

    [ 'slug' => 'brutalist-coffee-gear-ecommerce-feature-cards-bento-layout-for-divi-5',
      'title' => 'Brutalist Coffee Gear Ecommerce Feature Cards Bento Layout for Divi 5',
      'niche' => 'ecommerce', 'style' => 'bold', 'color' => 'orange', 'language' => 'brutalist-flat', 'kit' => null,
      'recipe' => 'features-bento', 'copy' => [
        0 => 'The kit', 1 => 'Gear we would buy ourselves',
        2 => $P( 'Every item is tested on our own bar before it reaches the shop.' ),
        3 => $P( '<a href="#">Shop all gear</a>' ),
        4 => 'px:16627279', 5 => 'A coffee bar with white grinders, a scale and dried flowers',
        6 => 'Grinders', 7 => $P( 'Burrs tested against forty beans so you do not have to guess.' ),
        8 => 'fa:f0f4', 10 => 'Scales', 11 => $P( 'Accurate to 0.1 g, with a built-in timer.' ),
        12 => 'fa:f0ad', 14 => 'Kettles', 15 => $P( 'Gooseneck control for an even pour.' ),
        16 => 'Filters', 17 => $P( 'Paper and metal, in every common size.' ),
        18 => 'Espresso machines',
        19 => $P( 'Our pick of home machines for every budget, with free setup and descale guidance.' ),
        20 => $P( '<a href="#">Compare machines</a>' ) ] ],

    [ 'slug' => 'playful-summer-camp-events-feature-cards-carousel-layout-for-divi-5',
      'title' => 'Playful Summer Camp Events Feature Cards Carousel Layout for Divi 5',
      'niche' => 'events', 'style' => 'playful', 'color' => 'pastel', 'language' => 'playful-pastel', 'kit' => null,
      'recipe' => 'features-carousel-strip', 'copy' => [
        0 => 'This summer', 1 => 'Six ways to spend a day',
        2 => $P( 'Every camp day mixes outdoors, making and a great deal of laughing.' ),
        3 => 'fa:f1bb', 5 => 'Forest play', 6 => $P( 'Dens, trails and mud kitchens under the trees.' ),
        7 => 'fa:f1fc', 9 => 'Art studio', 10 => $P( 'Paint, clay and glue for the very messy.' ),
        11 => 'fa:f001', 13 => 'Music lab', 14 => $P( 'Drums, ukuleles and a band by Friday.' ),
        15 => 'fa:f2e7', 17 => 'Cooking club', 18 => $P( 'Pizza, pancakes and one questionable soup.' ),
        19 => 'fa:f206', 21 => 'Bike skills', 22 => $P( 'Balance, braking and bumps for every confidence level.' ),
        23 => 'fa:f005', 25 => 'Show day', 26 => $P( 'A Friday performance for families, with snacks.' ) ] ],

    [ 'slug' => 'dark-digital-agency-capabilities-cards-carousel-layout-for-divi-5',
      'title' => 'Dark Digital Agency Capabilities Cards Carousel Layout for Divi 5',
      'niche' => 'agency', 'style' => 'dark', 'color' => 'purple', 'language' => 'glass-dark', 'kit' => 'hairline-halo',
      'recipe' => 'features-carousel-strip', 'copy' => [
        0 => 'Capabilities', 1 => 'What we ship',
        2 => $P( 'A senior team that designs, builds and launches digital products from a blank page.' ),
        3 => 'fa:f121', 5 => 'Product design', 6 => $P( 'Research, flows and interfaces tested with real users.' ),
        7 => 'fa:f108', 9 => 'Web apps', 10 => $P( 'Fast, accessible front ends on a stack your team can keep.' ),
        11 => 'fa:f03d', 13 => 'Motion', 14 => $P( 'Product films and interface animation that explain at a glance.' ),
        15 => 'fa:f0eb', 17 => 'Brand strategy', 18 => $P( 'Positioning and naming for products that need a point of view.' ),
        19 => 'fa:f1d8', 21 => 'Launch campaigns', 22 => $P( 'Landing pages, assets and a plan for the first ninety days.' ),
        23 => 'fa:f085', 25 => 'Design systems', 26 => $P( 'Components and tokens that keep every team on the same page.' ) ] ],

    [ 'slug' => 'luxe-real-estate-agent-profile-cards-layout-for-divi-5-team-grid',
      'title' => 'Luxe Real Estate Agent Profile Cards Layout for Divi 5: Team Grid',
      'niche' => 'real_estate', 'style' => 'elegant', 'color' => 'monochrome', 'language' => 'luxe', 'kit' => null,
      'recipe' => 'team-grid', 'copy' => [
        0 => 'The team', 1 => 'Meet the agents',
        2 => $P( 'Four specialists who know every street, every school and every price in the neighbourhood.' ),
        3 => 'px:20022702:800', 4 => 'Isabelle portrait', 5 => 'Isabelle Moreau', 6 => $P( 'Partner, residential sales' ),
        7 => 'px:30468668:800', 8 => 'Hannah portrait', 9 => 'Hannah Voss', 10 => $P( 'Senior agent, new homes' ),
        11 => 'px:38677835:800', 12 => 'Daniel portrait', 13 => 'Daniel Reyes', 14 => $P( 'Agent, waterfront' ),
        15 => 'px:12311579:800', 16 => 'Marcus portrait', 17 => 'Marcus Webb', 18 => $P( 'Agent, lettings' ) ] ],
];
