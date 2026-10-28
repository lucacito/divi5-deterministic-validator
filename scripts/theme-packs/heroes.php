<?php
declare(strict_types=1);

/**
 * Twenty free Divi 5 hero sections for the divi5lab.com catalogue: the plugin's ten hero recipes, each used twice in a
 * different design language, style kit, niche and tone. Photographs pinned by Pexels id (every URL checked).
 * Each entry: slug, title, niche, style, color (the store's filter axes), language, kit, recipe, copy (slot => text).
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$S = '&nbsp;&nbsp;';

return [
    [ 'slug' => 'candlelit-restaurant-hero-section-for-divi-5-farm-to-table-dinner',
      'title' => 'Candlelit Restaurant Hero Section for Divi 5: Farm-to-Table Dinner',
      'niche' => 'restaurant', 'style' => 'elegant', 'color' => 'orange', 'language' => 'organic-warm', 'kit' => 'warm-pill-soft',
      'recipe' => 'hero-cta', 'copy' => [
        0 => 'px:34874927:1920', 1 => 'Hearth & Field', 2 => 'Dinner, the way the farm intended.', 3 => 'Reserve a table' ] ],

    [ 'slug' => 'dark-music-festival-hero-section-for-divi-5-night-events-layout',
      'title' => 'Dark Music Festival Hero Section for Divi 5: Night Events Layout',
      'niche' => 'events', 'style' => 'dark', 'color' => 'purple', 'language' => 'glass-dark', 'kit' => null,
      'recipe' => 'hero-cta', 'copy' => [
        0 => 'px:5193531:1920', 1 => 'Afterglow Festival 2027', 2 => 'Three nights. One field. Every light on.', 3 => 'Get tickets' ] ],

    [ 'slug' => 'minimal-photographer-portfolio-hero-section-for-divi-5-editorial-layout',
      'title' => 'Minimal Photographer Portfolio Hero Section for Divi 5: Editorial Layout',
      'niche' => 'portfolio', 'style' => 'minimal', 'color' => 'monochrome', 'language' => 'editorial', 'kit' => 'underline-link',
      'recipe' => 'hero-pattern-centered', 'copy' => [
        0 => 'Light, patiently.',
        1 => $P( 'Ines Calloway is a documentary photographer who works with quiet places and the people who keep them. Currently booking for autumn 2027.' ),
        2 => 'View selected work' ] ],

    [ 'slug' => 'dark-developer-saas-hero-section-for-divi-5-logs-and-tracing',
      'title' => 'Dark Developer SaaS Hero Section for Divi 5: Logs and Tracing Landing',
      'niche' => 'saas', 'style' => 'dark', 'color' => 'green', 'language' => 'tech-mono', 'kit' => null,
      'recipe' => 'hero-pattern-centered', 'copy' => [
        0 => 'Logs you can read at 3am.',
        1 => $P( 'Tracelight collects logs, metrics and traces from every service and shows you the one line that broke production. Set up in ten minutes.' ),
        2 => 'Start free' ] ],

    [ 'slug' => 'corporate-real-estate-hero-section-for-divi-5-split-image-home-search',
      'title' => 'Corporate Real Estate Hero Section for Divi 5: Split Image Home Search',
      'niche' => 'real_estate', 'style' => 'corporate', 'color' => 'blue', 'language' => 'corporate-trust', 'kit' => null,
      'recipe' => 'hero-split-right', 'copy' => [
        0 => 'Residential sales · Austin',
        1 => 'Find the home that fits the life you have.',
        2 => $P( 'Local agents, honest valuations and a team that answers the phone. We have helped 1,200 families move since 2009.' ),
        3 => 'Browse homes', 4 => $P( '<a href="#">Book a free valuation</a>' ),
        5 => 'px:4626268:1400', 6 => 'A modern house with a glass front glowing warmly at dusk' ] ],

    [ 'slug' => 'luxe-watch-ecommerce-hero-section-for-divi-5-elegant-split-layout',
      'title' => 'Luxe Watch Ecommerce Hero Section for Divi 5: Elegant Split Layout',
      'niche' => 'ecommerce', 'style' => 'elegant', 'color' => 'monochrome', 'language' => 'luxe', 'kit' => null,
      'recipe' => 'hero-split-right', 'copy' => [
        0 => 'The Autumn Collection',
        1 => 'Time, finished by hand.',
        2 => $P( 'Swiss-movement watches assembled in small numbers, each with a five-year service promise and free engraving.' ),
        3 => 'Shop the collection', 4 => $P( '<a href="#">Book a private viewing</a>' ),
        5 => 'px:7474362:1400', 6 => 'A watch on a brown leather strap glowing against a dark background' ] ],

    [ 'slug' => 'playful-life-coach-hero-section-for-divi-5-pastel-overlap-layout',
      'title' => 'Playful Life Coach Hero Section for Divi 5: Pastel Overlap Layout',
      'niche' => 'coaching', 'style' => 'playful', 'color' => 'pastel', 'language' => 'playful-pastel', 'kit' => 'dotted-pill',
      'recipe' => 'hero-split-left-overlap', 'copy' => [
        0 => 'px:3724382:1400', 1 => 'A smiling woman lit in soft pink and violet light',
        2 => 'Career coaching', 3 => 'Change jobs without losing your spark.',
        4 => $P( 'One-to-one coaching for people who want a work life that feels like theirs. Twelve weeks, plain talk, real results.' ),
        5 => 'Book a free chat' ] ],

    [ 'slug' => 'organic-trail-running-club-hero-section-for-divi-5-fitness-overlap-layout',
      'title' => 'Organic Trail Running Club Hero Section for Divi 5: Fitness Overlap Layout',
      'niche' => 'fitness', 'style' => 'minimal', 'color' => 'green', 'language' => 'organic-warm', 'kit' => 'soft-solid',
      'recipe' => 'hero-split-left-overlap', 'copy' => [
        0 => 'px:39786816:1400', 1 => 'A runner on a quiet forest trail in morning light',
        2 => 'Trail running club', 3 => 'Run slow. Run far. Run together.',
        4 => $P( 'Saturday long runs, weekday coffee loops and coached sessions for every pace. Newcomers are always welcome.' ),
        5 => 'Join a run' ] ],

    [ 'slug' => 'bold-street-food-festival-hero-section-for-divi-5-events-image-layout',
      'title' => 'Bold Street Food Festival Hero Section for Divi 5: Events Image Layout',
      'niche' => 'events', 'style' => 'bold', 'color' => 'orange', 'language' => 'bold-vibrant', 'kit' => 'neo-brutal',
      'recipe' => 'hero-image-scrim', 'copy' => [
        0 => 'px:17704287:1920', 1 => 'Saturday 14 June · Riverside',
        2 => 'Forty stalls. One very full day.',
        3 => $P( 'The city\'s loudest street food festival returns with live music, cooking battles and plenty of napkins.' ),
        4 => 'Get tickets', 5 => 'See the stalls',
        6 => $P( 'Riverside Park' ), 7 => $P( 'Gates open 11am to 9pm' ), 8 => $P( 'Free entry, pay as you eat' ) ] ],

    [ 'slug' => 'editorial-nonprofit-hero-section-for-divi-5-beach-cleanup-charity',
      'title' => 'Editorial Nonprofit Hero Section for Divi 5: Beach Cleanup Charity',
      'niche' => 'nonprofit', 'style' => 'minimal', 'color' => 'blue', 'language' => 'editorial', 'kit' => null,
      'recipe' => 'hero-image-scrim', 'copy' => [
        0 => 'px:9034669:1920', 1 => 'Clean Tide Collective',
        2 => 'Every piece we lift is one the ocean keeps.',
        3 => $P( 'Volunteers have cleared 48 tonnes of plastic from our coastline. Join a cleanup this month or fund the next one.' ),
        4 => 'Join a cleanup', 5 => 'Donate',
        6 => $P( 'Monthly cleanups' ), 7 => $P( 'Saturdays at 9am' ), 8 => $P( 'Bags and gloves provided' ) ] ],

    [ 'slug' => 'dark-nonprofit-hero-section-for-divi-5-impact-statistics-gradient',
      'title' => 'Dark Nonprofit Hero Section for Divi 5: Impact Statistics Gradient',
      'niche' => 'nonprofit', 'style' => 'dark', 'color' => 'purple', 'language' => 'glass-dark', 'kit' => 'hairline-halo',
      'recipe' => 'hero-gradient-stats', 'copy' => [
        0 => 'Fund the next meal', 1 => 'Every dollar feeds four people.',
        2 => $P( 'Our food rescue network turns surplus into meals within 24 hours, so almost nothing goes to waste and nobody goes without.' ),
        3 => 'Give today',
        4 => $P( '2.1M' ), 5 => $P( 'meals served last year' ),
        6 => $P( '4x' ), 7 => $P( 'food delivered per dollar donated' ),
        8 => $P( '180+' ), 9 => $P( 'local partner kitchens' ) ] ],

    [ 'slug' => 'corporate-growth-agency-hero-section-for-divi-5-results-stats-gradient',
      'title' => 'Corporate Growth Agency Hero Section for Divi 5: Results Stats Gradient',
      'niche' => 'agency', 'style' => 'corporate', 'color' => 'blue', 'language' => 'corporate-trust', 'kit' => 'raised-square',
      'recipe' => 'hero-gradient-stats', 'copy' => [
        0 => 'Growth marketing agency', 1 => 'Pipeline you can count on.',
        2 => $P( 'We run paid, email and search for B2B teams, and we report on revenue instead of clicks.' ),
        3 => 'See client results',
        4 => $P( '312%' ), 5 => $P( 'average pipeline growth in year one' ),
        6 => $P( '3.4x' ), 7 => $P( 'return on ad spend' ),
        8 => $P( '90+' ), 9 => $P( 'B2B clients served' ) ] ],

    [ 'slug' => 'minimal-architecture-studio-portfolio-hero-for-divi-5-editorial-typography',
      'title' => 'Minimal Architecture Studio Portfolio Hero for Divi 5: Editorial Typography',
      'niche' => 'portfolio', 'style' => 'minimal', 'color' => 'monochrome', 'language' => 'editorial', 'kit' => null,
      'recipe' => 'hero-editorial-minimal', 'copy' => [
        0 => 'Studio Marren · Architecture',
        1 => 'Buildings that make a quiet case for staying.',
        2 => $P( 'An independent studio designing homes, libraries and small public spaces in timber, brick and light. Founded in Copenhagen, building worldwide.' ),
        3 => $P( '<a href="#">Read about the studio</a>' ) ] ],

    [ 'slug' => 'luxe-private-estates-real-estate-hero-for-divi-5-editorial-minimal',
      'title' => 'Luxe Private Estates Real Estate Hero for Divi 5: Editorial Minimal',
      'niche' => 'real_estate', 'style' => 'elegant', 'color' => 'monochrome', 'language' => 'luxe', 'kit' => null,
      'recipe' => 'hero-editorial-minimal', 'copy' => [
        0 => 'Harrow & Vane · Private Estates',
        1 => 'A short list of houses worth the journey.',
        2 => $P( 'We represent a small number of exceptional properties each year and sell them quietly, to the right people, at the right price.' ),
        3 => $P( '<a href="#">View the current collection</a>' ) ] ],

    [ 'slug' => 'brutalist-hot-sauce-ecommerce-hero-section-for-divi-5-bordered-cells',
      'title' => 'Brutalist Hot Sauce Ecommerce Hero Section for Divi 5: Bordered Cells',
      'niche' => 'ecommerce', 'style' => 'bold', 'color' => 'red', 'language' => 'brutalist-flat', 'kit' => null,
      'recipe' => 'hero-bordered-cells', 'copy' => [
        0 => 'Hot sauce, but make it honest.',
        2 => $P( 'Small batches, fermented for 60 days' ),
        4 => $P( 'Real peppers, five ingredients, no sugar' ),
        6 => $P( 'Free shipping on every box of three' ),
        7 => 'Shop the sauces' ] ],

    [ 'slug' => 'bold-first-10k-running-coach-hero-section-for-divi-5-fitness-bordered-cells',
      'title' => 'Bold First 10K Running Coach Hero Section for Divi 5: Fitness Bordered Cells',
      'niche' => 'fitness', 'style' => 'bold', 'color' => 'green', 'language' => 'bold-vibrant', 'kit' => 'pill-caps',
      'recipe' => 'hero-bordered-cells', 'copy' => [
        0 => 'Your first 10K starts here.',
        2 => $P( 'Eight weeks, three runs a week' ),
        4 => $P( 'A coach who texts back' ),
        6 => $P( 'Race day with us at the finish' ),
        7 => 'Start the plan' ] ],

    [ 'slug' => 'dark-web-studio-agency-hero-section-for-divi-5-spec-card-layout',
      'title' => 'Dark Web Studio Agency Hero Section for Divi 5: Spec Card Layout',
      'niche' => 'agency', 'style' => 'dark', 'color' => 'green', 'language' => 'tech-mono', 'kit' => null,
      'recipe' => 'hero-spec-card', 'copy' => [
        0 => $P( 'Web studio · Booking for Q4' ),
        1 => 'We build fast websites for slow industries.',
        2 => $P( 'A small studio designing and building marketing sites on Divi for law firms, clinics and manufacturers.' ),
        3 => 'Request a quote', 4 => 'See the work',
        5 => $P( 'Engagement sheet' ),
        6 => $P( "Scope{$S}Design, build, launch" ), 7 => $P( "Timeline{$S}Six to eight weeks" ),
        8 => $P( "Platform{$S}Divi 5, fully editable" ), 9 => $P( "Support{$S}60 days after launch" ) ] ],

    [ 'slug' => 'editorial-executive-coaching-hero-section-for-divi-5-programme-sheet',
      'title' => 'Editorial Executive Coaching Hero Section for Divi 5: Programme Sheet',
      'niche' => 'coaching', 'style' => 'minimal', 'color' => 'monochrome', 'language' => 'editorial', 'kit' => 'hairline-frame-caps',
      'recipe' => 'hero-spec-card', 'copy' => [
        0 => $P( 'Cohort 14 · Starts 6 October' ),
        1 => 'Leadership coaching for first-time managers.',
        2 => $P( 'Six months of one-to-one coaching and a small peer group, so you lead with confidence from week one.' ),
        3 => 'Apply now', 4 => 'Download the outline',
        5 => $P( 'Programme sheet' ),
        6 => $P( "Format{$S}Fortnightly 1:1 sessions" ), 7 => $P( "Group{$S}Six leaders, monthly" ),
        8 => $P( "Duration{$S}Six months" ), 9 => $P( "Investment{$S}\$3,600" ) ] ],

    [ 'slug' => 'soft-saas-meeting-notes-hero-section-for-divi-5-product-glow',
      'title' => 'Soft SaaS Meeting Notes Hero Section for Divi 5: Product Glow Layout',
      'niche' => 'saas', 'style' => 'minimal', 'color' => 'purple', 'language' => 'soft-saas', 'kit' => null,
      'recipe' => 'hero-product-glow', 'copy' => [
        0 => 'New: shared notebooks', 1 => 'Meeting notes that write themselves.',
        2 => $P( 'Notewell joins your calls, writes the summary and files the action items before you hang up.' ),
        3 => 'Start free', 4 => 'Watch the demo',
        5 => 'px:577210:1600', 6 => 'A laptop on a sofa showing a colourful analytics dashboard',
        7 => $P( 'No credit card needed' ), 8 => $P( 'Works with your calendar' ), 9 => $P( 'Free for small teams' ) ] ],

    [ 'slug' => 'dark-restaurant-booking-software-hero-section-for-divi-5-product-glow',
      'title' => 'Dark Restaurant Booking Software Hero Section for Divi 5: Product Glow Layout',
      'niche' => 'restaurant', 'style' => 'dark', 'color' => 'orange', 'language' => 'glass-dark', 'kit' => null,
      'recipe' => 'hero-product-glow', 'copy' => [
        0 => 'For busy restaurants', 1 => 'Fill every table, every night.',
        2 => $P( 'Tablefold takes bookings, manages the waitlist and texts guests before they forget. Set up in an afternoon.' ),
        3 => 'Start free trial', 4 => 'See it in action',
        5 => 'px:12935087:1600', 6 => 'A tablet showing a restaurant menu on a cafe table beside a coffee',
        7 => $P( 'Setup in an afternoon' ), 8 => $P( 'No hardware needed' ), 9 => $P( 'Cancel any time' ) ] ],
];
