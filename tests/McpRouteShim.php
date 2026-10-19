<?php

declare(strict_types=1);

// A route-recording register_rest_route for classes in the plugin namespace only (an unqualified call inside
// AiEditorDivi5\WP finds it before any global function), so it cannot clash with the Pro suite's global shim.
namespace AiEditorDivi5\WP {
    function register_rest_route( string $namespace, string $route, array $args = [] ): bool
    {
        $GLOBALS['__mcp_test_routes'][ '/' . $namespace . $route ][] = $args;

        return true;
    }
}
