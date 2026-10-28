<?php
declare(strict_types=1);

/**
 * Thirty free Divi 5 pricing layouts for the divi5lab.com catalogue: 12 three-tier plans, 9 two-plan highlights and
 * 9 comparison tables, each a different business, billing model, design language and style kit. No photographs.
 * Same entry shape as heroes.php.
 */
$P = static fn ( string $t ): string => "<p>$t</p>";

/** Three-tier plan cards: eyebrow, heading, intro, then three plans [name, desc, price, period, f1, f2, f3, button] (the middle one is featured) and the badge. */
$three = static function ( array $m, string $eyebrow, string $h, string $intro, array $a, array $b, array $c, string $badge = 'Most popular' ) use ( $P ): array {
    $copy = [ 0 => $eyebrow, 1 => $h, 2 => $P( $intro ), 17 => $P( $badge ) ];
    foreach ( [ [ $a, 3 ], [ $b, 18 ], [ $c, 32 ] ] as [ $x, $o ] ) {
        $copy += [ $o => $x[0], $o + 1 => $P( $x[1] ), $o + 2 => $P( $x[2] ), $o + 3 => $P( $x[3] ), $o + 6 => $P( $x[4] ), $o + 9 => $P( $x[5] ), $o + 12 => $P( $x[6] ), $o + 13 => $x[7] ];
    }
    return $m + [ 'copy' => $copy ];
};

/** Two-plan highlight: [eyebrow, heading, intro, planA[label,name,price,terms,f x5,button], badge, planB[name,price,terms,f x5,button]]. */
$two = static function ( array $m, string $eyebrow, string $h, string $intro, array $a, string $badge, array $b ) use ( $P ): array {
    $copy = [ 0 => $eyebrow, 1 => $h, 2 => $P( $intro ), 3 => $P( $a[0] ), 4 => $a[1], 5 => $P( $a[2] ), 6 => $P( $a[3] ),
        22 => $a[9], 23 => $P( $badge ), 24 => $b[0], 25 => $P( $b[1] ), 26 => $P( $b[2] ), 42 => $b[8] ];
    foreach ( [ 9, 12, 15, 18, 21 ] as $i => $slot ) { $copy[ $slot ] = $P( $a[4 + $i] ); }
    foreach ( [ 29, 32, 35, 38, 41 ] as $i => $slot ) { $copy[ $slot ] = $P( $b[3 + $i] ); }
    return $m + [ 'copy' => $copy ];
};

/** Comparison table: [eyebrow, heading, intro, plans[name,price] x3, badge, rows[5 labels], limits[3], button]. */
$table = static function ( array $m, string $eyebrow, string $h, string $intro, array $plans, string $badge, array $rows, array $limits, string $button ) use ( $P ): array {
    $copy = [ 0 => $eyebrow, 1 => $h, 2 => $P( $intro ), 4 => $P( $plans[0][0] ), 5 => $P( $plans[0][1] ), 6 => $P( $badge ),
        7 => $P( $plans[1][0] ), 8 => $P( $plans[1][1] ), 9 => $P( $plans[2][0] ), 10 => $P( $plans[2][1] ),
        11 => $P( $rows[0] ), 18 => $P( $rows[1] ), 22 => $P( $rows[2] ), 28 => $P( $rows[3] ), 33 => $P( $rows[4] ),
        19 => $P( $limits[0] ), 20 => $P( $limits[1] ), 21 => $P( $limits[2] ), 38 => $button ];
    return $m + [ 'copy' => $copy ];
};
$meta = static fn ( string $slug, string $title, string $niche, string $style, string $color, string $lang, ?string $kit, string $recipe ) =>
    [ 'slug' => $slug, 'title' => $title, 'niche' => $niche, 'style' => $style, 'color' => $color, 'language' => $lang, 'kit' => $kit, 'recipe' => $recipe ];

