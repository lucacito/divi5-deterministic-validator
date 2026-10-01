# Extending JHMG AI Editor for Divi 5

The plugin exposes a small set of generic hooks so a separate add-on plugin can
add MCP tools, REST routes, OpenAPI entries and an admin screen. The base plugin
never checks a licence and does not know about any add-on: with no listener
registered, behaviour is identical to a build without these hooks.

Hook names are a stable public API.

## Hooks

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `jhmg_aied_mcp_tools` | filter | `( array $tools ): array` | Append MCP tool definitions (`name`, `description`, `inputSchema`) to `tools/list`. |
| `jhmg_aied_mcp_call` | filter | `( null $response, string $name, array $arguments, mixed $rpcId )` | Claim a `tools/call` for a tool the base plugin does not know. Return a `WP_REST_Response` to claim it; anything else falls through to the `-32602 Unknown tool` error. |
| `jhmg_aied_register_rest_routes` | action | `( string $namespace )` | Fires at the end of `RestController::register_routes()`. Call `register_rest_route( $namespace, ... )` for your own routes. |
| `jhmg_aied_openapi_paths` | filter | `( array $paths, string $base ): array` | Add entries to the OpenAPI `paths` map. |
| `jhmg_aied_openapi_schemas` | filter | `( array $schemas ): array` | Add entries to `components.schemas`. |
| `jhmg_aied_admin_tabs` | filter | `( array $tabs ): array` | Add admin tabs as `slug => label`. Built-in slugs cannot be overridden. |
| `jhmg_aied_render_admin_tab` | action | `( string $tab )` | Render the screen for one of your tab slugs. |
| `jhmg_aied_image_token` | filter | `( ?string $url, string $token ): ?string` | Replace the URL of one bundled image when a recipe is served. |
| `jhmg_aied_image_guide` | filter | `( string $markdown ): string` | Append to or rewrite the image guide the AI receives from `get_image_guide`. |

## Rules for add-on code

1. **Check capabilities yourself.** The base plugin authenticates the request
   (Bearer API key or logged-in user) but does not authorise individual add-on
   tools. In your `jhmg_aied_mcp_call` handler and REST `permission_callback`
   call `current_user_can()` for the capability the action really needs, and
   return a JSON-RPC error response when it fails.
2. **Return built-in response shapes.** Use
   `['jsonrpc' => '2.0', 'id' => $rpcId, 'result' => ['content' => [['type' => 'text', 'text' => '...']]]]`
   for success and `'error' => ['code' => -32602, 'message' => '...']` for errors.
3. **Only claim your own tool names.** Return the incoming `$response` untouched
   for any name you do not handle so other add-ons can claim theirs.
4. **Write through the validator.** Any tool that saves Divi layout content
   should validate it with the bundled validator before touching the database.
5. **Keep OpenAPI additions compatible with the ChatGPT importer:** every
   `description` and `summary` at most 300 characters, and no bare
   `type: object` without `properties` (use `additionalProperties` or list the
   fields).

## Image tokens

Section recipes store their images as `{{aied:image:<token>}}` placeholders (for
example `{{aied:image:card-blue-1}}`). When a recipe is served, each token is
resolved to the site-local URL of the matching file in `assets/images/` (see
`manifest.json` there). A token that is not in the manifest resolves to the
manifest's fallback image, so no `{{aied:` text ever reaches the AI.

- `jhmg_aied_image_token` receives the resolved bundled URL and the token name.
  Return a different URL to serve that image from somewhere else (for example a
  Media Library or CDN image), or return the incoming `$url` / an empty value to
  keep the bundled one. **The plugin does not escape or validate the URL you
  return.** It is placed into the layout as-is, so an add-on must return a safe,
  absolute `http(s)` URL it controls or has already sanitised (for example with
  `esc_url_raw()`).
- `jhmg_aied_image_guide` receives the guide as **Markdown** and must return
  Markdown. Use it to add your own image-sourcing instructions after the built-in
  Media Library and bundled-pack sections. Return the incoming string untouched
  when you have nothing to add.

With no listener registered, both hooks leave behaviour unchanged.

## Minimal example

```php
add_filter( 'jhmg_aied_mcp_tools', function ( array $tools ): array {
    $tools[] = [
        'name'        => 'my_tool',
        'description' => 'Does one thing.',
        'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass() ],
    ];
    return $tools;
} );

add_filter( 'jhmg_aied_mcp_call', function ( $response, string $name, array $args, $id ) {
    if ( 'my_tool' !== $name ) {
        return $response;
    }
    if ( ! current_user_can( 'edit_pages' ) ) {
        return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => -32602, 'message' => 'Not allowed.' ] ], 200 );
    }
    return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => [ 'content' => [ [ 'type' => 'text', 'text' => 'ok' ] ] ] ], 200 );
}, 10, 4 );
```
