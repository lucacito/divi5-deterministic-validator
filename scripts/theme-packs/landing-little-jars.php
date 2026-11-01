<?php
declare(strict_types=1);

/**
 * Little Jars Preserve Co.: a playful landing page for a small-batch jam and marmalade brand. Hero product-glow recipe,
 * playful-pastel language with the chunky-pill-offset kit, recoloured to cream, raspberry and deep plum and set in
 * Bricolage Grotesque and DM Sans. Photographs pinned by Pexels id. Fictional business; prices and shipping are the
 * demo's own.
 */
$P = static fn ( string $t ): string => "<p>$t</p>";
$Q = static fn ( string $t ): string => '<p>&ldquo;' . $t . '&rdquo;</p>';
$L = static fn ( string $t ): string => '<p><a href="#">' . $t . '</a></p>';

return [
    'slug'     => 'playful-small-batch-preserves-landing-page-for-divi-5-little-jars',
    'title'    => 'Playful Small-Batch Preserves Landing Page for Divi 5: Little Jars',
    'niche'    => 'ecommerce',
    'style'    => 'playful',
    'color'    => 'pastel',
    'language' => 'playful-pastel',
    'kit'      => 'chunky-pill-offset',
    'palette'  => [ 'bg' => '#fff4e6', 'surface' => '#ffffff', 'text' => '#2e1238', 'muted' => '#6a5275', 'accent' => '#ef4f7a', 'accent_text' => '#2e1238', 'border' => '#f0d6c2', 'inverse' => '#ffffff', 'overlay' => '#2e1238' ],
    'fonts'    => [ 'Fredoka' => 'Bricolage Grotesque', 'Nunito' => 'DM Sans' ],
    'sections' => [
        [ 'hero-product-glow', [
            0 => 'Small batch, cooked weekly',
            1 => 'Jam that tastes like the orchard it came from',
            2 => $P( 'Cooked in copper pans with fruit picked within two days, sugar by weight and no syrup, ever.' ),
            3 => 'Shop the jars', 4 => 'Build a box',
            5 => 'px:6588623:1920', 6 => 'Four jars of preserves wrapped in brown paper and red string',
            7 => $P( 'Cooked in 6 kg batches' ), 8 => $P( 'Free shipping over $35' ), 9 => $P( 'Broken jars replaced, no questions' ) ] ],
        [ 'proof-rating-strip', [
            10 => $P( '4.8 out of 5' ), 11 => $P( 'From 1,240 reviews on our shop and Google' ),
            12 => 'fa:f0d1', 14 => $P( 'Ships in two days' ),
            15 => 'fa:f06c', 17 => $P( 'Fruit picked within 48 hours' ),
            18 => 'fa:f004', 20 => $P( 'Made by four people in one kitchen' ) ] ],
        [ 'features-zigzag', [
            0 => 'How it is made', 1 => 'Three things we do the slow way',
            2 => $P( 'No shortcuts in the pan. Every jar goes through the same three steps.' ),
            3 => 'px:6588617', 4 => 'Halved oranges on a wooden board beside a jar of marmalade',
            5 => 'Fruit picked at its best', 6 => $P( 'We buy from two growers within an hour\'s drive and cook the fruit within two days of picking.' ), 7 => $L( 'Meet the growers' ),
            8 => 'Copper pans, low heat', 9 => $P( 'Jam sets slowly in copper on a low flame, so the fruit keeps its colour and its shape.' ), 10 => $L( 'How we cook' ),
            11 => 'px:6588487', 12 => 'Hands tying red string around a jar of preserves',
            13 => 'px:6588431', 14 => 'Hands lifting a jar of marmalade from a kitchen table',
            15 => 'Hand-sealed, hand-labelled', 16 => $P( 'Each lid is pressed down and each label is written by hand, so every jar is looked at twice.' ), 17 => $L( 'See the jars' ) ] ],
        [ 'checklist-two-column', [
            0 => 'What goes in', 1 => 'Fruit, sugar and time, mostly',
            2 => $P( 'Every jar lists what is inside it, and none of it is a stand-in.' ),
            5 => $P( 'Fruit picked within two days' ), 8 => $P( 'Unrefined cane sugar, weighed' ),
            11 => $P( 'Fresh lemon juice, squeezed that morning' ), 14 => $P( 'Pectin only where the fruit needs it' ),
            17 => $P( 'No corn syrup, ever' ), 20 => $P( 'No colours or preservatives' ),
            23 => $P( 'Glass jars we wash and reuse' ), 26 => $P( 'A label signed by the cook' ) ] ],
        [ 'quote-over-image', [
            0 => 'px:5590969:1920', 2 => $P( 'The apricot and cardamom is the jar I open first. The kids argue over the last spoon.' ),
            3 => $P( 'Aisha Bell' ), 4 => $P( 'Home cook, two children' ) ] ],
        [ 'steps-numbered', [
            0 => 'How a box finds you', 1 => 'Three steps to a full pantry',
            2 => $P( 'Pick your jars, choose a rhythm, and we do the rest, usually within the week.' ),
            3 => $P( '01' ), 4 => 'Pick your jars',
            5 => $P( 'Choose a single jar, a breakfast trio or the pantry box, and mix the flavours.' ),
            6 => $P( '02' ), 7 => 'Choose a rhythm',
            8 => $P( 'Every four, six or eight weeks. Skip, pause or change flavours at any time.' ),
            9 => $P( '03' ), 10 => 'We cook, pack and post',
            11 => $P( 'Jars are cooked to order and posted on Tuesdays in packaging we can recycle.' ) ] ],
        [ 'stats-big-captions', [
            0 => 'In figures', 1 => 'Two years of jam, counted',
            2 => $P( 'Figures from our own shop, January 2024 to October 2025.' ),
            3 => $P( '14k' ), 4 => $P( 'jars cooked and sealed so far' ),
            5 => $P( '4.8' ), 6 => $P( 'average rating from 1,240 reviews' ),
            7 => $P( '71%' ), 8 => $P( 'of first-time buyers order again' ),
            9 => $P( '6 kg' ), 10 => $P( 'of fruit in every cooking batch' ) ] ],
        [ 'pricing-three-tier', [
            0 => 'Bundles', 1 => 'Pick a box, or build your own',
            2 => $P( 'Prices include tax. Subscribe and save ten percent on any box, and cancel at any time.' ),
            3 => 'One jar', 4 => $P( 'Try a flavour before you commit.' ), 5 => $P( '$9' ), 6 => $P( '/ jar' ),
            9 => $P( 'One 220 g jar' ), 12 => $P( 'Any flavour, any week' ), 15 => $P( 'Posted within three days' ), 16 => 'Buy one jar',
            17 => $P( 'Most popular' ),
            18 => 'Breakfast trio', 19 => $P( 'Three jars, one for each morning of the week.' ), 20 => $P( '$24' ), 21 => $P( '/ box of three' ),
            24 => $P( 'Three 220 g jars' ), 27 => $P( 'Swap any jar, once a month' ), 30 => $P( 'Gift wrap on request' ), 31 => 'Choose the trio',
            32 => 'The pantry box', 33 => $P( 'Six jars for a household that spreads jam on everything.' ), 34 => $P( '$48' ), 35 => $P( '/ box of six' ),
            38 => $P( 'Six 220 g jars, mixed or matched' ), 41 => $P( 'Subscribe and save ten percent' ), 44 => $P( 'Free shipping every time' ), 45 => 'Start a pantry box' ] ],
        [ 'faq-accordion', [
            0 => 'FAQ', 1 => 'Questions, answered',
            2 => $P( 'The post, the jars and the boxes, in short answers.' ),
            3 => 'Where do you ship?', 4 => $P( 'We post across the continental US. Orders over $35 ship free, and smaller orders pay $4.95.' ),
            5 => 'Do the jars arrive in one piece?', 6 => $P( 'Every jar is wrapped in paper and packed upright. If one breaks, send a photo and we will replace it.' ),
            7 => 'How long does shipping take?', 8 => $P( 'Boxes post on Tuesdays and usually arrive in two to four working days.' ),
            9 => 'Can I return a jar?', 10 => $P( 'Unopened jars can come back within 30 days for a refund. Opened jars cannot be resold, but tell us what went wrong.' ),
            11 => 'Can I change my delivery date?', 12 => $P( 'Yes, up to two days before the post. Change it in your account or reply to any of our emails.' ),
            13 => 'Can I send a box as a gift?', 14 => $P( 'Yes. Gift wrap is free on the trio and the pantry box, and we will leave out the price list.' ),
            15 => $P( 'Another question? Write to us at the address printed on every jar.' ) ] ],
        [ 'cta-band', [ 0 => 'First box this week.', 1 => 'Build your box', 2 => $P( 'Mix any flavours. Pause any time.' ) ] ],
    ],
];
