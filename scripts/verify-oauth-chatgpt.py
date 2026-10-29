#!/usr/bin/env python3
"""
Real-site check of the OAuth sign-in acting as ChatGPT's connector flow is DOCUMENTED by OpenAI
(https://developers.openai.com/plugins/build/auth): discovery of both well-known documents, the fields OpenAI
lists as required, Dynamic Client Registration with ChatGPT's redirect URIs, PKCE S256, `resource` on both the
authorization and the token request, a Bearer token on the MCP endpoint, refresh, and a refused look-alike redirect.

This proves OUR side of the flow against the published requirements. It does NOT prove that ChatGPT accepts the
server: that needs a paid ChatGPT account with Developer Mode and a public https address.

Run: make verify-oauth-chatgpt   (dev site only: it switches OAuth on and off and creates one throwaway
                                   administrator; the API key and its owner are never touched)
Stdlib only. Setup and cleanup go through wp-cli in the Docker wpcli container.
"""
import importlib.util
import json
import os
import re
import secrets
import sys
import urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location("flow", os.path.join(HERE, "verify-oauth-flow.py"))
flow = importlib.util.module_from_spec(spec)
spec.loader.exec_module(flow)

SITE, MCP = flow.SITE, flow.MCP
check, http, mcp, jbody, wp, wp_eval = flow.check, flow.http, flow.mcp, flow.jbody, flow.wp, flow.wp_eval

STABLE = "https://chatgpt.com/connector_platform_oauth_redirect"
BY_ID = "https://chatgpt.com/connector/oauth/" + "Ab_9-" + secrets.token_hex(4)
POST_URL = SITE + "/wp-admin/admin-post.php?action=aied_oauth_authorize"
FORM = {"Content-Type": "application/x-www-form-urlencoded"}
JSON = {"Content-Type": "application/json"}


def sign_in_and_get_code(client_id, redirect, cookie):
    """The browser half: authorize with resource + PKCE S256, approve on the consent page, read the code."""
    verifier, challenge = flow.pkce()
    state = secrets.token_hex(8)
    url = flow.authorize_query(client_id, challenge, state, redirect=redirect)
    s, h, b = http("GET", url, cookies=cookie)
    fields = flow.form_fields(b)
    consent_ok = s == 200 and "<strong>chatgpt.com</strong>" in b
    s, h, b = http("POST", POST_URL, FORM, urllib.parse.urlencode(dict(fields, decision="approve")), cookies=cookie)
    loc = h.get("location", "")
    q = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)
    code = (q.get("code") or [""])[0]
    return consent_ok, loc, code, (q.get("state") or [""])[0] == state, verifier


