<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The Divi 5 Lab admin tab (internal name Studio): five steps on the left, the live preview on the right.
 *
 * - The markup is rendered here; assets/studio-core.js (pure functions) and assets/studio.js add the behaviour. Without
 *   JavaScript the app stays hidden and one sentence says why, so nothing half-works.
 * - Everything a person typed or the site supplied is escaped here (esc_html / esc_attr) and handed to the scripts as
 *   plain strings in config(); the scripts write text with textContent and value only, never as HTML.
 * - The screen saves nothing itself: every action goes through the admin-ajax handlers of StudioAjax (nonce
 *   StudioAjax::NONCE in the POST field "nonce"), which build pages only as drafts through StudioService.
 * - Step 1 maps onto the brief as it is (no new fields): the business name is the page title and is offered as the
 *   hero headline, the site's one line is offered as the hero's supporting line and the one action wanted as the hero's
 *   button label. "Who it is for" only guides the person's own words and is not stored.
 */
final class StudioScreen
{
    public const HANDLE      = 'ai-editor-divi5-lab';
    public const CORE_HANDLE = 'ai-editor-divi5-lab-core';

    /** The density control's choices, as the stack handler accepts them. */
    public const DENSITIES = [ 'airy', 'balanced', 'compact' ];

    /** A placeholder longer than this is cut in the form (the recipe keeps it whole). */
    private const SAMPLE_CHARS = 200;

    /** How many of the site's published pages the link suggestions list. */
    private const MAX_PAGES = 200;

