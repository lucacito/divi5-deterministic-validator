<?php
declare(strict_types=1);

/**
 * Ashdown Workshop: a portfolio landing page for an independent furniture designer-maker in Sheffield. Hero editorial
 * recipe, editorial language with the quiet-solid kit, recoloured to warm paper, ink and walnut and set in Fraunces and
 * Instrument Sans. Photographs pinned by Pexels id. Fictional studio, people and press titles.
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$Q = static fn ( string $t ): string => '<p>&ldquo;' . $t . '&rdquo;</p>';
$L = static fn ( string $t ): string => '<p><a href="#">' . $t . '</a></p>';

return [
    'slug'     => 'elegant-furniture-maker-portfolio-landing-page-for-divi-5-ashdown-workshop',
    'title'    => 'Elegant Furniture Maker Portfolio Landing Page for Divi 5: Ashdown Workshop',
    'niche'    => 'portfolio',
    'style'    => 'elegant',
    'color'    => 'monochrome',
    'language' => 'editorial',
    'kit'      => 'quiet-solid',
    'palette'  => [ 'bg' => '#faf7f2', 'surface' => '#ffffff', 'text' => '#17130f', 'muted' => '#5f5a52', 'accent' => '#6e3a22', 'accent_text' => '#ffffff', 'border' => '#dcd3c4', 'inverse' => '#fbf6ec', 'overlay' => '#17130f' ],
    'fonts'    => [ 'Playfair Display' => 'Fraunces', 'Source Sans 3' => 'Instrument Sans' ],
    'sections' => [
        [ 'hero-editorial-minimal', [
            0 => 'Ashdown Workshop · Sheffield, since 2009',
            1 => 'Furniture built to outlast the room it is in.',
            2 => $P( 'Ruth Ashdown designs and makes tables, chairs and cabinets in oak, ash and walnut. Each piece is made by hand, one commission at a time, in a former tannery on the river.' ),
            3 => $L( 'See the commissions' ) ] ],
        [ 'features-zigzag', [
            0 => 'Selected commissions', 1 => 'Three pieces, three rooms',
            2 => $P( 'Each one started as a conversation about how the room is used and how the piece will be lived with.' ),
            3 => 'px:7109997', 4 => 'Hands cutting a dovetail joint in walnut',
            5 => 'The walnut dining table', 6 => $P( 'Ten solid walnut boards, joined by hand for a family of six who host on Sundays.' ), 7 => $L( 'Read the case study' ),
            8 => 'The oak kitchen table', 9 => $P( 'A long oak table for a farmhouse kitchen, sized to fit through the door it had to pass.' ), 10 => $L( 'Read the case study' ),
            11 => 'px:6908558', 12 => 'A long oak table and wooden cabinets in a bright modern kitchen',
            13 => 'px:7602895', 14 => 'A pale birch armchair being lifted by its curved arms',
            15 => 'The reading chair', 16 => $P( 'A low armchair in birch, with a seat cut for a reader who likes to sit with one leg folded.' ), 17 => $L( 'Read the case study' ) ] ],
        [ 'steps-numbered', [
            0 => 'How a commission runs', 1 => 'Three stages from first call to delivery',
            2 => $P( 'Most commissions take between ten and sixteen weeks, from the first drawing to the day the piece arrives.' ),
            3 => $P( '01' ), 4 => 'Talk, sketch, measure',
            5 => $P( 'A visit to the room, a set of sketches and a full-size template for anything that has to fit a gap.' ),
            6 => $P( '02' ), 7 => 'Choose the timber',
            8 => $P( 'We look at the boards together, by grain and colour. Oak, ash or walnut, each with its own character.' ),
            9 => $P( '03' ), 10 => 'Build, finish and deliver',
            11 => $P( 'Joinery by hand, three coats of natural oil, and delivery in our own van within sixty miles.' ) ] ],
        [ 'gallery-masonry-style', [
            0 => 'The workshop', 1 => 'Timber, tools and the afternoon light',
            2 => $P( 'Photographed at the bench, with the workshop as it usually is.' ),
            3 => 'px:27520661', 4 => 'A cluttered workshop with a walnut cabinet under a bare bulb',
            5 => 'px:32599546', 6 => 'Chisels with worn leather grips laid out on a bench',
            7 => 'px:40406319', 8 => 'Carving chisels resting in a bed of fresh wood shavings',
            9 => 'px:12278581', 10 => 'Stacked hardwood boards with the grain showing',
            11 => 'px:16243258', 12 => 'A wall of old hand tools above a teal workbench',
            13 => 'px:29537620', 14 => 'A wooden workbench laid out with traditional woodworking tools' ] ],
        [ 'founder-note', [
            0 => 'px:6790748:800', 1 => 'The bench where every Ashdown piece begins, with shavings and a pencil',
            2 => $P( 'Ruth Ashdown' ), 3 => $P( 'Designer and maker, founder' ),
            4 => 'A note from the founder', 5 => 'Good furniture is quiet about how it was made',
            6 => $P( 'I trained as a joiner before I trained as a designer, and I still think with my hands. A drawing is only a promise until the first shaving falls.' ),
            7 => $P( 'Every commission begins at the bench, not on a screen. We design around the timber, the room and the person who will sit in the chair, and we turn down work that does not suit the wood.' ),
            8 => $P( 'If you have a space that needs a piece to last, I would like to hear about it.' ),
            9 => $P( 'Ruth' ) ] ],
        [ 'proof-press-quotes', [
            0 => 'In the press', 1 => 'What other writers have said',
            2 => $P( 'Plane Quarterly' ), 3 => $Q( 'Ashdown builds for the next fifty years, not the next catalogue.' ), 4 => $P( 'Joinery worth keeping, spring 2025' ),
            5 => $P( 'Studio Notes Monthly' ), 6 => $Q( 'The joinery is the most honest I have seen all year.' ), 7 => $P( 'Five makers to know, March 2025' ),
            8 => $P( 'The Hearth Review' ), 9 => $Q( 'A chair made to be handed down, sized for real bodies.' ), 10 => $P( 'The long table, autumn 2024' ) ] ],
        [ 'features-accordion-image', [
            0 => 'How we work', 1 => 'Four things clients ask about first',
            2 => 'Timber', 3 => $P( 'Oak, ash and walnut from British sawmills we visit. Each board is chosen by grain and moisture, not only by price.' ),
            4 => 'Joinery', 5 => $P( 'Mortise and tenon, dovetail and pegged joints, cut by hand. Nothing is screwed, so the piece can always be taken apart and repaired.' ),
            6 => 'Finish', 7 => $P( 'Three coats of natural oil and wax, chosen to age well. We talk through the colour before any work starts.' ),
            8 => 'Delivery', 9 => $P( 'We deliver and place the piece ourselves within sixty miles of the workshop, and advise on its care after that.' ),
            10 => 'px:37358115', 11 => 'Hands guiding a hand plane over a board, with shavings falling away' ] ],
        [ 'faq-toggle-list', [
            0 => 'Commissions', 1 => 'Questions before you write',
            2 => 'How much does a commission cost?', 3 => $P( 'A solid oak dining table starts around £4,800, and most commissions fall between £3,000 and £9,000. We give a written quote after the first visit.' ),
            4 => 'How long does it take?', 5 => $P( 'Between ten and sixteen weeks from the first drawing to delivery, depending on the timber and the finish.' ),
            6 => 'Do you deliver?', 7 => $P( 'Within sixty miles of the workshop we deliver and place the piece ourselves. Further afield, we arrange a specialist carrier.' ),
            8 => 'Can you repair a piece I already own?', 9 => $P( 'Yes. We mend loose joints, replace worn timber and re-oil old surfaces, and we say honestly when a piece is beyond repair.' ),
            10 => 'Will solid wood move?', 11 => $P( 'Yes, with the seasons. We design for it, with joints that allow movement, and we advise on keeping the room steady.' ),
            12 => $P( 'Another question? Write to us from the contact page.' ) ] ],
        [ 'contact-location-form', [
            0 => 'px:12278569:1600', 1 => 'Stacked oak boards in the timber store at Ashdown Workshop',
            4 => $P( 'Address' ), 5 => $P( 'Unit 4, Tannery Yard, Sheffield' ),
            8 => $P( 'Studio hours' ), 9 => $P( 'Tuesday to Saturday, 9:00 to 17:00. Visits by appointment.' ),
            10 => 'Visit or write', 11 => 'Come and see the timber',
            12 => $P( 'Tell us about the room, the piece and when you need it. Ruth replies within two working days.' ), 13 => 'Send enquiry' ] ],
        [ 'cta-band', [ 0 => 'Tell us about the room you are furnishing.', 1 => 'Request a commission', 2 => $P( 'The first visit is free and takes about an hour, at your home or at the workshop.' ) ] ],
    ],
];