def main():
    if not re.match(r"^https?://(localhost|127\.0\.0\.1)(:\d+)?$", SITE):
        sys.exit("This check switches OAuth on and creates a user: it only runs against a local dev site, not " + SITE)

    uid = None
    try:
        api_key = wp_eval("echo AiEditorDivi5\\WP\\ApiKey::get();")
        if wp_eval("echo AiEditorDivi5\\WP\\OAuthSettings::enabled() ? 'on' : 'off';") != "off":
            sys.exit("OAuth is already switched on on this site. Switch it off first.")
        check(wp_eval("echo AiEditorDivi5\\WP\\OAuthSettings::enable() ? 'yes' : 'no';") == "yes", "OAuth switched on")

        print("\n== Discovery, without a 401 first (ChatGPT may fetch the documents directly) ==")
        s, h, b = http("GET", SITE + "/.well-known/oauth-protected-resource")
        prm = jbody(b)
        check(s == 200 and prm.get("resource") == MCP, "protected resource: `resource` is the connector URL exactly")
        check(isinstance(prm.get("authorization_servers"), list) and prm["authorization_servers"] == [SITE], "protected resource: `authorization_servers` names the issuer")
        s, h, b = http("GET", SITE + "/.well-known/oauth-authorization-server")
        asm = jbody(b)
        for field in ("issuer", "authorization_endpoint", "token_endpoint", "code_challenge_methods_supported"):
            check(field in asm, "authorization server document has `%s` (required by OpenAI)" % field)
        check(asm.get("issuer") == prm["authorization_servers"][0], "`issuer` equals the entry in `authorization_servers` exactly")
        check("S256" in asm.get("code_challenge_methods_supported", []), "S256 is advertised")
        check(bool(asm.get("registration_endpoint")), "`registration_endpoint` is advertised (DCR)")
        check("authorization_response_iss_parameter_supported" not in asm,
              "RFC 9207 `iss` is NOT advertised (so ChatGPT uses the callback-id redirect)")
        s, h, b = mcp({"jsonrpc": "2.0", "id": 1, "method": "ping"})
        wa = h.get("www-authenticate", "")
        check(s == 401 and re.search(r'^Bearer resource_metadata="[^"]+"', wa) is not None, "a 401 carries `WWW-Authenticate: Bearer resource_metadata=...`")

        print("\n== Dynamic Client Registration with ChatGPT's redirects ==")
        reg = asm["registration_endpoint"]
        s, h, b = http("POST", reg, JSON, json.dumps({"redirect_uris": [STABLE], "client_name": "ChatGPT", "token_endpoint_auth_method": "none"}))
        stable = jbody(b)
        check(s == 201 and stable.get("client_id", "").startswith("aied_cl_") and "client_secret" not in stable, "the stable redirect registers as a public client (%d)" % s)
        s, h, b = http("POST", reg, JSON, json.dumps({"redirect_uris": [BY_ID], "client_name": "ChatGPT"}))
        by_id = jbody(b)
        check(s == 201 and by_id.get("redirect_uris") == [BY_ID], "a callback-id redirect registers (%d)" % s)
        for bad in ("https://chatgpt.com/other", "https://chatgpt.com.evil.example/connector_platform_oauth_redirect",
                    "http://chatgpt.com/connector_platform_oauth_redirect", "https://chatgpt.com/connector/oauth/a/b"):
            s, h, b = http("POST", reg, JSON, json.dumps({"redirect_uris": [bad], "client_name": "ChatGPT"}))
            check(s == 400 and jbody(b).get("error") == "invalid_redirect_uri", "refused: %s (%d)" % (bad, s))
        s, h, b = http("POST", reg, JSON, json.dumps({"redirect_uris": [STABLE], "client_name": "ChatGPT", "token_endpoint_auth_method": "private_key_jwt"}))
        check(s == 400, "private_key_jwt is refused honestly (only public clients with PKCE are supported) (%d)" % s)

        print("\n== Sign-in, consent and token exchange (callback-id client) ==")
        name = "%s_chatgpt" % flow.PREFIX
        uid = int(wp("user", "create", name, name + "@example.test", "--role=administrator", "--user_pass=" + secrets.token_hex(12), "--porcelain"))
        cookie = flow.cookie_for(uid)
        consent_ok, loc, code, state_ok, verifier = sign_in_and_get_code(by_id["client_id"], BY_ID, cookie)
        check(consent_ok, "the consent page names the redirect host chatgpt.com")
        check(loc.startswith(BY_ID + "?") and code.startswith("aied_ac_") and state_ok, "approve redirects to the exact registered ChatGPT address with a code and the state")
        s, h, b = flow.token_request({"grant_type": "authorization_code", "code": code, "code_verifier": verifier,
                                      "client_id": by_id["client_id"], "redirect_uri": BY_ID, "resource": MCP})
        tok = jbody(b)
        check(s == 200 and tok.get("token_type") == "Bearer" and tok.get("access_token", "").startswith("aied_at_"), "the code is exchanged for a Bearer token (%d)" % s)
        access, refresh = tok.get("access_token", ""), tok.get("refresh_token", "")

        print("\n== MCP with the token, as ChatGPT would call it ==")
        s, h, b = mcp({"jsonrpc": "2.0", "id": 2, "method": "initialize",
                       "params": {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "openai-mcp", "version": "1.0.0"}}}, token=access)
        check(s == 200 and "result" in jbody(b), "initialize works (%d)" % s)
        s, h, b = mcp({"jsonrpc": "2.0", "id": 3, "method": "tools/list"}, token=access)
        names = [t.get("name") for t in jbody(b).get("result", {}).get("tools", [])]
        check(s == 200 and "get_page_layout" in names and "html_to_divi" in names, "tools/list returns the full tool list, %d tools (%d)" % (len(names), s))

        print("\n== Refresh, and the stable-redirect client ==")
        s, h, b = flow.token_request({"grant_type": "refresh_token", "refresh_token": refresh, "client_id": by_id["client_id"], "resource": MCP})
        check(s == 200 and jbody(b).get("access_token", "").startswith("aied_at_"), "the refresh token gives a new pair (%d)" % s)
        consent_ok, loc, code, state_ok, verifier = sign_in_and_get_code(stable["client_id"], STABLE, cookie)
        check(consent_ok and loc.startswith(STABLE + "?") and code.startswith("aied_ac_") and state_ok, "the stable-redirect client signs in too")
        s, h, b = flow.token_request({"grant_type": "authorization_code", "code": code, "code_verifier": verifier,
                                      "client_id": stable["client_id"], "redirect_uri": BY_ID, "resource": MCP})
        check(s == 400, "a token request with a different redirect than the authorization one is refused (%d)" % s)

        s, h, b = mcp({"jsonrpc": "2.0", "id": 4, "method": "tools/list"}, key=api_key)
        check(s == 200, "the API key is unaffected")
    finally:
        try:
            wp_eval("if (class_exists('AiEditorDivi5\\\\WP\\\\OAuthSettings') && AiEditorDivi5\\WP\\OAuthSettings::enabled()) { AiEditorDivi5\\WP\\OAuthSettings::disable(); }")
        except Exception as e:
            print("cleanup (disable) failed:", e)
        if uid:
            try:
                wp("user", "delete", str(uid), "--yes")
            except Exception as e:
                print("cleanup (user %s) failed: %s" % (uid, e))
        check(wp_eval("echo count(get_users(['search'=>'%s_*','search_columns'=>['user_login']]));" % flow.PREFIX) == "0", "no throwaway users are left")
        check(wp_eval("echo get_option('ai_editor_divi5_oauth_enabled', 'unset');") == "unset", "OAuth is switched off again")

    print()
    if flow.FAILS:
        print("%d check(s) FAILED:" % len(flow.FAILS))
        for f in flow.FAILS:
            print("  - " + f)
        sys.exit(1)
    print("All checks passed.")


if __name__ == "__main__":
    main()