return [
    // ---------------------------------------------------------------- three-tier plan cards (12)
    $three( $meta( 'soft-newsletter-platform-pricing-section-for-divi-5-three-tier-plans', 'Soft Newsletter Platform Pricing Section for Divi 5: Three-Tier Plans', 'saas', 'minimal', 'purple', 'soft-saas', null, 'pricing-three-tier' ),
        'Pricing', 'Send more. Pay for what you send.', 'Every plan includes unlimited subscribers, a drag-and-drop editor and a free domain check.',
        [ 'Writer', 'For a first newsletter.', '$0', 'free forever', 'Up to 1,000 sends a month', 'Basic stats', 'Community support', 'Start free' ],
        [ 'Publisher', 'For a growing audience.', '$24', 'per month', 'Up to 50,000 sends a month', 'Paid subscriptions', 'Automations and segments', 'Try 14 days free' ],
        [ 'Network', 'For several titles.', '$89', 'per month', 'Up to 500,000 sends a month', 'Five publications', 'Priority support', 'Talk to us' ] ),

    $three( $meta( 'dark-observability-pricing-section-for-divi-5-three-tier-usage-plans', 'Dark Observability Pricing Section for Divi 5: Three-Tier Usage Plans', 'saas', 'dark', 'blue', 'glass-dark', null, 'pricing-three-tier' ),
        'Plans', 'Pay for signal, not seats.', 'Billed on events ingested. Every plan includes unlimited users and 14 days of free history.',
        [ 'Hobby', 'For side projects.', '$0', 'per month', '1M events a month', '7-day retention', 'Community Slack', 'Start free' ],
        [ 'Team', 'For production services.', '$49', 'per month', '50M events a month', '30-day retention', 'Alert routing and on-call', 'Start trial' ],
        [ 'Scale', 'For many services.', '$249', 'per month', '1B events a month', '90-day retention', 'SSO and audit logs', 'Contact sales' ] ),

    $three( $meta( 'organic-yoga-studio-pricing-section-for-divi-5-three-tier-memberships', 'Organic Yoga Studio Pricing Section for Divi 5: Three-Tier Memberships', 'fitness', 'minimal', 'green', 'organic-warm', null, 'pricing-three-tier' ),
        'Memberships', 'Find a rhythm that fits your week.', 'No joining fee, and you can pause any month.',
        [ 'Drop-in', 'For the curious.', '$22', 'per class', 'Any class on the timetable', 'Mat hire included', 'No commitment', 'Book a class' ],
        [ 'Monthly', 'For a steady practice.', '$99', 'per month', 'Unlimited classes', 'One guest pass a month', 'Free workshops', 'Join now' ],
        [ 'Yearly', 'For a long-term habit.', '$990', 'per year', 'Everything in Monthly', 'Two months free', 'Private session on joining', 'Join for a year' ] ),

    $three( $meta( 'playful-coding-camp-pricing-section-for-divi-5-three-tier-sessions', 'Playful Coding Camp Pricing Section for Divi 5: Three-Tier Sessions', 'coaching', 'playful', 'pastel', 'playful-pastel', null, 'pricing-three-tier' ),
        'Summer camp', 'Pick a week, a fortnight or the whole summer.', 'Small groups, friendly mentors and a demo day at the end of every session.',
        [ 'One week', 'A taster for new coders.', '$240', 'per week', 'Five half-days', 'Laptop provided', 'Demo-day showcase', 'Reserve a week' ],
        [ 'Fortnight', 'Time to build something.', '$440', 'per fortnight', 'Ten half-days', 'Own project built', 'Certificate and trophy', 'Reserve two weeks' ],
        [ 'Whole summer', 'For budding developers.', '$1,100', 'per summer', 'Eight weeks of camp', 'Mentor one-to-ones', 'Free winter workshop', 'Reserve a place' ] ),

    $three( $meta( 'bold-tattoo-studio-pricing-section-for-divi-5-three-tier-session-rates', 'Bold Tattoo Studio Pricing Section for Divi 5: Three-Tier Session Rates', 'portfolio', 'bold', 'red', 'bold-vibrant', 'neo-brutal', 'pricing-three-tier' ),
        'Session rates', 'Small flash, long days, big pieces.', 'Every price includes aftercare, a touch-up and a very patient artist.',
        [ 'Flash', 'Pick from the sheet.', '$120', 'per piece', 'Up to one hour', 'Pre-drawn designs', 'Walk-ins welcome', 'See flash' ],
        [ 'Half day', 'Your idea, our hands.', '$450', 'per half day', 'Up to four hours', 'Custom drawing', 'Free consultation', 'Book a half day' ],
        [ 'Full day', 'For the big stuff.', '$800', 'per day', 'Up to eight hours', 'Sleeves and back pieces', 'Lunch break included', 'Book a day' ] ),

    $three( $meta( 'editorial-wedding-photographer-pricing-section-for-divi-5-three-tier-packages', 'Editorial Wedding Photographer Pricing Section for Divi 5: Three-Tier Packages', 'portfolio', 'minimal', 'monochrome', 'editorial', null, 'pricing-three-tier' ),
        'Collections', 'Three ways to remember the day.', 'Every collection includes an online gallery, full usage rights and a pre-wedding call.',
        [ 'Elopement', 'For two, and a few friends.', '$2,400', 'up to 4 hours', 'One photographer', '200 edited images', 'Online gallery', 'Enquire' ],
        [ 'Wedding day', 'The full story.', '$4,800', 'up to 10 hours', 'Two photographers', '600 edited images', 'Printed 30-page album', 'Enquire' ],
        [ 'Weekend', 'From the rehearsal to the brunch.', '$7,500', 'two days', 'Two photographers', '1,000 edited images', 'Film and album', 'Enquire' ] ),

    $three( $meta( 'corporate-property-management-pricing-section-for-divi-5-three-tier-fees', 'Corporate Property Management Pricing Section for Divi 5: Three-Tier Fees', 'real_estate', 'corporate', 'blue', 'corporate-trust', null, 'pricing-three-tier' ),
        'Management fees', 'Simple fees, no hidden extras.', 'A flat percentage of rent collected. Nothing to pay if the home is empty.',
        [ 'Find a tenant', 'Marketing and vetting only.', '50%', 'of first month', 'Photography and listing', 'Referencing and checks', 'Tenancy agreement', 'Get started' ],
        [ 'Full management', 'We run everything.', '9%', 'of monthly rent', 'Rent collection', '24-hour repairs line', 'Yearly inspections', 'Get started' ],
        [ 'Premium', 'For larger portfolios.', '7%', 'of monthly rent', 'Everything in Full', 'Dedicated manager', 'Monthly owner reports', 'Talk to us' ] ),

    $three( $meta( 'luxe-private-chef-pricing-section-for-divi-5-three-tier-dining-experiences', 'Luxe Private Chef Pricing Section for Divi 5: Three-Tier Dining Experiences', 'restaurant', 'elegant', 'monochrome', 'luxe', null, 'pricing-three-tier' ),
        'Private dining', 'An evening cooked in your home.', 'Prices per guest, with ingredients, service and clearing up included.',
        [ 'The Supper', 'Three courses, relaxed.', '$95', 'per guest', 'Three courses', 'Matching wine list', 'Serves 4 to 8', 'Enquire' ],
        [ 'The Tasting', 'Seven small plates.', '$165', 'per guest', 'Seven courses', 'Paired wines', 'Serves 6 to 12', 'Enquire' ],
        [ 'The Banquet', 'For a very big night.', '$240', 'per guest', 'Nine courses', 'Sommelier on hand', 'Serves 12 to 30', 'Enquire' ], 'Guest favourite' ),

    $three( $meta( 'tech-mono-api-pricing-section-for-divi-5-three-tier-usage-based-plans', 'Tech Mono API Pricing Section for Divi 5: Three-Tier Usage-Based Plans', 'saas', 'dark', 'green', 'tech-mono', null, 'pricing-three-tier' ),
        'API pricing', 'Pay per request. Stop paying when you stop.', 'No seats, no minimums and rate limits you can raise yourself.',
        [ 'Sandbox', 'For testing.', '$0', '10k requests', '10 requests a second', 'Test data only', 'Community support', 'Get a key' ],
        [ 'Live', 'For production.', '$0.40', 'per 1k requests', '100 requests a second', 'Webhooks and retries', 'Email support', 'Get a key' ],
        [ 'Volume', 'For heavy traffic.', '$0.15', 'per 1k requests', '1,000 requests a second', 'Dedicated region', 'Shared Slack channel', 'Contact us' ] ),

    $three( $meta( 'brutalist-print-shop-pricing-section-for-divi-5-three-tier-print-runs', 'Brutalist Print Shop Pricing Section for Divi 5: Three-Tier Print Runs', 'ecommerce', 'bold', 'monochrome', 'brutalist-flat', null, 'pricing-three-tier' ),
        'Print runs', 'The more you print, the less each costs.', 'Risograph on recycled paper. Prices per run, files checked free.',
        [ 'Zine', '20 copies, A5.', '$60', 'per run', '16 pages', 'Two colours', 'Ready in 3 days', 'Order' ],
        [ 'Small press', '100 copies, A5.', '$190', 'per run', '32 pages', 'Three colours', 'Ready in 5 days', 'Order' ],
        [ 'Big batch', '500 copies, A4.', '$720', 'per run', '48 pages', 'Four colours', 'Ready in 10 days', 'Order' ], 'Best value' ),

    $three( $meta( 'organic-meal-box-pricing-section-for-divi-5-three-tier-weekly-boxes', 'Organic Meal Box Pricing Section for Divi 5: Three-Tier Weekly Boxes', 'restaurant', 'minimal', 'orange', 'organic-warm', null, 'pricing-three-tier' ),
        'Weekly boxes', 'Real food, delivered on a Sunday.', 'Skip a week or cancel any time. Free delivery on every plan.',
        [ 'Two meals', 'For one or two.', '$34', 'per week', 'Two recipes, two portions', 'Seasonal vegetables', 'Recipe cards', 'Choose box' ],
        [ 'Four meals', 'For a small household.', '$62', 'per week', 'Four recipes, two portions', 'Add a dessert free', 'Recipe cards', 'Choose box' ],
        [ 'Family', 'For hungry crowds.', '$98', 'per week', 'Four recipes, four portions', 'Kid-friendly swaps', 'Breakfast add-on', 'Choose box' ] ),

    $three( $meta( 'bold-festival-ticket-pricing-section-for-divi-5-three-tier-ticket-types', 'Bold Festival Ticket Pricing Section for Divi 5: Three-Tier Ticket Types', 'events', 'bold', 'orange', 'bold-vibrant', null, 'pricing-three-tier' ),
        'Tickets', 'Pick your spot in the field.', 'Prices rise when each batch sells out, so earlier is cheaper.',
        [ 'Day pass', 'One day, your choice.', '$65', 'per day', 'Entry to all stages', 'Free water refills', 'Under-12s free', 'Get a day pass' ],
        [ 'Weekend', 'All three days.', '$165', 'whole weekend', 'Entry to all stages', 'Free camping', 'Merch voucher', 'Get weekend tickets' ],
        [ 'VIP', 'Closer, calmer, cleaner.', '$380', 'whole weekend', 'Viewing platforms', 'Private bar and toilets', 'Glamping tent', 'Get VIP tickets' ] ),

    // ---------------------------------------------------------------- two-plan highlights (9)
    $two( $meta( 'luxe-bridal-boutique-pricing-section-for-divi-5-two-plan-highlight', 'Luxe Bridal Boutique Pricing Section for Divi 5: Two-Plan Highlight', 'ecommerce', 'elegant', 'monochrome', 'luxe', null, 'pricing-two-plan-highlight' ),
        'Bridal services', 'Two ways to find the dress.', 'Both begin with a private fitting room and a glass of something cold.',
        [ 'Consultation', 'The fitting', '$80', 'Fully credited against your gown', 'A private 90-minute appointment', 'Up to four guests', 'Styling advice', 'A shortlist of silhouettes', 'Fabric swatches to take home', 'Book a fitting' ],
        'Complete', [ 'The full design', '$600', 'Deposit, balance on collection', 'Bespoke design sketches', 'Three fittings', 'Hand-finished hem and veil', 'Garment bag and steam', 'Free alterations for a year', 'Begin your design' ] ),

    $two( $meta( 'tech-mono-hosting-pricing-section-for-divi-5-two-plan-highlight', 'Tech Mono Hosting Pricing Section for Divi 5: Two-Plan Highlight', 'saas', 'dark', 'green', 'tech-mono', null, 'pricing-two-plan-highlight' ),
        'Hosting', 'Run it yourself, or let us.', 'Same software, same open-source licence, your choice of who keeps it running.',
        [ 'Self-hosted', 'Self-serve', '$0', 'MIT licence, forever', 'Full source code', 'Docker and Helm charts', 'Community forum', 'Upgrade scripts', 'Your own servers', 'Read the docs' ],
        'Managed', [ 'Cloud', '$39', 'Per month, per project', 'Hosted in your region', 'Backups every hour', 'One-click upgrades', '99.9% uptime SLA', 'Support by engineers', 'Start on cloud' ] ),

    $two( $meta( 'brutalist-bike-repair-pricing-section-for-divi-5-two-plan-highlight', 'Brutalist Bike Repair Pricing Section for Divi 5: Two-Plan Highlight', 'ecommerce', 'bold', 'monochrome', 'brutalist-flat', null, 'pricing-two-plan-highlight' ),
        'Workshop', 'Tune-up or overhaul.', 'Fixed prices, parts extra, and we ring before anything is added.',
        [ 'Quick fix', 'Tune-up', '$65', 'Back in 48 hours', 'Brake and gear adjustment', 'Tyre pressure and check', 'Chain clean and lube', 'Bolt check', 'Test ride', 'Book a tune-up' ],
        'Best for old bikes', [ 'Overhaul', '$220', 'Back in a week', 'Full strip and clean', 'New cables and chain', 'Wheels trued', 'Bearings regreased', 'Six-month warranty', 'Book an overhaul' ] ),

    $two( $meta( 'editorial-nonprofit-membership-pricing-section-for-divi-5-two-plan-highlight', 'Editorial Nonprofit Membership Pricing Section for Divi 5: Two-Plan Highlight', 'nonprofit', 'minimal', 'blue', 'editorial', null, 'pricing-two-plan-highlight' ),
        'Join us', 'Become a member of the museum.', 'Membership funds our free school visits, and you get in free all year.',
        [ 'Individual', 'One person', '$45', 'Per year, renews yearly', 'Free entry all year', 'Members-only evenings', 'Ten percent off the shop', 'Our quarterly magazine', 'Cancel any time', 'Join as an individual' ],
        'Best value', [ 'Household', '$80', 'Per year, renews yearly', 'Two adults and four children', 'Free entry all year', 'Members-only evenings', 'Fifteen percent off the shop', 'Two guest passes', 'Join as a household' ] ),

    $two( $meta( 'corporate-bookkeeping-pricing-section-for-divi-5-two-plan-highlight', 'Corporate Bookkeeping Pricing Section for Divi 5: Two-Plan Highlight', 'agency', 'corporate', 'blue', 'corporate-trust', null, 'pricing-two-plan-highlight' ),
        'Accounting services', 'Bookkeeping, or a part-time finance team.', 'Fixed monthly fees for UK limited companies and contractors.',
        [ 'Essentials', 'Bookkeeping', '$250', 'Per month, one-month notice', 'Monthly reconciliations', 'VAT returns', 'Year-end accounts', 'Payroll for up to five staff', 'Online accounting software', 'Choose Essentials' ],
        'Most popular', [ 'Finance team', '$890', 'Per month, one-month notice', 'Everything in Essentials', 'A named finance manager', 'Monthly board pack', 'Cash-flow forecasting', 'Tax planning meeting', 'Choose Finance team' ] ),

    $two( $meta( 'soft-podcast-hosting-pricing-section-for-divi-5-two-plan-highlight', 'Soft Podcast Hosting Pricing Section for Divi 5: Two-Plan Highlight', 'saas', 'minimal', 'purple', 'soft-saas', null, 'pricing-two-plan-highlight' ),
        'Podcast hosting', 'Start free. Grow when you are ready.', 'Both plans include unlimited episodes and a podcast website.',
        [ 'Free', 'Starter', '$0', 'No card required', 'Unlimited episodes', 'Apple and Spotify distribution', 'Basic download stats', 'A podcast website', 'Three-hour monthly upload', 'Start free' ],
        'Pro', [ 'Pro', '$19', 'Per month, billed yearly', 'Unlimited uploads', 'Dynamic ad insertion', 'Detailed audience stats', 'Your own domain', 'Transcripts and chapters', 'Go Pro' ] ),

    $two( $meta( 'bold-esports-coaching-pricing-section-for-divi-5-two-plan-highlight', 'Bold Esports Coaching Pricing Section for Divi 5: Two-Plan Highlight', 'coaching', 'bold', 'orange', 'bold-vibrant', 'chunky-pill-offset', 'pricing-two-plan-highlight' ),
        'Coaching', 'Rank up with a coach in your ear.', 'Coaches are former pros, matched to your game and your goals.',
        [ 'Single session', 'One-off', '$45', 'Per 60-minute session', 'Replay review', 'A written action plan', 'Chat support for 48 hours', 'Choose your coach', 'Any game we cover', 'Book a session' ],
        'Best value', [ 'Season pass', '$320', 'Per season, eight sessions', 'Eight 60-minute sessions', 'Weekly replay review', 'Private Discord channel', 'Scrim practice', 'A season goals review', 'Get the pass' ] ),

    $two( $meta( 'playful-dog-daycare-pricing-section-for-divi-5-two-plan-highlight', 'Playful Dog Daycare Pricing Section for Divi 5: Two-Plan Highlight', 'fitness', 'playful', 'pastel', 'playful-pastel', null, 'pricing-two-plan-highlight' ),
        'Daycare', 'A day pass or a pack of pals.', 'Small groups, big garden and a webcam so you can peek.',
        [ 'Day pass', 'Drop in', '$38', 'Per day', 'Full day, 8am to 6pm', 'Outdoor play and naps', 'Photo update at lunch', 'Fresh water and snacks', 'Free first visit', 'Book a day' ],
        'Best value', [ 'Monthly pack', '$520', 'Per month, twelve days', 'Twelve day passes', 'A weekly bath', 'Priority booking', 'Free pickup on Fridays', 'Birthday biscuit', 'Join the pack' ] ),

    $two( $meta( 'organic-community-garden-pricing-section-for-divi-5-two-plan-highlight', 'Organic Community Garden Pricing Section for Divi 5: Two-Plan Highlight', 'nonprofit', 'minimal', 'green', 'organic-warm', null, 'pricing-two-plan-highlight' ),
        'Garden plots', 'Grow your own, in a plot of any size.', 'Plots run March to November, with water, tools and compost included.',
        [ 'Half plot', 'Starter', '$60', 'Per season', 'About 12 square metres', 'Shared tool shed', 'Water and compost', 'Monthly work morning', 'Beginner workshops', 'Reserve a half plot' ],
        'Our favourite', [ 'Full plot', '$100', 'Per season', 'About 25 square metres', 'Shared tool shed', 'A greenhouse bench', 'Water and compost', 'Seeds swap in spring', 'Reserve a full plot' ] ),

    // ---------------------------------------------------------------- comparison tables (9)
    $table( $meta( 'editorial-online-course-pricing-table-for-divi-5-three-plan-comparison', 'Editorial Online Course Pricing Table for Divi 5: Three-Plan Comparison', 'coaching', 'minimal', 'monochrome', 'editorial', null, 'pricing-comparison-table' ),
        'Compare plans', 'Every way to learn with us', 'Pick a plan and change it whenever your goals do.',
        [ [ 'Reader', '$0' ], [ 'Student', '$19' ], [ 'Scholar', '$49' ] ], 'Most popular',
        [ 'Video lessons', 'Downloadable notes', 'Live Q&A sessions', 'Graded assignments', 'Certificate' ], [ 'Sample', 'All', 'All' ], 'Choose a plan' ),

    $table( $meta( 'tech-mono-cloud-server-pricing-table-for-divi-5-three-plan-comparison', 'Tech Mono Cloud Server Pricing Table for Divi 5: Three-Plan Comparison', 'saas', 'dark', 'green', 'tech-mono', null, 'pricing-comparison-table' ),
        'Compare plans', 'Servers, side by side', 'Hourly billing, capped at the monthly price. Resize at any time.',
        [ [ 'Nano', '$5' ], [ 'Standard', '$20' ], [ 'Dedicated', '$120' ] ], 'Most popular',
        [ 'NVMe storage', 'Bandwidth', 'Daily snapshots', 'Private networking', 'Dedicated CPU' ], [ '25 GB', '200 GB', '2 TB' ], 'Deploy a server' ),

    $table( $meta( 'corporate-real-estate-crm-pricing-table-for-divi-5-three-plan-comparison', 'Corporate Real Estate CRM Pricing Table for Divi 5: Three-Plan Comparison', 'real_estate', 'corporate', 'blue', 'corporate-trust', null, 'pricing-comparison-table' ),
        'Compare plans', 'The right plan for your agency', 'Per agent, billed monthly. Add or remove agents any time.',
        [ [ 'Solo', '$29' ], [ 'Agency', '$59' ], [ 'Group', '$99' ] ], 'Most popular',
        [ 'Contact and deal tracking', 'Listing syndication', 'Email and SMS campaigns', 'Team reporting', 'Custom branding' ], [ '500', '5,000', 'Unlimited' ], 'Start free trial' ),

    $table( $meta( 'brutalist-design-tool-pricing-table-for-divi-5-three-plan-comparison', 'Brutalist Design Tool Pricing Table for Divi 5: Three-Plan Comparison', 'agency', 'bold', 'monochrome', 'brutalist-flat', null, 'pricing-comparison-table' ),
        'Compare plans', 'What each plan actually gives you', 'No credits, no tokens. Just files and people.',
        [ [ 'Free', '$0' ], [ 'Pro', '$12' ], [ 'Team', '$30' ] ], 'Most popular',
        [ 'Unlimited drafts', 'Version history', 'Shared libraries', 'Branching and review', 'Admin controls' ], [ '7 days', '30 days', 'Forever' ], 'Pick a plan' ),

    $table( $meta( 'luxe-wealth-advisory-pricing-table-for-divi-5-three-service-levels', 'Luxe Wealth Advisory Pricing Table for Divi 5: Three Service Levels', 'agency', 'elegant', 'monochrome', 'luxe', null, 'pricing-comparison-table' ),
        'Service levels', 'Advice, scaled to your needs', 'Fees are a percentage of assets under management, reviewed every year.',
        [ [ 'Foundation', '1.0%' ], [ 'Private', '0.8%' ], [ 'Family office', '0.6%' ] ], 'Most chosen',
        [ 'Annual financial plan', 'Portfolio reviews', 'Tax and estate planning', 'Dedicated adviser', 'Concierge support' ], [ 'Yearly', 'Quarterly', 'Monthly' ], 'Arrange a conversation' ),

    $table( $meta( 'soft-hr-software-pricing-table-for-divi-5-three-plan-comparison', 'Soft HR Software Pricing Table for Divi 5: Three-Plan Comparison', 'saas', 'minimal', 'purple', 'soft-saas', null, 'pricing-comparison-table' ),
        'Compare plans', 'People tools, plan by plan', 'Priced per employee, per month. No setup fees.',
        [ [ 'Core', '$6' ], [ 'Plus', '$12' ], [ 'Enterprise', '$20' ] ], 'Most popular',
        [ 'Time off and sick leave', 'Employees included', 'Performance reviews', 'Custom workflows', 'SSO and audit log' ], [ '25', '250', 'Unlimited' ], 'Start free trial' ),

    $table( $meta( 'dark-webinar-platform-pricing-table-for-divi-5-three-plan-comparison', 'Dark Webinar Platform Pricing Table for Divi 5: Three-Plan Comparison', 'events', 'dark', 'purple', 'glass-dark', null, 'pricing-comparison-table' ),
        'Compare plans', 'Host an event of any size', 'Prices are per host, per month, billed yearly.',
        [ [ 'Meet', '$19' ], [ 'Present', '$49' ], [ 'Summit', '$149' ] ], 'Most popular',
        [ 'HD video and chat', 'Attendees per event', 'Branded registration pages', 'Breakout rooms', 'Live translation' ], [ '100', '1,000', '10,000' ], 'Host an event' ),

    $table( $meta( 'bold-gym-chain-pricing-table-for-divi-5-three-access-levels', 'Bold Gym Chain Pricing Table for Divi 5: Three Access Levels', 'fitness', 'bold', 'orange', 'bold-vibrant', null, 'pricing-comparison-table' ),
        'Access levels', 'One gym, or all forty', 'No joining fee. Month-to-month on every level.',
        [ [ 'Home gym', '$29' ], [ 'Any gym', '$49' ], [ 'Elite', '$79' ] ], 'Most popular',
        [ 'Open gym floor', 'Opening hours', 'Pool and sauna', 'Personal training session', 'Recovery lounge' ], [ '6 am to 10 pm', '5 am to 11 pm', '24 hours' ], 'Join now' ),

    $table( $meta( 'organic-farm-share-pricing-table-for-divi-5-three-box-comparison', 'Organic Farm Share Pricing Table for Divi 5: Three-Box Comparison', 'restaurant', 'minimal', 'green', 'organic-warm', null, 'pricing-comparison-table' ),
        'Farm share', 'What is in each box', 'Harvested within a day of delivery. Skip or swap any week.',
        [ [ 'Small', '$28' ], [ 'Medium', '$42' ], [ 'Large', '$64' ] ], 'Most popular',
        [ 'Seasonal vegetables', 'Fresh fruit', 'Eggs from our hens', 'Bread and pastries', 'Flowers' ], [ '6 kinds', '9 kinds', '12 kinds' ], 'Choose a box' ),
];
