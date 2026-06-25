<?php
/**
 * Plugin Name: Divi 5 Deterministic Validator
 * Plugin URI:  https://github.com/jhmg/divi5-deterministic-validator
 * Description: REST API endpoints for validating and safely updating Divi 5 layouts. Used by AI agent tools.
 * Version:     1.0.0
 * Requires PHP: 8.1
 * Author:      JHMG
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

add_action('rest_api_init', function (): void {
    $ctrl = new Divi5Validator\WP\RestController();
    $ctrl->register_routes();
});