    public static function enqueue(): void
    {
        $base  = plugin_dir_url( AI_EDITOR_DIVI5_FILE ) . 'assets/';
        $media = current_user_can( 'upload_files' );
        if ( $media && function_exists( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }
        wp_enqueue_style( self::HANDLE, $base . 'studio.css', [ 'ai-editor-divi5-admin' ], AI_EDITOR_DIVI5_VERSION );
        wp_enqueue_script( self::CORE_HANDLE, $base . 'studio-core.js', [], AI_EDITOR_DIVI5_VERSION, true );
        wp_enqueue_script( self::HANDLE, $base . 'studio.js', [ self::CORE_HANDLE ], AI_EDITOR_DIVI5_VERSION, true );
        wp_localize_script( self::HANDLE, 'aiedLabConfig', self::config( get_current_user_id() ) );
    }

    /**
     * The data the scripts need. Every value is plain data (no markup is built from it); the brief is the signed-in
     * user's own (another user's id gives null).
     *
     * @return array<string,mixed>
     */
    public static function config( int $user ): array
    {
        $languages = [];
        foreach ( DesignLanguages::ids() as $id ) {
            $l           = (array) DesignLanguages::get( $id );
            $languages[] = [ 'id' => $id, 'title' => (string) ( $l['title'] ?? $id ), 'summary' => (string) ( $l['summary'] ?? '' ), 'kit' => (string) ( $l['kit'] ?? '' ) ];
        }
        $kits = [];
        foreach ( StyleKits::ids() as $id ) {
            $kits[] = [ 'id' => $id, 'name' => self::kitName( $id ), 'summary' => (string) ( StyleKits::get( $id )['summary'] ?? '' ) ];
        }
        $goals  = [];
        $stacks = [];
        foreach ( self::goals() as $id => [ $label ] ) {
            $goals[]       = [ 'id' => $id, 'label' => $label ];
            $stacks[ $id ] = StudioStack::forGoal( $id, 'balanced' );
        }
        $densities = [];
        foreach ( self::densities() as $id => [ $label ] ) {
            $densities[] = [ 'id' => $id, 'label' => $label ];
        }

        return [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( StudioAjax::NONCE ),
            'home'      => function_exists( 'home_url' ) ? (string) home_url( '/' ) : '',
            'media'     => current_user_can( 'upload_files' ),
            'site'      => self::site(),
            'goals'     => $goals,
            'stacks'    => $stacks,
            'densities' => $densities,
            'languages' => $languages,
            'kits'      => $kits,
            'recipes'   => self::recipes(),
            'roles'     => self::roleLabels(),
            'slotRoles' => self::slotRoleLabels(),
            'pack'      => self::pack(),
            'pages'     => self::pages(),
            'brief'     => self::storedBrief( $user ),
            'i18n'      => self::i18n(),
        ];
    }

    /** A kit id as a name a person reads: "quiet-solid" => "Quiet solid". */
    public static function kitName( string $id ): string
    {
        return ucfirst( str_replace( '-', ' ', $id ) );
    }

    public static function render(): void
    {
        $site      = self::site();
        $languages = DesignLanguages::ids();
        $first     = $languages[0] ?? '';
        $ownKit    = (string) ( DesignLanguages::get( $first )['kit'] ?? '' );
        ?>
        <hr class="wp-header-end">
        <noscript><p class="aied-lab-nojs"><?php esc_html_e( 'Divi 5 Lab needs JavaScript. Turn JavaScript on in your browser and reload this page.', 'jhmg-ai-editor-for-divi-5' ); ?></p></noscript>
        <div id="aied-lab-app" class="aied-lab" hidden>
            <div class="aied-card aied-lab-intro">
                <p class="aied-lab-lead"><?php esc_html_e( 'Build a Divi 5 page in five steps. Every answer updates the preview on the right.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                <ul class="aied-lab-facts">
                    <li><?php esc_html_e( 'The preview is a temporary private draft page. It appears in your Pages list while it exists and is deleted when you create the page, or after a day.', 'jhmg-ai-editor-for-divi-5' ); ?></li>
                    <li><?php esc_html_e( 'Created pages are always drafts. Nothing is published until you publish it.', 'jhmg-ai-editor-for-divi-5' ); ?></li>
                    <li><?php esc_html_e( 'Nothing is permanent: every change to a page can be undone from the page history on the Dashboard.', 'jhmg-ai-editor-for-divi-5' ); ?></li>
                    <li><?php esc_html_e( 'Placeholders mark where your own words are needed. The plugin never invents prices, names or claims; it keeps the sample text until you replace it.', 'jhmg-ai-editor-for-divi-5' ); ?></li>
                </ul>
            </div>

            <div id="aied-lab-status" class="aied-lab-status" role="status" aria-live="polite"></div>

            <div class="aied-lab-layout">
                <div class="aied-lab-form">
                    <nav class="aied-lab-nav" aria-label="<?php esc_attr_e( 'Steps', 'jhmg-ai-editor-for-divi-5' ); ?>">
                        <ol class="aied-lab-stepper">
                            <?php foreach ( self::steps() as $n => $label ) : ?>
                                <li><button type="button" class="aied-lab-step" data-goto="<?php echo esc_attr( (string) $n ); ?>"<?php if ( 1 === $n ) : ?> aria-current="step"<?php endif; ?>><span class="aied-lab-step__num"><?php echo esc_html( (string) $n ); ?></span> <?php echo esc_html( $label ); ?></button></li>
                            <?php endforeach; ?>
                        </ol>
                    </nav>

                    <?php $steps = self::steps(); ?>
                    <section class="aied-card aied-lab-panel" data-step="1" aria-labelledby="aied-lab-h1">
                        <h2 id="aied-lab-h1" tabindex="-1"><?php echo esc_html( $steps[1] ); ?></h2>
                        <fieldset class="aied-lab-choices">
                            <legend><?php esc_html_e( 'What is this page for?', 'jhmg-ai-editor-for-divi-5' ); ?></legend>
                            <?php foreach ( self::goals() as $id => [ $label, $hint ] ) : ?>
                                <label class="aied-lab-choice">
                                    <input type="radio" name="aied-lab-goal" value="<?php echo esc_attr( $id ); ?>"<?php checked( 'book', $id ); ?>>
                                    <span class="aied-lab-choice__text"><strong><?php echo esc_html( $label ); ?></strong> <span class="aied-muted"><?php echo esc_html( $hint ); ?></span></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <?php
                        self::field( 'aied-lab-audience', __( 'Who is it for?', 'jhmg-ai-editor-for-divi-5' ), '', __( 'Only a reminder for your own words while you write them. It is not saved.', 'jhmg-ai-editor-for-divi-5' ) );
                        self::field( 'aied-lab-action', __( 'The one action you want visitors to take', 'jhmg-ai-editor-for-divi-5' ), '', __( 'Becomes the label of the main button, for example "Book a call".', 'jhmg-ai-editor-for-divi-5' ) );
                        self::field( 'aied-lab-name', __( 'Business name', 'jhmg-ai-editor-for-divi-5' ), $site['name'], __( 'The page title. It is also offered as the main headline. Filled in from your site title.', 'jhmg-ai-editor-for-divi-5' ) );
                        self::field( 'aied-lab-line', __( 'One line about it', 'jhmg-ai-editor-for-divi-5' ), $site['tagline'], __( 'Offered as the line under the main headline. Filled in from your site tagline.', 'jhmg-ai-editor-for-divi-5' ) );
                        self::stepNav( 1 );
                        ?>
                    </section>

                    <section class="aied-card aied-lab-panel" data-step="2" aria-labelledby="aied-lab-h2" hidden>
                        <h2 id="aied-lab-h2" tabindex="-1"><?php echo esc_html( $steps[2] ); ?></h2>
                        <fieldset class="aied-lab-choices aied-lab-languages">
                            <legend><?php esc_html_e( 'Design language', 'jhmg-ai-editor-for-divi-5' ); ?></legend>
                            <?php foreach ( $languages as $id ) :
                                $l = (array) DesignLanguages::get( $id ); ?>
                                <label class="aied-lab-choice">
                                    <input type="radio" name="aied-lab-language" value="<?php echo esc_attr( $id ); ?>"<?php checked( $first, $id ); ?>>
                                    <span class="aied-lab-choice__text"><strong><?php echo esc_html( (string) ( $l['title'] ?? $id ) ); ?></strong> <span class="aied-muted"><?php echo esc_html( (string) ( $l['summary'] ?? '' ) ); ?></span></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <div class="aied-lab-field">
                            <label for="aied-lab-kit"><?php esc_html_e( 'Button style', 'jhmg-ai-editor-for-divi-5' ); ?></label>
                            <select id="aied-lab-kit" aria-describedby="aied-lab-kit-summary">
                                <option value=""><?php esc_html_e( 'The design language\'s own style', 'jhmg-ai-editor-for-divi-5' ); ?></option>
                                <?php foreach ( StyleKits::ids() as $kit ) : ?>
                                    <option value="<?php echo esc_attr( $kit ); ?>"><?php echo esc_html( self::kitName( $kit ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p id="aied-lab-kit-summary" class="description"><?php echo esc_html( (string) ( StyleKits::get( $ownKit )['summary'] ?? '' ) ); ?></p>
                        </div>
                        <fieldset class="aied-lab-choices aied-lab-inline">
                            <legend><?php esc_html_e( 'Spacing', 'jhmg-ai-editor-for-divi-5' ); ?></legend>
                            <?php foreach ( self::densities() as $id => [ $label, $hint ] ) : ?>
                                <label class="aied-lab-choice">
                                    <input type="radio" name="aied-lab-density" value="<?php echo esc_attr( $id ); ?>"<?php checked( 'balanced', $id ); ?>>
                                    <span class="aied-lab-choice__text"><strong><?php echo esc_html( $label ); ?></strong> <span class="aied-muted"><?php echo esc_html( $hint ); ?></span></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <?php self::stepNav( 2 ); ?>
                    </section>

                    <section class="aied-card aied-lab-panel" data-step="3" aria-labelledby="aied-lab-h3" hidden>
                        <h2 id="aied-lab-h3" tabindex="-1"><?php echo esc_html( $steps[3] ); ?></h2>
                        <p class="aied-muted"><?php esc_html_e( 'These sections are suggested for your goal. Leave out the ones you do not need, change their order, or pick another layout. Each layout shows how many items it holds.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                        <ol id="aied-lab-sections" class="aied-lab-sections"></ol>
                        <p><button type="button" class="button" id="aied-lab-resuggest"><?php esc_html_e( 'Suggest sections again', 'jhmg-ai-editor-for-divi-5' ); ?></button></p>
                        <?php self::stepNav( 3 ); ?>
                    </section>

                    <section class="aied-card aied-lab-panel" data-step="4" aria-labelledby="aied-lab-h4" hidden>
                        <h2 id="aied-lab-h4" tabindex="-1"><?php echo esc_html( $steps[4] ); ?></h2>
                        <p class="aied-muted"><?php esc_html_e( 'Type your own words, or let your connected AI assistant write them. Anything you leave empty keeps the sample text and is listed as a placeholder on the last step.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                        <div class="aied-lab-handoff">
                            <button type="button" class="button" id="aied-lab-handoff"><?php esc_html_e( 'Copy instruction for my assistant', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                            <button type="button" class="button" id="aied-lab-refresh"><?php esc_html_e( 'Get my assistant\'s words', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                            <p class="description"><?php esc_html_e( 'The button saves your brief and copies an instruction to paste into your assistant. When it has written the words, they appear here.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                            <div id="aied-lab-instruction-box" hidden>
                                <label for="aied-lab-instruction"><?php esc_html_e( 'Instruction for your assistant', 'jhmg-ai-editor-for-divi-5' ); ?></label>
                                <textarea id="aied-lab-instruction" class="large-text" rows="4" readonly></textarea>
                            </div>
                        </div>
                        <datalist id="aied-lab-pages"></datalist>
                        <div id="aied-lab-words" class="aied-lab-words"></div>
                        <?php self::stepNav( 4 ); ?>
                    </section>

                    <section class="aied-card aied-lab-panel" data-step="5" aria-labelledby="aied-lab-h5" hidden>
                        <h2 id="aied-lab-h5" tabindex="-1"><?php echo esc_html( $steps[5] ); ?></h2>
                        <h3><?php esc_html_e( 'Layout check', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
                        <div id="aied-lab-verdict" class="aied-lab-verdict"><?php esc_html_e( 'Not checked yet.', 'jhmg-ai-editor-for-divi-5' ); ?></div>
                        <div id="aied-lab-problems" class="aied-lab-problems"></div>
                        <h3><?php esc_html_e( 'Placeholders still to fill', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
                        <div id="aied-lab-unfilled" class="aied-lab-unfilled"></div>
                        <p class="aied-lab-actions">
                            <button type="button" class="button button-primary" id="aied-lab-create"><?php esc_html_e( 'Create as draft', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                        </p>
                        <div id="aied-lab-created" class="aied-lab-created" hidden>
                            <p id="aied-lab-created-message"></p>
                            <p class="aied-lab-actions">
                                <a id="aied-lab-open" class="button button-primary" href="#"><?php esc_html_e( 'Open in Divi', 'jhmg-ai-editor-for-divi-5' ); ?></a>
                                <button type="button" class="button" id="aied-lab-rebuild"><?php esc_html_e( 'Rebuild', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                                <button type="button" class="button" id="aied-lab-handoff-2"><?php esc_html_e( 'Copy instruction for my assistant', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                            </p>
                            <p class="description"><?php esc_html_e( 'Rebuild saves your latest answers and builds the page again. It replaces the draft only if nobody changed it since; otherwise it makes a new draft and leaves the old page as it is.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                        </div>
                        <?php self::stepNav( 5 ); ?>
                    </section>
                </div>

                <div class="aied-lab-preview">
                    <div class="aied-lab-preview__bar">
                        <strong><?php esc_html_e( 'Live preview', 'jhmg-ai-editor-for-divi-5' ); ?></strong>
                        <span id="aied-lab-preview-state" class="aied-muted"></span>
                        <span class="aied-lab-widths" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'jhmg-ai-editor-for-divi-5' ); ?>">
                            <button type="button" class="button button-small" data-width="desktop" aria-pressed="true"><?php esc_html_e( 'Desktop', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                            <button type="button" class="button button-small" data-width="tablet" aria-pressed="false"><?php esc_html_e( 'Tablet', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                            <button type="button" class="button button-small" data-width="phone" aria-pressed="false"><?php esc_html_e( 'Phone', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                        </span>
                        <button type="button" class="button button-small" id="aied-lab-repreview"><?php esc_html_e( 'Refresh preview', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                    </div>
                    <div id="aied-lab-frame-wrap" class="aied-lab-frame-wrap" data-width="desktop">
                        <iframe id="aied-lab-frame" class="aied-lab-frame" title="<?php esc_attr_e( 'Preview of your page', 'jhmg-ai-editor-for-divi-5' ); ?>"></iframe>
                    </div>
                    <p class="description"><?php esc_html_e( 'The preview shows a private draft named "Divi 5 Lab preview (temporary)". It is removed when you create the page, or after a day.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /** @return array<int,string> step number => its name */
    private static function steps(): array
    {
        return [
            1 => __( 'Goal and business', 'jhmg-ai-editor-for-divi-5' ),
            2 => __( 'Look and feel', 'jhmg-ai-editor-for-divi-5' ),
            3 => __( 'Sections', 'jhmg-ai-editor-for-divi-5' ),
            4 => __( 'Words and pictures', 'jhmg-ai-editor-for-divi-5' ),
            5 => __( 'Review and create', 'jhmg-ai-editor-for-divi-5' ),
        ];
    }

    /** Back and Next under a step. */
    private static function stepNav( int $n ): void
    {
        ?>
        <p class="aied-lab-stepnav">
            <?php if ( $n > 1 ) : ?>
                <button type="button" class="button" data-goto="<?php echo esc_attr( (string) ( $n - 1 ) ); ?>"><?php esc_html_e( 'Back', 'jhmg-ai-editor-for-divi-5' ); ?></button>
            <?php endif; ?>
            <?php if ( $n < 5 ) : ?>
                <button type="button" class="button button-primary" data-goto="<?php echo esc_attr( (string) ( $n + 1 ) ); ?>"><?php esc_html_e( 'Next', 'jhmg-ai-editor-for-divi-5' ); ?></button>
            <?php endif; ?>
        </p>
        <?php
    }

    /** One labelled text input with its help line. */
    private static function field( string $id, string $label, string $value, string $help ): void
    {
        ?>
        <div class="aied-lab-field">
            <label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
            <input type="text" class="regular-text" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" maxlength="200" aria-describedby="<?php echo esc_attr( $id . '-help' ); ?>">
            <p class="description" id="<?php echo esc_attr( $id . '-help' ); ?>"><?php echo esc_html( $help ); ?></p>
        </div>
        <?php
    }

    /** @return array<string,array{0:string,1:string}> goal => [label, hint] */
    private static function goals(): array
    {
        return [
            'sell'     => [ __( 'Sell something', 'jhmg-ai-editor-for-divi-5' ), __( 'A product or service with prices.', 'jhmg-ai-editor-for-divi-5' ) ],
            'book'     => [ __( 'Get bookings or calls', 'jhmg-ai-editor-for-divi-5' ), __( 'Visitors book a call, a visit or an appointment.', 'jhmg-ai-editor-for-divi-5' ) ],
            'signup'   => [ __( 'Collect sign-ups', 'jhmg-ai-editor-for-divi-5' ), __( 'A newsletter, a waiting list or a free trial.', 'jhmg-ai-editor-for-divi-5' ) ],
            'explain'  => [ __( 'Explain something', 'jhmg-ai-editor-for-divi-5' ), __( 'An idea, a service or how something works.', 'jhmg-ai-editor-for-divi-5' ) ],
            'showcase' => [ __( 'Show my work', 'jhmg-ai-editor-for-divi-5' ), __( 'A portfolio, projects or a gallery.', 'jhmg-ai-editor-for-divi-5' ) ],
        ];
    }

    /** @return array<string,array{0:string,1:string}> density => [label, hint] */
    private static function densities(): array
    {
        return [
            'airy'     => [ __( 'Airy', 'jhmg-ai-editor-for-divi-5' ), __( 'More space, fewer things per section.', 'jhmg-ai-editor-for-divi-5' ) ],
            'balanced' => [ __( 'Balanced', 'jhmg-ai-editor-for-divi-5' ), __( 'The usual amount of space.', 'jhmg-ai-editor-for-divi-5' ) ],
            'compact'  => [ __( 'Compact', 'jhmg-ai-editor-for-divi-5' ), __( 'Less space, more things per section.', 'jhmg-ai-editor-for-divi-5' ) ],
        ];
    }

    /** @return array<string,string> recipe role => the name a person reads */
    private static function roleLabels(): array
    {
        return [
            'hero'         => __( 'Opening', 'jhmg-ai-editor-for-divi-5' ),
            'header'       => __( 'Page header', 'jhmg-ai-editor-for-divi-5' ),
            'content'      => __( 'Introduction', 'jhmg-ai-editor-for-divi-5' ),
            'features'     => __( 'Benefits', 'jhmg-ai-editor-for-divi-5' ),
            'process'      => __( 'How it works', 'jhmg-ai-editor-for-divi-5' ),
            'testimonials' => __( 'What people say', 'jhmg-ai-editor-for-divi-5' ),
            'proof'        => __( 'Trust', 'jhmg-ai-editor-for-divi-5' ),
            'stats'        => __( 'Numbers', 'jhmg-ai-editor-for-divi-5' ),
            'pricing'      => __( 'Prices', 'jhmg-ai-editor-for-divi-5' ),
            'faq'          => __( 'Questions and answers', 'jhmg-ai-editor-for-divi-5' ),
            'cta'          => __( 'Call to action', 'jhmg-ai-editor-for-divi-5' ),
            'gallery'      => __( 'Gallery', 'jhmg-ai-editor-for-divi-5' ),
            'team'         => __( 'Team', 'jhmg-ai-editor-for-divi-5' ),
            'contact'      => __( 'Contact', 'jhmg-ai-editor-for-divi-5' ),
            'blog'         => __( 'Latest posts', 'jhmg-ai-editor-for-divi-5' ),
            'shop'         => __( 'Products', 'jhmg-ai-editor-for-divi-5' ),
            'transition'   => __( 'Divider band', 'jhmg-ai-editor-for-divi-5' ),
        ];
    }

    /** @return array<string,string> slot role => the field label a person reads */
    private static function slotRoleLabels(): array
    {
        return [
            'eyebrow'      => __( 'Small line above the headline', 'jhmg-ai-editor-for-divi-5' ),
            'headline'     => __( 'Headline', 'jhmg-ai-editor-for-divi-5' ),
            'subhead'      => __( 'Supporting line', 'jhmg-ai-editor-for-divi-5' ),
            'body'         => __( 'Text', 'jhmg-ai-editor-for-divi-5' ),
            'button_label' => __( 'Button label', 'jhmg-ai-editor-for-divi-5' ),
            'link'         => __( 'Button link', 'jhmg-ai-editor-for-divi-5' ),
            'item_title'   => __( 'Item title', 'jhmg-ai-editor-for-divi-5' ),
            'picture'      => __( 'Picture', 'jhmg-ai-editor-for-divi-5' ),
            'alt'          => __( 'Picture description (for screen readers)', 'jhmg-ai-editor-for-divi-5' ),
            'figure'       => __( 'Number', 'jhmg-ai-editor-for-divi-5' ),
            'attribution'  => __( 'Name or role', 'jhmg-ai-editor-for-divi-5' ),
            'field_label'  => __( 'Form field label', 'jhmg-ai-editor-for-divi-5' ),
        ];
    }

    /** @return array<string,string> the words the scripts show, all translatable */
    private static function i18n(): array
    {
        return [
            'untitled'          => __( 'New page', 'jhmg-ai-editor-for-divi-5' ),
            'sectionTitle'      => __( 'Section %1$d: %2$s', 'jhmg-ai-editor-for-divi-5' ),
            'include'           => __( 'Include this section', 'jhmg-ai-editor-for-divi-5' ),
            'leftOut'           => __( 'Left out', 'jhmg-ai-editor-for-divi-5' ),
            'moveUp'            => __( 'Move up', 'jhmg-ai-editor-for-divi-5' ),
            'moveDown'          => __( 'Move down', 'jhmg-ai-editor-for-divi-5' ),
            'moveUpLabel'       => __( 'Move section %d up', 'jhmg-ai-editor-for-divi-5' ),
            'moveDownLabel'     => __( 'Move section %d down', 'jhmg-ai-editor-for-divi-5' ),
            'moved'             => __( 'Section moved to position %d.', 'jhmg-ai-editor-for-divi-5' ),
            'layout'            => __( 'Layout', 'jhmg-ai-editor-for-divi-5' ),
            'layoutItems'       => __( '%1$s (%2$d items)', 'jhmg-ai-editor-for-divi-5' ),
            'layoutOneItem'     => __( '%s (1 item)', 'jhmg-ai-editor-for-divi-5' ),
            'layoutTooFew'      => __( '%1$s (holds %2$d items, you filled %3$d)', 'jhmg-ai-editor-for-divi-5' ),
            'layoutCurrent'     => __( '%s (this layout)', 'jhmg-ai-editor-for-divi-5' ),
            'itemsHint'         => __( 'Layouts that hold fewer items than you have filled in cannot be picked. Empty an item first if you want one of them.', 'jhmg-ai-editor-for-divi-5' ),
            'swapConfirm'       => __( 'Some words you wrote do not fit this layout and will be removed: %s. Change the layout?', 'jhmg-ai-editor-for-divi-5' ),
            'swapDropped'       => __( 'Layout changed. These words did not fit and were removed: %s.', 'jhmg-ai-editor-for-divi-5' ),
            'swapDone'          => __( 'Layout changed. Your words were kept.', 'jhmg-ai-editor-for-divi-5' ),
            'recipeGone'        => __( 'This layout is no longer in the library. Pick another layout for this section; the words of the other sections are kept.', 'jhmg-ai-editor-for-divi-5' ),
            'slotsChanged'      => __( 'This layout has changed since your words were saved. Some of them no longer have a place: %s. Pick a layout for this section or retype them.', 'jhmg-ai-editor-for-divi-5' ),
            'pickReplacement'   => __( 'Pick a replacement layout', 'jhmg-ai-editor-for-divi-5' ),
            'noSections'        => __( 'Include at least one section.', 'jhmg-ai-editor-for-divi-5' ),
            'resuggestConfirm'  => __( 'Replace your sections with the suggestion for this goal? Words you wrote for them are removed.', 'jhmg-ai-editor-for-divi-5' ),
            'resuggestKept'     => __( 'Your sections were kept. Use "Suggest sections again" for the suggestion that fits this choice.', 'jhmg-ai-editor-for-divi-5' ),
            'numbered'          => __( '%1$s %2$d', 'jhmg-ai-editor-for-divi-5' ),
            'emptyKeepsSample'  => __( 'Leave it empty to keep the sample text.', 'jhmg-ai-editor-for-divi-5' ),
            'linkHint'          => __( 'An https:// address, mailto:, tel:, #a-section or a page of this site such as /contact/.', 'jhmg-ai-editor-for-divi-5' ),
            'figureHint'        => __( 'A plain number such as 1250. Signs like % or + come from the counter itself.', 'jhmg-ai-editor-for-divi-5' ),
            'mediaButton'       => __( 'Choose from Media Library', 'jhmg-ai-editor-for-divi-5' ),
            'mediaTitle'        => __( 'Choose a picture', 'jhmg-ai-editor-for-divi-5' ),
            'mediaUse'          => __( 'Use this picture', 'jhmg-ai-editor-for-divi-5' ),
            'mediaNoAddress'    => __( 'That picture\'s address cannot be used. Pick a picture stored on this site.', 'jhmg-ai-editor-for-divi-5' ),
            'packLabel'         => __( 'Or a built-in picture', 'jhmg-ai-editor-for-divi-5' ),
            'packNone'          => __( 'Keep the sample picture', 'jhmg-ai-editor-for-divi-5' ),
            'urlLabel'          => __( 'Or type a picture address', 'jhmg-ai-editor-for-divi-5' ),
            'urlHint'           => __( 'An https:// address or a path on this site starting with /.', 'jhmg-ai-editor-for-divi-5' ),
            'pictureChosen'     => __( 'Chosen: %s', 'jhmg-ai-editor-for-divi-5' ),
            'pictureSample'     => __( 'The sample picture is used.', 'jhmg-ai-editor-for-divi-5' ),
            'clearPicture'      => __( 'Use the sample picture', 'jhmg-ai-editor-for-divi-5' ),
            'previewing'        => __( 'Updating the preview...', 'jhmg-ai-editor-for-divi-5' ),
            'previewReady'      => __( 'Preview is up to date.', 'jhmg-ai-editor-for-divi-5' ),
            'previewApprox'     => __( 'The real preview could not be made, so this is a rough approximation.', 'jhmg-ai-editor-for-divi-5' ),
            'previewFailed'     => __( 'The preview could not be built. The problems are listed on the last step.', 'jhmg-ai-editor-for-divi-5' ),
            'verdictOk'         => __( 'Passed: the layout is valid Divi 5.', 'jhmg-ai-editor-for-divi-5' ),
            'verdictBad'        => __( 'Not passed: fix the problems below before creating the page.', 'jhmg-ai-editor-for-divi-5' ),
            'verdictUnknown'    => __( 'Not checked yet.', 'jhmg-ai-editor-for-divi-5' ),
            'okMark'            => __( 'OK', 'jhmg-ai-editor-for-divi-5' ),
            'badMark'           => __( 'Problem', 'jhmg-ai-editor-for-divi-5' ),
            'problemAt'         => __( '%1$s: %2$s', 'jhmg-ai-editor-for-divi-5' ),
            'pageLevel'         => __( 'Page', 'jhmg-ai-editor-for-divi-5' ),
            'unfilledNone'      => __( 'None: every place has your words.', 'jhmg-ai-editor-for-divi-5' ),
            'unfilledOne'       => __( 'One place still shows sample text. The page can be created anyway; replace it before you publish.', 'jhmg-ai-editor-for-divi-5' ),
            'unfilledCount'     => __( '%d places still show sample text. The page can be created anyway; replace them before you publish.', 'jhmg-ai-editor-for-divi-5' ),
            'warningsTitle'     => __( 'Worth checking', 'jhmg-ai-editor-for-divi-5' ),
            'saving'            => __( 'Saving your brief...', 'jhmg-ai-editor-for-divi-5' ),
            'copied'            => __( 'Instruction copied. Paste it into your assistant. Its words appear here when you press "Get my assistant\'s words", or by themselves within a minute.', 'jhmg-ai-editor-for-divi-5' ),
            'copyManual'        => __( 'Your browser did not allow copying. The instruction is selected in the box "Instruction for your assistant" on this step: copy it yourself (Ctrl+C or Cmd+C).', 'jhmg-ai-editor-for-divi-5' ),
            'syncFailed'        => __( 'Your assistant\'s latest words could not be read, so nothing was sent (sending now could replace them). Check that you are still logged in, then try again.', 'jhmg-ai-editor-for-divi-5' ),
            'checking'          => __( 'Looking for your assistant\'s words...', 'jhmg-ai-editor-for-divi-5' ),
            'mergedOne'         => __( 'One place was filled by your assistant.', 'jhmg-ai-editor-for-divi-5' ),
            'mergedSome'        => __( '%d places were filled by your assistant.', 'jhmg-ai-editor-for-divi-5' ),
            'mergedNone'        => __( 'No new words from your assistant yet.', 'jhmg-ai-editor-for-divi-5' ),
            'mergedKeptOne'     => __( 'One place you changed here was kept as you wrote it.', 'jhmg-ai-editor-for-divi-5' ),
            'mergedKept'        => __( '%d places you changed here were kept as you wrote them.', 'jhmg-ai-editor-for-divi-5' ),
            'needsSaveFirst'    => __( 'Copy the instruction for your assistant first, so there is a saved brief to read.', 'jhmg-ai-editor-for-divi-5' ),
            'creating'          => __( 'Creating the draft...', 'jhmg-ai-editor-for-divi-5' ),
            'rebuilding'        => __( 'Rebuilding the page...', 'jhmg-ai-editor-for-divi-5' ),
            'createdDraft'      => __( 'A draft page was created.', 'jhmg-ai-editor-for-divi-5' ),
            'createdUnfilledOne' => __( 'One place still shows sample text; replace it before you publish.', 'jhmg-ai-editor-for-divi-5' ),
            'createdUnfilled'   => __( '%d places still show sample text; replace them before you publish.', 'jhmg-ai-editor-for-divi-5' ),
            'createdDuplicate'  => __( 'This brief was just turned into a draft page, so that page is shown instead of making a second one.', 'jhmg-ai-editor-for-divi-5' ),
            'rebuiltNewDraft'   => __( 'A new draft page was made, because %s. The earlier page was left as it is.', 'jhmg-ai-editor-for-divi-5' ),
            'reasonEdited'      => __( 'the page was changed after it was built', 'jhmg-ai-editor-for-divi-5' ),
            'reasonPublished'   => __( 'the page is no longer a draft', 'jhmg-ai-editor-for-divi-5' ),
            'reasonTrashed'     => __( 'the page is in the trash', 'jhmg-ai-editor-for-divi-5' ),
            'reasonMissing'     => __( 'the page no longer exists, or was never built', 'jhmg-ai-editor-for-divi-5' ),
            'reasonNoUndo'      => __( 'its current content could not be kept in the page history, so replacing it could not be undone', 'jhmg-ai-editor-for-divi-5' ),
            'reasonNoAccess'    => __( 'you are not allowed to edit that page', 'jhmg-ai-editor-for-divi-5' ),
            'fixProblems'       => __( 'Nothing was saved. Fix the problems listed on the last step, then try again.', 'jhmg-ai-editor-for-divi-5' ),
            'rebuiltOverwritten' => __( 'The draft was rebuilt from your latest answers. Its page title was kept as it was (change it in the page editor if you want). The earlier content can be restored from the page history on the Dashboard.', 'jhmg-ai-editor-for-divi-5' ),
            'requestFailed'     => __( 'That did not work. Check that you are still logged in, then try again.', 'jhmg-ai-editor-for-divi-5' ),
            'leaveWarning'      => __( 'Your answers are not saved yet.', 'jhmg-ai-editor-for-divi-5' ),
        ];
    }

    /** @return array{name:string,tagline:string} the site title and tagline as plain text (WordPress stores them escaped) */
    private static function site(): array
    {
        $plain = static fn ( string $s ): string => html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        return [
            'name'    => $plain( (string) get_bloginfo( 'name' ) ),
            'tagline' => $plain( (string) get_bloginfo( 'description' ) ),
        ];
    }

    /**
     * Every recipe with what the screen needs: its role and layout, how many items it holds and its slots (id, role,
     * kind and the sample text, which the form shows as a placeholder).
     *
     * @return array<string,array<string,mixed>>
     */
    private static function recipes(): array
    {
        $out = [];
        foreach ( SectionRecipes::names() as $name ) {
            $tags  = SectionRecipes::tags( $name ) ?? [];
            $slots = [];
            foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $name ) ) as $slot ) {
                $sample  = 'picture' === $slot['kind'] ? '' : wp_strip_all_tags( (string) $slot['sample'] );
                $slots[] = [
                    'id'     => $slot['id'],
                    'role'   => $slot['role'],
                    'kind'   => $slot['kind'],
                    'sample' => mb_substr( $sample, 0, self::SAMPLE_CHARS ),
                ];
            }
            $out[ $name ] = [
                'role'        => (string) ( $tags['role'] ?? '' ),
                'layout'      => (string) ( $tags['layout'] ?? '' ),
                'items'       => StudioStack::itemCount( $name ),
                'needsImages' => ! empty( $tags['needs_images'] ),
                'slots'       => $slots,
            ];
        }

        return $out;
    }

    /** @return list<array{token:string,alt:string,src:string}> the built-in pictures, with their site-local addresses */
    private static function pack(): array
    {
        $base = plugins_url( 'assets/images/', AI_EDITOR_DIVI5_FILE );
        $out  = [];
        foreach ( ImagePack::manifest()['images'] as $image ) {
            $out[] = [ 'token' => (string) $image['token'], 'alt' => (string) ( $image['alt'] ?? '' ), 'src' => $base . rawurlencode( (string) $image['file'] ) ];
        }

        return $out;
    }

    /** @return list<array{title:string,path:string}> the site's published pages, offered as link suggestions */
    private static function pages(): array
    {
        if ( ! function_exists( 'get_pages' ) || ! function_exists( 'get_permalink' ) || ! function_exists( 'wp_make_link_relative' ) ) {
            return [];
        }
        $out = [];
        foreach ( (array) get_pages( [ 'post_status' => 'publish', 'number' => self::MAX_PAGES, 'sort_column' => 'menu_order,post_title' ] ) as $page ) {
            $link = get_permalink( $page );
            if ( ! is_string( $link ) || '' === $link ) {
                continue;
            }
            $out[] = [ 'title' => html_entity_decode( (string) $page->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'path' => (string) wp_make_link_relative( $link ) ];
        }

        return $out;
    }

    /** @return ?array{id:int,brief:array<string,mixed>,page_id:?int} the brief named by ?brief=<id>, when it is this user's */
    private static function storedBrief( int $user ): ?array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: it only selects which of the user's own briefs to show.
        $raw = $_GET['brief'] ?? null;
        if ( ! is_string( $raw ) || 1 !== preg_match( '/^[1-9][0-9]{0,9}\z/', $raw ) || $user <= 0 ) {
            return null;
        }
        try {
            $row = StudioStore::get( $user, (int) $raw );
        } catch ( \Throwable $e ) {
            return null;
        }
        if ( null === $row ) {
            return null;
        }

        return [ 'id' => (int) $row['id'], 'brief' => (array) $row['brief'], 'page_id' => null === $row['page_id'] ? null : (int) $row['page_id'] ];
    }
}
