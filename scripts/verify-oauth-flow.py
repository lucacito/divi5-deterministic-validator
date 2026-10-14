#!/usr/bin/env python3
"""
Real-site check of the OAuth sign-in over HTTP, acting as a client in the order Claude documents:
unauthenticated MCP call -> 401 + WWW-Authenticate -> protected-resource metadata -> authorization-server
metadata at the real web-server root -> Dynamic Client Registration -> browser sign-in and consent -> token
exchange with PKCE -> MCP calls with the token -> refresh and rotation -> replay -> revocation -> switch off.

Run: make verify-oauth   (dev site only: it switches OAuth on and off, creates three throwaway users, and
                          removes everything again; the API key and its owner are never touched)
Stdlib only. Setup and cleanup go through wp-cli in the Docker wpcli container.
"""
import base64
import hashlib
import html
import json
import os
import re
import secrets
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

SITE = os.environ.get("SITE", "http://localhost:8181").rstrip("/")
MCP = SITE + "/wp-json/ai-editor-divi5/v1/mcp"
CALLBACK = "https://claude.ai/api/mcp/auth_callback"
PREFIX = "aiedoauth"
FAILS = []


def check(ok, label):
    print(("PASS  " if ok else "FAIL  ") + label)
    if not ok:
        FAILS.append(label)


def wp(*args, stdin=None):
    cmd = ["docker", "compose", "exec", "-T", "wpcli", "php", "-d", "memory_limit=1G", "/usr/local/bin/wp", *args]
    out = subprocess.run(cmd, capture_output=True, text=True, input=stdin)
    if out.returncode != 0:
        raise RuntimeError("wp %s failed: %s %s" % (" ".join(args), out.stdout, out.stderr))
    return out.stdout.strip()


def wp_eval(code):
    return wp("eval", code)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def http(method, url, headers=None, data=None, cookies=None):
    """Returns (status, lower-cased headers, body text). Never follows redirects."""
    h = dict(headers or {})
    if cookies:
        h["Cookie"] = cookies
    body = None
    if data is not None:
        body = data if isinstance(data, bytes) else data.encode()
    req = urllib.request.Request(url, data=body, headers=h, method=method)
    try:
        r = OPENER.open(req, timeout=30)
        return r.status, {k.lower(): v for k, v in r.headers.items()}, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, {k.lower(): v for k, v in e.headers.items()}, e.read().decode("utf-8", "replace")


def mcp(message, token=None, key=None):
    headers = {"Content-Type": "application/json"}
    if token or key:
        headers["Authorization"] = "Bearer " + (token or key)
    return http("POST", MCP, headers, json.dumps(message))


def jbody(body):
    try:
        return json.loads(body)
    except Exception:
        return {}


def pkce():
    verifier = base64.urlsafe_b64encode(secrets.token_bytes(48)).decode().rstrip("=")
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip("=")
    return verifier, challenge


def cookie_for(user_id):
    return wp_eval(
        '$u=%d;$e=time()+3600;$t=WP_Session_Tokens::get_instance($u)->create($e);'
        'echo LOGGED_IN_COOKIE."=".wp_generate_auth_cookie($u,$e,"logged_in",$t);' % user_id
    )


def form_fields(page):
    return {html.unescape(m.group(1)): html.unescape(m.group(2)) for m in re.finditer(r'<input type="hidden" name="([^"]+)" value="([^"]*)">', page)}


def authorize_query(client_id, challenge, state, redirect=CALLBACK, extra=None):
    q = {"response_type": "code", "client_id": client_id, "redirect_uri": redirect, "state": state,
         "code_challenge": challenge, "code_challenge_method": "S256", "resource": MCP}
    q.update(extra or {})
    return SITE + "/wp-admin/admin-post.php?action=aied_oauth_authorize&" + urllib.parse.urlencode(q)


def token_request(params):
    return http("POST", SITE + "/wp-json/ai-editor-divi5/v1/oauth/token",
                {"Content-Type": "application/x-www-form-urlencoded"}, urllib.parse.urlencode(params))


