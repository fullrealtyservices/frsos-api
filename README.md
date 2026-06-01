# FRSOS

The official REST API for FRS people (real estate agents, loan originators, staff) and places (offices, regions, departments), with sites as a sub-resource owned by people or places. This plugin is a Backend-For-Frontend (BFF) projection that unifies WordPress users, BuddyPress xprofile, BuddyPress groups, and WP multisite blogs into a single, consistent REST surface under the `frsos/v1` namespace.

Consumers — mobile apps, LLM agents, marketing sites, internal tooling — call **one** API. They never need to know that a "person" is assembled from `wp_users` + `wp_usermeta` + xprofile tables, or that a "place" is a BP group, or that a "site" is a multisite blog. The plugin owns that mapping.

---

## Quick Start

1. **Install** — Clone or copy this plugin into `wp-content/plugins/frsos-api/`.
2. **Configure** — Add the constants from `wp-config-sample.php` to your `wp-config.php` (at minimum, define `FRSOS_API_KEYS`).
3. **Network-activate** — In WP Admin → Network Admin → Plugins, network-activate "FRSOS".

Optionally, copy `mu-plugin-loader/frsos-loader.php` into `wp-content/mu-plugins/` to force-load the plugin even if a sub-site admin tries to deactivate it.

Once activated, hit `https://your-domain.com/wp-json/frsos/v1/docs` to see the live Swagger UI.

---

## Why This Plugin Exists

Without this API, every consumer has to:

- Call `wp/v2/users` for the core user record.
- Call `buddypress/v1/members/{id}` for BP-specific fields.
- Call `buddypress/v1/xprofile/{field}/data/{user}` for each custom field, one at a time.
- Call `wp/v2/sites` (multisite) to find blogs the user owns.
- Stitch all of that together client-side, hoping field names match.

That's brittle, slow, and impossible for an LLM agent to navigate reliably. A mobile app would make 20+ round-trips just to render a profile screen.

**This plugin replaces all of that with a single, denormalized projection.** One `GET /people/{id}` returns the full person — core fields, profile fields, office/region affiliations, and owned sites — in one response, with stable field names that don't change when we swap the storage backend underneath.

The BFF pattern means:

- **Consumers are decoupled from storage.** If we migrate xprofile to ACF, or move sites off multisite, the API contract doesn't change.
- **Field names follow industry conventions**, not WordPress internals. `email`, not `user_email`. `phone`, not `field_27`.
- **Performance is owned by the API.** We can add caching, batching, and projection optimizations without touching consumer code.

---

## Endpoints Summary

| Method | Path | Description |
|--------|------|-------------|
| `GET`    | `/people` | List people; filter by `member_type`, `office`, `region`, `search`. |
| `GET`    | `/people/me` | The currently authenticated person. |
| `GET`    | `/people/{id}` | A person by WP user ID. |
| `GET`    | `/people/by-login/{login}` | A person by WP login (username). |
| `GET`    | `/people/by-nmls/{nmls}` | A loan originator by NMLS number. |
| `POST`   | `/people` | Create a person (admin only). |
| `PATCH`  | `/people/{id}` | Update a person (admin only). |
| `PATCH`  | `/people/me` | Update the authenticated person's own profile. |
| `GET`    | `/places` | List places (offices, regions, departments). |
| `GET`    | `/places/{id}` | A place by ID. |
| `GET`    | `/places/{id}/people` | People affiliated with a place. |
| `PATCH`  | `/places/{id}` | Update a place (admin only). |
| `POST`   | `/people/{id}/sites` | Provision a site owned by a person. |
| `POST`   | `/places/{id}/sites` | Provision a site owned by a place. |
| `PATCH`  | `/sites/{blog_id}` | Update site metadata (title, screenshot, status). |
| `POST`   | `/sites/{blog_id}/rebuild` | Trigger an async rebuild (fires the n8n webhook). |
| `GET`    | `/docs` | Swagger UI for this API. |
| `GET`    | `/openapi.yaml` | Raw OpenAPI 3.1 spec. |
| `GET`    | `/swagger-ui` | Alias for `/docs`. |
| `GET`    | `/llms.txt` | LLM-friendly index of endpoints + field semantics. |

---

## Authentication

The API has two distinct auth modes: **read** and **write**.

### Read (API key)

All `GET` requests require an API key passed in the `X-FRS-Api-Key` header. Keys are configured in `wp-config.php` via the `FRSOS_API_KEYS` constant (comma-separated). Logged-in WordPress administrators always have read access regardless of API key.

```bash
curl https://myhub21.com/wp-json/frsos/v1/people/123 \
  -H "X-FRS-Api-Key: key-for-mobile-app"
```

### Write (WordPress admin auth)

All `POST` and `PATCH` requests require a logged-in WordPress user with the `edit_users` capability. Use standard WP cookie auth (for browser clients) or application passwords (for server-to-server). API keys alone never grant write access.

```bash
curl -X PATCH https://myhub21.com/wp-json/frsos/v1/people/123 \
  -u admin:application-password \
  -H "Content-Type: application/json" \
  -d '{"phone": "+1-555-0100"}'
```

### Self-service writes

`PATCH /people/me` is a special case: any authenticated user can update their own profile (limited to a safe subset of fields — bio, phone, avatar, social links — not member_type or office assignment).

---

## Field Naming Policy

Field names follow these rules, in priority order:

1. **Native WP/BP when one exists.** If WordPress core or BuddyPress already has a name for a concept, we use it. `display_name`, `user_login`, `member_type`.
2. **Industry-standard otherwise.** When there's no native name, we use the term the industry uses. `nmls` (not `nmls_number_field_42`), `mls_id`, `license_state`.
3. **Vendor names only as link-back metadata.** Third-party identifiers (Courted IDs, Modex IDs, NMLS) live in a `links` sub-object so consumers can round-trip back to the source system, but they don't pollute the top level.
4. **No `frs_` prefix anywhere.** This is the FRS API by definition. Prefixing every field with `frs_` is redundant and ugly.

