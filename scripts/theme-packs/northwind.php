<?php
declare(strict_types=1);

/**
 * Northwind Architects: a six-page Divi 5 theme pack for an architecture studio in Rotterdam.
 * Design language brutalist-flat with the thick-frame-caps kit, recoloured to poured-concrete grey with an acid-lime accent.
 * Photographs pinned by Pexels id (every URL checked).
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$Q = static fn ( string $t ): string => '<p>&ldquo;' . $t . '&rdquo;</p>';
$crumb = static fn ( string $page ): string => '<p><a href="#">Home</a> / ' . $page . '</p>';

return [
    'name'     => 'Northwind Architects',
    'language' => 'brutalist-flat',
    'kit'      => 'thick-frame-caps',
    'palette'  => [ 'bg' => '#d6d8d3', 'surface' => '#f0f1ed', 'text' => '#0d0d0d', 'muted' => '#404040', 'accent' => '#d4ff2a', 'accent_text' => '#0d0d0d', 'border' => '#0d0d0d', 'inverse' => '#f0f1ed', 'overlay' => '#0d0d0d' ],
    'fonts'    => [],
    'pages'    => [
        'home' => [ 'title' => 'Home', 'sections' => [
            [ 'hero-image-scrim', [
                0 => 'px:4328661:1920', 1 => 'Architecture studio · Rotterdam',
                2 => 'We build things that outlast the brief.',
                3 => $P( 'Northwind designs housing, libraries and civic buildings in concrete, timber and light. Twenty-two people, one workshop, forty built projects.' ),
                4 => 'See the work', 5 => 'Meet the studio',
                6 => $P( 'Havenstraat 12, Rotterdam' ), 7 => $P( 'Studio open Mon to Fri' ), 8 => $P( 'Visits by appointment' ) ] ],
            [ 'stats-big-numbers', [
                0 => 'The studio, counted', 1 => 'Three numbers we are rather proud of',
                2 => $P( '40+' ), 3 => $P( 'buildings completed since 2009' ),
                4 => $P( '12' ), 5 => $P( 'national design awards' ),
                6 => $P( '0' ), 7 => $P( 'projects finished over budget in the last five years' ) ] ],
            [ 'portfolio-featured-grid', [
                0 => 'Selected work', 1 => 'Five buildings we would happily live in', 2 => 'All projects',
                3 => 'px:2793444', 4 => 'A concrete library on slender columns under a blue sky', 5 => 'Atlas Library', 6 => $P( 'Public library · 2023' ),
                7 => 'px:5472767', 8 => 'A concrete housing block with a green roof and long ramps', 9 => 'Mill Street Housing', 10 => $P( 'Social housing · 2021' ),
                11 => 'px:7482706', 12 => 'Looking up between two concrete towers to a thin strip of sky', 13 => 'Canal Court', 14 => $P( 'Mixed use · 2022' ),
                15 => 'px:13750059', 16 => 'A wide concrete stair rising under a glass skylight', 17 => 'Weir Stair Hall', 18 => $P( 'Civic building · 2020' ),
                19 => 'px:5465156', 20 => 'A concrete apartment building with deep cantilevered balconies', 21 => 'Kade Residences', 22 => $P( 'Apartments · 2024' ) ] ],
            [ 'features-bento', [
                0 => 'What we do', 1 => 'One studio, from sketch to site visit',
                2 => $P( 'We design, detail and supervise, so the building on site is the one we drew.' ),
                3 => $P( '<a href="#">See all services</a>' ),
                4 => 'px:9617369', 5 => 'An architect drawing at a desk beside books and fabric swatches',
                6 => 'Architecture', 7 => $P( 'New buildings and extensions, designed by a partner and built by people we trust.' ),
                8 => 'fa:f1ad', 10 => 'Masterplanning', 11 => $P( 'Streets, squares and neighbourhoods.' ),
                12 => 'fa:f0ad', 14 => 'Interiors', 15 => $P( 'Light, material and detail.' ),
                16 => 'Restoration', 17 => $P( 'Old buildings, kept alive.' ),
                18 => 'Site supervision',
                19 => $P( 'We visit the site every week until completion, because the details are won or lost there.' ),
                20 => $P( '<a href="#">How we work</a>' ) ] ],
            [ 'cta-band', [ 0 => 'Planning a building? Start with a conversation.', 1 => 'Talk to the studio', 2 => $P( 'We reply within two working days.' ) ] ],
        ] ],

        'projects' => [ 'title' => 'Projects', 'sections' => [
            [ 'header-image-band', [
                0 => 'px:31635618:1920', 1 => $crumb( 'Projects' ), 2 => 'Projects',
                3 => $P( 'Housing, libraries, pavilions and the occasional bridge.' ) ] ],
            [ 'gallery-masonry-style', [
                0 => 'Gallery', 1 => 'Details, shadows and weather',
                2 => $P( 'Photographs from completed buildings, taken a year after the scaffolding came down.' ),
                3 => 'px:12884282', 4 => 'A steel-railed concrete stair rising to a skylight',
                5 => 'px:32466569', 6 => 'A concrete wall with a cantilevered slab and narrow windows',
                7 => 'px:4015825', 8 => 'A long concrete apartment block with curved ribbing',
                9 => 'px:15285869', 10 => 'A concrete arch above a facade against a dusk sky',
                11 => 'px:31438131', 12 => 'The stepped concrete decks of a multi-storey building',
                13 => 'px:4328661', 14 => 'A concrete building with a grid of deep window openings' ] ],
            [ 'features-zigzag', [
                0 => 'In depth', 1 => 'Three projects, three problems solved',
                2 => $P( 'Every building starts with a problem. These are the ones we remember.' ),
                3 => 'px:2793444', 4 => 'A concrete library on slender columns',
                5 => 'A library that floats', 6 => $P( 'Lifting the reading rooms on slim columns freed the ground floor as a public square and kept the reading rooms above the flood line.' ), 7 => $P( '<a href="#">Read the case</a>' ),
                8 => 'Housing with a garden roof', 9 => $P( 'Planted terraces gave each flat a private garden and cut summer heat by six degrees, for less than the cost of air conditioning.' ), 10 => $P( '<a href="#">Read the case</a>' ),
                11 => 'px:5472767', 12 => 'A concrete housing block with a green roof and ramps',
                13 => 'px:5465156', 14 => 'Concrete balconies cantilevered over a street',
                15 => 'Balconies that shade', 16 => $P( 'Deep cantilevers shade the glazing in summer and let low winter sun in, a trick as old as architecture and still free.' ), 17 => $P( '<a href="#">Read the case</a>' ) ] ],
            [ 'before-after-compare', [
                0 => 'Before and after', 1 => 'A car park becomes a square',
                2 => $P( 'An empty deck in the city centre, reworked in eleven months.' ),
                3 => $P( 'Before' ), 4 => $P( 'A grey, windswept deck used by forty cars a day.' ),
                5 => $P( 'After' ), 6 => $P( 'A public square with shade, seating and a ground-floor market.' ),
                7 => 'px:31438131', 8 => 'A multi-storey concrete car park deck',
                9 => 'px:2793444', 10 => 'A concrete library standing on slender columns' ] ],
            [ 'cta-band', [ 0 => 'Have a site in mind?', 1 => 'Talk to the studio', 2 => $P( 'The first conversation is free.' ) ] ],
        ] ],

        'studio' => [ 'title' => 'The Studio', 'sections' => [
            [ 'hero-editorial-minimal', [
                0 => 'Northwind · Since 2009',
                1 => 'A studio of builders who happen to draw.',
                2 => $P( 'We are twenty-two architects, model-makers and site managers working from a converted rope factory on the harbour. We draw by hand, build models by the dozen and visit every site every week.' ),
                3 => $P( '<a href="#">Read our principles</a>' ) ] ],
            [ 'team-grid', [
                0 => 'The partners', 1 => 'Four people who sign every drawing',
                2 => $P( 'Each project is led by a partner from first sketch to final inspection.' ),
                3 => 'px:26834972:800', 4 => 'Daan de Vries portrait', 5 => 'Daan de Vries', 6 => $P( 'Founding partner' ),
                7 => 'px:31241969:800', 8 => 'Noor Bakker portrait', 9 => 'Noor Bakker', 10 => $P( 'Partner, public buildings' ),
                11 => 'px:28446973:800', 12 => 'Jun Hayashi portrait', 13 => 'Jun Hayashi', 14 => $P( 'Partner, housing' ),
                15 => 'px:4687549:800', 16 => 'Femi Adeyemi portrait', 17 => 'Femi Adeyemi', 18 => $P( 'Partner, restoration' ) ] ],
            [ 'values-list', [
                0 => 'Principles', 1 => 'Four rules pinned above the model table',
                3 => 'Honest materials', 4 => $P( 'Concrete looks like concrete and timber looks like timber. Nothing pretends.' ),
                6 => 'Build it twice', 7 => $P( 'Every important detail is built as a model, then as a mock-up, before it is built for real.' ),
                9 => 'Design for decades', 10 => $P( 'A building should be better in fifty years than it was on the day we handed it over.' ),
                12 => 'Public first', 13 => $P( 'Even private buildings share a street, so the street always gets something good.' ) ] ],
            [ 'founder-note', [
                0 => 'px:30479361:1000', 1 => 'Anke Visser portrait',
                2 => $P( 'Anke Visser' ), 3 => $P( 'Managing partner' ),
                4 => 'A note from the studio', 5 => 'We would rather build less, and better.',
                6 => $P( 'I joined Northwind as a student and never left. What keeps me here is how seriously we take the unglamorous parts: the drain, the junction, the way a stair feels under the foot.' ),
                7 => $P( 'We turn down projects that cannot be done well, and we say why. Clients tell us it is the first time an architect has been so direct.' ),
                8 => $P( 'If you want a building that will still be loved when we are gone, we should talk.' ),
                9 => $P( '&mdash; Anke' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:37475385:1920', 1 => 'Join us', 2 => 'We are always looking for good makers.',
                3 => $P( 'Architects, model-makers and site managers. Bring a portfolio and a very sharp pencil.' ),
                4 => 'See openings' ] ],
        ] ],

        'services' => [ 'title' => 'Services', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Services' ), 1 => 'Services',
                2 => $P( 'From the first sketch to the last snag, a single studio, a single team and a single fee.' ) ] ],
            [ 'list-icon-dividers', [
                0 => 'What we offer', 1 => 'Five jobs we do every week',
                2 => $P( 'We take on each stage separately or as a single appointment.' ),
                3 => 'fa:f1ad', 5 => 'Feasibility', 6 => $P( 'A two-week study that tells you what the site can hold, what it will cost and what the planners will say.' ),
                7 => 'fa:f1fc', 9 => 'Concept design', 10 => $P( 'Three ideas, modelled and costed, then one developed in detail.' ),
                11 => 'fa:f0ad', 13 => 'Detail design', 14 => $P( 'Drawings, specifications and tender documents that builders can price without guessing.' ),
                15 => 'fa:f015', 17 => 'Construction support', 18 => $P( 'Weekly site visits, quick answers and a firm hand on quality.' ),
                19 => 'fa:f00c', 21 => 'Handover', 22 => $P( 'A snag list, a user guide and a visit after twelve months to see how it is working.' ) ] ],
            [ 'pricing-comparison-table', [
                0 => 'Fees', 1 => 'How our stages are priced', 2 => $P( 'Percentages are of construction cost. We agree a fixed fee for each stage in writing.' ),
                4 => $P( 'Feasibility' ), 5 => $P( 'Fixed' ), 6 => $P( 'Most popular' ), 7 => $P( 'Design' ), 8 => $P( '8%' ), 9 => $P( 'Full service' ), 10 => $P( '11%' ),
                11 => $P( 'Site studies' ), 18 => $P( 'Concept and planning' ), 22 => $P( 'Detail design' ), 28 => $P( 'Tender documents' ), 33 => $P( 'Site supervision' ),
                19 => $P( 'Yes' ), 20 => $P( 'Yes' ), 21 => $P( 'Yes' ), 38 => 'Request a fee proposal' ] ],
            [ 'faq-two-column', [
                0 => 'Questions', 1 => 'Asked at the first meeting',
                2 => $P( 'Short answers to what clients ask us most.' ), 3 => 'Ask the studio',
                4 => 'How early should we call you?', 5 => $P( 'Before you buy the site, if you can. A two-week feasibility study often saves months.' ),
                6 => 'Do you take small projects?', 7 => $P( 'Yes. Extensions and single houses make up about a third of our work.' ),
                8 => 'Who will we deal with?', 9 => $P( 'A partner leads every project and is at every client meeting. You will never be passed down the line.' ),
                10 => 'How do you control cost?', 11 => $P( 'We cost every design stage and tender in packages, so there are no surprises on site.' ),
                12 => 'Do you work outside the Netherlands?', 13 => $P( 'Yes, with local partners. We have built in Belgium, Germany and Denmark.' ),
                14 => 'Can we visit a finished building?', 15 => $P( 'Gladly. We run open days each spring and can arrange visits with the owners.' ) ] ],
            [ 'cta-band', [ 0 => 'Ready to start with a feasibility study?', 1 => 'Request a proposal', 2 => $P( 'Two weeks, fixed fee.' ) ] ],
        ] ],

        'journal' => [ 'title' => 'Journal', 'sections' => [
            [ 'header-divider-intro', [
                0 => 'Journal', 1 => 'Notes from the studio and the site',
                2 => $P( 'Short essays on materials, mistakes and the buildings we love.' ) ] ],
            [ 'steps-horizontal', [
                0 => 'How we work', 1 => 'The four rhythms of a project',
                2 => $P( 'The calendar is the same on a shed or a library, only longer.' ),
                4 => 'Study', 5 => $P( 'Two weeks of site visits, history and conversations with neighbours.' ),
                7 => 'Draw', 8 => $P( 'Sketches, then models, then more sketches. We throw away most of them.' ),
                10 => 'Build', 11 => $P( 'Weekly site visits and mock-ups that stay on site until the final detail is right.' ),
                13 => 'Live', 14 => $P( 'We return a year later with a notebook and ask the users what they would change.' ),
                15 => 'Talk to the studio' ] ],
            [ 'compare-two-panels', [
                0 => 'On materials', 1 => 'Concrete and timber, honestly compared',
                2 => $P( 'We use both, and we are not neutral.' ),
                3 => 'Concrete', 4 => 'Heavy, durable and honest',
                7 => $P( 'Lasts a century or more' ), 10 => $P( 'Stores heat and cools slowly' ), 13 => $P( 'High carbon to make, so we use less' ), 16 => $P( 'Best for public buildings and foundations' ),
                17 => 'Timber', 18 => 'Light, warm and renewable',
                21 => $P( 'Stores carbon while it stands' ), 24 => $P( 'Fast to build, quiet to work with' ), 27 => $P( 'Needs care against fire and damp' ), 30 => $P( 'Best for houses and small public rooms' ),
                31 => 'Read our materials notes' ] ],
            [ 'faq-toggle-list', [
                0 => 'Reading list', 1 => 'Questions clients send us',
                2 => 'Why is your concrete so grey?', 3 => $P( 'Because we do not paint it. The colour of the sand and cement is the colour of the building.' ),
                4 => 'Does concrete leak?', 5 => $P( 'Only when it is badly detailed. A good drip, a good fall and a good builder fix nearly everything.' ),
                6 => 'Is brutalism still relevant?', 7 => $P( 'The honest use of material, the generosity to the street and the refusal to decorate are as relevant as ever.' ),
                8 => 'Do you design furniture?', 9 => $P( 'Sometimes, when we cannot find what a room needs. Everything is made by small local workshops.' ),
                10 => 'How do you feel about glass towers?', 11 => $P( 'Politely, and from a distance.' ),
                12 => $P( 'Another question? Write to studio@northwind.nl.' ) ] ],
            [ 'cta-card-over-image', [
                0 => 'px:15285869:1920', 1 => 'Visit', 2 => 'Come and see the model table.',
                3 => $P( 'Open studio on the first Friday of each month, with coffee, plans on the wall and the partners at hand.' ),
                4 => 'Book a visit' ] ],
        ] ],

        'contact' => [ 'title' => 'Contact', 'sections' => [
            [ 'header-title-band', [
                0 => $crumb( 'Contact' ), 1 => 'Contact the studio',
                2 => $P( 'Tell us about the site, the brief and the date. A partner will reply within two working days.' ) ] ],
            [ 'contact-location-form', [
                0 => 'px:37272849:1600', 1 => 'Northwind Architects: a round concrete room in monochrome',
                5 => $P( 'Havenstraat 12, 3011 Rotterdam' ), 9 => $P( 'Mon to Fri, 9am to 6pm. Visits by appointment.' ),
                10 => 'Visit or write', 11 => 'Tell us about your site.',
                12 => $P( 'A few lines are plenty. Drawings and photographs can follow once we have spoken.' ), 13 => 'Send enquiry' ] ],
            [ 'faq-accordion', [
                0 => 'FAQ', 1 => 'Before you write',
                2 => $P( 'Short answers to what clients ask first.' ),
                3 => 'What should I send first?', 4 => $P( 'The address, a rough brief and your timetable. A photograph of the site helps enormously.' ),
                5 => 'How soon will you reply?', 6 => $P( 'A partner answers within two working days.' ),
                7 => 'Is the first meeting free?', 8 => $P( 'Yes. An hour in the studio or on site, with coffee.' ),
                9 => 'Do you sign NDAs?', 10 => $P( 'Of course, and on the day if you prefer.' ),
                11 => 'Do you work remotely?', 12 => $P( 'For feasibility and concept stages, yes. Construction stages need us on site.' ),
                13 => 'Can I bring my builder?', 14 => $P( 'Please do. The earlier we all meet, the better the building.' ),
                15 => $P( 'Prefer to talk? Call +31 10 555 0183.' ) ] ],
            [ 'cta-band', [ 0 => 'Let us look at your site together.', 1 => 'Talk to the studio', 2 => $P( 'Open Monday to Friday.' ) ] ],
        ] ],
    ],
];