def main():
    if not re.match(r"^https?://(localhost|127\.0\.0\.1)(:\d+)?$", SITE):
        sys.exit("This check switches OAuth on and creates users: it only runs against a local dev site, not " + SITE)

    users = {}
    try:
        api_key = wp_eval("echo AiEditorDivi5\\WP\\ApiKey::get();")
        key_owner = wp_eval("echo AiEditorDivi5\\WP\\ApiKey::getUserId();")
        check(len(api_key) > 20, "the dev site has an API key (it is only read, never changed)")
        if wp_eval("echo AiEditorDivi5\\WP\\OAuthSettings::enabled() ? 'on' : 'off';") != "off":
            sys.exit("OAuth is already switched on on this site. Switch it off first.")

        # ---- 1. switched off: the plugin behaves exactly as before ------------------------------
        print("\n== OAuth switched off ==")
        s, h, b = http("GET", SITE + "/.well-known/oauth-authorization-server")
        check(s == 404, "the authorization-server document is not served (%d)" % s)
        s, h, b = mcp({"jsonrpc": "2.0", "id": 1, "method": "ping"})
        check(s == 401 and "www-authenticate" not in h, "an unauthenticated MCP call is 401 without any WWW-Authenticate (%d)" % s)
        s, h, b = http("POST", SITE + "/wp-json/ai-editor-divi5/v1/oauth/register", {"Content-Type": "application/json"}, "{}")
        check(s == 404, "the registration route does not exist (%d)" % s)
        s, h, b = mcp({"jsonrpc": "2.0", "id": 1, "method": "tools/list"}, key=api_key)
        check(s == 200 and len(jbody(b).get("result", {}).get("tools", [])) > 0, "the API key still works")
        s, h, b = mcp({"jsonrpc": "2.0", "id": 1, "method": "tools/list"}, token="aied_at_" + "0" * 64)
        check(s == 401, "an OAuth-looking token is refused while OAuth is off (%d)" % s)

        # ---- 2. switch on --------------------------------------------------------------------
        print("\n== Switch on, discovery ==")
        check(wp_eval("echo AiEditorDivi5\\WP\\OAuthSettings::enable() ? 'yes' : 'no';") == "yes", "enabling OAuth creates the table")
        s, h, b = mcp({"jsonrpc": "2.0", "id": 1, "method": "ping"})
        wa = h.get("www-authenticate", "")
        m = re.search(r'resource_metadata="([^"]+)"', wa)
        check(s == 401 and m is not None and 'scope="aied:edit"' in wa, "an unauthenticated MCP call is 401 with WWW-Authenticate: %s" % wa)
        s, h, b = http("GET", m.group(1) if m else SITE + "/x")
        prm = jbody(b)
        check(s == 200 and prm.get("resource") == MCP, "protected-resource metadata: resource equals the connector URL exactly")
        check(prm.get("authorization_servers") == [SITE], "authorization_servers lists the issuer first")
        s, h, b = http("GET", SITE + "/.well-known/oauth-authorization-server")
        asm = jbody(b)
        check(s == 200 and "json" in h.get("content-type", ""), "the authorization-server document is served at the real web-server root (%d)" % s)
        check(asm.get("issuer") == SITE, "issuer is the site address")
        check(asm.get("code_challenge_methods_supported") == ["S256"], "S256 is advertised (Claude refuses to continue without it)")
        check("client_id_metadata_document_supported" not in asm, "Client ID Metadata Documents are not advertised")
        check(asm.get("registration_endpoint", "").endswith("/oauth/register") and asm.get("token_endpoint", "").endswith("/oauth/token"), "endpoints are advertised")
        s, h, b = http("GET", SITE + "/.well-known/openid-configuration")
        check(s == 200 and jbody(b) == asm, "the OpenID path serves the same document")
        s, h, b = http("GET", SITE + "/.well-known/oauth-protected-resource/wp-json/ai-editor-divi5/v1/mcp")
        check(s == 200 and jbody(b) == prm, "the path-aware protected-resource URL works too")
        s, h, b = http("GET", SITE + "/.well-known/oauth-protected-resource")
        check(s == 200 and jbody(b) == prm, "and the plain one")
        s, h, b = http("HEAD", SITE + "/.well-known/oauth-authorization-server")
        check(s == 200 and b == "", "HEAD answers without a body")
        s, h, b = http("POST", SITE + "/.well-known/oauth-authorization-server", {}, "x=1")
        check(s != 200 or "issuer" not in b, "POST to a well-known path is not answered by the plugin")
        s, h, b = http("GET", SITE + "/.well-known/security.txt")
        check(s == 404 and "issuer" not in b, "unrelated well-known paths are untouched (%d)" % s)
        s, h, b = http("GET", SITE + "/.well-known/oauth-authorization-server/evil")
        check(s == 404, "unknown suffixes are not answered (%d)" % s)

        # ---- 3. registration ---------------------------------------------------------------------
        print("\n== Dynamic Client Registration ==")
        reg_url = asm.get("registration_endpoint", SITE + "/x")
        s, h, b = http("POST", reg_url, {"Content-Type": "application/json"}, json.dumps({"redirect_uris": ["https://evil.example/cb"], "client_name": "Evil"}))
        check(s == 400 and jbody(b).get("error") == "invalid_redirect_uri", "a foreign redirect address is refused (%d %s)" % (s, jbody(b).get("error")))
        s, h, b = http("POST", reg_url, {"Content-Type": "application/json"}, "not json")
        check(s == 400, "a body that is not JSON is refused (%d)" % s)
        s, h, b = http("POST", reg_url, {"Content-Type": "application/json"}, json.dumps({"redirect_uris": [CALLBACK], "client_name": "<img src=x onerror=alert(1)>Claude", "token_endpoint_auth_method": "none"}))
        client = jbody(b)
        check(s == 201 and client.get("client_id", "").startswith("aied_cl_") and "client_secret" not in client, "a Claude-style registration succeeds as a public client (%d)" % s)
        check("<" not in client.get("client_name", "<"), "the client name is stored as plain text")
        client_id = client.get("client_id", "")
        s, h, b = http("POST", reg_url, {"Content-Type": "application/json"}, json.dumps({"redirect_uris": ["http://localhost:3118/callback"], "client_name": "Claude Code"}))
        check(s == 201, "a loopback redirect (Claude Code) registers (%d)" % s)

        # ---- 4. users ---------------------------------------------------------------------------
        for role in ("administrator", "editor", "subscriber"):
            name = "%s_%s" % (PREFIX, role)
            uid = wp("user", "create", name, name + "@example.test", "--role=" + role, "--user_pass=" + secrets.token_hex(12), "--porcelain")
            users[role] = int(uid)

        # ---- 5. the browser: sign in and consent --------------------------------------------------
        print("\n== Sign-in and consent ==")
        verifier, challenge = pkce()
        state = secrets.token_hex(8)
        url = authorize_query(client_id, challenge, state)
        s, h, b = http("GET", url)
        loc = h.get("location", "")
        check(s == 302 and "wp-login.php" in loc and "redirect_to" in loc, "not signed in: sent to the WordPress login (%d %s)" % (s, loc[:80]))
        admin_cookie = cookie_for(users["administrator"])
        s, h, b = http("GET", url, cookies=admin_cookie)
        check(s == 200 and "Allow" in b and html.escape("Claude") in b, "signed in: the consent page shows the app (%d)" % s)
        check("<strong>claude.ai</strong>" in b, "it shows where the browser will be sent")
        check("&lt;img" in b or "<img" not in b, "the app name is escaped on the page")
        check(h.get("x-frame-options") == "DENY" and "frame-ancestors 'none'" in h.get("content-security-policy", ""), "it cannot be framed")
        check("no-store" in h.get("cache-control", "") or "no-cache" in h.get("cache-control", ""), "it is not cached")
        check(h.get("referrer-policy") == "no-referrer", "it sends no referrer")
        fields = form_fields(b)
        check(fields.get("client_id") == client_id and fields.get("_wpnonce"), "the form carries the request and a nonce")

        sub_cookie = cookie_for(users["subscriber"])
        s, h, b = http("GET", url, cookies=sub_cookie)
        check(s == 403 and "not allowed to edit pages" in b, "a user who cannot edit pages cannot approve (%d)" % s)
        s, h, b = http("GET", authorize_query(client_id, challenge, state, redirect="https://evil.example/cb"), cookies=admin_cookie)
        check(s == 400 and "location" not in h, "an unregistered redirect address is NEVER redirected to (%d)" % s)
        s, h, b = http("GET", authorize_query("aied_cl_" + "0" * 24, challenge, state), cookies=admin_cookie)
        check(s == 400 and "location" not in h, "an unknown client is NEVER redirected to (%d)" % s)
        s, h, b = http("GET", authorize_query(client_id, challenge, state, extra={"code_challenge_method": "plain"}), cookies=admin_cookie)
        check(s == 302 and "error=invalid_request" in h.get("location", "") and ("state=" + state) in h.get("location", ""), "plain PKCE is rejected back to the app with state")
        s, h, b = http("GET", authorize_query(client_id, challenge, state, extra={"response_type": "token"}), cookies=admin_cookie)
        check(s == 302 and "error=unsupported_response_type" in h.get("location", ""), "the implicit flow is rejected")
        s, h, b = http("GET", authorize_query(client_id, challenge, state, extra={"resource": "https://evil.example/mcp"}), cookies=admin_cookie)
        check(s == 302 and "error=invalid_target" in h.get("location", ""), "a foreign resource is rejected")

        # nonce handling
        post_url = SITE + "/wp-admin/admin-post.php?action=aied_oauth_authorize"
        bad = dict(fields, decision="approve", _wpnonce="deadbeef00")
        s, h, b = http("POST", post_url, {"Content-Type": "application/x-www-form-urlencoded"}, urllib.parse.urlencode(bad), cookies=admin_cookie)
        check(s == 403 and "location" not in h, "approving with a wrong nonce is refused (%d)" % s)
        s, h, b = http("POST", post_url, {"Content-Type": "application/x-www-form-urlencoded"}, urllib.parse.urlencode(dict(fields, decision="approve")))
        check(s in (302, 403) and "code=" not in h.get("location", ""), "approving without being signed in gives no code (%d)" % s)
        tampered = dict(fields, decision="approve", state="other-state")
        s, h, b = http("POST", post_url, {"Content-Type": "application/x-www-form-urlencoded"}, urllib.parse.urlencode(tampered), cookies=admin_cookie)
        check(s == 403 and "code=" not in h.get("location", ""), "the nonce is bound to the request: a changed state is refused (%d)" % s)
        s, h, b = http("POST", post_url, {"Content-Type": "application/x-www-form-urlencoded"}, urllib.parse.urlencode(dict(fields, decision="deny")), cookies=admin_cookie)
        check(s == 302 and h.get("location", "").startswith(CALLBACK + "?error=access_denied") and ("state=" + state) in h.get("location", ""), "Deny returns access_denied with the state")

        s, h, b = http("POST", post_url, {"Content-Type": "application/x-www-form-urlencoded"}, urllib.parse.urlencode(dict(fields, decision="approve")), cookies=admin_cookie)
        loc = h.get("location", "")
        q = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)
        code = (q.get("code") or [""])[0]
        check(s == 302 and loc.startswith(CALLBACK + "?") and code.startswith("aied_ac_") and q.get("state") == [state], "Approve redirects to the registered address with a code and the state")

        # ---- 6. token endpoint -------------------------------------------------------------------
        print("\n== Token endpoint ==")
        tok_params = {"grant_type": "authorization_code", "code": code, "code_verifier": verifier, "client_id": client_id, "redirect_uri": CALLBACK, "resource": MCP}
        s, h, b = token_request(dict(tok_params, code_verifier="x" * 43))
        check(s == 400 and jbody(b).get("error") == "invalid_grant", "a wrong verifier is refused (%d)" % s)
        s, h, b = token_request(tok_params)
        check(s == 400, "…and it burned the code, so even the right verifier now fails (%d)" % s)

        # a fresh grant for the real exchange
        def new_grant(cookie, label):
            v, c = pkce()
            st = secrets.token_hex(6)
            s1, h1, b1 = http("GET", authorize_query(client_id, c, st), cookies=cookie)
            f = form_fields(b1)
            s2, h2, b2 = http("POST", post_url, {"Content-Type": "application/x-www-form-urlencoded"}, urllib.parse.urlencode(dict(f, decision="approve")), cookies=cookie)
            cd = (urllib.parse.parse_qs(urllib.parse.urlparse(h2.get("location", "")).query).get("code") or [""])[0]
            return v, cd

        verifier, code = new_grant(admin_cookie, "admin")
        params = {"grant_type": "authorization_code", "code": code, "code_verifier": verifier, "client_id": client_id, "redirect_uri": CALLBACK, "resource": MCP}
        s, h, b = token_request(dict(params, redirect_uri="https://claude.com/api/mcp/auth_callback"))
        check(s == 400, "a different redirect_uri is refused (%d)" % s)
        verifier, code = new_grant(admin_cookie, "admin")
        params.update(code=code, code_verifier=verifier)
        s, h, b = token_request(params)
        tok = jbody(b)
        check(s == 200 and tok.get("token_type") == "Bearer" and tok.get("access_token", "").startswith("aied_at_") and tok.get("refresh_token", "").startswith("aied_rt_"), "the code is exchanged for a token pair (%d)" % s)
        check("no-store" in h.get("cache-control", ""), "the token response is not cacheable")
        check(tok.get("expires_in") == 3600, "the access token lives an hour")
        access, refresh = tok.get("access_token", ""), tok.get("refresh_token", "")
        s, h, b = token_request(params)
        check(s == 400 and jbody(b).get("error") == "invalid_grant", "replaying the code is refused")
        s, h, b = mcp({"jsonrpc": "2.0", "id": 1, "method": "tools/list"}, token=access)
        check(s == 401, "…and replaying a code ends the whole grant: its access token no longer works (%d)" % s)

        # ---- 7. MCP with a token ---------------------------------------------------------------------
        print("\n== MCP with the token ==")
        verifier, code = new_grant(admin_cookie, "admin")
        s, h, b = token_request({"grant_type": "authorization_code", "code": code, "code_verifier": verifier, "client_id": client_id, "redirect_uri": CALLBACK})
        tok = jbody(b)
        access, refresh = tok["access_token"], tok["refresh_token"]
        s, h, b = mcp({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2025-03-26", "capabilities": {}, "clientInfo": {"name": "t", "version": "1"}}}, token=access)
        check(s == 200 and "result" in jbody(b), "initialize works with the token (%d)" % s)
        s, h, b = mcp({"jsonrpc": "2.0", "id": 2, "method": "tools/list"}, token=access)
        ntools = len(jbody(b).get("result", {}).get("tools", []))
        check(s == 200 and ntools > 10, "tools/list returns the tools (%d tools)" % ntools)
        s, h, b = mcp({"jsonrpc": "2.0", "id": 3, "method": "tools/call", "params": {"name": "list_divi_pages", "arguments": {}}}, token=access)
        res = jbody(b).get("result", {})
        check(s == 200 and not res.get("isError"), "a real tool call (list_divi_pages) works as the approving user (%d)" % s)
        s, h, b = http("GET", SITE + "/wp-json/ai-editor-divi5/v1/pages", {"Authorization": "Bearer " + access})
        check(s == 200, "the REST API accepts the token too (%d)" % s)
        s, h, b = mcp({"jsonrpc": "2.0", "id": 4, "method": "tools/list"}, token=access[:-2] + "00")
        check(s == 401 and "www-authenticate" in h, "a wrong token is 401 with the challenge (%d)" % s)
        s, h, b = mcp({"jsonrpc": "2.0", "id": 4, "method": "tools/list"}, token=refresh)
        check(s == 401, "a refresh token is not an access token (%d)" % s)

        # ---- 8. refresh and rotation ------------------------------------------------------------------
        print("\n== Refresh, rotation, replay ==")
        s, h, b = token_request({"grant_type": "refresh_token", "refresh_token": refresh, "client_id": client_id})
        tok2 = jbody(b)
        check(s == 200 and tok2.get("refresh_token") and tok2["refresh_token"] != refresh, "refreshing returns a NEW refresh token (rotation) (%d)" % s)
        s, h, b = mcp({"jsonrpc": "2.0", "id": 5, "method": "tools/list"}, token=tok2.get("access_token", ""))
        check(s == 200, "the new access token works")
        s, h, b = token_request({"grant_type": "refresh_token", "refresh_token": refresh, "client_id": client_id})
        check(s == 400 and jbody(b).get("error") == "invalid_grant", "replaying the old refresh token is refused")
        s, h, b = mcp({"jsonrpc": "2.0", "id": 6, "method": "tools/list"}, token=tok2.get("access_token", ""))
        check(s == 401, "…and ends the whole grant: the newest access token dies too (%d)" % s)
        s, h, b = token_request({"grant_type": "refresh_token", "refresh_token": tok2.get("refresh_token", ""), "client_id": client_id})
        check(s == 400, "…and so does the newest refresh token (%d)" % s)

        # ---- 9. permissions are re-checked on every request --------------------------------------------
        print("\n== Permissions follow the user ==")
        editor_cookie = cookie_for(users["editor"])
        verifier, code = new_grant(editor_cookie, "editor")
        s, h, b = token_request({"grant_type": "authorization_code", "code": code, "code_verifier": verifier, "client_id": client_id, "redirect_uri": CALLBACK})
        etok = jbody(b)
        s, h, b = mcp({"jsonrpc": "2.0", "id": 7, "method": "tools/list"}, token=etok.get("access_token", ""))
        check(s == 200, "an editor's token works while the editor can edit pages (%d)" % s)
        wp("user", "set-role", str(users["editor"]), "subscriber")
        s, h, b = mcp({"jsonrpc": "2.0", "id": 8, "method": "tools/list"}, token=etok.get("access_token", ""))
        check(s == 403, "after the user is demoted the same token is refused (%d)" % s)
        wp("user", "delete", str(users["editor"]), "--yes")
        users.pop("editor", None)
        s, h, b = mcp({"jsonrpc": "2.0", "id": 9, "method": "tools/list"}, token=etok.get("access_token", ""))
        check(s == 401, "after the user is deleted the token is refused (%d)" % s)
        s, h, b = token_request({"grant_type": "refresh_token", "refresh_token": etok.get("refresh_token", ""), "client_id": client_id})
        check(s == 400, "and it cannot be refreshed (%d)" % s)

        # ---- 10. revoke -------------------------------------------------------------------------------
        print("\n== Revocation and switching off ==")
        verifier, code = new_grant(admin_cookie, "admin")
        s, h, b = token_request({"grant_type": "authorization_code", "code": code, "code_verifier": verifier, "client_id": client_id, "redirect_uri": CALLBACK})
        rtok = jbody(b)
        wp_eval("AiEditorDivi5\\WP\\OAuthRuntime::tokens()->revokeAll();")
        s, h, b = mcp({"jsonrpc": "2.0", "id": 10, "method": "tools/list"}, token=rtok.get("access_token", ""))
        check(s == 401, "revoking everything kills the token (%d)" % s)
        s, h, b = mcp({"jsonrpc": "2.0", "id": 11, "method": "tools/list"}, key=api_key)
        check(s == 200, "the API key is unaffected by OAuth revocation")
        verifier, code = new_grant(admin_cookie, "admin")
        s, h, b = token_request({"grant_type": "authorization_code", "code": code, "code_verifier": verifier, "client_id": client_id, "redirect_uri": CALLBACK})
        check(s == 401 and jbody(b).get("error") == "invalid_client", "revoking everything also forgot the registered clients (%d)" % s)

        wp_eval("AiEditorDivi5\\WP\\OAuthSettings::disable();")
        s, h, b = http("GET", SITE + "/.well-known/oauth-authorization-server")
        check(s == 404, "switched off: the well-known document is gone again (%d)" % s)
        s, h, b = mcp({"jsonrpc": "2.0", "id": 12, "method": "ping"})
        check(s == 401 and "www-authenticate" not in h, "switched off: no WWW-Authenticate again")
        check(wp_eval("echo AiEditorDivi5\\WP\\OAuthTable::exists() ? 'table' : 'no table'; ") == "no table", "switched off: the table is dropped")
        check(wp_eval("echo get_option('ai_editor_divi5_oauth_enabled', 'unset');") == "unset", "switched off: the option is gone")
        s, h, b = mcp({"jsonrpc": "2.0", "id": 13, "method": "tools/list"}, key=api_key)
        check(s == 200, "the API key works at the end")
        check(wp_eval("echo AiEditorDivi5\\WP\\ApiKey::getUserId();") == key_owner, "the API key's owner is unchanged")
    finally:
        try:
            wp_eval("if (class_exists('AiEditorDivi5\\\\WP\\\\OAuthSettings') && AiEditorDivi5\\WP\\OAuthSettings::enabled()) { AiEditorDivi5\\WP\\OAuthSettings::disable(); }")
        except Exception as e:
            print("cleanup (disable) failed:", e)
        for uid in list(users.values()):
            try:
                wp("user", "delete", str(uid), "--yes")
            except Exception as e:
                print("cleanup (user %s) failed: %s" % (uid, e))
        leftovers = wp_eval("echo count(get_users(['search'=>'%s_*','search_columns'=>['user_login']]));" % PREFIX)
        check(leftovers == "0", "no throwaway users are left")

    print()
    if FAILS:
        print("%d check(s) FAILED:" % len(FAILS))
        for f in FAILS:
            print("  - " + f)
        sys.exit(1)
    print("All checks passed.")


if __name__ == "__main__":
    main()