Example:

```json
{
  "id": 123,
  "display_name": "Jane Doe",
  "email": "jane@fullrealtyservices.com",
  "phone": "+1-555-0100",
  "member_type": "loan-originator",
  "nmls": "1234567",
  "office": { "id": 42, "name": "Atlanta" },
  "links": {
    "courted_id": "ag_abc123",
    "modex_id": "mx_xyz789"
  }
}
```

---

## Data Sources

Internally, a person is assembled from:

- **`wp_users`** — core identity (login, email, registration date).
- **`wp_usermeta`** — flat key/value extras (avatar, capabilities, last-login).
- **BP xprofile** — structured profile fields (bio, phone, social links, NMLS).
- **BP groups (with member types)** — office, region, and department affiliation.
- **WP multisite blogs** — owned sites (personal site, team site, listing site).

A place is a BP group with a `place_type` of `office`, `region`, or `department`. A site is a multisite blog mapped to either a person or a place via blog meta.

**Consumers never see this.** The API returns a flat, denormalized projection. The data source is an implementation detail.

---

## Sites Sub-Resource

Sites are owned by either a person or a place — never standalone. The ownership relationship is canonical:

- `POST /people/{id}/sites` — provision a site owned by a person (e.g., a loan originator's personal site).
- `POST /places/{id}/sites` — provision a site owned by a place (e.g., an office's team site).
- `PATCH /sites/{blog_id}` — update site metadata (title, screenshot URL, lifecycle status).
- `POST /sites/{blog_id}/rebuild` — trigger an async rebuild.

The owning person or place is returned as part of every site response, so consumers can navigate from a site back to its owner without a second call.

---

## Webhook Integration

Site provisioning and rebuilds are async. Here's the flow:

1. Client calls `POST /people/{id}/sites` with a template + initial config.
2. The API creates the multisite blog (status: `provisioning`), then fires a webhook to n8n with the new blog ID + ownership metadata.
3. n8n runs the build pipeline (theme install, content seed, screenshot capture).
4. When done, n8n calls back via `PATCH /sites/{blog_id}` with the screenshot URL and status `ready`.

Clients poll `GET /sites/{blog_id}` (or subscribe to a webhook of their own) to know when the site is ready.

The webhook URL and shared secret are configured via constants — see `wp-config-sample.php`.

---

## Filters / Extension Points

The plugin exposes WordPress filters so other plugins or site code can extend behavior without forking.

| Filter | Purpose |
|--------|---------|
| `frs_papi_read_permission` | Override read auth (e.g., allow a JWT alongside API keys). Receives `(bool $allowed, WP_REST_Request $request)`. |
| `frs_papi_write_permission` | Override write auth (e.g., delegate to a custom capability). Receives `(bool $allowed, WP_REST_Request $request)`. |
| `frs_papi_profile` | Modify a person projection before it's returned. Receives `(array $profile, int $user_id, WP_REST_Request $request)`. |
| `frs_papi_place` | Modify a place projection before it's returned. |
| `frs_papi_site` | Modify a site projection before it's returned. |
| `frs_papi_writable_self_fields` | Control which fields `PATCH /people/me` allows the user to change. |

Example — add a computed field:

```php
add_filter( 'frs_papi_profile', function ( $profile, $user_id ) {
    $profile['years_at_company'] = floor( ( time() - strtotime( $profile['hire_date'] ?? 'now' ) ) / YEAR_IN_SECONDS );
    return $profile;
}, 10, 2 );
```

---

## WP-CLI Commands

_None shipped in 1.0.0._ Planned for a future release:

- `wp frs people list` — list people with the same filters as the REST endpoint.
- `wp frs people rebuild-cache <id>` — recompute the projection for one person.
- `wp frs sites provision <owner-type> <owner-id> --template=<slug>` — provision a site without going through the REST endpoint.

---

## Development Setup

```bash
# Clone into your local WP install
cd wp-content/plugins
git clone https://github.com/fullrealtyservices/frsos-api.git

# Or symlink from a workspace
ln -s ~/Projects/frsos-api wp-content/plugins/frsos-api

# Network-activate
wp plugin activate frsos-api --network

# Add API key to wp-config.php
echo "define( 'FRSOS_API_KEYS', 'dev-key' );" >> wp-config.php

# Hit the docs
open https://your-local-site.test/wp-json/frsos/v1/docs
```

The plugin ships a manual `spl_autoload_register` for the `FRSOS\` namespace, so **no `composer install` is needed at runtime**. `composer.json` is provided for IDE integration and dev dependencies (PHPCS, PHPUnit) only.

---

## Versioning

This API follows [Semantic Versioning](https://semver.org/). The URL namespace (`frsos/v1`) is stable across the 1.x line — new fields and endpoints may be added without bumping the major, but existing field semantics will not change.

### Changelog

- **1.0.0** — Initial release. People, places, and sites endpoints; API key + admin auth; self-documenting via OpenAPI + Swagger UI + llms.txt; n8n webhook integration for site provisioning.

---

## License

GPLv2 or later — see [LICENSE](./LICENSE).

---

## Live Documentation

Once installed, the canonical docs live at:

- **Swagger UI:** `/wp-json/frsos/v1/docs`
- **OpenAPI spec:** `/wp-json/frsos/v1/openapi.yaml`
- **LLM index:** `/wp-json/frsos/v1/llms.txt`

These are always in sync with the deployed code — this README is just an overview.
