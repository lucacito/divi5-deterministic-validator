<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/StyleKits.php';
require_once __DIR__ . '/KitAdapters.php';
require_once __DIR__ . '/KitResolver.php';
require_once __DIR__ . '/RecipeTokens.php';
require_once __DIR__ . '/LanguageAdapters.php';

/**
 * AI-facing Divi 5 authoring guide.
 *
 * Returns the real block structure rules + styling attribute shapes (mined
 * verbatim from real Divi 5 exports — see docs/STYLE.md) so an AI assistant can
 * produce valid, *styled* layouts instead of plain ones. Served by the
 * get_style_guide MCP tool and the GET /style-guide REST endpoint.
 */
final class StyleGuide
{
    /**
     * The topics served on request: `design-languages`, `design-language:<id>` for every design language, then the
     * vocabulary-family topics (each teaches only shapes proven on the real Divi; see docs/shape-evidence-5.15.1.md),
     * then `style-kits`, `kit-rules` (what each module takes from a kit) and `style-kit:<id>` for every style kit.
     *
     * @return list<string>
     */
    public static function topics(): array
    {
        $topics = [ 'design-languages' ];
        foreach ( DesignLanguages::ids() as $id ) {
            $topics[] = 'design-language:' . $id;
        }
        $topics[] = 'backgrounds';
        $topics[] = 'dividers';
        $topics[] = 'motion';
        $topics[] = 'hover-states';
        $topics[] = 'layout-advanced';
        $topics[] = 'layout-sizing';
        $topics[] = 'typography';
        $topics[] = 'imagery';
        $topics[] = 'controls';
        $topics[] = 'toggles';
        $topics[] = 'forms';
        $topics[] = 'composition';
        $topics[] = 'style-kits';
        $topics[] = 'kit-rules';
        foreach ( StyleKits::ids() as $id ) {
            $topics[] = 'style-kit:' . $id;
        }
        return $topics;
    }

