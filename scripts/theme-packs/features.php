<?php
declare(strict_types=1);

/**
 * Twenty free Divi 5 features layouts for the divi5lab.com catalogue: zigzag rows, icon lists, checklists, accordions
 * with an image, comparison panels, a bento grid, a carousel and icon grids, each in a different design language, style
 * kit, niche and tone. Photographs pinned by Pexels id (every URL checked). Same entry shape as heroes.php.
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$H = static fn ( string $h, string $t ): string => "<h3>$h</h3>\n<p>$t</p>";
$A = static fn ( string $t ): string => "<p><a href=\"#\">$t</a></p>";

return [
    [ 'slug' => 'minimal-photographer-portfolio-features-layout-for-divi-5-three-zigzag-rows',
      'title' => 'Minimal Photographer Portfolio Features Layout for Divi 5: Three Zigzag Rows',
      'niche' => 'portfolio', 'style' => 'minimal', 'color' => 'monochrome', 'language' => 'editorial', 'kit' => null,
      'recipe' => 'features-zigzag', 'copy' => [
        0 => 'How I work', 1 => 'Three things every shoot includes',
        2 => $P( 'Quiet, unhurried photography, from the first call to the final print.' ),
        3 => 'px:3905448', 4 => 'A vintage film camera resting on a wooden desk beside a notebook',
        5 => 'A slow, careful approach',
        6 => $P( 'I shoot on film and digital, and I take the time to find the light before I raise the camera.' ), 7 => $A( 'See the process' ),
        8 => 'A studio built around you',
        9 => $P( 'Natural light, a small crew and no rush. Most sessions run two to three hours, with tea.' ), 10 => $A( 'Book a session' ),
        11 => 'px:39190728', 12 => 'Two photographers reviewing images together in a bright studio with lighting stands',
        13 => 'px:7305375', 14 => 'A wall of framed photographs under warm hanging lamps',
        15 => 'Prints made to last',
        16 => $P( 'Archival papers, hand-checked colour and framing you can hang the day it arrives.' ), 17 => $A( 'Order prints' ) ] ],

    [ 'slug' => 'corporate-real-estate-features-layout-for-divi-5-three-zigzag-rows',
      'title' => 'Corporate Real Estate Features Layout for Divi 5: Three Zigzag Rows',
      'niche' => 'real_estate', 'style' => 'corporate', 'color' => 'blue', 'language' => 'corporate-trust', 'kit' => null,
      'recipe' => 'features-zigzag', 'copy' => [
        0 => 'Why sellers choose us', 1 => 'Three ways we get homes sold',
        2 => $P( 'Local expertise, honest advice and a marketing plan built for your street.' ),
        3 => 'px:18470969', 4 => 'A bright living room with a cream sofa and soft daylight',
        5 => 'Staged to sell',
        6 => $P( 'We style and photograph every home so buyers fall for it before they walk in.' ), 7 => $A( 'See our staging' ),
        8 => 'Priced on evidence',
        9 => $P( 'Pricing built from recent local sales, never guesswork, so you sell fast at a fair figure.' ), 10 => $A( 'Get a valuation' ),
        11 => 'px:19966809', 12 => 'A bright white kitchen with a built-in oven and under-cabinet lighting',
        13 => 'px:15048771', 14 => 'Family homes on a quiet street under a blue sky',
        15 => 'Buyers already waiting',
        16 => $P( 'Our list of 4,000 active buyers means many homes receive an offer in the first week.' ), 17 => $A( 'Join the buyer list' ) ] ],

    [ 'slug' => 'luxe-boutique-ecommerce-features-layout-for-divi-5-three-zigzag-rows',
      'title' => 'Luxe Boutique Ecommerce Features Layout for Divi 5: Three Zigzag Rows',
      'niche' => 'ecommerce', 'style' => 'elegant', 'color' => 'monochrome', 'language' => 'luxe', 'kit' => null,
      'recipe' => 'features-zigzag', 'copy' => [
        0 => 'The House', 1 => 'Made slowly, made well',
        2 => $P( 'Three small habits behind everything we sell.' ),
        3 => 'px:26600130', 4 => 'A perfume bottle in warm window light beside red roses',
        5 => 'Scent, composed',
        6 => $P( 'Each fragrance is blended in small batches in Grasse and rested for sixty days before bottling.' ), 7 => $A( 'Discover the scents' ),
        8 => 'Leather, finished by hand',
        9 => $P( 'Full-grain Italian leather, cut and stitched in our workshop, with a lifetime repair promise.' ), 10 => $A( 'Explore the bags' ),
        11 => 'px:9327162', 12 => 'Leather handbags in tan, black and red on a white shelf',
        13 => 'px:24815712', 14 => 'A gold necklace set with stones on dark velvet',
        15 => 'Jewellery, kept for life',
        16 => $P( 'Recycled gold and ethically sourced stones, set to be passed down rather than thrown away.' ), 17 => $A( 'View the collection' ) ] ],

    [ 'slug' => 'dark-saas-reliability-features-layout-for-divi-5-icon-list-with-dividers',
      'title' => 'Dark SaaS Reliability Features Layout for Divi 5: Icon List with Dividers',
      'niche' => 'saas', 'style' => 'dark', 'color' => 'green', 'language' => 'tech-mono', 'kit' => null,
      'recipe' => 'list-icon-dividers', 'copy' => [
        0 => 'Reliability', 1 => 'What you get on every plan',
        2 => $P( 'Five guarantees, each backed by an SLA credit.' ),
        3 => 'fa:f017', 5 => 'Uptime you can plan around', 6 => $P( 'A 99.99% monthly SLA, measured at the edge and refunded automatically when we miss it.' ),
        7 => 'fa:f3ed', 9 => 'Security by default', 10 => $P( 'SSO, audit logs and encryption at rest and in transit on every workspace.' ),
        11 => 'fa:f0c2', 13 => 'Regions you choose', 14 => $P( 'Keep data in the EU, the US or Asia-Pacific, and move it at any time.' ),
        15 => 'fa:f1c0', 17 => 'Backups every hour', 18 => $P( 'Point-in-time restore for thirty days, tested every week.' ),
        19 => 'fa:f086', 21 => 'Humans on support', 22 => $P( 'Engineers answer every ticket, usually in under an hour.' ) ] ],

    [ 'slug' => 'brutalist-agency-services-features-layout-for-divi-5-icon-list-with-dividers',
      'title' => 'Brutalist Agency Services Features Layout for Divi 5: Icon List with Dividers',
      'niche' => 'agency', 'style' => 'bold', 'color' => 'monochrome', 'language' => 'brutalist-flat', 'kit' => null,
      'recipe' => 'list-icon-dividers', 'copy' => [
        0 => 'Services', 1 => 'What we will actually do for you',
        2 => $P( 'No packages with fancy names. Five jobs, priced plainly.' ),
        3 => 'fa:f1fc', 5 => 'Brand identity', 6 => $P( 'A name, a mark, colours and a rulebook short enough to be read.' ),
        7 => 'fa:f108', 9 => 'Websites', 10 => $P( 'Designed, built and handed over, with the editor and a training video.' ),
        11 => 'fa:f0a1', 13 => 'Campaigns', 14 => $P( 'One clear idea, made for the places your customers already are.' ),
        15 => 'fa:f03d', 17 => 'Film', 18 => $P( 'Short films and loops for launches, shot and cut in-house.' ),
        19 => 'fa:f085', 21 => 'Retainers', 22 => $P( 'A standing team for the work that never really ends.' ) ] ],

    [ 'slug' => 'luxe-executive-coaching-features-layout-for-divi-5-icon-list-with-dividers',
      'title' => 'Luxe Executive Coaching Features Layout for Divi 5: Icon List with Dividers',
      'niche' => 'coaching', 'style' => 'elegant', 'color' => 'monochrome', 'language' => 'luxe', 'kit' => null,
      'recipe' => 'list-icon-dividers', 'copy' => [
        0 => 'The programme', 1 => 'What six months with us includes',
        2 => $P( 'A private, structured engagement for senior leaders who want clarity and momentum.' ),
        3 => 'fa:f086', 5 => 'Fortnightly one-to-ones', 6 => $P( 'Ninety minutes each, with a written summary and agreed actions.' ),
        7 => 'fa:f201', 9 => 'A 360-degree baseline', 10 => $P( 'Confidential feedback from your team, board and peers at the start and the end.' ),
        11 => 'fa:f02d', 13 => 'A curated reading list', 14 => $P( 'Six books and papers chosen for your situation and discussed in session.' ),
        15 => 'fa:f0e0', 17 => 'Support between sessions', 18 => $P( 'Short written replies within one working day, whenever you need them.' ),
        19 => 'fa:f024', 21 => 'A closing review', 22 => $P( 'A clear summary of progress and a plan for the next twelve months.' ) ] ],

    [ 'slug' => 'organic-wellbeing-coaching-features-layout-for-divi-5-two-column-checklist',
      'title' => 'Organic Wellbeing Coaching Features Layout for Divi 5: Two-Column Checklist',
      'niche' => 'coaching', 'style' => 'minimal', 'color' => 'green', 'language' => 'organic-warm', 'kit' => null,
      'recipe' => 'checklist-two-column', 'copy' => [
        0 => 'Every programme', 1 => 'What is always included',
        2 => $P( 'Gentle structure, with plenty of room for how you actually live.' ),
        5 => $P( 'A free first conversation' ), 8 => $P( 'Weekly sessions, in person or online' ),
        11 => $P( 'A shared notebook between sessions' ), 14 => $P( 'Voice notes when you need a nudge' ),
        17 => $P( 'A seasonal check-in and reset' ), 20 => $P( 'Reading and listening suggestions' ),
        23 => $P( 'Access to our monthly circle' ), 26 => $P( 'A kind, honest goodbye plan' ) ] ],

    [ 'slug' => 'bold-fitness-membership-features-layout-for-divi-5-two-column-checklist',
      'title' => 'Bold Fitness Membership Features Layout for Divi 5: Two-Column Checklist',
      'niche' => 'fitness', 'style' => 'bold', 'color' => 'red', 'language' => 'bold-vibrant', 'kit' => 'heavy-block',
      'recipe' => 'checklist-two-column', 'copy' => [
        0 => 'Membership', 1 => 'Everything in the box',
        2 => $P( 'One price, no joining fee and no contract after the first month.' ),
        5 => $P( 'Unlimited classes' ), 8 => $P( 'Open gym at any time' ),
        11 => $P( 'Coached warm-ups every session' ), 14 => $P( 'A quarterly progress check' ),
        17 => $P( 'A free guest pass each month' ), 20 => $P( 'Towels and showers' ),
        23 => $P( 'Online workouts for travel' ), 26 => $P( 'Member-only events' ) ] ],

    [ 'slug' => 'editorial-nonprofit-donor-questions-features-layout-for-divi-5-accordion-with-image',
      'title' => 'Editorial Nonprofit Donor Questions Features Layout for Divi 5: Accordion with Image',
      'niche' => 'nonprofit', 'style' => 'minimal', 'color' => 'blue', 'language' => 'editorial', 'kit' => null,
      'recipe' => 'features-accordion-image', 'copy' => [
        0 => 'How your gift works', 1 => 'Four things donors ask first',
        2 => 'Where does the money go?', 3 => $P( 'Ninety-one pence in every pound goes straight to programmes. The rest keeps the lights on and the books audited.' ),
        4 => 'Can I give monthly?', 5 => $P( 'Yes, and monthly gifts help us plan. You can pause or cancel from your account at any time.' ),
        6 => 'Is my donation tax-deductible?', 7 => $P( 'We are a registered charity, and every receipt includes what you need to claim tax relief.' ),
        8 => 'How will I know what changed?', 9 => $P( 'Each quarter we send a short, honest update with photos, figures and what did not go to plan.' ),
        10 => 'px:36713503', 11 => 'Volunteers filling bags with litter beside a lake' ] ],

    [ 'slug' => 'dark-saas-product-benefits-features-layout-for-divi-5-accordion-with-image',
      'title' => 'Dark SaaS Product Benefits Features Layout for Divi 5: Accordion with Image',
      'niche' => 'saas', 'style' => 'dark', 'color' => 'purple', 'language' => 'glass-dark', 'kit' => null,
      'recipe' => 'features-accordion-image', 'copy' => [
        0 => 'Why teams switch', 1 => 'Four reasons it sticks',
        2 => 'It reads your repo', 3 => $P( 'Connect GitHub and Gridlight maps services, owners and dependencies in the first five minutes.' ),
        4 => 'Alerts that mean something', 5 => $P( 'Noisy alerts are grouped and ranked, so the on-call engineer sees the one that matters.' ),
        6 => 'Runbooks that write themselves', 7 => $P( 'Every incident ends with a draft runbook built from what your team actually did.' ),
        8 => 'Priced per service', 9 => $P( 'No seat tax. Pay for the services you monitor and add teammates for free.' ),
        10 => 'px:6424584', 11 => 'Green code on a dark monitor in a dim room' ] ],

    [ 'slug' => 'bold-kids-party-planner-features-layout-for-divi-5-accordion-with-image',
      'title' => 'Bold Kids Party Planner Features Layout for Divi 5: Accordion with Image',
      'niche' => 'events', 'style' => 'bold', 'color' => 'orange', 'language' => 'bold-vibrant', 'kit' => 'chunky-pill-offset',
      'recipe' => 'features-accordion-image', 'copy' => [
        0 => 'Our parties', 1 => 'Four things we handle so you do not',
        2 => 'The whole setup', 3 => $P( 'Balloons, tables, backdrops and sweet treats, set up before the first guest arrives.' ),
        4 => 'The entertainment', 5 => $P( 'Magicians, face painters and games leaders who know how to keep small groups happy.' ),
        6 => 'The food', 7 => $P( 'Allergy-aware snacks and a cake table that looks as good as it tastes.' ),
        8 => 'The tidy-up', 9 => $P( 'We clear everything afterwards, so you can sit down and have a cup of tea.' ),
        10 => 'px:16220888', 11 => 'A dessert table covered in colourful balloons and cakes' ] ],

    [ 'slug' => 'brutalist-clothing-ecommerce-comparison-features-layout-for-divi-5-two-panels',
      'title' => 'Brutalist Clothing Ecommerce Comparison Features Layout for Divi 5: Two Panels',
      'niche' => 'ecommerce', 'style' => 'bold', 'color' => 'monochrome', 'language' => 'brutalist-flat', 'kit' => null,
      'recipe' => 'compare-two-panels', 'copy' => [
        0 => 'The difference', 1 => 'Fast fashion and slow clothes',
        2 => $P( 'Same wardrobe, a very different cost.' ),
        3 => 'Fast fashion', 4 => 'Cheap now, expensive later',
        7 => $P( 'Worn twice, then binned' ), 10 => $P( 'Fabric that bobbles in a month' ),
        13 => $P( 'Made in conditions nobody shows you' ), 16 => $P( 'A new trend every week' ),
        17 => 'Slow clothes', 18 => 'Pay once, wear for years',
        21 => $P( 'Worn for a decade' ), 24 => $P( 'Natural fibres that soften with age' ),
        27 => $P( 'Every factory named on the label' ), 30 => $P( 'A repair service for life' ),
        31 => 'Shop slow clothes' ] ],

    [ 'slug' => 'dark-studio-agency-comparison-features-layout-for-divi-5-two-panels',
      'title' => 'Dark Studio Agency Comparison Features Layout for Divi 5: Two Panels',
      'niche' => 'agency', 'style' => 'dark', 'color' => 'green', 'language' => 'tech-mono', 'kit' => null,
      'recipe' => 'compare-two-panels', 'copy' => [
        0 => 'The difference', 1 => 'A marketplace and a dedicated studio',
        2 => $P( 'Two ways to get design work done, side by side.' ),
        3 => 'Freelance marketplace', 4 => 'Fast to start, hard to trust',
        7 => $P( 'A different person every project' ), 10 => $P( 'No shared brand memory' ),
        13 => $P( 'Quality that varies wildly' ), 16 => $P( 'You manage everything yourself' ),
        17 => 'Our studio', 18 => 'One team, accountable throughout',
        21 => $P( 'The same designers every time' ), 24 => $P( 'A brand library that grows' ),
        27 => $P( 'Senior review on all work' ), 30 => $P( 'One person manages your project' ),
        31 => 'Talk to the studio' ] ],

    [ 'slug' => 'soft-saas-workspace-features-layout-for-divi-5-bento-feature-grid',
      'title' => 'Soft SaaS Workspace Features Layout for Divi 5: Bento Feature Grid',
      'niche' => 'saas', 'style' => 'minimal', 'color' => 'purple', 'language' => 'soft-saas', 'kit' => null,
      'recipe' => 'features-bento', 'copy' => [
        0 => 'Features', 1 => 'Everything a growing team needs',
        2 => $P( 'Plan, ship and report from one calm workspace.' ), 3 => $A( 'Explore all features' ),
        4 => 'px:5912324', 5 => 'A laptop on a round wooden table showing a financial dashboard',
        6 => 'Reports that build themselves',
        7 => $P( 'Connect your tools once and get a live dashboard and a weekly summary written for your board.' ),
        8 => 'fa:f201', 10 => 'Forecasts', 11 => $P( 'See next quarter before it arrives.' ),
        12 => 'fa:f0e7', 14 => 'Automations', 15 => $P( 'Hand repetitive work to a rule.' ),
        16 => 'Integrations', 17 => $P( 'Forty tools, one click each.' ),
        18 => 'Permissions that make sense',
        19 => $P( 'Give every role exactly the access it needs, with a full audit trail and nothing to configure twice.' ),
        20 => $A( 'See security' ) ] ],

    [ 'slug' => 'dark-conference-tracks-features-layout-for-divi-5-carousel-cards',
      'title' => 'Dark Conference Tracks Features Layout for Divi 5: Carousel Cards',
      'niche' => 'events', 'style' => 'dark', 'color' => 'purple', 'language' => 'glass-dark', 'kit' => null,
      'recipe' => 'features-carousel-strip', 'copy' => [
        0 => 'Programme', 1 => 'Six tracks, two days',
        2 => $P( 'Pick the sessions that matter to your team.' ),
        3 => 'fa:f0eb', 5 => 'Product', 6 => $P( 'How the best teams decide what to build next.' ),
        7 => 'fa:f121', 9 => 'Engineering', 10 => $P( 'Architecture, tooling and the cost of speed.' ),
        11 => 'fa:f1fc', 13 => 'Design', 14 => $P( 'Systems, craft and working with engineers.' ),
        15 => 'fa:f201', 17 => 'Growth', 18 => $P( 'Experiments that worked, and the ones that did not.' ),
        19 => 'fa:f0c0', 21 => 'Leadership', 22 => $P( 'Hiring, culture and hard conversations.' ),
        23 => 'fa:f1d8', 25 => 'Launch', 26 => $P( 'Going to market without burning the budget.' ) ] ],

    [ 'slug' => 'organic-restaurant-kitchen-values-features-layout-for-divi-5-accordion-with-image',
      'title' => 'Organic Restaurant Kitchen Values Features Layout for Divi 5: Accordion with Image',
      'niche' => 'restaurant', 'style' => 'elegant', 'color' => 'orange', 'language' => 'organic-warm', 'kit' => null,
      'recipe' => 'features-accordion-image', 'copy' => [
        0 => 'Our kitchen', 1 => 'Four things we do differently',
        2 => 'We cook with the season', 3 => $P( 'The menu changes with the harvest, and sometimes with what the farmer brought this morning.' ),
        4 => 'We make almost everything', 5 => $P( 'Bread, butter, cured meats and pickles are all made in our own kitchen.' ),
        6 => 'We waste very little', 7 => $P( 'Peelings become stock, scraps feed the garden and the garden feeds the kitchen.' ),
        8 => 'We pour small growers', 9 => $P( 'Our wine list is short, natural and chosen by the people who serve it.' ),
        10 => 'px:36430150', 11 => 'A chef plating a dish with careful hands' ] ],

    [ 'slug' => 'playful-craft-workshop-features-layout-for-divi-5-two-column-checklist',
      'title' => 'Playful Craft Workshop Features Layout for Divi 5: Two-Column Checklist',
      'niche' => 'portfolio', 'style' => 'playful', 'color' => 'pastel', 'language' => 'playful-pastel', 'kit' => null,
      'recipe' => 'checklist-two-column', 'copy' => [
        0 => 'Every workshop', 1 => 'What is in the studio bag',
        2 => $P( 'All materials are provided, so bring nothing but curiosity.' ),
        5 => $P( 'All materials and tools' ), 8 => $P( 'A tidy apron to borrow' ),
        11 => $P( 'Small groups of up to eight' ), 14 => $P( 'A teacher in the room all session' ),
        17 => $P( 'Tea, coffee and cake' ), 20 => $P( 'Your finished piece to take home' ),
        23 => $P( 'A photo for your portfolio' ), 26 => $P( 'A discount on your next class' ) ] ],

    [ 'slug' => 'bold-nonprofit-impact-features-layout-for-divi-5-four-icon-features',
      'title' => 'Bold Nonprofit Impact Features Layout for Divi 5: Four Icon Features',
      'niche' => 'nonprofit', 'style' => 'bold', 'color' => 'red', 'language' => 'bold-vibrant', 'kit' => null,
      'recipe' => 'icon-features', 'copy' => [
        0 => 'Four ways we make a difference',
        1 => 'fa:f2e7', 3 => $H( 'Meals', 'Rescued food reaches families within 24 hours, hot or fresh.' ),
        4 => 'fa:f19d', 6 => $H( 'Learning', 'After-school clubs that keep children fed and on track at school.' ),
        7 => 'fa:f015', 9 => $H( 'Homes', 'Emergency housing and the practical help to stay housed.' ),
        10 => 'fa:f0c0', 12 => $H( 'Community', 'Neighbours helping neighbours, with training and a small grant to start.' ) ] ],

    [ 'slug' => 'soft-fitness-member-perks-features-layout-for-divi-5-four-icon-blurbs',
      'title' => 'Soft Fitness Member Perks Features Layout for Divi 5: Four Icon Blurbs',
      'niche' => 'fitness', 'style' => 'minimal', 'color' => 'purple', 'language' => 'soft-saas', 'kit' => null,
      'recipe' => 'blurb-grid', 'copy' => [
        0 => 'Member perks', 1 => 'Everything that comes with your membership, on top of unlimited classes.',
        2 => 'Recovery room', 3 => 'fa:f004', 5 => $P( 'Sauna, cold plunge and stretching space, free for members.' ),
        6 => 'Nutrition clinic', 7 => 'fa:f2e7', 9 => $P( 'Monthly one-to-one sessions with our dietitian.' ),
        10 => 'Kids club', 11 => 'fa:f0c0', 13 => $P( 'Supervised play while you train, three mornings a week.' ),
        14 => 'Workout app', 15 => 'fa:f10b', 17 => $P( 'Programmes and class bookings in your pocket.' ) ] ],

    [ 'slug' => 'luxe-private-estates-principles-features-layout-for-divi-5-three-icon-values',
      'title' => 'Luxe Private Estates Principles Features Layout for Divi 5: Three Icon Values',
      'niche' => 'real_estate', 'style' => 'elegant', 'color' => 'monochrome', 'language' => 'luxe', 'kit' => null,
      'recipe' => 'icon-values', 'copy' => [
        0 => $P( 'Our principles' ), 1 => $P( 'Three commitments we make to every client.' ),
        2 => 'fa:f3ed', 4 => $P( 'Discretion' ), 5 => $P( 'Private sales, quiet marketing and total control over who sees your home.' ),
        6 => 'fa:f005', 8 => $P( 'Craft' ), 9 => $P( 'Photography, film and floor plans made to the standard of the house itself.' ),
        10 => 'fa:f0c0', 12 => $P( 'Access' ), 13 => $P( 'A private network of buyers and advisers across forty countries.' ) ] ],
];
