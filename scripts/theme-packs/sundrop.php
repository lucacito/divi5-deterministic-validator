<?php
declare(strict_types=1);

/**
 * Sundrop Solar: a six-page Divi 5 theme pack for a residential solar installer in Phoenix.
 * Design language corporate-trust with the pressable kit, recoloured to warm cream, midnight navy and burnt orange and
 * set in Poppins. Photographs pinned by Pexels id (every URL checked).
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$Q = static fn ( string $t ): string => '<p>&ldquo;' . $t . '&rdquo;</p>';
$H = static fn ( string $h, string $t ): string => "<h3>$h</h3>\n<p>$t</p>";
$crumb = static fn ( string $page ): string => '<p><a href="#">Home</a> / ' . $page . '</p>';

return [
    'name'     => 'Sundrop Solar',
    'language' => 'corporate-trust',
    'kit'      => 'pressable',
    'palette'  => [ 'bg' => '#fffaf0', 'surface' => '#fdf0d8', 'text' => '#1b2a4a', 'muted' => '#566079', 'accent' => '#c2410c', 'accent_text' => '#fffaf0', 'border' => '#ecdcc0', 'inverse' => '#fff6e6', 'overlay' => '#14203b' ],
    'fonts'    => [ 'IBM Plex Sans' => 'Poppins' ],
    'pages'    => [
        'home' => [ 'title' => 'Home', 'sections' => [
            [ 'hero-cta', [
                0 => 'px:12284244:1920', 1 => 'Sundrop Solar · Phoenix, Arizona',
                2 => 'Turn your roof into your cheapest power plant.',
                3 => 'Get a free solar quote' ] ],
            [ 'icon-features', [
                0 => 'Why Arizona homeowners go solar with us',
                1 => 'fa:f155', 3 => $H( 'Lower bills from month one', 'Most homes cut their electric bill by 70% to 95%, with a clear estimate before you sign.' ),
                4 => 'fa:f3ed', 6 => $H( 'A twenty-five year promise', 'Panels, inverter and workmanship are all covered, in writing, by us and not a distant manufacturer.' ),
                7 => 'fa:f0e7', 9 => $H( 'Installed in two days', 'A local crew of our own employees, never subcontractors, and a tidy roof when they leave.' ),
                10 => 'fa:f15c', 12 => $H( 'We handle the paperwork', 'Permits, utility sign-off and any incentive forms are done for you.' ) ] ],
            [ 'features-bento', [
                0 => 'The system', 1 => 'Everything on the roof, and under it',
                2 => $P( 'Tier-one panels, a smart inverter and an optional battery, designed around how your family uses power.' ),
                3 => $P( '<a href="#">See every option</a>' ),
                4 => 'px:6040783', 5 => 'Two installers lifting a black solar panel onto a sunny roof',
                6 => 'Panels that last', 7 => $P( 'Black, low-glare panels rated for thirty years and every Arizona monsoon, with a 25-year performance guarantee.' ),
                8 => 'fa:f0e7', 10 => 'Smart inverter', 11 => $P( 'Monitor every kilowatt on your phone.' ),
                12 => 'fa:f240', 14 => 'Home battery', 15 => $P( 'Power through outages, quietly.' ),
                16 => 'EV charging', 17 => $P( 'Charge your car with sunshine.' ),
                18 => 'Free monitoring app',
                19 => $P( 'See what you produce, what you use and what you saved, every day. Alerts if anything looks wrong.' ),
                20 => $P( '<a href="#">See the app</a>' ) ] ],
            [ 'testimonials-avatar-grid', [
                0 => 'Customers', 1 => 'Three homes, three smaller bills',
                2 => $P( 'Real savings reported by homeowners a year after installation.' ),
                3 => $Q( 'Our summer bill dropped from $340 to $18. The installers finished in two days and left the roof cleaner than they found it.' ), 4 => 'px:26834972:800', 5 => 'Carlos portrait', 6 => $P( 'Carlos M.' ), 7 => $P( 'Tempe · 8 kW system' ),
                8 => $Q( 'I was sure it was too good to be true. Fourteen months later we have saved $4,100 and the battery kept us going through two outages.' ), 9 => 'px:8727416:800', 10 => 'Linda portrait', 11 => $P( 'Linda W.' ), 12 => $P( 'Mesa · 10 kW with battery' ),
                13 => $Q( 'They answered every question honestly, including the ones that talked them out of an extra panel. That is why we chose them.' ), 14 => 'px:7322232:800', 15 => 'Frank portrait', 16 => $P( 'Frank D.' ), 17 => $P( 'Scottsdale · 12 kW system' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:10900225:1920', 1 => 'Free quote', 2 => 'See what your roof could save.',
                3 => $P( 'Send us your latest bill. In one working day you get a design, a price and your payback in years, with no obligation.' ),
                4 => 'Get my free quote' ] ],
        ] ],

        'solar' => [ 'title' => 'Solar Panels', 'sections' => [
            [ 'header-image-band', [
                0 => 'px:38021376:1920', 1 => $crumb( 'Solar panels' ), 2 => 'Solar panels',
                3 => $P( 'How rooftop solar works, what it costs and what you can expect to save.' ) ] ],
            [ 'steps-horizontal', [
                0 => 'How it works', 1 => 'From sunshine to savings in four steps',
                2 => $P( 'No jargon, no pressure and no surprise charges.' ),
                4 => 'Capture', 5 => $P( 'Panels turn sunlight into direct current as soon as it hits the roof.' ),
                7 => 'Convert', 8 => $P( 'The inverter converts it to the alternating current your home uses.' ),
                10 => 'Use', 11 => $P( 'Your home draws solar power first, so you buy less from the grid.' ),
                13 => 'Sell', 14 => $P( 'Surplus goes back to the grid, and your utility credits you for it.' ),
                15 => 'See your estimate' ] ],
            [ 'compare-two-panels', [
                0 => 'The difference', 1 => 'Buying and leasing, honestly compared',
                2 => $P( 'Both lower your bill. Only one gives you the asset.' ),
                3 => 'Lease', 4 => 'No upfront cost, less to gain',
                7 => $P( 'Little or nothing to pay at first' ), 10 => $P( 'The leasing company keeps any tax incentives' ), 13 => $P( 'Payments often rise each year' ), 16 => $P( 'Harder to sell a house with a lease' ),
                17 => 'Buy', 18 => 'Own the asset, keep the savings',
                21 => $P( 'Tax incentives, where available, go to you' ), 24 => $P( 'Typical payback in six to eight years' ), 27 => $P( 'Adds value to your home' ), 30 => $P( 'Financing available at low rates' ),
                31 => 'Compare for my home' ] ],
            [ 'features-zigzag', [
                0 => 'In the field', 1 => 'Three jobs that show how we work',
                2 => $P( 'Every roof is different, so every design is too.' ),
                3 => 'px:9875418', 4 => 'An installer in a black t-shirt carrying a solar panel up to a roof',
                5 => 'A tile roof in Tempe', 6 => $P( 'We lift each panel by hand, flash every fixing and finish with a roof inspection, so the tiles stay exactly where they were.' ), 7 => $P( '<a href="#">See the job</a>' ),
                8 => 'A flat roof in Scottsdale', 9 => $P( 'Ballasted mounts mean no holes at all. The whole system was live in a single day, with no change to the roof membrane.' ), 10 => $P( '<a href="#">See the job</a>' ),
                11 => 'px:6961112', 12 => 'An engineer inspecting a large array of solar panels on a roof',
                13 => 'px:9784823', 14 => 'A suburban house with garage and cars under a bright blue sky',
                15 => 'A family home in Mesa', 16 => $P( 'Eight kilowatts of panels turned a $290 summer bill into $12. The family spent the savings on a long holiday.' ), 17 => $P( '<a href="#">See the job</a>' ) ] ],
            [ 'faq-two-column', [
                0 => 'Solar questions', 1 => 'What homeowners ask us first',
                2 => $P( 'Short answers, with no sales talk.' ), 3 => 'Ask an expert',
                4 => 'Will it work on a cloudy day?', 5 => $P( 'Yes, at reduced output. Phoenix has about 300 sunny days a year, so most systems exceed their forecast.' ),
                6 => 'What happens at night?', 7 => $P( 'Your home draws grid power, or battery power if you have one. Net metering balances the difference.' ),
                8 => 'How long does an installation take?', 9 => $P( 'One to two days on site. Permits and utility approval take three to six weeks, and we manage both.' ),
                10 => 'Will panels damage my roof?', 11 => $P( 'No. We flash every fixing and warrant the roof around each mount for the life of the system.' ),
                12 => 'What if I sell my home?', 13 => $P( 'Solar typically adds value. We give you a transfer pack that makes the sale simple.' ),
                14 => 'Do panels need maintenance?', 15 => $P( 'Almost none. A rinse once or twice a year is plenty, and our app alerts you if output dips.' ) ] ],
            [ 'cta-band', [ 0 => 'Find out what your roof could produce.', 1 => 'Get a free solar quote', 2 => $P( 'A design and a price within one working day.' ) ] ],
        ] ],

        'pricing' => [ 'title' => 'Savings and Pricing', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Savings and pricing' ), 1 => 'Savings and pricing',
                2 => $P( 'Three system sizes, a clear price for each and a payback you can check yourself.' ) ] ],
            [ 'pricing-three-tier', [
                0 => 'Packages', 1 => 'Pick a system that fits your home',
                2 => $P( 'Prices include panels, inverter, installation, permits and the monitoring app, before any tax incentives.' ),
                3 => 'Starter', 4 => $P( 'For a one- or two-person home.' ), 5 => $P( '$14,900' ), 6 => $P( '6 kW system' ),
                9 => $P( 'About 20 panels' ), 12 => $P( 'Saves around $1,300 a year' ), 15 => $P( 'Payback in about 8 years' ), 16 => 'Get a Starter quote',
                17 => $P( 'Most chosen' ), 18 => 'Family', 19 => $P( 'For a typical household.' ), 20 => $P( '$22,400' ), 21 => $P( '9 kW system' ),
                24 => $P( 'About 28 panels' ), 27 => $P( 'Saves around $1,950 a year' ), 30 => $P( 'Payback in about 7 years' ), 31 => 'Get a Family quote',
                32 => 'Plus battery', 33 => $P( 'For outages and evenings.' ), 34 => $P( '$36,900' ), 35 => $P( '9 kW and 13 kWh' ),
                38 => $P( 'Powers key circuits for a day' ), 41 => $P( 'Time-of-use savings built in' ), 44 => $P( 'Payback in about 10 years' ), 45 => 'Get a battery quote' ] ],
            [ 'checklist-two-column', [
                0 => 'Included', 1 => 'Everything in every package',
                2 => $P( 'No add-ons, no hidden extras, no surprise on the day.' ),
                5 => $P( 'Free site survey' ), 8 => $P( 'Custom design and shade analysis' ),
                11 => $P( 'Permits and utility paperwork' ), 14 => $P( 'Two-day professional installation' ),
                17 => $P( '25-year panel and workmanship warranty' ), 20 => $P( 'Free monitoring app' ),
                23 => $P( 'Help claiming any tax incentives' ), 26 => $P( 'A friendly check-up after twelve months' ) ] ],
            [ 'stats-big-numbers', [
                0 => 'The savings', 1 => 'What our customers actually see',
                2 => $P( '$1,900' ), 3 => $P( 'average yearly bill savings' ),
                4 => $P( '7.2' ), 5 => $P( 'years average payback' ),
                6 => $P( '98%' ), 7 => $P( 'of systems beat their estimate' ) ] ],
            [ 'cta-split-form', [
                0 => 'Free quote', 1 => 'Get a price for your roof.',
                2 => $P( 'Tell us where you live and send a recent bill. We reply within one working day with a design and a price.' ),
                5 => $P( 'A free site survey' ), 8 => $P( 'A design on your roof photo' ), 11 => $P( 'A clear payback estimate' ),
                12 => 'Request your quote', 13 => $P( 'We reply within one working day.' ), 14 => 'Request quote' ] ],
        ] ],

        'installs' => [ 'title' => 'Installations', 'sections' => [
            [ 'header-divider-intro', [
                0 => 'Installations', 1 => 'More than a thousand roofs, all in the sun',
                2 => $P( 'A selection of recent jobs across the valley, from small bungalows to big family homes.' ) ] ],
            [ 'portfolio-featured-grid', [
                0 => 'Recent jobs', 1 => 'Five roofs we are pleased with', 2 => 'All installations',
                3 => 'px:38021376', 4 => 'A large array of black solar panels on a terracotta tile roof', 5 => 'Tempe tile roof', 6 => $P( '9.6 kW · Terracotta tiles' ),
                7 => 'px:9875415', 8 => 'An installer lowering a panel onto a roof beside a window', 9 => 'Chandler two-storey', 10 => $P( '7.2 kW · Stucco and tile' ),
                11 => 'px:12243093', 12 => 'Panels on a hillside roof above a city view', 13 => 'Hillside home', 14 => $P( '8 kW · Steep pitch' ),
                15 => 'px:32387194', 16 => 'A white modern house with black panels on the roof', 17 => 'Modern new build', 18 => $P( '6.4 kW · Flat panels' ),
                19 => 'px:16432398', 20 => 'An aerial view of a house with solar panels beside a garden', 21 => 'Family garden home', 22 => $P( '10 kW · With battery' ) ] ],
            [ 'before-after-compare', [
                0 => 'Before and after', 1 => 'The same home, one summer later',
                2 => $P( 'A Mesa bungalow that cut its bill by 94% in the first year.' ),
                3 => $P( 'Before' ), 4 => $P( 'A bare tile roof and an electric bill of $310 in July.' ),
                5 => $P( 'After' ), 6 => $P( 'Twenty-four panels, a smart inverter and a July bill of $19.' ),
                7 => 'px:6628274', 8 => 'An aerial view of a snowy house roof without panels',
                9 => 'px:9784823', 10 => 'A sunny suburban house under a blue sky' ] ],
            [ 'testimonials-avatar-grid', [
                0 => 'Homeowners', 1 => 'A few more words',
                2 => $P( 'From our review folder.' ),
                3 => $Q( 'The crew was friendly, quick and spotless. We forgot they were there by lunch.' ), 4 => 'px:26834972:800', 5 => 'Miguel portrait', 6 => $P( 'Miguel R.' ), 7 => $P( 'Gilbert' ),
                8 => $Q( 'We went with the battery and slept through the last blackout. Worth every dollar.' ), 9 => 'px:19332236:800', 10 => 'Sofia portrait', 11 => $P( 'Sofia T.' ), 12 => $P( 'Phoenix' ),
                13 => $Q( 'Honest advice, a fair price and a follow-up call after a year. Not many firms do that.' ), 14 => 'px:12871465:800', 15 => 'Gabriel portrait', 16 => $P( 'Gabriel H.' ), 17 => $P( 'Glendale' ) ] ],
            [ 'cta-band', [ 0 => 'Want your roof on this page?', 1 => 'Get a free solar quote', 2 => $P( 'Design and price within one working day.' ) ] ],
        ] ],

        'about' => [ 'title' => 'About', 'sections' => [
            [ 'hero-editorial-minimal', [
                0 => 'Sundrop Solar · Since 2013',
                1 => 'A local company with a very big sky.',
                2 => $P( 'We are a family-owned Arizona installer with sixty employees, a rooftop of our own and no subcontractors. Every roof we touch is covered by our name.' ),
                3 => $P( '<a href="#">Meet the team</a>' ) ] ],
            [ 'team-grid', [
                0 => 'The team', 1 => 'The people who will be on your roof',
                2 => $P( 'Our crews are employees who have worked together for years.' ),
                3 => 'px:26834972:800', 4 => 'Diego Reyes portrait', 5 => 'Diego Reyes', 6 => $P( 'Founder and CEO' ),
                7 => 'px:31241969:800', 8 => 'Anita Rao portrait', 9 => 'Anita Rao', 10 => $P( 'Head of design' ),
                11 => 'px:7322232:800', 12 => 'Tom Hale portrait', 13 => 'Tom Hale', 14 => $P( 'Lead installer' ),
                15 => 'px:8727416:800', 16 => 'Joan Whitfield portrait', 17 => 'Joan Whitfield', 18 => $P( 'Customer care' ) ] ],
            [ 'story-timeline', [
                0 => 'px:6961123:1920', 1 => 'Our story', 2 => 'Twelve years under the same sun',
                3 => $P( 'From two installers and a pickup truck to the valley\'s favourite solar company.' ),
                4 => $P( '2013' ), 5 => 'Two people and a ladder', 6 => $P( 'Diego and his brother install their first system on their mother\'s roof in Tempe.' ),
                7 => $P( '2017' ), 8 => 'The first crew', 9 => $P( 'Ten employees, a small warehouse and a promise never to use subcontractors.' ),
                10 => $P( '2021' ), 11 => 'A thousand roofs', 12 => $P( 'We pass one thousand installations and open a battery division.' ),
                13 => $P( '2025' ), 14 => 'Sixty people', 15 => $P( 'Still family owned, still local and still on the roof ourselves every week.' ) ] ],
            [ 'values-list', [
                0 => 'What we stand for', 1 => 'Four rules on the warehouse wall',
                3 => 'Employees, not subcontractors', 4 => $P( 'The people on your roof work for us, are trained by us and answer to us.' ),
                6 => 'Quote it honestly', 7 => $P( 'Savings are based on your real bills, not on the best case we could find.' ),
                9 => 'Leave it clean', 10 => $P( 'Every job ends with a clear roof, a swept drive and a walk-round with you.' ),
                12 => 'Answer the phone', 13 => $P( 'A real person picks up during working hours, and we call back within the hour.' ) ] ],
            [ 'quote-over-image', [
                0 => 'px:325944:1920', 2 => $P( 'We started because our own electric bill hurt. Twelve years later, making other people\'s bills smaller is still the best part of the job.' ),
                3 => $P( 'Diego Reyes' ), 4 => $P( 'Founder, Sundrop Solar' ) ] ],
        ] ],

        'contact' => [ 'title' => 'Contact', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Contact' ), 1 => 'Contact us',
                2 => $P( 'Ask a question or book a free site survey. We reply the same working day.' ) ] ],
            [ 'contact-details-form', [
                0 => 'Contact', 1 => 'Tell us about your roof.',
                5 => $P( 'hello@sundropsolar.com' ), 9 => $P( '(602) 555-0137' ), 13 => $P( '4400 East Camelback Road, Phoenix, AZ 85018' ),
                17 => $P( 'Mon to Fri 7am to 6pm, Sat 8am to 1pm.' ), 18 => 'Send message' ] ],
            [ 'faq-toggle-list', [
                0 => 'FAQ', 1 => 'Before you get in touch',
                2 => 'How soon can you visit?', 3 => $P( 'Most site surveys happen within three working days, and take under an hour.' ),
                4 => 'Do you charge for the quote?', 5 => $P( 'Never. The survey, the design and the quote are all free, with no obligation.' ),
                6 => 'Which areas do you cover?', 7 => $P( 'The whole Phoenix valley, from Buckeye to Queen Creek, and Tucson on request.' ),
                8 => 'Can I see an installed system?', 9 => $P( 'Yes. Many customers are happy to show you their system. We will arrange a visit.' ),
                10 => 'What payment options do you offer?', 11 => $P( 'Cash, low-rate loans and zero-deposit financing, with clear monthly figures on your quote.' ),
                12 => $P( 'Another question? Write to hello@sundropsolar.com.' ) ] ],
            [ 'cta-band', [ 0 => 'Ready to see your savings?', 1 => 'Get a free solar quote', 2 => $P( 'One working day, no obligation.' ) ] ],
        ] ],
    ],
];