    /** @return list<string> every topic name, the design languages and the style kits each collapsed to one `<prefix>:<id>` entry */
    private static function topicNames(): array
    {
        $names = [];
        foreach ( self::topics() as $topic ) {
            $name = $topic;
            if ( str_starts_with( $topic, 'design-language:' ) ) {
                $name = 'design-language:<id>';
            } elseif ( str_starts_with( $topic, 'style-kit:' ) ) {
                $name = 'style-kit:<id>';
            }
            if ( ! in_array( $name, $names, true ) ) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The one line the default guide ends with: how to ask for a topic, naming every topic. Generated from topics() so it cannot
     * drift; the design languages are one placeholder entry instead of one entry per id.
     */
    private static function topicLine(): string
    {
        return 'Topics: call get_style_guide with topic=<name>: ' . implode( ', ', self::topicNames() ) . '.';
    }

    /** The description of the `topic` parameter in the MCP schema and the OpenAPI spec (generated; at most 300 characters, and kept at 260 or fewer so the next adapters' topic names fit). */
    public static function topicParameterDescription(): string
    {
        return 'Optional topic: ' . implode( ', ', self::topicNames() ) . '. <id>: one language or kit.';
    }

    /**
     * The guide as Markdown. Pure — no WordPress calls — so it is trivially testable.
     * With no topic (null or ''), the default guide; with a topic, only that topic's text.
     * An unknown topic is a normal answer that lists the valid topics.
     */
    public static function markdown( ?string $topic = null ): string
    {
        if ( null === $topic || '' === $topic ) {
            return self::guide() . "\n\n" . self::topicLine();
        }
        if ( 'design-languages' === $topic ) {
            return DesignLanguages::markdown();
        }
        if ( 'backgrounds' === $topic ) {
            return self::backgroundsTopic();
        }
        if ( 'dividers' === $topic ) {
            return self::dividersTopic();
        }
        if ( 'motion' === $topic ) {
            return self::motionTopic();
        }
        if ( 'hover-states' === $topic ) {
            return self::hoverStatesTopic();
        }
        if ( 'layout-advanced' === $topic ) {
            return self::layoutAdvancedTopic();
        }
        if ( 'layout-sizing' === $topic ) {
            return self::layoutSizingTopic();
        }
        if ( 'typography' === $topic ) {
            return self::typographyTopic();
        }
        if ( 'imagery' === $topic ) {
            return self::imageryTopic();
        }
        if ( 'controls' === $topic ) {
            return self::controlsTopic();
        }
        if ( 'toggles' === $topic ) {
            return self::togglesTopic();
        }
        if ( 'forms' === $topic ) {
            return self::formsTopic();
        }
        if ( 'composition' === $topic ) {
            return self::compositionTopic();
        }
        if ( 'style-kits' === $topic ) {
            return self::styleKitsTopic();
        }
        if ( 'kit-rules' === $topic ) {
            return self::kitRules();
        }
        if ( str_starts_with( $topic, 'design-language:' ) && in_array( $topic, self::topics(), true ) ) {
            return DesignLanguages::markdown( substr( $topic, strlen( 'design-language:' ) ) );
        }
        if ( str_starts_with( $topic, 'style-kit:' ) && in_array( $topic, self::topics(), true ) ) {
            return self::styleKitTopic( substr( $topic, strlen( 'style-kit:' ) ) );
        }
        $lines = [ '# Unknown topic', '', sprintf( 'There is no topic "%s". Valid topics:', SectionRecipes::plain( $topic ) ), '' ];
        foreach ( self::topics() as $valid ) {
            $lines[] = '- `' . $valid . '`';
        }
        return implode( "\n", $lines ) . "\n";
    }

    /** Topic `style-kits`: what a kit is, the catalog, which language uses which kit and how to choose. At most 10,000 bytes. */
    private static function styleKitsTopic(): string
    {
        $catalog = StyleKits::markdown();
        $catalog = (string) preg_replace( '/\A# Style kits\n\n/', '', $catalog );
        $catalog = (string) preg_replace( '/\n\nAsk for one kit in full with its id\.\n\z/', "\n", $catalog );

        $byKit = [];
        foreach ( DesignLanguages::ids() as $languageId ) {
            $language = DesignLanguages::get( $languageId );
            if ( null !== $language ) {
                $byKit[ (string) ( $language['kit'] ?? '' ) ][] = $languageId;
            }
        }
        // Only the kits a language starts with are listed (one line each), so the catalog stays within its budget as kits are added.
        $defaults = [];
        foreach ( StyleKits::ids() as $id ) {
            if ( [] !== ( $byKit[ $id ] ?? [] ) ) {
                $defaults[] = sprintf( '- `%s`: %s', $id, implode( ', ', $byKit[ $id ] ) );
            }
        }
        $defaults[] = '- Every other kit: no design language starts with it; ask for it with `style_kit`.';

        return "# Style kits: one shape for every control\n\n"
            . "A style kit is a bundle of decisions applied to a module so that every module of that kind on the page matches: border width, style and sides (a full frame or an underline), corner radius, filled or outline, shadow, hover (a colour change or a lift), label capitalization, weight and letter spacing, padding. "
            . "Colour and font are not part of a kit: they come from the page's design language, so one kit looks different in every language and stays readable in all of them.\n\n"
            . "Every design language has a default kit. `get_section_recipes` takes `style_kit` with `name` to serve a recipe in another kit; without it the recipe uses the language's own kit.\n\n"
            . "## The kits\n\n" . $catalog . "\n"
            . "Each kit in full, with ready-made blocks: topic=style-kit:<id> with any id listed above. What each module takes: topic=kit-rules.\n\n"
            . "## Default kit of each design language\n\n" . implode( "\n", $defaults ) . "\n\n"
            . "## How to choose\n\n"
            . "- Start from your design language's default kit; change it only when the brief asks for another feel.\n"
            . "- Match the kit to the language: its corners (tight or soft) and weight (outline and thin, or filled and heavy) should read like the rest of the page.\n"
            . "- One kit per page. Don't mix kits: a soft filled button next to a tight outline one looks like two designs.\n\n"
            . "## What is kit-driven today\n\n"
            . self::appliedNounsSentence() . ' ' . self::notAppliedSentence() . ' '
            . "To build one without a recipe, copy the block from the kit's topic and swap its colours for your design language's roles.\n";
    }

    /** The ink (colour role) a child uses on a ground of the given fill role: the one pairing KitAdapters::ON also uses for a filled button's label (pinned equal by a test). */
    /**
     * The glyph codes the icon chip clause lists (Font Awesome, type "fa", weight "900"): each is one the shipped recipes render in a
     * `divi/icon` block (StyleGuideKitRulesTest derives the allowed set from data/section-recipes.json), with the name only where it is certain.
     */
    private const GLYPHS = 'f00c check, f00d close, f005 star, f017 clock, f0e7 bolt, f201 chart, f3c5 pin, f0e0 envelope, f095 phone, f013 gear, f10d quote';

    private const GROUND_INK = [ 'accent' => 'accent-text', 'text' => 'bg' ];

    /** What the sentence names, per marker role: a plain noun. A role without an entry is named by its id. */
    private const APPLIED_NOUNS = [
        'control'           => 'buttons',
        'control-secondary' => 'buttons',
        'card'              => 'cards',
        'panel'             => 'cards',
        'item'              => 'accordion and toggle items',
        'item-rule'         => 'accordion and toggle items',
        'form'              => 'forms',
        'rule'              => 'rules',
        'rule-strong'       => 'rules',
        'rule-accent'       => 'rules',
        'chip'              => 'chips',
    ];

    /**
     * The modules the guide names as keeping the recipe's own styling, each with the blocks that would make it kit-driven:
     * a noun is named only while NONE of its blocks is in KitAdapters::APPLIED, so the sentence cannot claim a module that an
     * adapter now styles (accordions left this list with the item adapter).
     */
    private const NOT_APPLIED_NOUNS = [
        'accordions'   => [ 'divi/accordion', 'divi/toggle' ],
        'forms'        => [ 'divi/contact-form' ],
        'blurbs'       => [ 'divi/blurb' ],
        'testimonials' => [ 'divi/testimonial' ],
        'image radius' => [ 'divi/image' ],
    ];

    /**
     * "Blurbs, testimonials and every other module keep the recipe's own styling." (or, with every noun kit-driven, "Every other
     * module keeps the recipe's own styling."), generated from KitAdapters::APPLIED (see NOT_APPLIED_NOUNS).
     *
     * @param array<string,list<string>>|null $applied defaults to KitAdapters::APPLIED
     * @param list<string>|null               $media   blocks that take the media radius; defaults to KitAdapters::MEDIA_RADIUS_BLOCKS (a block there is no longer named)
     */
    public static function notAppliedSentence( ?array $applied = null, ?array $media = null ): string
    {
        $applied ??= KitAdapters::APPLIED;
        $media   ??= KitAdapters::MEDIA_RADIUS_BLOCKS;
        $nouns = [];
        foreach ( self::NOT_APPLIED_NOUNS as $noun => $blocks ) {
            if ( [] === array_intersect( $blocks, array_merge( array_keys( $applied ), $media ) ) ) {
                $nouns[] = $noun;
            }
        }
        $nouns[] = 'every other module';

        // The verb agrees in number (carriers spec Ruling 9): "Every other module keeps", "Forms and every other module keep".
        return ucfirst( count( $nouns ) > 1 ? implode( ', ', array_slice( $nouns, 0, -1 ) ) . ' and ' . end( $nouns ) : $nouns[0] ) . ( count( $nouns ) > 1 ? ' keep' : ' keeps' ) . " the recipe's own styling.";
    }

    /**
     * The kit-rules paragraph on grounds, with the fill roles read from the adapter's own ground patches (KitAdapters::GROUNDS
     * through patch(), for the first shipped kit) and the child inks from GROUND_INK, followed by a blank line; '' (the
     * paragraph is dropped, fail closed) unless the fills are exactly `text` (inverted) and `accent` (accent), the two the
     * sentence and GROUND_INK describe.
     *
     * @param array<string,string>|null $fills ground => fill colour role; defaults to the adapter's (a parameter only so a test can prove the check fails closed)
     */
    public static function groundSentence( ?array $fills = null ): string
    {
        if ( null === $fills ) {
            $fills = [];
            $kit   = StyleKits::get( (string) ( StyleKits::ids()[0] ?? '' ) );
            foreach ( [ 'inverted', 'accent' ] as $ground ) {
                $patch            = null === $kit ? null : KitAdapters::patch( 'divi/group', 'panel', 'light', $kit, $ground );
                $fills[ $ground ] = (string) preg_replace( '/^\{\{aied:color:([a-z-]+)\}\}$/', '$1', (string) ( $patch['module']['decoration']['background']['desktop']['value']['color'] ?? '' ) );
            }
        }
        if ( [ 'inverted' => 'text', 'accent' => 'accent' ] !== $fills ) {
            return '';
        }

        return sprintf(
            'A panel can sit on an `inverted` ground (%s colour as fill; its text in the %s colour, or the surface colour on a surface band) or an `accent` ground (%s colour as fill; its text in the %s colour). The frame takes the fill colour; the kit never re-inks the children. ',
            $fills['inverted'],
            self::GROUND_INK[ $fills['inverted'] ],
            $fills['accent'],
            self::GROUND_INK[ $fills['accent'] ]
        ) . 'A button on an inverted panel takes the `panel` treatment (each kit topic says how).' . "\n\n";
    }

    /**
     * The body of the `kit-rules` paragraph "Accordion and toggle items", generated from the role map: every sentence is said only
     * while a carrier (`divi/accordion`, `divi/toggle`) answers the role it describes, and every path named is one
     * KitAdapters::item() writes for that role (tests compare them with the adapter's real output for every kit). '' while no
     * carrier answers an item role.
     *
     * @param array<string,list<string>> $applied
     */
    private static function itemRules( array $applied ): string
    {
        $accordion = array_values( array_intersect( [ 'item', 'item-rule' ], $applied['divi/accordion'] ?? [] ) );
        $toggle    = array_values( array_intersect( [ 'item', 'item-rule' ], $applied['divi/toggle'] ?? [] ) );
        $item      = in_array( 'item', $accordion, true ) || in_array( 'item', $toggle, true );
        $rule      = in_array( 'item-rule', $accordion, true ) || in_array( 'item-rule', $toggle, true );
        if ( ! $item && ! $rule ) {
            return '';
        }
        $d = 'desktop.value';

        $both = $item && $rule;
        $what = $both ? 'An `item` is a boxed item and an `item-rule` a rule row' : ( $item ? 'An `item` is a boxed item' : 'An `item-rule` is a rule row' );
        $s    = [
            $what . ', on a `divi/accordion` (one accordion styles all its items, never one item alone) or a `divi/toggle`.',
            ( $both ? 'Both: the' : 'The' ) . ' title in the text colour, open and closed; the answer in the muted colour; the icons in the text colour (an accordion has no open icon); every state is written, so none falls back to Divi\'s grey defaults; no hover.',
        ];
        if ( $item ) {
            $s[] = 'An `item` is filled like a card (the surface colour, the bg colour on a surface band), the same in both states, and takes the card\'s frame, radius and rest shadow; on a toggle it also takes the card padding (an accordion keeps Divi\'s own item padding: its padding setting would pad the answer).';
        }
        if ( $rule ) {
            $s[] = 'An `item-rule` (on a light or surface band only) is filled with the band colour, has square corners, only a bottom rule (the kit\'s width, at least 1px, and style, in the card frame colour) and no shadow; the recipe keeps its own padding.';
        }
        if ( [] !== $accordion ) {
            $s[] = "On the accordion write all of it on the parent; each item gets it: fills at `openToggle.decoration.background.{$d}.color` and `closedToggle.decoration.background.{$d}.color`, titles at `openToggle.decoration.font.font.{$d}.color` and `closedToggle.decoration.font.font.{$d}.color`, the icon at `closedToggleIcon.decoration.icon.{$d}.color`, the answer at `content.decoration.bodyFont.body.font.{$d}.color`.";
        }
        if ( [] !== $accordion ) {
            $s[] = "An item is a `divi/accordion-item`: `title.innerContent.{$d}` (the question), `content.innerContent.{$d}` (the answer, `<p>` HTML) and `module.advanced.open.{$d}` = \"on\" on the open item"
                . ( [] !== $toggle ? '; a `divi/toggle` takes the same three.' : '.' );
        }
        if ( [] !== $toggle ) {
            $s[] = [] !== $accordion
                ? "A `divi/toggle` carries the look on itself, not on a parent, at the same paths except the closed title at `closedTitle.decoration.font.font.{$d}.color` (not `closedToggle`) and the open icon at `openToggleIcon.decoration.icon.{$d}.color`."
                : "A `divi/toggle` carries the look on itself, not on a parent: fills at `openToggle.decoration.background.{$d}.color` and `closedToggle.decoration.background.{$d}.color`, the open title at `openToggle.decoration.font.font.{$d}.color`, the closed title at `closedTitle.decoration.font.font.{$d}.color` (not `closedToggle`), the icons at `closedToggleIcon.decoration.icon.{$d}.color` and `openToggleIcon.decoration.icon.{$d}.color`, the answer at `content.decoration.bodyFont.body.font.{$d}.color`.";
        }
        if ( $item ) {
            $s[] = "An `item` also writes its frame and radius at `module.decoration.border.{$d}` and its shadow at `module.decoration.boxShadow.{$d}`" . ( in_array( 'item', $toggle, true ) ? ", and a toggle its padding at `module.decoration.spacing.{$d}.padding`" : '' ) . '.';
        }
        if ( $rule ) {
            $s[] = "An `item-rule` writes only its bottom rule at `module.decoration.border.{$d}.styles.bottom` and square corners at `module.decoration.border.{$d}.radius`: no frame, no shadow.";
        }

        return implode( ' ', $s );
    }

    /**
     * The one sentence that defines `on`, the band a kit block sits on (round 3 of the guide polish: four of six dogfood runs
     * could not tell which band a dark language is). Generated from the bands the adapters accept (KitAdapters::RULE_BANDS and
     * CHIP_BANDS, which cover the button, card, item and form bands too): a band is described only if it is accepted and a band
     * without a description here is never named.
     *
     * @param list<string>|null $bands defaults to the union of the two constants (a parameter only so a test can prove the sentence follows them)
     */
    public static function bandSentence( ?array $bands = null ): string
    {
        $bands ??= array_values( array_unique( array_merge( KitAdapters::RULE_BANDS, KitAdapters::CHIP_BANDS ) ) );
        $says    = [
            'light'   => "is the language's own page colour (a dark language's too)",
            'surface' => 'its surface',
            'accent'  => 'the accent',
            'dark'    => 'a photo or overlay',
            'panel'   => 'the inside of an inverted card',
        ];
        $parts = [];
        foreach ( $bands as $band ) {
            if ( isset( $says[ $band ] ) ) {
                $parts[] = '`' . $band . '` ' . $says[ $band ];
            }
        }

        return [] === $parts ? '' : ' `on` is the band a block sits on: ' . implode( ', ', $parts ) . '.';
    }

    /**
     * Topic `kit-rules`: what each module takes from a kit, the same for every kit, said ONCE here instead of in every kit
     * topic. Generated from KitAdapters::APPLIED: a module's section is served only while an adapter answers its roles; the
     * carriers of the carriers adapter close it in one paragraph (carrierRules()). At most 10,000 bytes (pinned at 9,600).
     *
     * @param array<string,list<string>>|null $applied defaults to KitAdapters::APPLIED (a parameter only so a test can prove the topic follows the map)
     * @param array<string,bool>|null          $proven  defaults to KitAdapters::FORM_PROVEN (a parameter only so a test can prove the Contact forms paragraph follows the probe's verdicts)
     * @param list<string>|null                $media   defaults to KitAdapters::MEDIA_RADIUS_BLOCKS (a parameter only so a test can prove the Images paragraph follows the const)
     * @param array<string,bool>|null          $ruleProven defaults to KitAdapters::RULE_PROVEN (a parameter only so a test can prove the Rules and chips paragraph follows the probe's verdicts)
     * @param array<string,bool>|null          $chipProven defaults to KitAdapters::CHIP_PROVEN (likewise)
     */
    public static function kitRules( ?array $applied = null, ?array $proven = null, ?array $media = null, ?array $ruleProven = null, ?array $chipProven = null ): string
    {
        $applied ??= KitAdapters::APPLIED;
        $media   ??= KitAdapters::MEDIA_RADIUS_BLOCKS;
        $roles   = array_merge( [], ...array_values( $applied ) );
        $has     = static fn ( string $role ): bool => in_array( $role, $roles, true );

        $out = "# Kit rules: what each module takes from a kit\n\n"
            . "The same for every kit. Each kit's own values and ready-made blocks: topic=style-kit:<id>.\n\n"
            . self::appliedSentence( $applied ) . ' ' . self::notAppliedSentence( $applied, $media ) . "\n\n"
            . "## Reusing a kit topic's blocks\n\n"
            . "Every block in a kit topic is real: resolved by the plugin's own pipeline for the `editorial` language on a light section, with placeholder text. "
            . "Put it inside a column. With another design language keep the structure and swap each colour for its role (listed beside the blocks as value = role(s)), not by its value: two roles can share one value; "
            . "and swap the font family for your language's body font. "
            . "Where a design language's prose and the kit disagree about the corners, borders, shadow or hover of a block a kit styles, the kit wins."
            . self::bandSentence() . "\n";
        if ( $has( 'control' ) ) {
            $out .= "\n## Buttons\n\n"
                . "A button takes the kit's control values: fill, border and its sides, control radius, shadow, hover and lift, text and control padding. "
                . "On an accent band or a photo a button keeps the kit's shape; only its colours change (each kit topic says how). "
                . "Shadows and transforms belong on the button module (module.decoration.boxShadow, module.decoration.transform), never on button.decoration: Divi drops those on a button.\n";
        }
        if ( $has( 'card' ) ) {
            $out .= "\n## Cards and panels\n\n"
                . 'A `card` is a grid tile and a `panel` is a static surface; both go on a `divi/group` (or a `divi/column`). '
                . 'The fill is always the language\'s surface colour (the `surface` role; the `bg` role on a surface-coloured band), never transparent. '
                . 'The kit\'s border width and style are used, but at least 1px; the frame colour is the text colour (an accent frame is drawn in the text colour) or the border hairline (the `border` role) when the kit\'s border colour is `same` or `border`. '
                . 'A card takes the card radius and padding. A panel is a static surface: the same radius, frame and shadow, no hover, and the recipe keeps its own padding. A panel takes the rest shadow only.' . "\n\n"
                . self::groundSentence()
                . 'Kit decisions no card takes: the text decisions (capitalization, weight and letter spacing are button-only) and the fill colour' . ( [] === $media ? '; a kit\'s media radius is recorded in the kit but no module takes it yet.' : '.' ) . "\n";
        }
        if ( [] !== $media ) {
            $out .= "\n## Images\n\n"
                . 'Photos (' . implode( ', ', array_map( static fn ( string $b ): string => '`' . $b . '`', $media ) ) . ') take the kit\'s media radius, the third radius of every kit topic. '
                . 'In a recipe served with a kit, a photo that the recipe rounds follows it (0px gives a square photo); a circle, a pill, an arch or a tiled grid keeps its own corners: those are shapes. '
                . 'For your own image write all four corners at that radius at `image.decoration.border.desktop.value.radius`.' . "\n";
        }
        $itemRules = self::itemRules( $applied );
        if ( '' !== $itemRules ) {
            $out .= "\n## Accordion and toggle items\n\n" . $itemRules . "\n";
        }

        if ( $has( 'form' ) ) {
            $out .= "\n## Contact forms\n\n" . self::formRules( $proven ?? KitAdapters::FORM_PROVEN ) . "\n";
        }
        if ( $has( 'rule' ) || $has( 'rule-strong' ) || $has( 'chip' ) ) {
            $rp    = $ruleProven ?? KitAdapters::RULE_PROVEN;
            $cp    = $chipProven ?? KitAdapters::CHIP_PROVEN;
            $shape = self::ruleChipShapes( $applied, $rp, $cp );
            $body  = self::ruleChipRules( $applied, $rp, $cp );
            if ( '' !== $body ) {
                // Only the divider, group, column and icon are described here: a map whose rule or chip roles sit only on the
                // carriers of the More carriers paragraph has no Rules and chips paragraph.
                $out .= "\n## Rules and chips\n\n" . $body . ( '' === $shape ? '' : "\n\n" . $shape ) . "\n";
            }
        }

        return $out . self::carrierRules( $applied );
    }

    /**
     * The `## More carriers` paragraph of `kit-rules` (carriers spec Ruling 8, at most 560 B with its heading): one clause per
     * carrier in $applied whose base decision is proven (a testimonial card or panel, a blurb icon chip, a number counter rule,
     * the accent mark on a divider), generated from the map, the probe's verdicts and the patch itself (the blurb paths are the
     * leaves of KitAdapters::chipPatch(), never typed). A proven decision is said, an unproven one is never claimed and its
     * fallback is stated. '' when none of the four carriers is offered.
     *
     * @param array<string,list<string>>|null $applied defaults to KitAdapters::APPLIED
     * @param array<string,bool>|null          $proven  defaults to KitAdapters::CARRIER_PROVEN (a parameter only so a test can prove the clauses follow the probe's verdicts)
     */
    public static function carrierRules( ?array $applied = null, ?array $proven = null ): string
    {
        $applied ??= KitAdapters::APPLIED;
        $proven  ??= KitAdapters::CARRIER_PROVEN;
        $is      = static fn ( string $decision ): bool => true === ( $proven[ $decision ] ?? false );
        $roleOf  = static fn ( string $block, string $role ): bool => in_array( $role, $applied[ $block ] ?? [], true );
        $or      = static fn ( array $names ): string => count( $names ) > 1 ? implode( ', ', array_slice( $names, 0, -1 ) ) . ' or ' . end( $names ) : (string) ( $names[0] ?? '' );
        $parts   = [];

        $cardRoles = array_values( array_filter( [ 'card', 'panel' ], static fn ( string $r ): bool => $roleOf( 'divi/testimonial', $r ) ) );
        if ( [] !== $cardRoles && $is( 'testimonial-fill' ) && $is( 'testimonial-frame' ) && $is( 'testimonial-radius' ) && $is( 'testimonial-quote' ) ) {
            $missing = array_values( array_filter( [
                $is( 'testimonial-padding' ) ? '' : 'padding',
                $is( 'testimonial-shadow' ) ? '' : 'shadow',
                $is( 'testimonial-hover-shadow' ) ? '' : 'hover shadow',
                $is( 'testimonial-hover-lift' ) ? '' : 'lift',
                $is( 'testimonial-hover-frame' ) ? '' : 'hover frame',
            ] ) );
            $parts[] = $or( array_map( static fn ( string $r ): string => '`' . $r . '`', $cardRoles ) ) . ' on a `divi/testimonial`: the group card, no ground; the quote icon stays Divi\'s'
                . ( $is( 'testimonial-underline' ) ? '' : '; an underline kit frames it at 1px' )
                . ( [] === $missing ? '' : '; no kit ' . implode( ', ', $missing ) ) . '.';
        }

        $kit = StyleKits::get( (string) ( StyleKits::ids()[0] ?? '' ) );
        if ( $roleOf( 'divi/blurb', 'chip' ) && $is( 'blurb-icon-radius' ) && null !== $kit ) {
            $names = [ 'radius' => 'control radius', 'background' => 'fill', 'advanced' => 'glyph' ];
            $what  = [];
            foreach ( array_keys( self::leafPaths( KitAdapters::chipPatch( 'divi/blurb', 'light', $kit, KitAdapters::CHIP_PROVEN, KitAdapters::RULE_PROVEN, $proven ) ) ) as $path ) {
                foreach ( $names as $part => $name ) {
                    if ( str_contains( $path, '.' . $part ) ) {
                        $what[] = $name . ' `' . $path . '`';
                        break;
                    }
                }
            }
            $parts[] = '`chip` on a `divi/blurb`: ' . implode( ', ', $what )
                . ( $is( 'blurb-icon-fill' ) && $is( 'blurb-icon-glyph' ) ? '' : '; not on an accent band' ) . '; padding and size stay.';
        }

        $ruleRoles = array_values( array_filter( [ 'rule', 'rule-strong' ], static fn ( string $r ): bool => $roleOf( 'divi/number-counter', $r ) ) );
        if ( [] !== $ruleRoles && $is( 'counter-top' ) ) {
            $styles = array_values( array_filter( [ 'dashed', 'dotted', 'double' ], static fn ( string $style ): bool => ! $is( 'counter-top-' . $style ) ) );
            $parts[] = $or( array_map( static fn ( string $r ): string => '`' . $r . '`', $ruleRoles ) ) . ' on a `divi/number-counter`: the top edge, as on a group'
                . ( [] === $styles ? '' : '; ' . implode( ' or ', $styles ) . ' is drawn as solid' )
                . ( $is( 'counter-top-alpha' ) ? '' : '; quiet takes the strong colour off light and surface' ) . '.';
        }

        if ( $roleOf( 'divi/divider', 'rule-accent' ) && $is( 'divider-accent-over-preset' ) && true === ( KitAdapters::RULE_PROVEN['divider-line'] ?? false ) ) {
            $parts[] = '`rule-accent` on a `divi/divider`: accent colour, strong width, kit style' . ( $is( 'divider-accent-style' ) ? '' : ' (drawn solid)' )
                . '; `on`: ' . implode( ' or ', KitAdapters::ACCENT_RULE_BANDS ) . '; a brand mark, not a separator.';
        }

        return [] === $parts ? '' : "\n## More carriers\n\n" . implode( ' ', $parts ) . "\n";
    }

    /** Dotted path => value of every leaf of $node. @param array<mixed> $node @return array<string,mixed> */
    private static function leafPaths( array $node, string $prefix = '' ): array
    {
        $out = [];
        foreach ( $node as $k => $v ) {
            $path = '' === $prefix ? (string) $k : $prefix . '.' . $k;
            if ( is_array( $v ) && [] !== $v ) {
                $out += self::leafPaths( $v, $path );
            } else {
                $out[ $path ] = $v;
            }
        }
        return $out;
    }

    /**
     * The Rules and chips paragraph of `kit-rules`: what the `rule`, `rule-strong` and `chip` markers write, generated from
     * the map ($applied: which carriers answer which role) and the probe's verdicts ($ruleProven, $chipProven): a proven
     * decision is said, an unproven one is never claimed and its fallback is stated; a carrier whose base is unproven is not
     * offered.
     *
     * @param array<string,list<string>> $applied
     * @param array<string,bool>         $ruleProven decision => proven on its carrier
     * @param array<string,bool>         $chipProven decision => proven on its carrier
     */
    private static function ruleChipRules( array $applied, array $ruleProven, array $chipProven ): string
    {
        $is      = static fn ( array $map, string $decision ): bool => true === ( $map[ $decision ] ?? false );
        $roleOf  = static fn ( string $block, string $role ): bool => in_array( $role, $applied[ $block ] ?? [], true );
        $bands   = static fn ( array $names ): string => implode( ', ', array_slice( $names, 0, -1 ) ) . ' or ' . end( $names );
        $parts   = [];

        // Rules: the carriers that answer a rule role and whose base is proven.
        $carrier = static fn ( string $block ): bool => ( $roleOf( $block, 'rule' ) || $roleOf( $block, 'rule-strong' ) ) && $is( $ruleProven, 'divi/divider' === $block ? 'divider-line' : ( 'divi/group' === $block ? 'group-top' : 'column-top' ) );
        $divider = $carrier( 'divi/divider' );
        $edges   = array_values( array_filter( [ 'divi/group', 'divi/column' ], $carrier ) );
        if ( $divider || [] !== $edges ) {
            $what = [];
            if ( $divider ) {
                $what[] = 'a `divi/divider` (colour, weight, style)';
            }
            if ( [] !== $edges ) {
                $what[] = 'the top edge of a ' . implode( ' or ', array_map( static fn ( string $b ): string => '`' . $b . '`', $edges ) );
            }
            $names   = [ 'divider-' => '`divi/divider`', 'group-top-' => '`divi/group`', 'column-top-' => '`divi/column`' ];
            $present = [ 'divider-' => $divider, 'group-top-' => in_array( 'divi/group', $edges, true ), 'column-top-' => in_array( 'divi/column', $edges, true ) ];
            // A clause is said only while at least one present carrier proves the form it describes (a carrier that does not is
            // named in the fallback sentences below).
            $anyProven = static function ( string $suffix ) use ( $names, $present, $is, $ruleProven ): bool {
                foreach ( array_keys( $names ) as $prefix ) {
                    if ( $present[ $prefix ] && $is( $ruleProven, $prefix . $suffix ) ) {
                        return true;
                    }
                }
                return false;
            };
            $text = '`rule` (quiet), `rule-strong`: ' . implode( ' or ', $what ) . '; `on`: ' . $bands( KitAdapters::RULE_BANDS ) . '. '
                . 'Width from the kit: 1-2px quiet, 2-4px strong' . ( $anyProven( 'double' ) ? ', 3px+ for a double' : '' ) . '. '
                . 'Quiet: hairline colour on light and surface' . ( $anyProven( 'alpha' ) ? ', 40% ink elsewhere' : '' ) . '; strong: full ink. ';
            foreach ( $names as $prefix => $name ) {
                if ( ! $present[ $prefix ] ) {
                    continue;
                }
                $styles = array_values( array_filter( [ 'dashed', 'dotted', 'double' ], static fn ( string $style ): bool => ! $is( $ruleProven, $prefix . $style ) ) );
                if ( [] !== $styles ) {
                    $text .= 'A ' . $name . ' rule in ' . implode( ' or ', $styles ) . ' is drawn as solid. ';
                }
            }
            $noAlpha = array_keys( array_filter( $names, static fn ( string $prefix ): bool => $present[ $prefix ] && ! $is( $ruleProven, $prefix . 'alpha' ), ARRAY_FILTER_USE_KEY ) );
            if ( [] !== $noAlpha ) {
                $text .= 'On accent, dark and panel the quiet rule of ' . implode( ' and ', array_map( static fn ( string $prefix ): string => $names[ $prefix ], $noAlpha ) ) . ' takes the strong colour. ';
            }
            if ( [] !== $edges && in_array( 'divi/group', $edges, true ) && ! $is( $ruleProven, 'group-top-in-card' ) ) {
                $text .= 'A rule group is not proven inside a card: keep it outside. ';
            }
            $parts[] = rtrim( $text );
        }

        // Chips.
        $tag  = $roleOf( 'divi/group', 'chip' ) && ( $is( $chipProven, 'tag-radius' ) || $is( $chipProven, 'tag-frame' ) );
        $icon = $roleOf( 'divi/icon', 'chip' ) && ( $is( $chipProven, 'icon-radius' ) || $is( $chipProven, 'icon-fill' ) || $is( $chipProven, 'icon-glyph' ) );
        if ( $tag || $icon ) {
            $what = [];
            if ( $tag ) {
                $tagParts = array_values( array_filter( [ $is( $chipProven, 'tag-radius' ) ? 'control radius' : '', $is( $chipProven, 'tag-frame' ) ? 'outline frame' : '' ] ) );
                $styles   = array_values( array_filter( [ 'dashed', 'dotted', 'double' ], static fn ( string $style ): bool => ! $is( $chipProven, 'tag-' . $style ) ) );
                $tagNotes = [];
                if ( $is( $chipProven, 'tag-frame' ) ) {
                    if ( [] !== $styles ) {
                        $tagNotes[] = implode( ' or ', $styles ) . ' is drawn as solid';
                    }
                    if ( ! $is( $ruleProven, 'group-top-alpha' ) ) {
                        // The tag frame reuses the group rule's 40% colour (KitAdapters::chipPatch): same fallback.
                        $tagNotes[] = 'on accent and dark the frame is the strong colour';
                    }
                }
                $what[]   = 'tag (`divi/group`: ' . implode( ', ', $tagParts ) . ( [] !== $tagNotes ? '; ' . implode( '; ', $tagNotes ) : '' ) . ')';
            }
            if ( $icon ) {
                $iconParts = array_values( array_filter( [ $is( $chipProven, 'icon-radius' ) ? 'control radius' : '', $is( $chipProven, 'icon-fill' ) ? 'accent fill' : '', $is( $chipProven, 'icon-glyph' ) ? 'accent-text glyph' : '' ] ) );
                $accent    = $is( $chipProven, 'icon-fill' ) && $is( $chipProven, 'icon-glyph' ) ? '; surface/text on accent' : '; not offered on an accent band';
                $what[]    = 'icon (`divi/icon`: ' . implode( ', ', $iconParts ) . $accent . ')';
            }
            $parts[] = '`chip`: ' . implode( ' or ', $what ) . '; `on`: ' . $bands( KitAdapters::CHIP_BANDS ) . '.';
        }

        return implode( ' ', $parts );
    }

    /**
     * The "write it yourself" passage under Rules and chips: the exact width rule and the real attribute paths of each shape
     * (guide polish, plan 2026-10-21-guide-polish-rules-chips). Same gates as ruleChipRules(): a clause is said only while the
     * decision it relies on is proven, and the passage is '' when nothing is offered. The paths are the probe-proven ones
     * (fixtures/shapes/kit-rule-vocabulary.md, kit-chip-vocabulary.md); the glyph path is the real-export one. It names the
     * shapes and the numbers, never the marker syntax.
     *
     * @param array<string,list<string>> $applied
     * @param array<string,bool>         $ruleProven decision => proven on its carrier
     * @param array<string,bool>         $chipProven decision => proven on its carrier
     */
    private static function ruleChipShapes( array $applied, array $ruleProven, array $chipProven ): string
    {
        $is     = static fn ( array $map, string $decision ): bool => true === ( $map[ $decision ] ?? false );
        $roleOf = static fn ( string $block, string $role ): bool => in_array( $role, $applied[ $block ] ?? [], true );
        $rule   = static fn ( string $block ): bool => $roleOf( $block, 'rule' ) || $roleOf( $block, 'rule-strong' );
        $parts  = [];

        $divider = $rule( 'divi/divider' ) && $is( $ruleProven, 'divider-line' );
        $edges   = [];
        if ( $rule( 'divi/group' ) && $is( $ruleProven, 'group-top' ) ) {
            $edges[] = 'group';
        }
        if ( $rule( 'divi/column' ) && $is( $ruleProven, 'column-top' ) ) {
            $edges[] = 'column';
        }
        if ( $divider || [] !== $edges ) {
            $double = ( $divider && $is( $ruleProven, 'divider-double' ) ) || ( in_array( 'group', $edges, true ) && $is( $ruleProven, 'group-top-double' ) ) || ( in_array( 'column', $edges, true ) && $is( $ruleProven, 'column-top-double' ) );
            // The numbers are KitAdapters::ruleWidth(): max(1, min(2, w)) and max(2, min(4, w + 1)), a double at least 3px.
            $parts[] = 'Width by hand: quiet = the kit\'s border width, at least 1px, at most 2px; strong = that width plus 1px, at least 2px, at most 4px'
                . ( $double ? '; a double is never under 3px' : '' ) . '. Line style = the kit\'s border style.';
            if ( $divider ) {
                $parts[] = 'Divider: `divider.advanced.line.desktop.value` with `color`, `weight`, `style`.';
            }
            if ( [] !== $edges ) {
                $parts[] = 'Top edge of a ' . implode( ' or ', $edges ) . ': `module.decoration.border.desktop.value.styles` with `all` = width `0px` plus the style and colour, and `top` = the rule width, the same style and colour; '
                    . 'the other three sides stay 0; to rule the top of a specific ' . implode( ' or ', $edges ) . ' (for example between plan columns), write the edge on that block itself' . ( $divider ? '; a divider module is for a separate line between blocks.' : '.' );
                // Round 3, probed on both carriers (kit-rule-vocabulary.md "Guide-only fact"): a framed block keeps its frame.
                $parts[] = 'A ' . implode( ' or ', $edges ) . ' that already has a frame keeps `styles.all` and gets only `styles.top` (width, style, colour); the `all` 0px form is for a block without a frame.';
                $parts[] = 'A kit rule has no left or right edge, so side-by-side columns get no vertical rule: use the top edge' . ( $divider ? ' or a divider line.' : '.' );
            }
        }

        if ( $roleOf( 'divi/group', 'chip' ) && ( $is( $chipProven, 'tag-radius' ) || $is( $chipProven, 'tag-frame' ) ) ) {
            $tag = array_values( array_filter( [
                $is( $chipProven, 'tag-radius' ) ? '`radius` (the four corners, the kit\'s control radius)' : '',
                $is( $chipProven, 'tag-frame' ) ? '`styles.all` (width, style, colour; 1px at least)' : '',
            ] ) );
            $parts[] = 'Tag: `module.decoration.border.desktop.value` with ' . implode( ' and ', $tag ) . '. Its fill stays transparent; its padding and size are yours.'
                . ( $is( $chipProven, 'tag-frame' ) ? ' Frame colour: the quiet colour when the kit\'s border colour is `border` or `same`, else the strong one.' : '' );
        }

        if ( $roleOf( 'divi/icon', 'chip' ) && ( $is( $chipProven, 'icon-radius' ) || $is( $chipProven, 'icon-fill' ) || $is( $chipProven, 'icon-glyph' ) ) ) {
            $icon = array_values( array_filter( [
                $is( $chipProven, 'icon-radius' ) ? '`module.decoration.border.desktop.value.radius` (the kit\'s control radius)' : '',
                $is( $chipProven, 'icon-fill' ) ? '`module.decoration.background.desktop.value.color` (fill)' : '',
                $is( $chipProven, 'icon-glyph' ) ? '`icon.advanced.color.desktop.value` (glyph colour)' : '',
            ] ) );
            $text = 'Icon chip: ' . implode( ', ', $icon );
            if ( $is( $chipProven, 'icon-fill' ) || $is( $chipProven, 'icon-glyph' ) ) {
                // The glyph character itself (a real export: unicode, type and weight go together).
                $text .= '; the glyph is `icon.innerContent.desktop.value` with `unicode` (e.g. "&#xf00c;"), `type` "fa", `weight` "900"; codes: ' . self::GLYPHS;
            }
            $parts[] = $text . '. Its size: glyph size at `icon.advanced.size.desktop.value` plus the padding at `module.decoration.spacing.desktop.value.padding` (e.g. 20px with 10px).';
        }

        return implode( ' ', $parts );
    }

    /**
     * The Contact forms paragraph of `kit-rules`: what the `form` marker writes, with every conditional decision worded from
     * $proven (the probe's verdicts, KitAdapters::FORM_PROVEN): a proven one is said, an unproven one is never claimed and its
     * fallback is stated.
     *
     * @param array<string,bool> $proven decision => proven on the form
     */
    private static function formRules( array $proven ): string
    {
        $is = static fn ( string $decision ): bool => true === ( $proven[ $decision ] ?? false );

        $text = 'A `form` marker styles a `divi/contact-form` (fields, submit button, captcha); `on` is the band: light, surface, accent or dark. '
            . 'A field is filled with surface (bg on a surface band); text colour for its text, muted for its placeholder; frame in the kit\'s width (1px at least) and style, in text colour, or muted for a `same` or `border` border colour (never on an accent band). '
            . ( $is( 'field-padding' ) ? 'It takes the control radius and control padding (the four sides at `field.decoration.spacing.desktop.value.padding`). ' : 'It takes the control radius; its padding stays the recipe\'s. ' )
            . ( $is( 'field-underline' ) ? 'An underline kit draws a bottom rule only. ' : 'An underline kit still draws a full 1px frame. ' );

        $with    = array_values( array_filter( [ $is( 'button-padding' ) ? 'submit padding' : '', $is( 'button-shadow' ) ? 'submit shadow' : '', $is( 'button-lift' ) ? 'submit lift' : '' ] ) );
        $without = array_values( array_filter( [
            $is( 'button-padding' ) ? '' : 'The submit button keeps the recipe\'s padding. ',
            $is( 'button-shadow' ) ? '' : 'The submit button has no shadow. ',
            $is( 'button-lift' ) ? '' : 'The submit button does not lift. ',
        ] ) );
        $text .= 'The submit button is the kit\'s primary button' . ( [] === $with ? '' : ', with ' . ( count( $with ) > 1 ? implode( ', ', array_slice( $with, 0, -1 ) ) . ' and ' . end( $with ) : $with[0] ) . ' at `button.decoration.*`, not `module.decoration` (live on a contact form, the opposite of a `divi/button`)' ) . '; '
            . ( $is( 'button-underline' ) ? 'an underline edge keeps its colour on hover. ' : 'an underline edge is a full frame. ' )
            . implode( '', $without )
            . 'The captcha takes the band\'s ink. The form frame stays yours.';

        return $text;
    }

    /**
     * "Kit-driven today: buttons (`divi/button`: `control`, `control-secondary`), cards (`divi/group`, `divi/column`: `card`,
     * `panel`), ... and chips (`divi/group`, `divi/icon`: `chip`)." Generated from KitAdapters::APPLIED (block => roles), so
     * the guide can only claim what an adapter exists for: grouped by NOUN (APPLIED_NOUNS order), each noun listing the
     * blocks that carry any of its roles, in APPLIED order, and those roles; a block that carries roles of two nouns is named
     * under each. A role without a noun is named by its id, after the nouns.
     *
     * @param array<string,list<string>>|null $applied defaults to KitAdapters::APPLIED (a parameter only so a test can prove the sentence follows the map)
     */
    public static function appliedSentence( ?array $applied = null ): string
    {
        $parts  = array_map( static fn ( array $p ): string => $p[1], self::appliedParts( $applied ?? KitAdapters::APPLIED ) );
        $joined = count( $parts ) > 1 ? implode( ', ', array_slice( $parts, 0, -1 ) ) . ' and ' . end( $parts ) : (string) ( $parts[0] ?? 'nothing' );

        return 'Kit-driven today: ' . $joined . '.';
    }

    /**
     * The catalog's noun-only form of appliedSentence() (carriers spec Ruling 7): "Kit-driven today: buttons, cards, accordion
     * and toggle items, forms, rules and chips (blocks and marker roles: topic=kit-rules)." The same nouns, in the same order and
     * joined the same way, from the same map; a role without a noun is named by its id.
     *
     * @param array<string,list<string>>|null $applied defaults to KitAdapters::APPLIED (a parameter only so a test can prove the sentence follows the map)
     */
    public static function appliedNounsSentence( ?array $applied = null ): string
    {
        $nouns  = array_map( static fn ( array $p ): string => $p[0], self::appliedParts( $applied ?? KitAdapters::APPLIED ) );
        $joined = count( $nouns ) > 1 ? implode( ', ', array_slice( $nouns, 0, -1 ) ) . ' and ' . end( $nouns ) : (string) ( $nouns[0] ?? 'nothing' );

        return 'Kit-driven today: ' . $joined . ' (blocks and marker roles: topic=kit-rules).';
    }

    /**
     * [noun, "noun (blocks: roles)"] per part of the applied sentence, in its order (see appliedSentence()).
     *
     * @param array<string,list<string>> $applied
     * @return list<array{0:string,1:string}>
     */
    private static function appliedParts( array $applied ): array
    {
        $ticks   = static fn ( array $names ): string => implode( ', ', array_map( static fn ( string $n ): string => '`' . $n . '`', $names ) );
        $byNoun  = [];
        foreach ( self::APPLIED_NOUNS as $role => $noun ) {
            $byNoun[ $noun ][] = $role;
        }
        $parts = [];
        foreach ( $byNoun as $noun => $nounRoles ) {
            $blocks = [];
            $roles  = [];
            foreach ( $applied as $block => $blockRoles ) {
                $mine = array_intersect( $nounRoles, $blockRoles );
                if ( [] !== $mine ) {
                    $blocks[] = $block;
                    $roles    = array_merge( $roles, $mine );
                }
            }
            if ( [] !== $blocks ) {
                $parts[] = [ $noun, sprintf( '%s (%s: %s)', $noun, $ticks( $blocks ), $ticks( array_values( array_intersect( $nounRoles, $roles ) ) ) ) ];
            }
        }
        $unnamed = [];
        foreach ( $applied as $block => $blockRoles ) {
            foreach ( $blockRoles as $role ) {
                if ( ! isset( self::APPLIED_NOUNS[ $role ] ) ) {
                    $unnamed[ $role ][] = $block;
                }
            }
        }
        foreach ( $unnamed as $role => $blocks ) {
            $parts[] = [ '`' . $role . '`', sprintf( '`%s` (%s: `%s`)', $role, $ticks( $blocks ), $role ) ];
        }

        return $parts;
    }

    /**
     * Topic `style-kit:<id>`: one kit's decisions and its own resolved blocks (primary and secondary button, card, panel,
     * inverted panel, toggle), produced by the real pipeline for the editorial language, plus ONE pointer to `kit-rules` for
     * what is the same in every kit. At most 10,000 bytes.
     */
    private static function styleKitTopic( string $id ): string
    {
        $kit      = StyleKits::get( $id );
        $language = DesignLanguages::get( 'editorial' );
        if ( null === $kit || null === $language ) {
            return StyleKits::markdown( $id );
        }

        $primary   = self::kitButton( $kit, $language, 'control', 'Primary button' );
        $secondary = self::kitButton( $kit, $language, 'control-secondary', 'Secondary button' );

        $lines = [ rtrim( StyleKits::markdown( $id ) ), '' ];
        $lines[] = 'What each module takes from a kit, and how to reuse these blocks in another design language (the same for every kit): topic=kit-rules.';
        $textOnly = self::kitTextOnlySentence( $kit );
        if ( '' !== $textOnly ) {
            $lines[] = '';
            $lines[] = $textOnly;
        }
        $lines[] = '';
        if ( '' !== $primary && '' !== $secondary ) {
            $lines[] = '## Buttons in this kit';
            $lines[] = '';
            $lines[] = 'Colours in these blocks (resolved for `editorial` on a light section), as value = role(s): '
                . self::kitColourLegend( array_values( array_filter( [ KitAdapters::patch( 'divi/button', 'control', 'light', $kit ), KitAdapters::patch( 'divi/button', 'control-secondary', 'light', $kit ) ] ) ), $language ) . '.';
            $lines[] = '';
            $lines[] = 'Primary:';
            $lines[] = '';
            $lines[] = '```';
            $lines[] = $primary;
            $lines[] = '```';
            $lines[] = '';
            $lines[] = 'Secondary:';
            $lines[] = '';
            $lines[] = '```';
            $lines[] = $secondary;
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '## On a light section, an accent band and a photo';
            $lines[] = '';
            $lines[] = 'Same shape, only the colour rules change. ' . self::kitSurfaceSentence( $kit, 'light', 'on a light section' ) . ' ' . self::kitSurfaceSentence( $kit, 'accent', 'on an accent band' ) . ' ' . self::kitSurfaceSentence( $kit, 'dark', 'on a photo or a dark overlay' );
            $effects = self::kitEffectsSentence( $kit );
            if ( '' !== $effects ) {
                $lines[] = '';
                $lines[] = '## Shadow, lift and underline';
                $lines[] = '';
                $lines[] = $effects;
            }
        }

        $cards = self::kitCardSection( $kit, $language );
        if ( '' !== $cards ) {
            $lines[] = '';
            $lines[] = $cards;
        }

        return implode( "\n", $lines ) . "\n";
    }

    /**
     * The "Cards and panels in this kit" section: this kit's own card values (read from the adapter's own patch, never
     * hand-typed per kit) and a resolved card, panel, inverted panel and toggle, produced by the real pipeline; what every
     * kit shares is in `kit-rules`. '' when the pipeline cannot resolve them.
     *
     * @param array<string,mixed> $kit
     * @param array<string,mixed> $language
     */
    private static function kitCardSection( array $kit, array $language ): string
    {
        $card  = self::kitCard( $kit, $language, 'card', 'Card' );
        $panel = self::kitCard( $kit, $language, 'panel', 'Panel' );
        $patch = KitAdapters::patch( 'divi/group', 'card', 'light', $kit );
        if ( '' === $card || '' === $panel || null === $patch ) {
            return '';
        }
        $deco   = (array) $patch['module']['decoration'];
        $border = (array) $deco['border']['desktop']['value']['styles'];
        $bottom = isset( $border['bottom'] );
        $edge   = (array) ( $bottom ? $border['bottom'] : $border['all'] );
        if ( 1 !== preg_match( '/^\{\{aied:color:([a-z-]+)\}\}$/', (string) $edge['color'], $m ) ) {
            return ''; // fail closed: a frame colour that is not a role token is never guessed
        }
        $role     = $m[1];
        $inverted = self::kitInvertedPanel( $kit, $language );
        $toggle   = self::kitToggle( $kit, $language );
        $ground   = KitAdapters::patch( 'divi/group', 'panel', 'light', $kit, 'inverted' );
        $onPanel  = KitAdapters::patch( 'divi/button', 'control', 'panel', $kit );
        $item     = KitAdapters::patch( 'divi/toggle', 'item', 'light', $kit );
        if ( '' === $inverted || '' === $toggle || null === $ground || null === $onPanel || null === $item ) {
            return '';
        }
        // Every colour the four snippets carry, in one legend (the toggle adds the muted answer colour).
        $legend = self::kitColourLegend( array_values( array_filter( [ $patch, KitAdapters::patch( 'divi/group', 'panel', 'light', $kit ), $ground, $onPanel, $item ] ) ), $language );
        if ( '' === $legend ) {
            return '';
        }
        $frame  = $bottom
            ? sprintf( 'A card has only a bottom edge (an underline): %s %s in the %s colour.', $edge['width'], $edge['style'], $role )
            : sprintf( 'A card has a %s %s frame in the %s colour.', $edge['width'], $edge['style'], $role );
        if ( isset( $deco['transform'] ) ) {
            $hover = 'On hover a card lifts 4px.';
        } elseif ( isset( $deco['border']['desktop']['hover'] ) ) {
            $hover = 'On hover a card\'s frame turns to the text colour.';
        } else {
            $hover = 'A card has no hover change.';
        }
        $shadow = (array) $kit['shadow'];
        $space  = (array) $kit['space'];
        $radius = (array) $kit['radius'];

        $lines   = [];
        $lines[] = '## Cards and panels in this kit';
        $lines[] = '';
        $lines[] = sprintf( 'Corner radius %s, padding %s (a card only). ', (string) $radius['card'], (string) $space['card'] )
            . $frame . ' ' . $hover
            . sprintf( ' Shadow: rest %s, hover %s.', (string) $shadow['rest'], (string) $shadow['hover'] );
        $lines[] = '';
        $lines[] = 'Colours in these blocks, as value = role(s): ' . $legend . '.';
        $lines[] = '';
        $lines[] = 'Card:';
        $lines[] = '';
        $lines[] = '```';
        $lines[] = $card;
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'Panel:';
        $lines[] = '';
        $lines[] = '```';
        $lines[] = $panel;
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'A button on an inverted panel takes the `panel` treatment: ' . self::kitSurfaceSentence( $kit, 'panel', 'on it', [ 'control' => 'primary' ] )
            . ' The recipe keeps its own padding on a panel (' . self::PANEL_PADDING . ' here).';
        $lines[] = '';
        $lines[] = 'Inverted panel:';
        $lines[] = '';
        $lines[] = '```';
        $lines[] = $inverted;
        $lines[] = '```';
        $lines[] = '';
        $lines[] = sprintf( 'Toggle (an `item`, padding %s). An accordion is styled on its parent: no padding, `closedToggle` font instead of `closedTitle`, no open icon.', (string) $space['card'] );
        $lines[] = '';
        $lines[] = '```';
        $lines[] = $toggle;
        $lines[] = '```';

        return implode( "\n", $lines );
    }

    /** The padding the inverted panel snippet carries: a panel keeps the recipe's own (structural) padding, the kit gives none. */
    private const PANEL_PADDING = '32px';

    /**
     * One toggle of a kit for a language on a light band, role `item`: the marker resolved by the real pipeline (KitAdapters
     * through KitResolver, then RecipeTokens), never hand-typed attributes. Empty when anything stays unresolved.
     *
     * @param array<string,mixed> $kit
     * @param array<string,mixed> $language
     */
    private static function kitToggle( array $kit, array $language ): string
    {
        $attrs  = [
            'aied'           => [ 'kit' => 'item', 'on' => 'light' ],
            'title'          => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Toggle title' ] ] ],
            'content'        => [ 'innerContent' => [ 'desktop' => [ 'value' => '<p>Placeholder answer.</p>' ] ] ],
            'builderVersion' => '5.8.0',
        ];
        $markup = '<!-- wp:divi/toggle ' . RecipeTokens::encodeJson( $attrs ) . ' /-->';
        $markup = RecipeTokens::resolve( KitResolver::resolve( $markup, $kit ), $language );

        return str_contains( $markup, 'aied' ) ? '' : $markup;
    }

    /**
     * One card or panel of a kit for a language on a light band: a group with one placeholder text child, the card marker
     * resolved by the real pipeline (KitAdapters through KitResolver, then RecipeTokens), never hand-typed attributes.
     * Empty when anything stays unresolved.
     *
     * @param array<string,mixed> $kit
     * @param array<string,mixed> $language
     */
    private static function kitCard( array $kit, array $language, string $role, string $text ): string
    {
        $group = [
            'aied'           => [ 'kit' => $role, 'on' => 'light' ],
            'module'         => [ 'decoration' => [ 'layout' => [ 'desktop' => [ 'value' => [ 'flexDirection' => 'column', 'rowGap' => '12px' ] ] ] ] ],
            'builderVersion' => '5.0.2',
        ];
        $child = [
            'module'         => [ 'decoration' => [ 'layout' => [ 'desktop' => [ 'value' => [ 'display' => 'block' ] ] ] ] ],
            'content'        => [ 'innerContent' => [ 'desktop' => [ 'value' => '<h4>' . $text . ' title</h4><p>Placeholder text for the ' . strtolower( $text ) . '.</p>' ] ] ],
            'builderVersion' => '5.0.0-public-beta.1',
        ];
        $markup = '<!-- wp:divi/group ' . RecipeTokens::encodeJson( $group ) . ' -->' . "\n"
            . '<!-- wp:divi/text ' . RecipeTokens::encodeJson( $child ) . ' /-->' . "\n"
            . '<!-- /wp:divi/group -->';
        $markup = RecipeTokens::resolve( KitResolver::resolve( $markup, $kit ), $language );

        return str_contains( $markup, 'aied' ) ? '' : $markup;
    }

    /**
     * One inverted panel of a kit for a language on a light band: a group with the card marker's `inverted` ground, and a primary
     * button on the `panel` surface, all resolved by the real pipeline
     * (KitAdapters through KitResolver, then RecipeTokens). Empty when anything stays unresolved.
     *
     * @param array<string,mixed> $kit
     * @param array<string,mixed> $language
     */
    private static function kitInvertedPanel( array $kit, array $language ): string
    {
        $group  = [
            'aied'           => [ 'kit' => 'panel', 'on' => 'light', 'ground' => 'inverted' ],
            'module'         => [ 'decoration' => [
                'layout'  => [ 'desktop' => [ 'value' => [ 'flexDirection' => 'column', 'rowGap' => '12px' ] ] ],
                // The kit writes no padding on a panel; without the recipe's own the button would sit on the panel's edges.
                'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => array_fill_keys( [ 'top', 'right', 'bottom', 'left' ], self::PANEL_PADDING ) ] ] ],
            ] ],
            'builderVersion' => '5.0.2',
        ];
        $button = self::kitButton( $kit, $language, 'control', 'Button', 'panel' );
        if ( '' === $button ) {
            return '';
        }
        $markup = '<!-- wp:divi/group ' . RecipeTokens::encodeJson( $group ) . ' -->' . "\n"
            . '{{button}}' . "\n"
            . '<!-- /wp:divi/group -->';
        $markup = str_replace( '{{button}}', $button, RecipeTokens::resolve( KitResolver::resolve( $markup, $kit ), $language ) );

        return str_contains( $markup, 'aied' ) ? '' : $markup;
    }

    /**
     * What the kit's shadow, lift and underline do, read from the adapter's own patch for the primary button (the same on
     * the secondary, except that a ghost secondary draws no underline, which is then said), or '' when the kit has none of them.
     *
     * @param array<string,mixed> $kit
     */
    private static function kitEffectsSentence( array $kit ): string
    {
        $patch = KitAdapters::patch( 'divi/button', 'control', 'light', $kit );
        if ( null === $patch ) {
            return '';
        }
        $module = (array) $patch['module']['decoration'];
        $out    = [];
        $rest   = $module['boxShadow']['desktop']['value']['style'] ?? null;
        $across = $module['boxShadow']['desktop']['value']['horizontal'] ?? null;
        $down   = $module['boxShadow']['desktop']['value']['vertical'] ?? null;
        if ( 'preset1' === $rest && '0px' === $down ) {
            $out[] = 'A soft glow surrounds the button: box shadow preset1 on the button module, 0px down, 16px blur, in the accent colour at 45% opacity (accent-text on an accent band); it stays on hover.';
        } elseif ( 'preset4' === $rest && '0px' === $across ) {
            $out[] = 'A hard edge sits straight under the button: box shadow preset4 on the button module, 0px across and 4px down, no blur, opaque, in the ink of the surface (the text colour on a light section, accent-text on an accent band, inverse on a photo); it stays on hover.';
        } elseif ( 'preset1' === $rest ) {
            $out[] = 'A soft shadow sits under the button: box shadow preset1 on the button module, 8px down, 24px blur, in the design language\'s overlay colour at 18% opacity; it stays on hover.';
        } elseif ( 'preset4' === $rest ) {
            $out[] = 'A hard offset shadow sits under the button: box shadow preset4 on the button module, 6px right and 6px down, no blur, opaque, in the ink of the surface (the text colour on a light section, accent-text on an accent band, inverse on a photo); it stays on hover.';
        }
        if ( isset( $module['boxShadow']['desktop']['hover'] ) ) {
            $out[] = 'A soft shadow appears under the button on hover: box shadow preset1 on the button module, 12px down, 24px blur, in the overlay colour at 25% opacity.';
        }
        if ( isset( $module['transform'] ) ) {
            $out[] = 'On hover the button lifts 4px (a transform on the button module, translate y -4px); its colours stay as they are.';
        }
        if ( isset( $patch['button']['decoration']['border']['desktop']['value']['styles']['bottom'] ) ) {
            $out[] = 'Only the bottom border is drawn: an underline (the frame width is 0px and the bottom side carries the width, style and colour); its colours never change on hover.';
            $secondary = KitAdapters::patch( 'divi/button', 'control-secondary', 'light', $kit );
            if ( '0px' === (string) ( $secondary['button']['decoration']['border']['desktop']['value']['styles']['bottom']['width'] ?? '' ) ) {
                $out[] = 'The secondary button draws no underline: it is a plain label (its bottom side is 0px).';
            }
        }

        return implode( ' ', $out );
    }

    /**
     * "The secondary button is text-only: ..." for each button role the kit draws with no fill and no side wider than 0px
     * on a light section (read from the adapter's own patch: a solid kit with a 0px border makes its secondary a
     * transparent, borderless button; a ghost, the primary of a ghost kit or the secondary of an outline kit, has no frame
     * whatever the kit's width), with the reason that is true for it, or '' when every role has a fill or a border.
     *
     * @param array<string,mixed> $kit
     */
    private static function kitTextOnlySentence( array $kit ): string
    {
        $out = [];
        foreach ( [ 'control' => 'primary', 'control-secondary' => 'secondary' ] as $role => $name ) {
            $patch = KitAdapters::patch( 'divi/button', $role, 'light', $kit );
            if ( null === $patch ) {
                continue;
            }
            $d      = (array) $patch['button']['decoration'];
            $widths = array_map( static fn ( $s ): string => (string) ( $s['width'] ?? '' ), (array) $d['border']['desktop']['value']['styles'] );
            if ( KitAdapters::TRANSPARENT !== $d['background']['desktop']['value']['color'] || [ '0px' ] !== array_values( array_unique( $widths ) ) ) {
                continue;
            }
            $fill  = (string) $kit['fill'];
            $fill  = 'control' === $role ? $fill : KitAdapters::secondaryFill( $fill );
            $out[] = 'ghost' === $fill
                ? sprintf( 'The %s button is text-only: a ghost, no fill and no frame, so it reads as a quiet link.', $name )
                : sprintf( 'The %s button is text-only: no fill and no border (this kit\'s border width is 0px), so it reads as a quiet link.', $name );
        }

        return implode( ' ', $out );
    }

    /**
     * One button block of a kit for a language, produced by the real pipeline (KitAdapters through KitResolver, then
     * RecipeTokens): never hand-typed attributes. Empty when anything stays unresolved. $on names the surface the button
     * sits on (the marker's `on`; '' = the default, a light section).
     *
     * @param array<string,mixed> $kit
     * @param array<string,mixed> $language
     */
    private static function kitButton( array $kit, array $language, string $role, string $text, string $on = '' ): string
    {
        $attrs = [
            'aied'           => '' === $on ? [ 'kit' => $role ] : [ 'kit' => $role, 'on' => $on ],
            'module'         => [ 'decoration' => [ 'layout' => [ 'desktop' => [ 'value' => [ 'display' => 'block' ] ] ] ] ],
            'button'         => [
                'decoration'   => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'family' => '{{aied:font:body}}' ] ] ] ] ],
                'innerContent' => [ 'desktop' => [ 'value' => [ 'text' => $text, 'id' => 0 ] ] ],
            ],
            'builderVersion' => '5.0.0-public-beta.1',
        ];
        $markup = '<!-- wp:divi/button ' . RecipeTokens::encodeJson( $attrs ) . ' /-->';
        $markup = RecipeTokens::resolve( KitResolver::resolve( $markup, $kit ), $language );

        return str_contains( $markup, 'aied' ) ? '' : $markup;
    }

    /**
     * The colour legend of some adapter patches: "#9a3b1f = accent; #ffffff = surface and accent-text; rgba(28,22,16,0.18) =
     * overlay at 18%". Every colour token the patches use, with the language's value, in order of first use. A plain value
     * names EVERY role of the language that has it: in editorial `surface` and `accent-text` are both #ffffff, and a legend
     * that named only one would let an AI read a card fill as a button label. '' when a token does not resolve.
     *
     * @param list<array<string,mixed>> $patches
     * @param array<string,mixed>       $language
     */
    private static function kitColourLegend( array $patches, array $language ): string
    {
        $tokens = [];
        foreach ( $patches as $patch ) {
            if ( false !== preg_match_all( '/\{\{aied:color:[a-z-]+(?::[0-9]+)?\}\}/', (string) json_encode( $patch ), $m ) ) {
                $tokens = array_merge( $tokens, $m[0] );
            }
        }
        $parts = [];
        foreach ( array_values( array_unique( $tokens ) ) as $token ) {
            $value = RecipeTokens::resolve( $token, $language );
            if ( $value === $token || 1 !== preg_match( '/^\{\{aied:color:([a-z-]+)(?::([0-9]+))?\}\}$/', $token, $t ) ) {
                return '';
            }
            if ( isset( $t[2] ) ) {
                $parts[ $value ] = $t[1] . ' at ' . $t[2] . '%';
                continue;
            }
            $roles = [];
            foreach ( (array) ( $language['colors'] ?? [] ) as $key => $hex ) {
                if ( is_string( $hex ) && strtolower( $hex ) === strtolower( $value ) ) {
                    $roles[] = str_replace( '_', '-', (string) $key );
                }
            }
            $roles           = [] === $roles ? [ $t[1] ] : $roles;
            $parts[ $value ] = count( $roles ) > 1 ? implode( ', ', array_slice( $roles, 0, -1 ) ) . ' and ' . end( $roles ) : $roles[0];
        }
        $out = [];
        foreach ( $parts as $value => $roles ) {
            $out[] = $value . ' = ' . $roles;
        }

        return implode( '; ', $out );
    }

    /**
     * The colour rules of the kit's primary and secondary button on a surface, read from the adapter's own patch
     * (role names only), e.g. "Primary: surface fill, text label, surface border."
     *
     * @param array<string,mixed>  $kit
     * @param array<string,string> $roles button marker role => the name the sentence gives it (default: both buttons)
     */
    private static function kitSurfaceSentence( array $kit, string $on, string $where, array $roles = [ 'control' => 'primary', 'control-secondary' => 'secondary' ] ): string
    {
        $out = [];
        foreach ( $roles as $role => $name ) {
            $patch = KitAdapters::patch( 'divi/button', $role, $on, $kit );
            if ( null === $patch ) {
                continue;
            }
            $d      = (array) $patch['button']['decoration'];
            $out[] = sprintf(
                'The %s button %s has %s fill, %s label and %s border.',
                $name,
                $where,
                self::kitRole( $d['background']['desktop']['value']['color'] ),
                self::kitRole( $d['font']['font']['desktop']['value']['color'] ),
                self::kitRole( $d['border']['desktop']['value']['styles']['all']['color'] )
            );
        }

        return implode( ' ', $out );
    }

    /** A colour role of an adapter patch in words: "text", "accent-text", or "a transparent". */
    private static function kitRole( string $value ): string
    {
        return 1 === preg_match( '/^\{\{aied:color:([a-z-]+)\}\}$/', $value, $m ) ? 'the ' . $m[1] . ' colour as' : 'a transparent';
    }

    /** Topic `backgrounds`: gradient, image, pattern and mask shapes, every one proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function backgroundsTopic(): string
    {
        return <<<'MD'
# Backgrounds: gradients, images, patterns, masks

Every shape and style listed here was rendered on the real Divi 5.15.1 and changed the page; a rule marked (source) is read
from Divi's own code and was not rendered, and the Limits section says what was not measured. They work on a
`divi/section`, a `divi/row` and a `divi/column`. All paths below sit under
`module.decoration.background.desktop.value.` (for example `module.decoration.background.desktop.value.gradient.type`);
use `desktop`, Divi cascades it to tablet and phone. `color` is the plain fill under the rest.
Never use video (it needs a media file) and never write CSS.

## Gradient (`gradient.*`)
- `enabled` = "on" and `stops`: a list of `{"position":"0","color":"#hex"}` (position is a string, 0 to 100, in the unit of
  `length`). Two or more stops; three or more make a multi-stop blend.
- `type`: "linear" (default), "circular", "elliptical", "conic".
- `direction` (linear): an angle such as "135deg", or one of the eight keywords "to top", "to top right", "to right",
  "to bottom right", "to bottom", "to bottom left", "to left", "to top left". For "conic" it is the start angle ("90deg").
- `directionRadial` (circular, elliptical, conic): where the centre sits: one word (center, top, bottom, left, right) or a
  vertical-and-horizontal pair such as "top right".
- Repeating stripes: `repeat` = "on" together with a short `length` such as "25%".
- `overlaysImage` = "on" draws the gradient above the section's image (use a translucent gradient for text over a photo).
- A conic gradient has a hard seam at its start angle unless the first and last stop share a colour.

## Image (`image.*`)
- `url`: an image token from get_image_guide or a Media Library URL; never a remote or invented URL.
- `size`: "cover", "contain", or "custom" with `width` and `height` ("320px", "auto"); width and height only apply with "custom".
- `position`: a horizontal word plus a vertical word: "left top", "center top", "right top", "left center", "right center",
  "left bottom", "center bottom", "right bottom". `horizontalOffset` and `verticalOffset` ("40px") move the image away from
  the named edge, so they only act next to a named left or right (horizontal) and top or bottom (vertical) `position`; with
  the default centre they move nothing (source; measured as a stylesheet-only change). Set both together.
- `repeat`: "repeat", "repeat-x", "repeat-y", "space", "round" (default no-repeat); useful with a small custom size.
- `blend`: "multiply" or "soft-light" mix the image with the section's `color` (set a colour to blend with).
- `parallax.enabled` = "on" scrolls the image slower than the page; `parallax.method` = "off" selects Divi's other parallax method.

## Pattern (`pattern.*`)
A repeating texture. Set `enabled` = "on", a `style` and a `color` (use a low alpha such as rgba(255,255,255,0.08) so
text stays readable).
- `style`, 24 proven: 3d-diamonds, checkerboard, confetti, crosses, cubes, diagonal-stripes, diagonal-stripes-2, diamonds, honeycomb, inverted-chevrons, inverted-chevrons-2, ogees, pills, pinwheel, polka-dots, scallops, shippo, smiles, squares, triangles, tufted, waves, zig-zag, zig-zag-2.
- `size`: "cover", "contain", "stretch", or "custom" with `width` and `height` ("60px") as the tile size.
- `repeat`: "repeat-x", "repeat-y", "space", "round". `repeatOrigin`: "left top" or "right bottom" style words.
  `horizontalOffset` and `verticalOffset` shift the start, but only when `repeatOrigin` is also set, `repeat` is not "space"
  and `size` is not "stretch"; without those they change nothing (each missing case measured). Set both together.
  `repeat` and `repeatOrigin` also need a `size` other than "stretch" (source).
- `blend` = "multiply". `transform`: a list of "flipHorizontal", "flipVertical", "invert", "rotate"
  (rotate drew nothing for polka-dots (rendered); crosses, pinwheel, shippo and tufted are symmetric too (source)).

## Mask (`mask.*`)
A shape cut out of the section: the mask paints `color` over the section and leaves the section's own background visible
only inside the shape. Set `color` to the colour behind the section (the page or the neighbouring section), usually "#ffffff".
Set `enabled` = "on", a `style` and a `color`.
- `style`, 23 proven: arch, bean, blades, caret, chevrons, corner-blob, corner-lake, corner-paint, corner-pill, corner-square, diagonal, diagonal-bars, diagonal-bars-2, diagonal-pills, ellipse, floating-squares, honeycomb, layer-blob, paint, rock-stack, square-stripes, triangles, wave.
- `aspectRatio`: "landscape" (default), "square", "portrait".
- `size`: "cover", "contain", or "custom" with `width` and `height` ("60%", "auto"); `position` ("right top") applies
  only when `size` is not the default stretch (source), and `horizontalOffset` and `verticalOffset` ("40px") also need a
  `position` (without one they change nothing, measured); set both offsets together.
- `blend` = "multiply". `transform`: the same four words as a pattern; the rotate exception above is pattern-only (in Divi's
  files every one of the 23 mask styles has distinct rotated artwork; source).

## Worked examples (each validates)
Gradient:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"gradient":{"enabled":"on","type":"linear","direction":"135deg","stops":[{"position":"0","color":"#1e3a8a"},{"position":"55","color":"#7c3aed"},{"position":"100","color":"#db2777"}]}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Plan, build, launch"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
Image under a translucent gradient (replace IMAGE_URL with the url you chose):
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"image":{"url":"IMAGE_URL","size":"cover","position":"left top"},"gradient":{"enabled":"on","overlaysImage":"on","stops":[{"position":"0","color":"rgba(10,10,40,0.85)"},{"position":"100","color":"rgba(10,10,40,0.35)"}]}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Words over a photo"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
Pattern:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#1b2a4a","pattern":{"enabled":"on","style":"diagonal-stripes","color":"rgba(255,255,255,0.08)"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Calm texture behind text"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
Mask:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#1b2a4a","mask":{"enabled":"on","style":"wave","color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"A wave edge"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
Text over a pattern, photo or conic gradient needs a contrast check; patterns and masks are decoration, never carry
meaning. A `tablet` or `phone` value replaces the desktop one at that width (rendered for the gradient `direction`;
other settings inherit the same way in Divi's code but were not rendered with overrides). Not measured, so do not rely on
them: hover and sticky states, blend modes beyond those named, units other than px and %, global colour references,
and combinations (a pattern with a mask, a mask with a gradient on one element).
MD;
    }

    /** Topic `dividers`: section top/bottom dividers, every one proven on the real Divi 5.15.1 (inner shadow is dead and says so). At most 10,000 bytes. */
    private static function dividersTopic(): string
    {
        return <<<'MD'
# Section dividers: wave, curve and slant edges between sections

Every shape and style listed here was rendered on the real Divi 5.15.1 and changed the page; a rule marked (source) is read
from Divi's own code and was not rendered, and the Limits section says what was not measured. Dividers exist on
`divi/section` only (a row or a column has none, source). A divider is a shape painted inside the section along its top or
bottom edge, over the section's own background.

All paths below sit under `module.advanced.dividers.top.desktop.value.` (top edge) or
`module.advanced.dividers.bottom.desktop.value.` (bottom edge), for example `module.advanced.dividers.bottom.desktop.value.style`.
Set the two edges independently. Never write CSS for a divider.

## Settings
- `style`: a name, default "none" ("none" set explicitly hides a divider). 26 proven (each rendered as a top divider and as a bottom divider): arrow, arrow2, arrow3, asymmetric, asymmetric2, asymmetric3, asymmetric4, clouds, clouds2, curve, curve2, graph, graph2, graph3, graph4, mountains, mountains2, ramp, ramp2, slant, slant2, triangle, wave, wave2, waves, waves2.
- `color`: a hex colour such as "#f8fafc". Without it the divider is filled with the plain background `color` of the
  NEIGHBOURING section: the previous section for a top divider, the next section for a bottom divider (rendered: a top divider
  took the colour of the section above, a bottom divider the colour of the section below). So the shape reads as the neighbour
  rising into this section. Give the neighbour a plain `module.decoration.background.desktop.value.color` that differs from this
  section's, or set `color` yourself (do this when the neighbour has only a gradient or image: its colour is not used, source).
  When both sections share one colour and `color` is unset, Divi paints the divider black (source).
- `height`: "100px" (default). "200px", "50px", "30px" and "50%" were rendered.
- `repeat`: "1x" (default) or "3x" tiles the shape horizontally; "3x" was rendered. `repeat` has no effect on 3 styles: clouds, clouds2, triangle (rendered: dead; Divi draws them as one stretched piece, source).
- `flip`: a list, ["horizontal"], ["vertical"] or ["horizontal","vertical"] (all three rendered on a bottom divider; on a top divider only ["vertical"] was rendered). Use it to point an
  asymmetric shape the other way; a vertical flip turns the shape upside down.
- `arrangement`: "below" (default) or "above". Rendered: "above" raises the divider's z-index from 1 to 10; Divi describes
  that as drawing it over rows and modules that overlap it (source). Leave the default unless content must tuck behind the shape.

## Using them well
- A divider is painted inside the section, so on a short section it crosses the content (observed on a screenshot, not in the
  evidence file: a default-padding section had its heading crossed by a 100px divider). Give that edge padding of at least the divider height, as in the examples.
- Dark section into a light neighbour (or the reverse) shows the shape best. Use one shape family on a page; pick the same
  `height` for every divider so the rhythm holds.
- No style overflowed a phone: all 26, top and bottom, rendered at 400 px with no horizontal scroll.
- A band with a divider on both edges, each filled with the page colour: see the topic `composition`.

## Responsive
`tablet` and `phone` override the desktop value, for example `module.advanced.dividers.bottom.tablet.value.height` and
`module.advanced.dividers.bottom.phone.value.style`. Rendered: a tablet `height` of "50px" changed 900 and 400 px but not
1280; a phone `height` of "30px" changed only 400 px; a phone `style` of "none" hid the divider only at 400 px. Other
settings (`color`, `repeat`, `flip`, `arrangement`) were not rendered with overrides.

## Row gutter (block layout rows only)
`module.advanced.gutter.desktop.value.width` ("1" or "4", both rendered) sets the gap between a row's columns, but only on a
row whose layout `module.decoration.layout.desktop.value.display` is "block": "1" removed the gap, "4" widened it. On the
default flex row it does nothing (rendered: dead); use the layout's `columnGap` there.

## Inner shadow
Do not use `module.advanced.innerShadow`: on Divi 5.15.1 the front end draws no inner shadow for it (rendered: dead); the Visual Builder preview was not checked.

## Worked examples (each validates)
A wave from a dark hero into a light section (the next section's colour fills the wave):
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#1e3a8a"}}},"spacing":{"desktop":{"value":{"padding":{"top":"120px","bottom":"180px"}}}}},"advanced":{"dividers":{"bottom":{"desktop":{"value":{"style":"wave","height":"120px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"A wave into the next section"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#f1f5f9"}}},"spacing":{"desktop":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"The next section sets the divider colour"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#0f172a"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
A section with a top curve (smaller on phones), a flipped bottom slant with an explicit colour, between two plain sections:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#fde68a"}}},"spacing":{"desktop":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Section above"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#1e293b"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#0f766e"}}},"spacing":{"desktop":{"value":{"padding":{"top":"140px","bottom":"140px"}}}}},"advanced":{"dividers":{"top":{"desktop":{"value":{"style":"curve","height":"90px"}},"phone":{"value":{"height":"40px"}}},"bottom":{"desktop":{"value":{"style":"slant","flip":["horizontal"],"color":"#f8fafc"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Top curve, flipped bottom slant, smaller on phones"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#f8fafc"}}},"spacing":{"desktop":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Section below"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#0f172a"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
Not measured, so do not rely on them: other height units, `repeat` values other than 3x, divider colours other than hex,
hover and sticky states, tablet and phone `color`, `repeat`, `flip` and `arrangement`, dividers on sections outside the page's own content,
the section gutter of a specialty section, and styles an add-on plugin registers.
MD;
    }

    /** Topic `motion`: entrance animation, scroll effects and sticky, every shape proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function motionTopic(): string
    {
        return <<<'MD'
# Motion: entrance animation, scroll effects, sticky

Every shape and value listed here was rendered on the real Divi 5.15.1 (the front end of a draft page preview) and changed the
page; a rule marked (source) is read
from Divi's own code and was not rendered, and the Limits section says what was not measured. Motion was proven by what Divi
writes and computes (classes and inline settings on the element, computed transform, opacity and position, the element's
rectangle after scrolling), not by watching the motion play. Never claim how a motion looks, and never write CSS or script for
motion. Hover effects and transitions are in the topic `hover-states`.

## Entrance animation
Paths below sit under `module.decoration.animation.{bp}.value.`. Divi writes the animation onto the element (the classes
`et_animated` and the style name, and inline animation properties) and removes them when it ends (observed on a test page, not in the evidence file); the element starts at opacity 0
unless `startingOpacity` says otherwise (rendered, with the element at the top of the page; that it starts when the element scrolls
into view was not measured).
- `style`: one of the styles below; with no style there is no animation
- `direction`: only on styles that take one
- `duration`: "2000ms" rendered; the default is 1000ms (source)
- `delay`: "8000ms" rendered; the default is 0ms (source)
- `repeat`: "loop" rendered; "once" is the default (source)
- `speedCurve`: a hyphenated name
- `startingOpacity`: "40%" rendered; the default is 0% (source)
- `intensity.slide`: "20%" rendered; the default is 50% (source)
- `intensity.zoom`: "20%" rendered
- `intensity.flip`: "20%" rendered
- `intensity.fold`: "20%" rendered
- `intensity.roll`: "20%" rendered
- `button.decoration.animation.{bp}.value.style`: the same on the button element path
Styles: 7 proven: bounce, fade, flip, fold, roll, slide, zoom.
Styles that take a direction: 6 proven: bounce, flip, fold, roll, slide, zoom. `direction` on fade did nothing (rendered: dead).
Directions: 5 proven: bottom, center, left, right, top. center is the default (source).
Speed curves: 5 proven: ease, ease-in, ease-in-out, ease-out, linear. The default is ease-in-out (source).
Hosts: 8 proven: blurb, button, column, group, heading, image, row, section. Only `style` "fade" was measured on the seven hosts
other than the heading; direction, duration and the rest were measured on the heading only.
Rules: an `intensity.slide` on fade did nothing (rendered: dead); fade and bounce have no intensity (source). A tablet `duration` of
"2500ms" changed the page at 900 px only; the phone width kept the desktop value. A phone `style` of "none" did not switch the
animation off at 400 px (rendered: dead), so do not rely on a per-device off switch.

## Scroll effects
Paths below sit under `module.decoration.scroll.{bp}.value.`. Divi's script applies them while the page scrolls. Proven on a
section only, by scrolling the section to a set position and comparing it with an identical section that had no effect.
- `verticalMotion.enable`: "on"; moves the section up or down (changed transform; its rectangle moved)
- `horizontalMotion.enable`: "on"; moves it sideways (changed transform; its rectangle moved)
- `fade.enable`: "on"; changed opacity
- `scaling.enable`: "on"; changed transform
- `rotating.enable`: "on"; changed transform
- `blur.enable`: "on"; changed the filter
- `verticalMotion.offset.start`: "20" rendered, and it moved the section about five times as far as the default "4" (source); bigger numbers
  move it further, so the default is the gentle choice; `offset.end` and `offset.mid` are the other two
- `verticalMotion.offset.end`: "-20" rendered (a strong value); the default is "-4" (source)
- `verticalMotion.viewport.top`: "60" rendered; it changed the render at 900 px only, so it depends on where the section sits
- `fade.offset.start`: "50" rendered; the default is "0" (source)
- `motionTriggerStart`: "top" rendered; "middle" is the default and "bottom" exists (source)
Effects: 6 proven: blur, fade, horizontalMotion, rotating, scaling, verticalMotion.
Rules: an `offset` without its effect switched on did nothing (rendered: dead); `viewport` has the same switch (source). Two effects together (fade and verticalMotion)
rendered. A phone `verticalMotion.enable` of "off" removed the effect at 400 px only. Defaults for every `viewport` and `offset`
are Divi's own (source).

## Sticky
Paths below sit under `module.decoration.sticky.{bp}.value.`. Proven on a section only. With `top`, a section scrolled past became
`position: fixed` at the top edge of the screen (400 px; at 900 and 1280 px it sat 32px down, below the preview's admin bar), and an
`offset.top` of 80px moved it 80px. With `bottom` and `topBottom` the section became `position: fixed` (computed) and `offset`
changed where it sat, but no edge placement was measured for them.
- `position`: top, bottom or topBottom
- `offset.top`: "80px" rendered, with position top; the default is 0px (source)
- `offset.bottom`: "60px" rendered, with position bottom
Positions: 4 proven: bottom, none, top, topBottom. none was rendered as a phone override ("none" on phones turned a desktop sticky
section off at 400 px only).
An offset without a `position` did nothing (rendered: dead). Use one sticky bar per page.

## Sticky group in a column and its limit
A Group inside a column (a side title that follows the reader), with `position` "top" and `offset.top` "20px", stayed on screen while its
section scrolled. With no limit it stayed there 600px past the end of the section too (rendered). The limit is
`module.decoration.sticky.{bp}.value.limit.bottom`. Rendered with a 1200px sibling after the group in its column, at 1280, 900 and 400 px:
- `limit.bottom`: "section", "row" or "column"
Rules (rendered, scrolling 150px at a time): with "section" the group stuck inside the section, then stopped with its bottom edge on
the section's bottom edge and scrolled away with it. One 1,000px jump instead leaves Divi's script mid-update, so a measurement or a
screenshot taken straight after a long jump can show the group in the wrong place. "body" and "none" are in Divi's own list (source) and were not
rendered; `limit.top` is the mirror for the positions bottom and topBottom and was not rendered. A section's own list has no section,
row or column (source), so a sticky section takes no such limit.

## Using motion well (strategy, not measured)
One entrance style per page, applied to the hero and the main cards; stagger neighbours by 100 to 200ms of `delay`. Keep
`scale` on hover small (103 to 108%) and transitions short (200 to 400ms). Scroll effects: one or two gentle ones.

## Worked examples (each validates)
A heading that slides in from the left, then a paragraph that fades in after it:
```
<!-- wp:divi/section {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"module":{"decoration":{"animation":{"desktop":{"value":{"style":"slide","direction":"left","duration":"800ms","intensity":{"slide":"20%"}}}}}},"title":{"innerContent":{"desktop":{"value":"Slides in from the left"}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/text {"module":{"decoration":{"animation":{"desktop":{"value":{"style":"fade","duration":"800ms","delay":"200ms"}}}}},"content":{"innerContent":{"desktop":{"value":"<p>Fades in 200ms later.</p>"}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
A bar that sticks to the top of the screen, and a section that fades as it scrolls:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#0f172a"}}},"sticky":{"desktop":{"value":{"position":"top","offset":{"top":"0px"}}}},"spacing":{"desktop":{"value":{"padding":{"top":"16px","bottom":"16px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Sticky bar"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- wp:divi/section {"module":{"decoration":{"scroll":{"desktop":{"value":{"fade":{"enable":"on"}}}},"spacing":{"desktop":{"value":{"padding":{"top":"200px","bottom":"200px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Fades while scrolling"}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
Not measured, so do not rely on them: `decoration.interactions` (not built, not taught), scroll effects and sticky on anything but
a section or a Group in a column, `limit.top`, offset surrounding and its own transition, other durations, delays and percentages, animation of
elements below the fold, and how any motion looks. Divi's animation, scroll and sticky code was searched for a reduced-motion
rule and none was found, so keep motion short and gentle; the plugin cannot add one.
MD;
    }

    /** Topic `hover-states`: hover values, transforms and transitions, every shape proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function hoverStatesTopic(): string
    {
        return <<<'MD'
# Hover states, transforms and transitions

Every shape and value listed here was rendered on the real Divi 5.15.1 (the front end of a draft page preview) and changed the
page; a rule marked (source) is read
from Divi's own code and was not rendered, and the Limits section says what was not measured. Motion was proven by what Divi
writes and computes (the computed colour, shadow, transform and filter with the pointer on the element, the transition timing),
not by watching the motion play. Never claim how a hover or a transition looks, and never write CSS or script for them. Entrance
animation, scroll effects and sticky are in the topic `motion`.

## Hover and transform
A hover value sits next to the normal one under the key `hover` instead of `value`: `module.decoration.background.{bp}.hover.color`.
Measured on the desktop hover state only, with the pointer on the element. A hover colour worked without a normal colour set,
but set both so the starting colour is yours. Each setting is named with the element it was proven on.
- `module.decoration.background.{bp}.hover.color`: hex, on a group
- `module.decoration.border.{bp}.hover.styles.all.color`: hex, on a group with a normal border
- `module.decoration.boxShadow.{bp}.hover.style`: a preset name; on a group and on the button module
- `module.decoration.boxShadow.{bp}.hover.vertical`: "20px", on a group, together with `style`
- `module.decoration.boxShadow.{bp}.hover.blur`: "40px", on a group, together with `style`
- `module.decoration.boxShadow.{bp}.hover.color`: "rgba(0,0,0,0.35)", on a group, together with `style`
- `module.decoration.transform.{bp}.hover.scale.x`: "105%" on a group, "108%" on the button module; set `scale.y` too
- `module.decoration.transform.{bp}.hover.scale.y`: the same value as `scale.x`
- `module.decoration.transform.{bp}.hover.translate.y`: "-8px", on a group (lifts a card)
- `module.decoration.transform.{bp}.hover.rotate.z`: "3deg", on a group
- `module.decoration.filters.{bp}.hover.brightness`: "120%", on a group
- `module.decoration.filters.{bp}.hover.saturate`: "160%", on a group
- `title.decoration.font.font.{bp}.hover.color`: text colour, on a heading
- `button.decoration.background.{bp}.hover.color`: hex, on a button
- `button.decoration.background.{bp}.hover.enableColor`: "on" next to the colour, as the builder saves it
- `button.decoration.font.font.{bp}.hover.color`: text colour, on a button
- `button.decoration.border.{bp}.hover.styles.all.color`: hex, on a button with a normal border
- `module.decoration.transform.{bp}.value.scale.x`: "90%" (not hover: a resting scale), on a group; `scale.y` likewise
- `module.decoration.transform.{bp}.value.scale.y`: "90%"
- `module.decoration.transform.{bp}.value.skew.x`: "6deg", on a group
Shadow presets: 3 proven: preset1, preset3, preset6. preset3 is outer; preset6 is inner (inset). preset2, preset4, preset5 and
preset7 exist (source) but were not rendered. On a button, a hover value under `button.decoration.boxShadow` or
`button.decoration.transform` did nothing (rendered: dead); use the module paths above.

## Transition
Paths below sit under `module.decoration.transition.{bp}.value.`. A transition is emitted only for a state the module has
(source; rendered: a duration on a group with no hover value did nothing), so give the element a hover value first.
- `duration`: "800ms" and "1500ms" rendered; the default is 300ms (source)
- `delay`: "200ms" rendered; the default is 0ms (source)
- `speedCurve`: a camelCase name
Speed curves: 5 proven: ease, easeIn, easeInOut, easeOut, linear. The default is ease (source); ease was shown against a linear base.
Responsive: with an explicit desktop `duration` of "300ms", a tablet `duration` of "1500ms" changed the computed duration at 900
and 400 px and not at 1280. Set the desktop `duration` whenever you set a tablet one: a tablet-only value also changed desktop in the
first run.

## Worked example (validates)
A card that lifts, gains a shadow and a border colour on hover, with a transition:
```
<!-- wp:divi/section {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"}}},"border":{"desktop":{"value":{"styles":{"all":{"width":"2px","color":"#e2e8f0"}}},"hover":{"styles":{"all":{"color":"#1e3a8a"}}}}},"boxShadow":{"desktop":{"hover":{"style":"preset3"}}},"transform":{"desktop":{"hover":{"translate":{"y":"-8px"}}}},"transition":{"desktop":{"value":{"duration":"300ms","speedCurve":"easeOut"}}},"spacing":{"desktop":{"value":{"padding":{"top":"32px","right":"32px","bottom":"32px","left":"32px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"A card that lifts on hover"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#0f172a"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/group -->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
## Limits
Not measured, so do not rely on them: hover on other elements, hover at tablet and phone, the other hover settings (spacing, font
size, text shadow, gradient or image background), transition on the sticky state, other durations, delays and percentages, and how
any hover looks. Touch screens have no hover: never put essential information behind one.
MD;
    }

    /** Topic `layout-advanced`: position, overlap, z-index, order, hiding per device and transforms, every shape proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function layoutAdvancedTopic(): string
    {
        return <<<'MD'
# Layout: overlap, stacking, order, hiding and transforms

Every shape listed here was rendered on the real Divi 5.15.1 (the front end of a draft page preview, Chromium at 1280, 900 and
400 px) and changed the page; the numbers marked rendered were measured, the worked examples use ordinary values. A rule marked
(source) is read from Divi's own code and was not rendered, and the Limits section says what was not measured. The Visual Builder
was not checked. Overlap was proven from measured rectangles. Sizing, flex, grid and
spacing are in the topic `layout-sizing`. An overlap that made a phone page wider than the screen is not taught as safe.

## Position
Paths below sit under `module.decoration.position.{bp}.value.`. A module already sits in a relative position context: an `offset`
alone moved it 24px down (rendered), while `mode` "relative" alone changed only the stylesheet (rendered: css-only).
- `mode`: "absolute" takes the module out of the flow, "fixed" pins it to the screen
- `origin.absolute`: the corner an absolute module is anchored to
- `origin.fixed`: "bottom right" rendered, for a fixed module
- `origin.relative`: "bottom left" rendered, for a relative module
- `offset.vertical`: "24px" rendered; a negative value hangs outside
- `offset.horizontal`: "24px" rendered
Absolute origins 8 proven: bottom center, bottom left, bottom right, center center, center left, center right, top center, top right. The
first word is the vertical side, the second the horizontal side (source). "top left" is the default and changed nothing (rendered:
dead). "center" is placed at 50% and shifted back by half its size (source); "center center" centred the 160px module (left 560
at 1280 px).
Hosts 6 proven: blurb, button, group, heading, image, text. Only the group was measured in every mode; the others with "absolute"
and `origin.absolute` "top right" (image: a computed position change only; its box was not laid out when measured).
Rules (rendered): `mode` "default" did nothing; the origin is read for the module's own mode, so `origin.absolute` on a relative
module did nothing. An absolute group left the flow: the card after it moved up and covered all 160 x 80 of it (12,800 square pixels) at all
three widths, with the card on top. A fixed group with `origin.fixed` "bottom right" and both offsets "24px" sat 24px from the
bottom right corner of a 900px-high screen at 1280, 900 and 400 px. `origin.relative` "bottom left" with `offset.vertical` "24px"
moved the module 48px higher than the same offset from the top.
Phone: `mode` "relative" on the phone only changed 400 px only; the offsets stay (the module sat 24px right and down of its natural
place).
Overflow: an absolute group hanging 60px outside the right edge of its column made a 400px page 20px wider than the screen (not
safe). The same group inside a section with `module.decoration.overflow.{bp}.value.x` "hidden" did not overflow (rendered); that
also computes the other axis as auto (see `layout-sizing`, Overflow), so it clips vertical bleed (sticky children
were not measured).

## Stacking and overlap
- `module.decoration.zIndex.{bp}.value`: a number (5 rendered; "5" worked too)
- `module.decoration.spacing.{bp}.value.margin.top`: "-40px" rendered; a negative value pulls the module over what is above it
- `module.decoration.spacing.{bp}.value.margin.left`: "-40px" rendered
- `module.decoration.spacing.{bp}.value.margin.right`: moved nothing of the module (below)
- `module.decoration.spacing.{bp}.value.margin.bottom`: moved nothing of the module
Rules (rendered): `zIndex` 5 put an absolute group above the card after it at 1280, 900 and 400 px; with 5 in the base, -1 put it
behind the card (a section with no background colour). In a section, and in a column, with a dark background colour, -1 left the
decor on top at its own centre at all three widths, so do not count on -1 to tuck a decor behind a coloured block. `zIndex` 5 on a group with no position mode also put it above a card pulled over it. Divi left 30px
between the two groups, so margin.top "-40px" overlapped the card above by only 10px; "-25%" (a share of the column width; a strong
value) moved the module 256, 180 and 80px at 1280, 900 and 400 px. `margin.left` "-40px" moved the module 40px left; `margin.right` and
`margin.bottom` "-40px" moved nothing of the module (they pull what follows; no neighbour was measured). A row with margin.top
"-80px" overlapped the row above by 20px; a section with "-80px" (a strong pull) covered 80px of the dark section before it and was on top. A phone `margin.top` of "0px" reset the pull at 400 px only; a
tablet value reached 900 and 400 px.

## Order
Paths below sit under `module.decoration.order.{bp}.value.`.
- `order`: "1" or the number 1; "-1" moves a module before the others
Rendered on two half-width columns: "1" on the first column moved it after the second at 1280, 900 and 400 px; "-1" on the second
moved it before the first. A bare `value` (value: 1) did nothing (dead). On a phone, where the row stacks, "-1" on the second
column put it above the first at 400 px only. A tablet "-1" also reached 400 px; a phone "0" cancelled it.

## Hide on a device
- `module.decoration.disabledOn.{bp}.value`: "on" hides the module (display none)
Devices 3 proven: desktop, phone, tablet. Each hid the module at its own width only: desktop at 1280, tablet at 900, phone at
400 px; tablet and phone together hid it at 900 and 400. "off" on desktop showed again a module that was "on" there; any other
value ("true") did nothing (dead). Hosts 9 proven: blurb, button, column, group, heading, image, row, section, text. Each was hidden on a phone.

## Transforms
Paths below sit under `module.decoration.transform.{bp}.value.`. A transform moves what is painted; its neighbours do not move.
- `translate.x`: "24px" rendered
- `translate.y`: "-24px" and "-25%" rendered (a share of the module's own height)
- `rotate.z`: "3deg" rendered
- `rotate.x`: "20deg" (squashes; no perspective)
- `rotate.y`: "20deg" (squashes; no perspective)
- `skew.y`: "6deg" rendered
- `scale.x`: "105%", set `scale.y` too
- `scale.y`: "105%"
- `origin.x`: "0%"; the other axis defaults to 50%
- `origin.y`: "0%"
Rules (rendered): `translate.y` "48px" covered the card below by 18px.
With `rotate.z` "12deg", `origin.x` and `origin.y` "0%" moved the rotated box from left 121, top 445 to left 111, top 461 at 1280 px.
`translate.x` "20%" on a module as wide as its column made a 400px page 24px wider than the screen (not safe; 54px at 900 and 77px at 1280 too). On the
phone only, translate.y "0px" with rotate.z "0deg" reset a lifted, tilted module at 400 px only. A resting `scale` and `skew.x`, and every hover transform, are in the topic `hover-states`.

## Worked examples (validate)
A light section that overlaps the dark section before it (-80px is a strong pull):
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#0f172a"}}},"spacing":{"desktop":{"value":{"padding":{"top":"120px","bottom":"120px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Hero headline"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#f1f5f9"}}},"spacing":{"desktop":{"value":{"margin":{"top":"-80px"},"padding":{"top":"80px","bottom":"80px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Card content"}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
A badge pinned to the top right corner of a card, hidden on phones:
```
<!-- wp:divi/section {"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#7c3aed"}}},"position":{"desktop":{"value":{"mode":"absolute","origin":{"absolute":"top right"},"offset":{"vertical":"24px","horizontal":"24px"}}}},"zIndex":{"desktop":{"value":5}},"disabledOn":{"phone":{"value":"on"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"New"}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/group -->
<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#e2e8f0"}}},"sizing":{"desktop":{"value":{"minHeight":"200px"}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Card"}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/group -->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
Not measured, so do not rely on them: position on rows, columns and sections, stacking against a sticky or fixed header, other
sizes of overlap, hiding at tabletWide, phoneWide and in between, widths other than 1280, 900 and
400 px, 3D rotation with a perspective, transforms on the tablet, and a two-column row: every position candidate sat in a
one-column row, so a badge in the corner of a card depends on the column being the positioned ancestor.
MD;
    }

    /** Topic `layout-sizing`: sizing, column widths, overflow, flex, grid and spacing, every shape proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function layoutSizingTopic(): string
    {
        return <<<'MD'
# Layout: sizing, overflow, flex, grid and spacing

Every shape listed here was rendered on the real Divi 5.15.1 (the front end of a draft page preview, Chromium at 1280, 900 and
400 px) and changed the page; the numbers marked rendered were measured, the worked examples use ordinary values. A rule marked
(source) is read from Divi's own code and was not rendered, and the Limits section says what was not measured. The Visual Builder
was not checked. Overlap, stacking, order, hiding and transforms are in the topic `layout-advanced`.

## Sizing
Paths below sit under `module.decoration.sizing.{bp}.value.` on a group; an image module keeps its sizing under
`module.advanced.sizing`, listed last.
- `minHeight`: "240px" rendered; "60vh" gave 540px on the 900px-high screen
- `height`: "240px"
- `maxHeight`: "120px" capped a group set to 300px
- `maxWidth`: "480px"
- `width`: "50%"
- `alignment`: "left", "center" or "right"
- `aspectRatio.width`: "16"
- `aspectRatio.height`: "9"
- `module.decoration.spacing.{bp}.value.margin.left`: "auto"
- `module.decoration.spacing.{bp}.value.margin.right`: "auto"
- `module.advanced.sizing.{bp}.value.maxWidth`: "320px" on an image module
- `module.advanced.sizing.{bp}.value.aspectRatio.width`: "1" on an image module
- `module.advanced.sizing.{bp}.value.aspectRatio.height`: "1"
- `image.decoration.fit.{bp}.value.objectFit`: "cover", set together with the image aspect ratio
Rules (rendered): "16" and "9" on a full-width group made it 576, 405 and 180px high at 1024, 720 and 320px wide; "1" and "1"
made a square of 320px (the group had `maxWidth` "320px"); "auto" on both did nothing (dead). `maxWidth` "480px" narrowed the group at 1280 and 900 px; at 400 px the
column was already narrower. `alignment` moved a group that had `width` "100%" and `maxWidth` "480px" (left 400 for "center",
672 for "right", against 128 at 1280 px); `alignment` "center" with no width setting did nothing (dead). Margin "auto" on both sides
without `width` "100%" shrank a `maxWidth` "480px" group to its content (51px wide at 1280, 900 and 400 px), so set both; with
`width` "100%" the margin pair centred it the same way. A phone `width`
"100%" over a desktop "50%" changed 400 px only. `minWidth` "600px" made a 400px page 240px wider than the screen (not safe).

## Column widths
- `module.decoration.sizing.{bp}.value.flexType`: "N_24" on a column, N of a 24-unit grid
flexType 5 proven: 10_24, 16_24, 18_24, 20_24, 4_24. Each was the first column of a two-column row with the other column at 24
minus N (the row and columns use the shapes Divi writes for equal columns that stack on a phone). The column got about N/24 of
the row minus its share of the column gap: at 1280 px (row 1024px) 4_24 gave 124px, 10_24 394, 16_24 664, 18_24 754 and 20_24
844px; at 900 px 87, 277, 467, 530 and 593px; at 400 px the phone value "24_24" kept the columns stacked.

## Overflow
Paths below sit under `module.decoration.overflow.{bp}.value.`.
- `x`: a value from the list
- `y`: a value from the list
Values 3 proven: auto, hidden, scroll. "visible" is the default and changed only the stylesheet (rendered: css-only); "clip" did
nothing (dead). Setting one axis changed both computed axes (a CSS rule). A border radius of 16px on all four corners made the
group `overflow: hidden` (computed); `x` and `y` "visible" with that radius gave `overflow: visible`; `x` "auto" with that
radius left `overflow-y` hidden. Only the computed values were measured, not how content is cut.

## Flex and grid layout
Paths below sit under `module.decoration.layout.{bp}.value.` and are measured on a group holding three or six headings.
- `display`: "block" or "grid"
- `flexDirection`: see below
- `justifyContent`: see below
- `alignItems`: see below
- `flexWrap`: see below
- `columnGap`: "40px"
- `rowGap`: "40px"
- `alignContent`: "center", with a wrapping group
- `gridColumnCount`: "3"
- `gridColumnWidths`: "equalMinimum"
- `gridColumnMinWidth`: "200px", with "equalMinimum"
- `module.decoration.sizing.{bp}.value.alignSelf`: "flex-end", on a child of a flex group
- `module.decoration.sizing.{bp}.value.gridColumnSpan`: "2", on a child of a grid group
Directions: 4 proven: column, column-reverse, row, row-reverse.
Justify: 6 proven: center, flex-end, flex-start, space-around, space-between, space-evenly.
Align items: 5 proven: baseline, center, flex-end, flex-start, stretch.
Wrap: 2 proven: wrap, wrap-reverse. "nowrap" changed only the stylesheet (rendered: css-only).
Rules (rendered): a plain group is already `display: flex`, so `display` "flex" on it did nothing (dead) and "row" changed its
direction; the flex settings changed the computed property, their
effect on the children was not measured. `justifyContent` on a group whose `display` is "block" did nothing (dead), and
`alignContent` on a non-wrapping group did nothing (dead). A phone `flexDirection` "column" changed 400 px only.
`sizing.size` takes a list: ["flexGrow"] widened a 120px child to 874, 570 and 170px at 1280, 900 and 400 px in a flex row;
`alignSelf` "flex-end" dropped a stretched 200px child to the bottom of the row and did nothing when the parent was "block".
["flexGrow"] alone also writes flex-shrink 0: text beside an icon in a nowrap row made a 400px page 600px too wide (not safe);
["flexGrow","flexShrink"] let it wrap (rendered). Use both there.
Grid: `gridColumnCount` "3" made six headings 110px high instead of 390px; a tablet "2" changed 900 and 400 px (180px high); a
phone "1" changed 400 px only (110 to 390px); "equalMinimum" with "200px" gave 4 columns at 1280 px and 3 at 900 px (two rows of six items) and changed nothing at 400 px, where one
column already fits;
`gridColumnMinWidth` without "equalMinimum" did nothing (dead); `gridColumnSpan` "2" widened a child from 321 to 673px, and did
nothing when the parent was not a grid (dead).

## Spacing
Paths below sit under `module.decoration.spacing.{bp}.value.`.
- `padding.top`: "40px"
- `padding.bottom`: "40px"
- `padding.left`: "5%" rendered (a share of the width)
- `padding.right`: "5%"
Rules (rendered): `padding.syncVertical` "on" is only a builder toggle: alone it did nothing (dead); with only `padding.top` "40px" the group grew by 40px, not 80, so
set every side you want. Section padding per device, 120px, 80px and 48px at top and bottom (desktop, tablet, phone), gave
sections of 280, 200 and 136px (content 40px). One fluid value, `clamp(48px, 8vw, 120px)` on top and bottom, gave 245, 184 and 136px.

## Worked examples (validate)
A wide and a narrow column that stack on phones, in a section whose padding follows the device:
```
<!-- wp:divi/section {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"120px","bottom":"120px"}}},"tablet":{"value":{"padding":{"top":"80px","bottom":"80px"}}},"phone":{"value":{"padding":{"top":"48px","bottom":"48px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"1_2,1_2"}},"flexColumnStructure":{"desktop":{"value":"equal-columns_2"}}},"decoration":{"layout":{"desktop":{"value":{"flexWrap":"nowrap"}},"phone":{"value":{"flexWrap":"wrap"}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"1_2"}}},"decoration":{"sizing":{"desktop":{"value":{"flexType":"16_24"}},"phone":{"value":{"flexType":"24_24"}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Wide column"}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"1_2"}}},"decoration":{"sizing":{"desktop":{"value":{"flexType":"8_24"}},"phone":{"value":{"flexType":"24_24"}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Narrow column"}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
Three cards in a grid that is 3 columns, then 2, then 1:
```
<!-- wp:divi/section {"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/group {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"grid","gridColumnCount":"3","columnGap":"32px","rowGap":"32px"}},"tablet":{"value":{"gridColumnCount":"2"}},"phone":{"value":{"gridColumnCount":"1"}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#e2e8f0"}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Card one"}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/group -->
<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#e2e8f0"}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Card two"}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/group -->
<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#e2e8f0"}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Card three"}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/group -->
<!-- /wp:divi/group -->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
Not measured, so do not rely on them: flex and grid settings on rows, columns and sections (the row `flexWrap` of example 1 is only
the base of the measured column-width candidates), flexType values other than the five listed, rows of three or more columns, the
other grid options (fixed and manual column widths, row heights, auto flow, density), custom flex grow and shrink numbers, how
overflow cuts content, spacing in calc() or vw other than the one clamp() shown, and the Visual Builder.
MD;
    }

    /** Topic `typography`: heading and body fonts, lines, shadows and text effects, every shape proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function typographyTopic(): string
    {
        return self::typographyTopicText() . self::typographyLanguageSentence();
    }

    /**
     * The `typography` topic's last section, "Design language type", generated from LanguageAdapters::APPLIED['type'],
     * TYPE_HEADING_LEVELS, TYPE_PATHS and TYPE_PROVEN (the cascade wording only while the carrier's cascade is proven): ''
     * when no block takes the language type. It names no number: the numbers are each language's Type line. At most 450 B.
     *
     * @param array<string,list<string>>|null $applied defaults to LanguageAdapters::APPLIED['type']
     * @param array<string,bool>|null         $proven  defaults to LanguageAdapters::TYPE_PROVEN
     */
    public static function typographyLanguageSentence( ?array $applied = null, ?array $proven = null ): string
    {
        $applied ??= LanguageAdapters::APPLIED['type'];
        $proven  ??= LanguageAdapters::TYPE_PROVEN;
        $byRole   = [];
        $where    = [];
        $cascade  = true;
        foreach ( $applied as $block => $roles ) {
            $path = LanguageAdapters::TYPE_PATHS[ $block ] ?? null;
            if ( null === $path || [] === $roles ) {
                continue;
            }
            $heading = 'divi/heading' === $block;
            foreach ( $roles as $role ) {
                $byRole[ $role ][] = $heading
                    ? implode( ' and ', LanguageAdapters::TYPE_HEADING_LEVELS[ $role ] ?? [] ) . ( 'display' === ( LanguageAdapters::TYPE_FONT[ $role ] ?? 'display' ) ? '' : ' (' . LanguageAdapters::TYPE_FONT[ $role ] . ' font)' )
                    : 'labels';
            }
            $proved   = true === ( $proven[ ( $heading ? 'heading' : 'text' ) . '-cascade' ] ?? false );
            $cascade  = $cascade && $proved;
            $where[]  = '`' . $path . ( $proved ? '.desktop.value`' : '.{bp}.value` (desktop, tablet, phone)' ) . ' on a ' . ( $heading ? 'heading' : 'text' );
        }
        if ( [] === $byRole ) {
            return '';
        }
        $covers = [];
        foreach ( LanguageAdapters::TYPE_ROLES as $role ) {
            if ( isset( $byRole[ $role ] ) ) {
                $covers[] = '`' . $role . '` ' . implode( ' and ', $byRole[ $role ] );
            }
        }

        return "\n\n## Design language type\n\nThe language sets headline type (Type line, topic=design-language:<id>): " . implode( ', ', $covers )
            . '. Set its weight and `letterSpacing` (`capitalization` `uppercase` only where the language names capital letters) at '
            . implode( ', ', $where ) . ( $cascade ? '; it reaches 900 and 400 px (rendered).' : '.' );
    }

    private static function typographyTopicText(): string
    {
        return <<<'MD'
# Typography: weight, case, spacing, shadows and text effects

Every shape listed here was rendered on the real Divi 5.15.1 (the front end of a draft page preview, Chromium at 1280, 900 and
400 px) and changed the computed styles of the page; the worked example uses ordinary values. A rule marked (source) is read from
Divi's own code and was not rendered, and the Limits section says what was not measured. The Visual Builder was not checked.
Images are in the topic `imagery`; buttons, icons, blurbs, counters and dividers are in `controls`.

## Heading font
Paths below sit under `title.decoration.font.font.{bp}.value.` (a heading module; a blurb title took `weight` only, see `controls`;
other modules name their own element).
- `weight`
- `style`
- `capitalization`
- `letterSpacing`
- `lineHeight`
- `family`
- `textWrap`
- `hyphens`
Weights 4 proven: 300, 600, 800, 900. A heading's own weight is 500.
Style 4 proven: italic, overline, strikethrough, underline. It is a list, for example ["italic"].
Capitalization 5 proven: allSmallCaps, capitalize, lowercase, smallCaps, uppercase.
Families 4 proven: Barlow Condensed, Inter, Playfair Display, Source Sans 3. Each is in Divi's own Google Fonts list
(google-fonts.json in the Divi theme); the computed font-family named it with a fallback stack. Whether the font file loaded
was not checked.
Also rendered: `letterSpacing` "-1px", "2px" and "0.1em"; `lineHeight` "1.6em" ("1em" is a heading's own and changed only the
stylesheet); `textWrap` "balance" and "pretty"; `hyphens` "on".
Upper case is `capitalization` "uppercase". A `style` list holding "uppercase", the shape the default guide shows, changed nothing
on a heading or on a button label (rendered: dead). Use `capitalization`.
Responsive: `letterSpacing` "4px" on desktop with "0px" on the phone changed 400 px only; "0px" on the tablet changed 900 and 400 px.

## Underline and lines
Paths below sit under `title.decoration.font.font.{bp}.value.` (as above). `style` ["underline"] (or overline, strikethrough) draws the line, and these restyle it (all rendered with ["underline"]):
- `lineColor`
- `lineStyle`
- `lineThickness`
- `underlineOffset`
Line styles 4 proven: dashed, dotted, double, wavy. `lineStyle` with no line in `style` changed nothing (rendered: dead); colour,
thickness and offset without a line have nothing to draw on (source).

## Text module headings
Paths below sit under `content.decoration.headingFont.h2.font.{bp}.value.` (h1 to h6; the content must hold that tag).
Levels 6 proven: h1, h2, h3, h4, h5, h6. `weight` "300" changed the computed weight of that level's tag only.
Keys on h2 6 proven: capitalization, family, letterSpacing, lineHeight, style, weight. On h3: capitalization.

## Text module body
Paths below sit under `content.decoration.bodyFont.body.font.{bp}.value.`.
- `weight`
- `lineHeight`
- `letterSpacing`
- `style`
- `capitalization`
- `family`
- `textWrap`
- `hyphens`
- `columnCount`
- `columnGap`
Rendered: weight "300" and "600", lineHeight "1.8em", letterSpacing "0.5px", style ["italic"], capitalization "uppercase",
family "Inter", textWrap "pretty", hyphens "on". Columns: `columnCount` "2" with `columnGap` "48px" (set together) printed two
columns at 1280, 900 and 400 px; a phone `columnCount` "1" changed 400 px only. `columnGap` alone was not rendered.

## Text shadow
Paths below sit under `title.decoration.font.textShadow.{bp}.value.` (a heading). The body of a text module takes
`content.decoration.bodyFont.body.textShadow.{bp}.value.style` the same way ("preset1" rendered).
- `style`
- `color`
- `horizontal`
- `vertical`
- `blur`
Presets 5 proven: preset1, preset2, preset3, preset4, preset5. They are em values, so they scale with the text (source).
Overrides sit on top of a preset: `color` "#dc2626", `horizontal` "0.1em", `vertical` "0.2em" and `blur` "0.4em" were rendered over
preset1. `color`, `vertical` and `blur` set without any `style` changed nothing (rendered: dead; `horizontal` was not tried without
a `style`). `style` "none" removed a preset2 shadow.
Phone: a phone `style` "none" does not remove a desktop shadow (rendered: dead, the desktop rule still applies). Set `horizontal`,
`vertical` and `blur` to "0em" on the phone instead (rendered: 400 px only).

## Text effects
Paths below sit under `title.decoration.font.textEffects.{bp}.value.` (a heading).
- `strokeWidth`
- `strokeColor`
- `strokePosition`
- `fillType`
- `gradient.type`
- `gradient.direction`
- `gradient.stops`
- `imageFill.url`
Fill types 3 proven: gradient, image, transparent.
`strokeWidth` "2px" with `strokeColor` "#dc2626" (set together) set the text stroke width and colour. `strokePosition` "fill-stroke"
changed the paint order from stroke to fill; "stroke-fill" is what a stroke wider than 1px already has (source: TextEffects.php
278-286; rendered: dead). `fillType` "transparent" alone changed the computed text fill colour to transparent, so the text may be
invisible unless a stroke is set; for outline text set `fillType`, `strokeWidth` and `strokeColor` together (rendered).
`fillType` "gradient" with `gradient.type` "linear", `gradient.direction` "90deg" and two `gradient.stops` (position "0" and "100"
with a colour each) set a gradient background clipped to the text; the same gradient without `fillType` changed nothing (rendered:
dead). `fillType` "image" with `imageFill.url` (an image address, see get_image_guide) set an image background clipped to the text.
Gradient, transparent and image text can be unreadable: check contrast against the lightest part of the fill.

## Worked example (validates)
```
<!-- wp:divi/section {"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Our approach"}},"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"h4","size":"14px","weight":"600","capitalization":"uppercase","letterSpacing":"2px","color":"#dc2626"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Careful work, clearly explained"}},"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"h2","size":"44px","weight":"800","lineHeight":"1.2em","family":"Playfair Display","color":"#0f172a","textWrap":"balance"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>We plan, build and test every page.</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"size":"18px","lineHeight":"1.8em","color":"#334155"}}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
- Only a heading and the body of a text module were measured for the keys above (a blurb title also took `weight`); the fonts of
  other modules were not.
- Variable-font axes, drop caps, vertical text, the column rule and whether a family has a face for a weight were not measured.
- Text effects: one linear gradient with two stops, one image address and the stroke were measured, on a heading only.
- Tablet and phone overrides were rendered only for `letterSpacing`, the zero text shadow and `columnCount`; every other key was measured
  with desktop values only.
- Computed styles were compared; legibility and contrast were not judged.
MD;
    }

    /** Topic `imagery`: image filters, fit, corners, borders, shadows and hover overlay, every shape proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function imageryTopic(): string
    {
        return <<<'MD'
# Imagery: filters, fit, corners, borders, shadows and hover overlay

Every shape listed here was rendered on the real Divi 5.15.1 (the front end of a draft page preview, Chromium at 1280, 900 and
400 px) on an image module and changed the computed styles of the page; the worked examples use ordinary values. A rule marked
(source) is read from Divi's own code and was not rendered, and the Limits section says what was not measured. The Visual Builder
was not checked. Which picture to use is in get_image_guide; fonts are in the topic `typography`.

## Filters
Paths below sit under `module.decoration.filters.{bp}.value.`.
- `brightness`
- `contrast`
- `saturate`
- `sepia`
- `invert`
- `hueRotate`
- `blur`
- `opacity`
- `blendMode`
Rendered: `brightness` "130%", `contrast` "130%", `saturate` "0%" (computed saturate(0)) and "180%", `sepia` "100%", `invert` "100%", `hueRotate`
"90deg", `blur` "4px", `opacity` "60%", `blendMode` "multiply". Divi reads no grayscale key (source: Filters.php:117-135), so use `saturate` "0%". A value of "0"
without a unit is skipped by Divi (source). Filters that blur what is behind an element are not taught.

## Fit
Paths below sit under `image.decoration.fit.{bp}.value.`. They matter when the image box is not the picture's own shape; they
were rendered in a 1:1 box (`module.advanced.sizing.{bp}.value.aspectRatio.width` and `.height` "1", see `layout-sizing`).
- `objectFit`
- `objectPosition`
Fit values 4 proven: contain, cover, none, scale-down. "fill" is the browser default and changed only the stylesheet (rendered:
css-only). `objectPosition` "left top" and "right bottom" were rendered with `objectFit` "cover".

## Corners
Paths below sit under `image.decoration.border.{bp}.value.radius.`.
- `topLeft`
- `topRight`
- `bottomRight`
- `bottomLeft`
Rendered: "16px" on all four corners, and "50%" on all four corners of a square image. Set every corner you want
rounded: `topLeft` alone rounded that corner only, and `sync` "on" with `topLeft` alone did the same (rendered), so `sync` does not
copy a value to the other corners. A radius also set `overflow` hidden on the image wrapper (rendered).

## Border
Paths below sit under `image.decoration.border.{bp}.value.styles.`.
- `all.width`
- `all.color`
- `all.style`
- `bottom.width`
- `bottom.color`
- `bottom.style`
Rendered: width "4px", colour "#1e3a8a" and style "solid" set together on all sides, and the same three on the bottom side only.
`all.width` alone printed a border in a dark grey (computed rgb(51, 51, 51)), so give the colour too. Styles 3 proven: dashed, dotted, double.
They were rendered with width and colour in the base.

## Shadow
Paths below sit under `image.decoration.boxShadow.{bp}.value.`. The shadow was computed on the image wrapper, not on the picture.
- `style`
- `color`
- `blur`
- `vertical`
- `spread`
Presets 5 proven: preset1, preset2, preset3, preset4, preset5. Preset6 and preset7 are inner shadows (source); Divi adds an extra
element for an inner shadow on an image module (source: ElementClassnames.php 96-100), and the probe saw the page structure change,
not a shadow, so they are not proven on an image.
`color` "rgba(30,58,138,0.35)", `blur` "40px", `vertical` "24px" and `spread` "8px" were rendered over preset1; "40px" blur is a
strong value. `horizontal`, `vertical`, `blur` and `color` set without a `style` changed nothing (rendered: dead; `spread` was not
tried without one), so always set `style`.

## Hover overlay
Paths below sit under `image.advanced.overlay.{bp}.value.`. The overlay needs a link on the image (`image.innerContent.{bp}.value.linkUrl`
was "#") or the lightbox (source); `use` "on" with no link changed nothing (rendered: dead). Rendered with the pointer on the image.
- `use`
- `backgroundColor`
`backgroundColor` "rgba(30,58,138,0.6)" changed the overlay's computed background colour.

## Overlay icon
Paths below sit under `image.advanced.overlayIcon.{bp}.value.` (with the overlay on and a link, as above).
- `iconColor`
- `hoverIcon.unicode`
- `hoverIcon.type`
- `hoverIcon.weight`
`iconColor` "#ffffff" changed the computed colour of the icon (`::before`). `hoverIcon.unicode`, `.type` and `.weight` (set together: "&#xf002;", "fa", "900")
changed the `::before` content, font family and weight.

## Worked examples (each validates)
Rounded picture with a soft shadow (replace IMAGE_URL with the url you chose):
```
<!-- wp:divi/section {"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"IMAGE_URL","alt":"Our team at work"}}},"decoration":{"border":{"desktop":{"value":{"radius":{"topLeft":"16px","topRight":"16px","bottomRight":"16px","bottomLeft":"16px"}}}},"boxShadow":{"desktop":{"value":{"style":"preset1"}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
Square crop with a hover overlay:
```
<!-- wp:divi/section {"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/image {"module":{"advanced":{"sizing":{"desktop":{"value":{"aspectRatio":{"width":"1","height":"1"}}}}}},"image":{"innerContent":{"desktop":{"value":{"src":"IMAGE_URL","alt":"A finished project","linkUrl":"#"}}},"decoration":{"fit":{"desktop":{"value":{"objectFit":"cover"}}}},"advanced":{"overlay":{"desktop":{"value":{"use":"on","backgroundColor":"rgba(30,58,138,0.6)"}}},"overlayIcon":{"desktop":{"value":{"iconColor":"#ffffff"}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
- Only the image module was measured: other modules' filters, borders and shadows were not, and the filter hover state is in `hover-states`.
- Border sides other than all and bottom, per-side colours, the lightbox, other icon glyphs and the overlay without hover were not measured.
- Desktop values only: no tablet or phone override was rendered for any key here.
- Computed styles were compared; how a shadow, filter or overlay looks on a particular picture was not judged.
- Check text over a picture or an overlay for contrast.
MD;
    }

    /** Topic `controls`: button, blurb, icon, number counter and divider styling, every shape proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function controlsTopic(): string
    {
        return self::controlsTopicText() . self::controlsKitSentence() . self::objectValuesParagraph();
    }

    /**
     * The `controls` topic's one style-kit sentence, generated from KitAdapters::APPLIED: '' while no adapter styles a divider
     * or an icon. It names only the roles those two blocks answer.
     *
     * @param array<string,list<string>>|null $applied defaults to KitAdapters::APPLIED
     * @param array<string,bool>|null          $ruleProven defaults to KitAdapters::RULE_PROVEN (the divider half needs `divider-line`)
     * @param array<string,bool>|null          $chipProven defaults to KitAdapters::CHIP_PROVEN (the icon half needs `icon-radius`)
     */
    public static function controlsKitSentence( ?array $applied = null, ?array $ruleProven = null, ?array $chipProven = null ): string
    {
        $applied     ??= KitAdapters::APPLIED;
        $ruleProven  ??= KitAdapters::RULE_PROVEN;
        $chipProven  ??= KitAdapters::CHIP_PROVEN;
        $ticks = static fn ( array $names ): string => implode( ' or ', array_map( static fn ( string $r ): string => '`' . $r . '`', $names ) );
        $parts = [];
        // Each half is said only while the decision it relies on is proven (the divider's line, the icon's corners).
        if ( isset( $applied['divi/divider'] ) && true === ( $ruleProven['divider-line'] ?? false ) ) {
            $parts[] = 'with a ' . $ticks( $applied['divi/divider'] ) . ' marker a divider takes the kit\'s width and style';
        }
        if ( isset( $applied['divi/icon'] ) && true === ( $chipProven['icon-radius'] ?? false ) ) {
            $parts[] = 'with a ' . $ticks( $applied['divi/icon'] ) . ' marker an icon takes the kit\'s corners';
        }
        if ( [] === $parts ) {
            return '';
        }

        return "\n\n## Style kits\n\n" . ucfirst( implode( ', ', $parts ) ) . '; colours come from the design language (topic=kit-rules).';
    }

    /**
     * The shapes of the object-valued content keys the validator knows (SchemaRules::contentKeyRules(), `mustBeObject`) for
     * which a plain string is a render fatal: each one is worth a one-line example so a hand-written page gets it right.
     * The examples are the shapes of the shipped recipes (a test compares them with the real testimonial and contact form).
     */
    /** Modules the validator guards but the guides never teach (video is not taught: StyleGuideTypographyTopicTest), so the paragraph does not name them. */
    public const OBJECT_VALUES_NOT_TAUGHT = [ 'divi/video' ];

    public const OBJECT_SHAPES = [
        'divi/testimonial'  => [ 'company' => '{"text": "Company"}', 'portrait' => '{"url": "...", "alt": "..."}' ],
        'divi/contact-form' => [ 'button' => '{"text": "Send"}' ],
    ];

    /**
     * "## Values that must be objects": generated from the validator's own table, so it can never disagree with what the
     * plugin refuses. A string where Divi needs an object makes Divi answer HTTP 500 for the whole page (rendered on Divi
     * 5.15.1), which the save gate reports as SCALAR_WHERE_OBJECT. Names every module and key, and gives the shape where one
     * is known (OBJECT_SHAPES). '' when the table has no object-valued key.
     *
     * @param array<string,list<array{0:string,1:bool,2:bool}>>|null $rules defaults to SchemaRules::contentKeyRules()
     */
    public static function objectValuesParagraph( ?array $rules = null ): string
    {
        $rules ??= ( new \Divi5Validator\SchemaRules() )->contentKeyRules();
        $items = [];
        foreach ( $rules as $module => $keys ) {
            if ( in_array( $module, self::OBJECT_VALUES_NOT_TAUGHT, true ) ) {
                continue;
            }
            $names = [];
            foreach ( $keys as [ $key, , $mustBeObject ] ) {
                if ( true === $mustBeObject ) {
                    $shape   = self::OBJECT_SHAPES[ $module ][ $key ] ?? null;
                    $names[] = '`' . $key . '`' . ( null === $shape ? '' : ' ' . $shape );
                }
            }
            if ( [] !== $names ) {
                $items[] = '`' . $module . '` ' . implode( ', ', $names );
            }
        }
        if ( [] === $items ) {
            return '';
        }

        return "\n\n## Values that must be objects\n\nOn these keys `innerContent.desktop.value` is an object, never a plain string: a string makes Divi answer HTTP 500 for the whole page and the save is refused (SCALAR_WHERE_OBJECT): "
            . implode( '; ', $items ) . '. A testimonial\'s `author` and `jobTitle` are plain strings and its `content` is HTML.';
    }

    private static function controlsTopicText(): string
    {
        return <<<'MD'
# Controls: buttons, blurbs, icons, counters and dividers

Every shape listed here was rendered on the real Divi 5.15.1 (the front end of a draft page preview, Chromium at 1280, 900 and
400 px) and changed the computed styles of the page (the pseudo-elements `::before` and `::after` were compared where Divi draws
an icon or a line there; the rendered text where a character is printed); the worked example uses ordinary values. A rule marked
(source) is read from Divi's own code and was not rendered, and the Limits section says what was not measured. The Visual Builder
was not checked. Fonts are in the topic `typography`, pictures in `imagery`.

## Button icon
Paths below sit under `button.decoration.button.{bp}.value.`. A button already has an icon (rendered: placement and colour alone
restyled the `::before` and `::after` of a button with no icon settings, and `icon.onHover` "off" changed the `::after` opacity from
0 to 1, so by default the icon appears on hover), so you change the icon, you do not switch it on.
- `icon.settings.unicode`
- `icon.settings.type`
- `icon.settings.weight`
- `icon.placement`
- `icon.onHover`
- `icon.color`
- `icon.enable`
Rendered: the glyph "&#xf054;" with type "fa" and weight "900" (set together); `icon.placement` "left"; `icon.onHover` "off"
(opacity 1 instead of 0: always visible, not only on hover); `icon.color` "#f59e0b"; `icon.enable` "off" changed the `::after` display from block to none. `icon.enable` "on" is the
default and changed nothing (rendered: dead). An `enable` written beside `icon` (`value.enable`) changed nothing, on or off (rendered:
dead), so write it inside `icon`.

## Button label, box and position
- `button.decoration.font.font.{bp}.value.{weight,letterSpacing,capitalization,family}`
- `module.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}`
- `button.decoration.border.{bp}.value.radius.{topLeft,topRight,bottomRight,bottomLeft}`
- `button.decoration.border.{bp}.value.styles.all.{width,color,style}`
- `module.decoration.boxShadow.{bp}.value.style`
- `module.advanced.alignment.{bp}.value`
Rendered: weight "800", letterSpacing "2px", capitalization "uppercase", family "Inter"; padding "16px" top and bottom with "40px"
left and right (the button's padding is the module's spacing); radius "999px" (a pill: a strong value) on all four corners; border "2px", "#1e3a8a", "solid";
box shadow "preset1"; alignment "center" and "right". A label `style` list with "uppercase" in it changed nothing (rendered: dead; use
`capitalization`). Padding and box shadow written on the `button` element (`button.decoration.spacing`, `button.decoration.boxShadow`)
changed nothing (rendered: dead); write them on the module.

## Blurb icon
- `imageIcon.innerContent.{bp}.value.{useIcon,icon.unicode,icon.type,icon.weight}`
- `imageIcon.advanced.color.{bp}.value`
- `imageIcon.advanced.placement.{bp}.value`
- `imageIcon.decoration.sizing.{bp}.value.iconFontSize`
- `imageIcon.decoration.background.{bp}.value.color`
- `imageIcon.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}`
Rendered with an icon on (`useIcon` "on" with the glyph "&#xf054;", "fa", "900", set together): colour "#dc2626"; placement "left" and
"right" ("top" is the default and changed nothing (rendered: dead)); `iconFontSize` "64px"; background "#e0e7ff"; padding "16px" on all
four sides. The icon size is `iconFontSize`: the sizing `width` of the icon changed nothing (rendered: dead). Of the
font keys of `typography` only the title `weight` and the body `lineHeight` were rendered on a blurb; the others are not measured there.

## Icon module
Paths below sit under `icon.advanced.`.
- `color.{bp}.value`
- `size.{bp}.value`
- `align.{bp}.value`
Rendered: colour "#dc2626", size "64px", align "left" and "right"; align "center" is the default and changed nothing (rendered: dead).

## Number counter
- `number.decoration.font.font.{bp}.value.{size,weight,color,family}`
- `title.decoration.font.font.{bp}.value.{capitalization,letterSpacing,color}`
- `number.advanced.enablePercentSign.{bp}.value`
- `module.advanced.text.text.{bp}.value.orientation`
Rendered: number size "48px" (its own size is 72px), weight "800", colour "#dc2626", family "Playfair Display"; title capitalization
"uppercase", letterSpacing "2px", colour "#64748b". `enablePercentSign` "off" removed the % from the printed text; "on" is the default and
changed nothing (rendered: dead). `orientation` "left" aligned the module's text left; "center" is the default and changed nothing (rendered: dead).

## Divider line
Paths below sit under `divider.advanced.line.{bp}.value.` (the line is drawn on the module's `::before`).
- `color`
- `weight`
- `style`
- `position`
Styles 3 proven: dashed, dotted, double. Positions 2 proven: bottom, center. Rendered: colour "#dc2626", weight "6px". Position "top" is
the default and changed nothing (rendered: dead). The `show` switch could not be measured (source only).

## Circle counter
- `circle.advanced.color.{bp}.value`
- `circle.advanced.background.{bp}.value.color`
- `circle.advanced.background.{bp}.value.opacity`
- `number.advanced.percentSign.{bp}.value`
- `number.decoration.font.font.{bp}.value.color`
- `title.decoration.font.font.{bp}.value.color`
Divi draws the ring on a canvas, so its pixel colours were compared. Rendered: `circle.advanced.color` recoloured the bar (by
default the site's primary colour, source); a track `color` drew the unfilled ring in that colour at Divi's faint default opacity
0.1, and `opacity` "1" with that colour made it solid. Set the two together: opacity with no track colour changed nothing in a
first run (the candidate note in the evidence file). `percentSign` "off" printed "50" instead of "50%"; the number and title took the
set colours. Naming trap: the number counter's switch is `enablePercentSign`, the circle counter's `percentSign` (source).
Every counter prints the figure you give it: recipes hold demo values; use a figure the owner can prove, or remove the counter.

## Worked example (validates)
```
<!-- wp:divi/section {"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/number-counter {"title":{"innerContent":{"desktop":{"value":"Projects delivered"}},"decoration":{"font":{"font":{"desktop":{"value":{"capitalization":"uppercase","letterSpacing":"2px","color":"#64748b"}}}}}},"number":{"innerContent":{"desktop":{"value":"120"}},"advanced":{"enablePercentSign":{"desktop":{"value":"off"}}},"decoration":{"font":{"font":{"desktop":{"value":{"size":"48px","weight":"800","color":"#0f172a"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/divider {"divider":{"advanced":{"line":{"desktop":{"value":{"color":"#dc2626","weight":"2px","style":"dashed"}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"See our work"}}},"decoration":{"font":{"font":{"desktop":{"value":{"weight":"600","capitalization":"uppercase","letterSpacing":"1px","color":"#ffffff"}}}},"background":{"desktop":{"value":{"color":"#1e3a8a"}}},"border":{"desktop":{"value":{"radius":{"topLeft":"8px","topRight":"8px","bottomRight":"8px","bottomLeft":"8px"}}}},"button":{"desktop":{"value":{"icon":{"placement":"left"}}}}}},"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"14px","right":"32px","bottom":"14px","left":"32px"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
- Only the modules named here were measured; icon glyphs other than one Font Awesome code, the icon on tablet and phone, blurb image mode,
  divider width and the bar counter were not.
- Desktop values only: no tablet or phone override was rendered for any key here.
- Computed styles (and the pseudo-elements above) were compared; how an icon or a line looks was not judged.
- A button text colour and background need contrast; see the default guide.
MD;
    }

    /** Topic `toggles`: accordion, toggle and tabs colours, every shape proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function togglesTopic(): string
    {
        return self::togglesTopicText() . self::togglesKitSentence();
    }

    /**
     * The `toggles` topic's one style-kit sentence, generated from KitAdapters::APPLIED: '' while no adapter styles an
     * accordion or a toggle (tabs are never kit-driven: Divi paints their bar, see the Tabs section).
     *
     * @param array<string,list<string>>|null $applied defaults to KitAdapters::APPLIED
     */
    public static function togglesKitSentence( ?array $applied = null ): string
    {
        $applied ??= KitAdapters::APPLIED;
        if ( ! isset( $applied['divi/accordion'] ) && ! isset( $applied['divi/toggle'] ) ) {
            return '';
        }

        return "\n\n## Style kits\n\nAn accordion or a toggle can take the page's style kit (frame, corners, shadow and every state colour): a ready-made toggle per kit is in topic=style-kit:<id>, the rules in topic=kit-rules. Tabs keep their own look.";
    }

    private static function togglesTopicText(): string
    {
        return <<<'MD'
# Toggles: accordion, toggle and tabs colours

Every shape listed here was rendered on the real Divi 5.15.1 (the front end of a draft page preview, Chromium at 1280, 900 and
400 px) and changed the computed styles of the page (the title's `::before` where Divi draws the icon); the worked example uses
ordinary values. A rule marked (source) is read from Divi's own code and was not rendered, and the Limits section says what was
not measured. The Visual Builder was not checked. Whole sections: the recipes `faq-accordion`, `faq-toggle-list` and
`features-accordion-image` (get_section_recipes). Fonts are in the topic `typography`.

## Accordion and toggle
On a `divi/accordion` (the parent of its items) and on a stand-alone `divi/toggle`:
- `openToggle.decoration.background.{bp}.value.color`
- `closedToggle.decoration.background.{bp}.value.color`
- `closedToggleIcon.decoration.icon.{bp}.value.color`
Rendered: the open item's background (white by default) and the closed items' (Divi's #f4f4f4) took the set colour; the icon
colour changed the closed title's `::before`.

## Toggle only
Rendered on the stand-alone `divi/toggle`:
- `openToggle.decoration.font.font.{bp}.value.color`
- `closedTitle.decoration.font.font.{bp}.value.color`
- `openToggleIcon.decoration.icon.{bp}.value.color`
- `title.decoration.font.font.{bp}.value.color`
- `content.decoration.bodyFont.body.font.{bp}.value.color`
`openToggle`'s font colour (printed !important, source) set the open title with the title at h5 and at h3; `closedTitle` set the closed
titles; `openToggleIcon` coloured the open item's icon; the body colour reached the answer. The generic `title` colour reached an
open and a closed title in the probe (h5, and the open title at h3), but not an open h3 title in one recipe render (observed, cause
not isolated), so set the open title with `openToggle` and the closed ones with `closedTitle`.

## The accordion's open item
The accordion draws no icon on its open item (source: Divi's stylesheet hides it, and the accordion has no open-icon setting). Use
toggles where an open-state icon matters (`faq-toggle-list`).

## Tabs
Rendered on `divi/tabs`:
- `tab.decoration.background.{bp}.value.color`
- `tab.decoration.font.font.{bp}.value.color`
- `tab.decoration.font.font.{bp}.value.size`
- `tab.decoration.font.font.{bp}.value.family`
- `activeTab.decoration.background.{bp}.value.color`
- `activeTab.decoration.font.font.{bp}.value.color`
- `content.decoration.background.{bp}.value.color`
Divi paints the tab bar #f4f4f4 and the separators #d9d9d9, and no setting reaches them (source: Divi's stylesheet; only a
custom CSS field reaches the bar, and custom CSS is never written; the outer frame was not tested). On a dark or tinted look the
bar stays light grey: use an accordion or toggles instead.

## Worked example (validates)
Two toggles on a dark section, the first open:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#0f172a"}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/toggle {"module":{"advanced":{"open":{"desktop":{"value":"on"}}}},"openToggle":{"decoration":{"background":{"desktop":{"value":{"color":"#1e293b"}}},"font":{"font":{"desktop":{"value":{"color":"#f8fafc"}}}}}},"closedToggle":{"decoration":{"background":{"desktop":{"value":{"color":"#1e293b"}}}}},"closedTitle":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"#f8fafc"}}}}}},"openToggleIcon":{"decoration":{"icon":{"desktop":{"value":{"color":"#f8fafc"}}}}},"closedToggleIcon":{"decoration":{"icon":{"desktop":{"value":{"color":"#f8fafc"}}}}},"title":{"innerContent":{"desktop":{"value":"How long does a project take?"}},"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"h3"}}}}}},"content":{"innerContent":{"desktop":{"value":"<p>Your answer in two or three plain sentences.</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#cbd5e1"}}}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/toggle {"openToggle":{"decoration":{"background":{"desktop":{"value":{"color":"#1e293b"}}},"font":{"font":{"desktop":{"value":{"color":"#f8fafc"}}}}}},"closedToggle":{"decoration":{"background":{"desktop":{"value":{"color":"#1e293b"}}}}},"closedTitle":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"#f8fafc"}}}}}},"openToggleIcon":{"decoration":{"icon":{"desktop":{"value":{"color":"#f8fafc"}}}}},"closedToggleIcon":{"decoration":{"icon":{"desktop":{"value":{"color":"#f8fafc"}}}}},"title":{"innerContent":{"desktop":{"value":"What happens after I get in touch?"}},"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"h3"}}}}}},"content":{"innerContent":{"desktop":{"value":"<p>Your answer in two or three plain sentences.</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#cbd5e1"}}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
- Only colours were measured here (and one tab font size and family); the sizes, borders and spacing of these modules were not.
- The items were measured in the state the page loaded with; clicking to open or close was not measured.
- Desktop values only: no tablet or phone override was rendered for any key here.
- Computed styles (and the icon's `::before`) were compared; check the contrast of every title and answer on its background.
MD;
    }

    /** Topic `forms`: contact form fields, captcha, countdown timer, and the modules not to use, every shape proven on the real Divi 5.15.1. At most 10,000 bytes. */
    private static function formsTopic(): string
    {
        return self::formsTopicText() . self::formsKitSentence();
    }

    /**
     * The `forms` topic's one style-kit sentence, generated from KitAdapters::APPLIED: '' while no adapter styles a contact
     * form.
     *
     * @param array<string,list<string>>|null $applied defaults to KitAdapters::APPLIED
     */
    public static function formsKitSentence( ?array $applied = null ): string
    {
        $applied ??= KitAdapters::APPLIED;
        if ( ! isset( $applied['divi/contact-form'] ) ) {
            return '';
        }

        return "\n\n## Style kits\n\nA contact form can take the page's style kit with one `form` marker (its fields, submit button and captcha); the rules are in topic=kit-rules.";
    }

    private static function formsTopicText(): string
    {
        return <<<'MD'
# Forms: contact form fields, captcha and countdown

Every shape listed here was rendered on the real Divi 5.15.1 (the front end of a draft page preview, Chromium at 1280, 900 and
400 px) and changed the computed styles of the page (the `::placeholder` where named); the worked example uses ordinary values.
A rule marked (source) is read from Divi's own code and was not rendered, and the Limits section says what was not measured. The
Visual Builder was not checked. Whole sections: the recipes `contact-details-form`, `contact-location-form`, `cta-split-form` and
`cta-countdown` (get_section_recipes).

## Contact form fields
On a `divi/contact-form` (the fields are its `divi/contact-field` children):
- `field.decoration.background.{bp}.value.color`
- `field.decoration.font.font.{bp}.value.color`
- `field.decoration.font.font.{bp}.value.family`
- `field.decoration.placeholderFont.font.{bp}.value.color`
- `field.decoration.border.{bp}.value.radius.{topLeft,topRight,bottomRight,bottomLeft}`
- `field.decoration.border.{bp}.value.styles.all.{width,color,style}`
Rendered: the fill (Divi's #eeeeee by default), the typed text colour (#999999 by default) and the family took the set values.
`placeholderFont` colour changed the `::placeholder` colour from Chrome's #757575; the field font colour does NOT reach the
placeholder, so set both (with labels hidden, Divi's default, the placeholders are the only labels). Divi draws no frame on a
field: width, colour and style set together drew one (1px rendered); the four corners set together rounded the inputs (12px
rendered). On a dark look a frame in the border colour can be too faint; the recipes use the muted text colour for it.

## Captcha
- `captcha.decoration.font.font.{bp}.value.color`
- `captcha.decoration.font.font.{bp}.value.family`
- `captcha.decoration.border.{bp}.value.styles.all.{width,color,style}`
- `captcha.decoration.background.{bp}.value.color`
The basic captcha is on by default and its question prints Divi's #666666 (rendered), nearly invisible on a dark look: set its
colour. The captcha border and background styled the paragraph that holds the question and the answer box together (rendered),
not the box; the box sits in a field (source), so the field settings above style it.

## Countdown timer
On a `divi/countdown-timer`:
- `number.decoration.font.font.{bp}.value.color`
- `number.decoration.font.font.{bp}.value.lineHeight`
- `separator.decoration.font.font.{bp}.value.{color,family,size,lineHeight}`
- `label.decoration.font.font.{bp}.value.{color,family,size}`
Rendered on a timer with Divi's own past default end date (every unit 00): each changed its element (numbers, the colons, the
unit labels; the label size went from 14px to 18px). Divi dims a unit that has reached zero (source: its stylesheet sets opacity
.4 on it), so a past date, and the last units of a real deadline, print faint numbers. Use only a real deadline the owner gives;
the `cta-countdown` recipe holds a labelled placeholder date to replace.

## Do not use
- The map module: it needs a Google Maps API key and loads Google's script from a third-party host (source), which this plugin
  never adds. Show the address as text, as `contact-location-form` does.
- The filterable portfolio: it lists the site's own Divi Projects (source), so its items cannot be written into a page, and its
  filter bar keeps Divi's own colours (no filter setting was probed). Use `portfolio-featured-grid` or `gallery-masonry-style`.
- A receiver address: never write one. The form sends to the site admin email unless the owner sets another in Divi (source).

## Worked example (validates)
A contact form on a dark card with readable fields, placeholders and captcha:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#0f172a"}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/contact-form {"module":{"advanced":{"uniqueId":{"desktop":{"value":"5b1f0c9e-2a4d-4c8e-9f3a-7d6e1b2c4a50"}}}},"field":{"decoration":{"background":{"desktop":{"value":{"color":"#1e293b"}}},"font":{"font":{"desktop":{"value":{"color":"#f8fafc"}}}},"placeholderFont":{"font":{"desktop":{"value":{"color":"#94a3b8"}}}},"border":{"desktop":{"value":{"radius":{"topLeft":"4px","topRight":"4px","bottomRight":"4px","bottomLeft":"4px"},"styles":{"all":{"width":"1px","color":"#94a3b8","style":"solid"}}}}}}},"captcha":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"#f8fafc"}}}}}},"button":{"innerContent":{"desktop":{"value":{"text":"Send"}}},"decoration":{"background":{"desktop":{"value":{"color":"#2563eb"}}},"font":{"font":{"desktop":{"value":{"color":"#ffffff"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/contact-field {"fieldItem":{"advanced":{"id":{"desktop":{"value":"Name"}}},"innerContent":{"desktop":{"value":"Name"}}},"module":{"decoration":{"sizing":{"desktop":{"value":{"flexType":"24_24"}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/contact-field {"fieldItem":{"advanced":{"id":{"desktop":{"value":"Email"}},"type":{"desktop":{"value":"email"}}},"innerContent":{"desktop":{"value":"Email"}}},"module":{"decoration":{"sizing":{"desktop":{"value":{"flexType":"24_24"}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/contact-field {"fieldItem":{"advanced":{"id":{"desktop":{"value":"Message"}},"type":{"desktop":{"value":"text"}}},"innerContent":{"desktop":{"value":"Message"}}},"module":{"decoration":{"sizing":{"desktop":{"value":{"flexType":"24_24"}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/contact-form -->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
- Not measured: field types, required marks, visible labels, spam protection settings, the success and error messages, mail
  delivery, and the countdown's own background (the recipe sets it with the plain module background).
- The before/after module's label and handle settings were not probed.
- Desktop values only: no tablet or phone override was rendered for any key here.
- Computed styles were compared; check the contrast of fields, placeholders and the captcha on their fill.
MD;
    }

    /** Topic `composition`: techniques built from registered shapes and used by library recipes that passed the render gate. At most 10,000 bytes. */
    private static function compositionTopic(): string
    {
        return <<<'MD'
# Composition: ruled grids, one-side rules, edged bands and full-width bands

These techniques use only shapes that were rendered on the real Divi 5.15.1 (the front end of a draft page preview, Chromium at
1280, 900 and 400 px; the settings are in the topics named) and each is used by a library recipe that the recipe render gate drew on
the real Divi under the default look and at least the glass-dark, tech-mono and playful-pastel languages, with no phone
overflow and no failing contrast. The technique as a whole was not probed, and its look was not measured (the gate measures
overflow and text contrast). A rule marked (source) is read from Divi's own code and was not rendered, and the
Limits section says what was not measured. The Visual Builder was not checked. Fetch the named recipe for the whole section.

## Hairline-ruled grid
A grid group whose background is the line colour, with 1px gaps; each cell is a group in the page or surface colour, so the gaps
read as 1px rules (recipes `proof-logo-wall`, `stats-big-captions`, `pricing-comparison-table`). On the grid group:
- `module.decoration.background.{bp}.value.color`: the line colour
- `module.decoration.layout.{bp}.value.display`: "grid"
- `module.decoration.layout.{bp}.value.gridColumnCount`: "3", with a phone value ("2" or "1")
- `module.decoration.layout.{bp}.value.columnGap`: "1px"
- `module.decoration.layout.{bp}.value.rowGap`: "1px"
- `module.decoration.border.{bp}.value.styles.all.{width,color,style}`: "1px", the line colour, "solid", for the outer edge
Each cell: a group with `module.decoration.background.{bp}.value.color` (the page or surface colour) and padding. Fill every row:
an empty slot may show the line colour as a block. For a comparison table, tint one column's cells and keep labels short (phone
cells are narrow; a five-column table broke words at 400 px in a draft, observed, not in the evidence file).

## One-side rule
A rule on one edge of a column or group (recipe `proof-press-quotes`, a rule over each column):
- `module.decoration.border.{bp}.value.styles.all.{width,color,style}`: "0px", the rule colour, "solid"
- `module.decoration.border.{bp}.value.styles.top.width`: "2px"
The top edge takes the colour and style written on `all` (source: Divi's border styles write `all` first, then only what a side
changes). Add top padding so the content clears the rule. Under a style kit the width, style and colour come from the kit (topic=kit-rules); this example is the default look.

## Two-edged divider band
A section in a contrasting fill with a divider on both edges, each filled with the page colour, so the band seems to flow out of the
section above and into the one below (recipe `transition-wave-band`). The settings are in the topic `dividers`:
- `module.advanced.dividers.top.{bp}.value.{style,height,color}`: "wave", "80px", the page colour
- `module.advanced.dividers.bottom.{bp}.value.{style,height,color,flip}`: the same, with `flip` ["horizontal"]
Set `color` on both: the sections on either side share one colour, and with one shared colour and no `color` Divi paints the
divider black (source). Give the band top and bottom padding larger than the divider height, with smaller heights on tablets and
phones.

## Full-width row band
A row that spans the page width inside its section, with its own fill (recipe `transition-overlap-panel`):
- `module.decoration.sizing.{bp}.value.width`: "100%", on the row
- `module.decoration.sizing.{bp}.value.maxWidth`: "100%", on the row
- `module.decoration.background.{bp}.value.color`: the band fill, on the row
These sizing paths were probed on groups; on a row they are proven only by the render gate on that recipe, not by a probe of their
own. Give the row side padding so the text keeps off the screen edge. A following row can be pulled over the band's lower edge with a
negative `module.decoration.spacing.{bp}.value.margin.top` (the topic `layout-advanced`; the recipe pulls -160px, a strong value,
and gives the band more bottom padding than the pull); follow it with a section on the page colour.

## Worked examples (validate)
A hairline-ruled row of three figures (replace each placeholder with a figure the owner can prove):
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#e2e8f0"}}},"border":{"desktop":{"value":{"styles":{"all":{"width":"1px","color":"#e2e8f0","style":"solid"}}}}},"layout":{"desktop":{"value":{"display":"grid","gridColumnCount":"3","columnGap":"1px","rowGap":"1px"}},"phone":{"value":{"gridColumnCount":"1"}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"}}},"spacing":{"desktop":{"value":{"padding":{"top":"32px","bottom":"32px","left":"24px","right":"24px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"[Figure 1]"}},"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"h3","color":"#0f172a"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>[What the figure counts]</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#475569"}}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/group -->
<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"}}},"spacing":{"desktop":{"value":{"padding":{"top":"32px","bottom":"32px","left":"24px","right":"24px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"[Figure 2]"}},"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"h3","color":"#0f172a"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>[What the figure counts]</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#475569"}}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/group -->
<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"}}},"spacing":{"desktop":{"value":{"padding":{"top":"32px","bottom":"32px","left":"24px","right":"24px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"[Figure 3]"}},"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"h3","color":"#0f172a"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>[What the figure counts]</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#475569"}}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/group -->
<!-- /wp:divi/group -->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
A wave band between two sections on the page colour:
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#0f172a"}}},"spacing":{"desktop":{"value":{"padding":{"top":"152px","bottom":"152px"}}},"phone":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}},"advanced":{"dividers":{"top":{"desktop":{"value":{"style":"wave","height":"80px","color":"#ffffff"}},"phone":{"value":{"height":"32px"}}},"bottom":{"desktop":{"value":{"style":"wave","height":"80px","color":"#ffffff","flip":["horizontal"]}},"phone":{"value":{"height":"32px"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"[One line that bridges this section and the next]"}},"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"h2","color":"#ffffff","textAlign":"center"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

## Limits
- Not probed as techniques: only their settings were (see the topics named) and the recipes were rendered; the look was not
  measured.
- Not measured: a ruled grid with an empty slot, rules on the other sides, other divider shapes on a two-edged band, and a full-width
  row with a background image.
- Every recipe text, figure and image alt is a placeholder: replace it with the owner's real content or remove it.
MD;
    }

    private static function guide(): string
    {
        return <<<'MD'
# Divi 5 Layout Authoring Guide

Build `post_content` as Gutenberg block comments. Every Divi block carries
`"builderVersion":"5.8.0"`. Attribute values are keyed by breakpoint — use
`desktop` (Divi cascades to tablet/phone). All shapes below are confirmed from
real Divi 5 exports; do not invent attribute keys.

## Structure (nesting)
```
divi/placeholder              (root wrapper, optional)
  divi/section                (a section; may also hold a column directly)
    divi/row
      divi/column             (holds modules; may also hold a nested row)
        [modules]
        divi/row-inner        (specialty nested row)
          divi/column-inner   (same children as divi/column)
            [modules]
```
**A `divi/column` can NEVER directly contain another `divi/column`** (doing so is
rejected with UNEXPECTED_CHILD_TYPE). To nest columns inside a column, go through a
row: **column → `divi/row-inner` → `divi/column-inner`** (or a plain `divi/row` →
`divi/column`). That row-in-column pattern is the ONLY way to build a multi-column
grid inside a column. When you just need ONE styled card container inside a column
(not real columns), use a **`divi/group`** instead — it holds modules directly and
takes the same `module.decoration` styling (background, border, radius, padding,
flex layout) as a column.

Compound modules: `divi/accordion>divi/accordion-item`,
`divi/counters>divi/counter`, `divi/icon-list>divi/icon-list-item`,
`divi/tabs>divi/tab`, `divi/slider>divi/slide`,
`divi/social-media-follow>divi/social-media-follow-network`.

## Required content (or the layout is rejected)
- `divi/heading`: `title.innerContent.desktop.value` = "Text"
- `divi/text`: `content.innerContent.desktop.value` = "<p>HTML</p>"
- `divi/image`: `image.innerContent.desktop.value` = `{ "src": "URL" }`
- `divi/button`: `button.innerContent.desktop.value` = `{ "text": "Label" }`
Images: never leave a `src` blank and never invent or hotlink a URL. Call
**get_image_guide**: use the site's Media Library first (**list_media_images**),
and the built-in image pack (real site-local URLs, listed in that guide) only
when the library has nothing suitable.

## Heading levels — exactly one H1
Set a heading's level with `title.decoration.font.font.{bp}.value.headingLevel`
= "h1".."h6". Use **exactly ONE h1 per page** (the main hero headline); make
section titles h2 and card/sub titles h3–h4. More than one h1 is REJECTED
(MULTIPLE_H1).

## Animated stat counter (divi/number-counter)
For metrics/stats that count up on scroll, prefer divi/number-counter over a
plain heading number:
`{"number":{"innerContent":{"desktop":{"value":"250000"}}},"title":{"innerContent":{"desktop":{"value":"Tasks Automated"}}},"builderVersion":"5.8.0"}`

## Styling attribute shapes
Put these alongside `builderVersion` on the relevant block. `{bp}` = `desktop`.

**Backgrounds** (section/row/column `module`):
- solid: `module.decoration.background.{bp}.value.color` = "#0a0a0a"
- gradient: `module.decoration.background.{bp}.value.gradient.enabled`="on",
  `…gradient.stops.0.{position,color}` (0,"rgba(10,10,10,.5)"),
  `…gradient.stops.1.{position,color}` (100,"rgba(0,0,0,.6)"), `…gradient.length`="100%"

**Spacing** (units px/vw/%/em):
`module.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}` = "6vw";
`…spacing.{bp}.value.margin.bottom` = "24px"

**Heading typography** (`divi/heading`):
`title.decoration.font.font.{bp}.value.{size,color,textAlign,lineHeight}`
= "5vw" / "#FFFFFF" / "center" / "1em"

**Body typography** (`divi/text`):
`content.decoration.bodyFont.body.font.{bp}.value.{size,color}` = "20px" / "#c9c9c9";
`module.advanced.text.text.{bp}.value.orientation` = "center"

**Button** (`divi/button`):
`button.decoration.background.{bp}.value.color` = "#ff4d00";
`button.decoration.font.font.{bp}.value.color` = "#FFFFFF";
`button.decoration.border.{bp}.value.radius.{topLeft,topRight,bottomRight,bottomLeft}` = "8px" (set ALL four);
hover: `button.decoration.background.{bp}.hover.color` = "#bf6631"

**Border & radius** (cards): set ALL FOUR corners — `sync:"on"` does NOT
propagate a single corner to the others at render, so setting only `topLeft`
leaves the other three at 0 (one rounded corner, three square).
`module.decoration.border.{bp}.value.radius.topLeft` = "16px";
`module.decoration.border.{bp}.value.radius.topRight` = "16px";
`module.decoration.border.{bp}.value.radius.bottomRight` = "16px";
`module.decoration.border.{bp}.value.radius.bottomLeft` = "16px";
`module.decoration.border.{bp}.value.radius.sync` = "on";
`module.decoration.border.{bp}.value.styles.all.{width,color}` = "2px","#FFFFFF"

**Glass card (glassmorphism look):** combine a semi-transparent background with a
subtle light border and radius on a Group/column/row:
`module.decoration.background.{bp}.value.color` = "rgba(18,18,18,0.75)" (or
"rgba(255,255,255,0.06)" on dark);
`module.decoration.border.{bp}.value.styles.all.{width,color}` = "1px","rgba(255,255,255,0.12)";
`module.decoration.border.{bp}.value.radius.{topLeft,topRight,bottomRight,bottomLeft}` = "20px" (all four).
True frosted-glass *backdrop-blur* (blurring what's behind the card) is NOT a
standard Divi attribute and needs CSS code, which this plugin never saves. Use the
semi-transparent + border recipe above for the glass look.

**Box shadow:**
`module.decoration.boxShadow.{bp}.value.{style,horizontal,vertical,blur,color}`
= "preset4","6px","-2px","40px","rgba(0,0,0,.5)"

**Transform / z-index / overflow:**
`module.decoration.transform.{bp}.value.translate.{x,y}` = "5%","15%";
`module.decoration.zIndex.{bp}.value` = 10;
`module.decoration.overflow.{bp}.value.{x,y}` = "hidden"

**CSS filters** (incl. hover): `<key>.decoration.filters.{bp}.value.brightness` = "120%"
(also contrast, saturate, blur, opacity); `.hover.` for hover.

**Entrance animation:**
`<key>.decoration.animation.{bp}.value.{style,direction,duration}`
= "slide"/"top"/"600ms" (styles: fade, slide, bounce, zoom, flip, roll, fold)

**Divider** (`divi/divider`): `divider.advanced.line.{bp}.value.{color,weight}`

**Sticky** (a header bar that stays on screen while the page scrolls), on a section, row or module:
`module.decoration.sticky.{bp}.value.position` = "top" (or "bottom", or "topBottom"), and optionally
`module.decoration.sticky.{bp}.value.offset.top` = "20px" (the gap from the top of the screen).
A sticky element keeps following the reader past the end of its section unless it has a limit: `module.decoration.sticky.{bp}.value.limit.bottom`
= "section" (on a Group in a column, with position "top"; see topic `motion`).
**Scroll effects** (a section that drifts, fades, scales, rotates or blurs as the page scrolls):
`module.decoration.scroll.{bp}.value.verticalMotion.enable` = "on". The same shape turns on
`horizontalMotion`, `fade`, `scaling`, `rotating` and `blur` (each also has `viewport` {top, start, end, bottom} and
`offset` {start, mid, end}; leave them out and Divi uses its own defaults, which are right for most pages).
Checked on Divi 5.15.1: `position` "top"/"bottom"/"topBottom" and `offset.top`, and `verticalMotion.enable`, are exactly what the
builder saves; a section with position "top" stays fixed near the top of the screen while the page scrolls, and with
`verticalMotion` or `fade` on it moves or fades as it scrolls (`make verify-guide-shapes`). Use them sparingly: one sticky bar,
one or two gentle effects. Do not guess other names under `sticky` or `scroll`.

## Font family, weight, style (serif headings + sans body)
On any font element (`title.decoration.font.font`, `content.decoration.bodyFont.body.font`,
`button.decoration.font.font`):
```
…font.{bp}.value.family        = "Playfair Display"   # any Google/Divi font; e.g. serif heading + sans body
…font.{bp}.value.weight        = "700"
…font.{bp}.value.letterSpacing = "2px"                # great for kickers
title.decoration.font.font.{bp}.value.capitalization = "uppercase"   # upper case; also lowercase, capitalize, smallCaps; same key on every font element
…font.{bp}.value.style          = ["italic"]           # a list: "italic","underline","overline","strikethrough"
```
Styled H1–H6 inside a Text module: `content.decoration.headingFont.h2.font.{bp}.value.{color,lineHeight,textAlign,style}`.

## Flex layout — reliable multi-column (preferred over column-count)
Put a flex layout on a container, size children with `flexType` (24-unit grid):
```
# container (section/row/column/group):
module.decoration.layout.{bp}.value.display        = "flex"
module.decoration.layout.{bp}.value.flexDirection  = "row"      # "column" to stack
module.decoration.layout.{bp}.value.justifyContent = "center"
module.decoration.layout.{bp}.value.alignItems     = "center"   # "stretch" for equal-height cards
module.decoration.layout.{bp}.value.columnGap      = "32px"
module.decoration.layout.{bp}.value.rowGap         = "24px"
module.decoration.layout.{bp}.value.flexWrap       = "wrap"
# each child:
module.decoration.sizing.{bp}.value.flexType = "8_24"   # 8_24=1/3, 12_24=1/2, 6_24=1/4, 24_24=full
```
To stack on mobile: set the container's `layout.phone.value.flexDirection = "column"` and
each child's `sizing.phone.value.flexType = "24_24"`.

## Per-device (responsive) settings
Most settings have one value per device under the same attribute, keyed `desktop`, `tablet` and `phone`
(`tabletWide` and `phoneWide` are the in-between devices). Set the desktop value, then add `tablet` and `phone` only where
they differ. All of these are verified on Divi 5.14, and again on 5.15.1, by measuring a real page at three screen widths (`make verify-guide-shapes`).

**Smaller headline on phones** (48px on desktop, 36px on a tablet, 24px on a phone):
`title.decoration.font.font.desktop.value.size` = "48px", `...tablet.value.size` = "36px", `...phone.value.size` = "24px".

**Body text size and alignment per device** (a text module): `content.decoration.bodyFont.body.font.{bp}.value.size`
and `.textAlign` (for example "center" on desktop, "left" on a phone). A heading's alignment is
`title.decoration.font.font.{bp}.value.textAlign`.

**Spacing per device**: `module.decoration.spacing.{bp}.value.padding.top` (also `.right`, `.bottom`, `.left`) and
`margin.bottom`, for example "60px" on desktop and "20px" on a phone.

**Columns stack on a phone** only when the row says so: a plain row of two columns stays side by side on a phone. These
are the exact shapes Divi's own builder writes (verified by measuring the result at desktop, tablet and phone widths).
For N equal columns, set on the `divi/row`:
`module.advanced.columnStructure.desktop.value` = "1_2,1_2" (N=2), "1_3,1_3,1_3" (N=3) or "1_4,1_4,1_4,1_4" (N=4);
`module.advanced.flexColumnStructure.desktop.value` = "equal-columns_N";
`module.decoration.layout.desktop.value.flexWrap` = "nowrap", and `flexWrap` = "wrap" for `phone` and `phoneWide`
(for N=4 use `tablet` and `tabletWide` instead, so four columns go two by two on a tablet).
On each `divi/column`: `module.advanced.type.desktop.value` = "1_2" / "1_3" / "1_4" and
`module.decoration.sizing.{bp}.value.flexType`: desktop "12_24" / "8_24" / "6_24", phone and phoneWide "24_24"
(N=4 also tablet and tabletWide "12_24").

## Mobile: collapse multi-column rows to one column
On every row that has more than one column, add a phone layout that stacks the
columns vertically so the page is one column on mobile:
```
module.decoration.layout.phone.value = {"display":"flex","flexDirection":"column","rowGap":"0px"}
```
(Set this on the divi/row alongside its desktop columnStructure.)

## Buttons side by side (horizontal CTAs)
Two buttons placed directly in a column STACK vertically. To put a pair of CTAs
on one line, wrap them in a NESTED row whose single column has a flex-row layout:
```
divi/row -> divi/column with
  module.decoration.layout.desktop.value = {"display":"flex","flexDirection":"row",
    "justifyContent":"center","alignItems":"center","columnGap":"16px","rowGap":"12px","flexWrap":"wrap"}
  module.decoration.layout.phone.value   = {"display":"flex","flexDirection":"column","rowGap":"12px"}
-> [divi/button, divi/button]
```
So the hero/CTA structure is: section > row > column > [heading, text,
row(nested) > column(flex-row) > button + button]. Never leave two bare buttons
in a column expecting them side by side.

## Sizing & centering
```
module.decoration.sizing.{bp}.value.maxWidth  = "720px"   # constrain content width
module.decoration.sizing.{bp}.value.minHeight = "100px"
module.decoration.sizing.{bp}.value.alignment = "center"
# center a max-width element (which otherwise aligns left):
module.decoration.spacing.{bp}.value.margin.left  = "auto"
module.decoration.spacing.{bp}.value.margin.right = "auto"
```

## Background image positioning & gradient direction
```
module.decoration.background.{bp}.value.image.size     = "cover"        # or "contain"
module.decoration.background.{bp}.value.image.position = "right center"
module.decoration.background.{bp}.value.gradient.direction = "90deg"    # linear angle
module.decoration.background.{bp}.value.gradient.type      = "linear"   # linear|circular|elliptical|conic
```

## Absolute positioning & z-index (advanced)
```
module.decoration.position.{bp}.value.mode            = "absolute"
module.decoration.position.{bp}.value.origin.absolute = "bottom center"
module.decoration.position.{bp}.value.offset.vertical = "20px"
module.decoration.zIndex.{bp}.value                   = 10
```

## No custom code
Never write CSS, JavaScript or PHP code, and never use a Divi Code module. This plugin refuses them:
a layout with a CSS field (anything under a module's `css` attribute), a `divi/code` or
`divi/fullwidth-code` module, `<script>`, `<style>`, `<iframe>`, `<object>` or `<embed>` markup, a
`javascript:` link, an `on…=` event attribute or a PHP tag is rejected with `CUSTOM_CSS_NOT_ALLOWED`,
`CODE_MODULE_NOT_ALLOWED` or `SCRIPT_CONTENT_NOT_ALLOWED` and the exact path. Style with the module's own
decoration attributes above instead. Code already on a page (the owner's own) is left untouched when
you edit that page; you cannot add or change it. If a design needs something the decoration attributes
cannot express, tell the owner it is not possible through this plugin instead of working around it.
Close every quote and bracket in a style value (`calc(10px + 2vw)`, `"Open Sans"`): Divi writes the value into the
page's stylesheet as it is, so an unclosed `'`, `"` or `(` makes the browser drop every rule after it and the page loses
all its custom styling. Such a value is rejected with `STYLE_VALUE_BREAKS_PAGE` (measured on Divi 5.15.1).

## Full-viewport height & background blend
```
module.decoration.sizing.{bp}.value.height    = "85vh"        # full-screen hero
module.decoration.background.{bp}.value.image.blend = "multiply"   # blend a bg image into the color/overlay
module.decoration.position.{bp}.value.mode    = "relative"     # also "absolute"
```

## Design guidance
Pick a design language first: call get_style_guide topic=design-languages, choose the one that fits the brief and state it before you build (don't default to the same dark theme). Then compose, don't repeat:
- never the same layout in adjacent sections; alternate background treatment (light, tinted, dark, image or gradient)
- at most one card grid per three sections
- give the first section a focal element (image, gradient or large type)
Use a dominant + accent color, generous vertical padding (5–8vw), round cards (12–20px), box-shadow and subtle transforms for depth, entrance animations sparingly. Check text contrast against the lightest part of its background (on gradients, the lightest stop).
If a save or validate result carries `advisories.design_variety`, act on it: if a finding's `try` has names, swap that section for one of them (get_section_recipes); if `try` is empty, follow the finding's `message`. Then save again. It is a hint only: it never blocks a save, and the page is saved as it is.

## Worked example — one styled section
```
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#0a0a0a"}}},"spacing":{"desktop":{"value":{"padding":{"top":"6vw","bottom":"6vw"}}}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Building Digital Experiences"}},"decoration":{"font":{"font":{"desktop":{"value":{"size":"5vw","color":"#FFFFFF","textAlign":"center","headingLevel":"h1"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Start a Project"}}},"decoration":{"background":{"desktop":{"value":{"color":"#ff4d00"}}},"border":{"desktop":{"value":{"radius":{"topLeft":"8px","topRight":"8px","bottomRight":"8px","bottomLeft":"8px","sync":"on"}}}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```
Always run validate_layout (or rely on update_page_layout/create_page, which
validate) before trusting a layout.

## Undo
Every save through this plugin (update_page_layout / edit_page_content) keeps the
page's previous content as a snapshot when it can (the result's `history.stored`
says whether it did). Up to the last 10 previous versions are kept per page, fewer
for very large pages (a per-page budget of about 768 KB keeps the saved history
small). If an edit was wrong and a snapshot was kept, `list_page_history` then
`restore_page_version` undoes it; the restore is itself undoable when its own
`history.stored` is true. Prefer small `edit_page_content` edits over rewriting a
whole page.

## Read a big page cheaply
A full `get_page_layout` read of a real page costs 8,000 to 16,000 tokens. Ask for `view: "outline"` first: it lists every
block with a `path` (such as `0.0.1.2`), its name, its size in bytes and the start of its text, for about a sixth of the cost.
Then `view: "block"` with a `path` returns that one block's exact markup, which you can use as an `edit_page_content` find
string or to rebuild just that part. Leave `view` out for the whole layout (needed before replacing the whole page).

## Page title vs headline
The **page title** is WordPress's own title for the page: what shows in the page list,
the browser tab (usually) and search. Change it with `update_page_layout` and `title`
(`title` alone is fine; the layout is then left untouched). The **headline** is a heading
module inside the layout, often the `h1`: change it with `edit_page_content` or
`update_page_layout` with `post_content`. They are separate: a layout edit never changes the page title, and a title change never
rewrites text typed into the layout.
A heading typed into the layout is layout text and does not change when the title changes.
A Divi post title module (`divi/post-title`) shows the WordPress page title, so it follows
a rename (checked on Divi 5.14); a heading module with the words typed in does not follow it.

- When the owner says "change the title" and the layout has an `h1` that repeats the old
  title, say so and ask whether to change both. Never report a layout edit as a title
  change, or the other way round.
- This tool changes only the WordPress page title, not menu labels (a menu may keep
  showing the old label: tell the owner to check their menu), the page address (the slug),
  or a browser-tab or SEO title set by an SEO plugin: say so, do not improvise a workaround.
- A title change cannot be undone with `restore_page_version` (a restore brings back the
  layout only). Tell the owner the old title; the result of the call contains it.

## Preview before saving
update_page_layout and edit_page_content accept `dry_run: true`. The call then runs the
same checks as a real save but saves nothing: it returns whether the result would pass
the checker plus a diff of what would change (blocks added, removed or changed, with the
before and after value of each changed setting, at most 50 changes). Use it for a big
rewrite or any edit you are not sure about, confirm that only the intended blocks
changed, then repeat the same call without `dry_run` to save. A preview of an invalid
layout still returns normally, with `valid: false` and the violations; fix them before
saving. A replaced or new section counts as one removed/added block; its children are
not listed.
If a preview or a save would remove more than half of the page's text, images or modules,
the result carries `advisories.content_loss` (counts before and after, and which kinds
were lost). That is a warning, not a refusal: check it against what the person asked for,
read the page again with `get_page_layout` if you wrote from a partial copy, and put back
what was dropped.

## Match the site's structure
Call `get_site_structure` before building a page that should list a custom post type or
taxonomy, use an image at a particular registered size, or refer to Advanced Custom Fields
field names. It is read-only and never returns field values; it only tells you what exists.

## Show a custom field's value (dynamic content)
A module can show a value Divi reads from the page it sits on, such as an Advanced Custom
Fields (ACF) field, instead of fixed text. Call `get_site_structure` first (it lists the ACF
field names, labels and types) and use only a name it returns. Never invent a field name.
The binding is one `$variable(...)$` string that takes the place of the fixed value; Divi
fills it in when the page is shown. Write it exactly like this (inside a block comment the
quotes are written as \u0022), with the field's name in place of `FIELD_NAME`:
`$variable({\u0022type\u0022:\u0022content\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022post_meta_key\u0022,\u0022settings\u0022:{\u0022before\u0022:\u0022\u0022,\u0022after\u0022:\u0022\u0022,\u0022select_meta_key\u0022:\u0022custom_meta_FIELD_NAME\u0022,\u0022meta_key\u0022:\u0022\u0022,\u0022date_format\u0022:\u0022default\u0022,\u0022custom_date_format\u0022:\u0022\u0022,\u0022enable_html\u0022:\u0022off\u0022}}})$`
The `custom_meta_` prefix is part of the value. `before` and `after` wrap the value, for
example `<h2>` and `</h2>`. It is verified in
a text module's content (`content.innerContent.desktop.value`) and
a blurb's title (`title.innerContent.desktop.value.text`). The same string also works as an image's
`image.innerContent.desktop.value.src` (an ACF image field, whatever return format it uses;
keep `id` and `alt` empty) and as a button's `button.innerContent.desktop.value.linkUrl` (an
ACF URL field). Do not use it anywhere else, and do not use ACF repeater or loop fields.
The checker cannot see whether a field exists: a name the site does not have
renders an empty module, with no error. The save, create and preview tools add an `unknown_custom_fields` note
(inside `advisories.style_references`) when a layout binds a field that is not an ACF field
here and that no page has; replace it with a name `get_site_structure` returned. A field only
has a value on the pages its ACF field group applies to: each group in `get_site_structure`
has a `location` list of alternatives (any one set applying is enough), each a list of rules
that must all match, such as `post_type == page`. Bind only fields whose group applies to the page you are editing.
`get_site_structure` also lists, in its `custom_fields` section, the custom field keys this
site's posts really use, ACF or not (a sample of recent posts; keys only). To see what a
field holds on one page or Project, call `get_custom_fields` with its id: it returns each
key with its value (never protected or secret-looking keys), so you can write copy that
fits. A key with no ACF field behind it binds the same way in a text module: put its name in place of
`FIELD_NAME`.

If the site sells products with WooCommerce, call `list_products` before you write a shop page or product copy: it returns the
real product names, prices, stock and categories (and how many products there are), and `get_product` gives one product in
full, including its variations. Use those values; never invent a product, a price or a category. These tools only read: you
cannot change a product from here.

## Match the site (colors and presets)
Call `list_global_styles` before styling. It lists the site's own global colors (ids like
`gcid-…`), module presets and variables. Reuse a site color instead of hard-coding a hex:
write it as a color variable, exactly like this (inside a block comment the quotes are
written as \u0022):
`"color":"$variable({\u0022type\u0022:\u0022color\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gcid-XXXX\u0022,\u0022settings\u0022:{}}})$"`
with a real id from the tool in place of `gcid-XXXX`. The five theme colors
(`gcid-primary-color`, `gcid-secondary-color`, `gcid-heading-color`, `gcid-body-color`,
`gcid-link-color`) exist on every Divi 5 site. Never invent an id: an id the site does not
have leaves the color undefined, so the text silently loses its color.
A module with no `modulePreset` (or `"modulePreset":["default"]`) already gets the site's
default preset for its type, so most modules need nothing. Use a named preset only when its
name and `controls` fit, as `"modulePreset":["<id from the tool>"]`; an unknown id silently
falls back to the default preset.

Numbers and strings variables (ids like `gvid-…`, listed by the same tool with their values)
can be reused too, but only in four places that are verified: a **numbers**
variable as a `padding` value or as a **heading's** font `size`; a
**strings** variable as a heading's title text (`title.innerContent`) or as a text module's
content (`content.innerContent`). For a strings variable
the whole `$variable(...)$` token is the entire value of `title.innerContent.desktop.value` or `content.innerContent.desktop.value`.
Write the value exactly like this (inside a block comment the quotes are written as \u0022):
`"size":"$variable({\u0022type\u0022:\u0022content\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gvid-XXXX\u0022,\u0022settings\u0022:{}}})$"`
with a real id from the tool in place of `gvid-XXXX`. Do not cite a variable anywhere else,
and do not reference fonts, images, links or gradients variables: those are
listed for information only. Never invent an id: Divi silently drops a value (a padding, a
size, a line of text) that cites an id the site does not have, and the checker cannot see
that.

The tool is read-only: presets, colors and variables are created in Divi's Visual Builder
(its Variables Manager). The save, create and preview tools add an
`advisories.style_references` note when a layout cites a preset, color or variable id the
site does not have (`unknown_presets`, `unknown_colors`, `unknown_variables`); replace those
ids with the site's own.
## Components (Divi 5.15)
A **component** is a section, a row or a single module that the site saved once and reuses on many pages. On a page it
appears as a `divi/global-layout` block: it has `globalModule` (the component's id), `blockName` (`divi/section`, `divi/row`,
or the module, such as `divi/heading`), `localAttrs` and `localAttrOverridePaths`. A section or row instance wraps that
section's or row's own blocks; a module instance is one self-closing `divi/global-layout` inside a column. Blocks inside an
instance carry a `syncNodeId`.
- **Do not rewrite or restyle what a component owns.** Divi shows the component's own content on every page that uses it, so a
  change to a block with a `syncNodeId` (its text, colors, spacing) does not appear. The save and preview tools add
  `advisories.components.component_owned_edits` when that is what a change did. Change the component itself in the Divi
  builder (Edit Component), or leave it as is. Never turn an instance into plain blocks to "edit" it: that detaches it.
- **A Slot is the page's own place inside a component.** A `divi/global-slot` block (with its `slotId`) holds the modules this
  page puts there; modules inside a Slot carry no `syncNodeId` and are yours to change. Keep the `slotId`. Do not add a module
  in the same column next to a Slot: that edits the component itself, for every page.
- Call `get_site_structure` with `include=components` to see which components exist, their kind, their Slot ids and how many pages
  use each. A component id the site does not have (`advisories.components.unknown_components`) leaves a page that shows its own
  stored copy and is linked to nothing; use ids from that list. To create or change a component, use the Divi builder.
MD;
    }
}
