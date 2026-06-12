<?php
/**
 * FRSOS — wp-config.php configuration sample.
 *
 * Copy the constants below into your wp-config.php file. They MUST appear
 * BEFORE the line:
 *
 *     require_once ABSPATH . 'wp-settings.php';
 *
 * Only FRSOS_API_KEYS is required. Everything else has a sensible default.
 *
 * @package FRSOS
 */

// -----------------------------------------------------------------------------
// REQUIRED
// -----------------------------------------------------------------------------

/**
 * API keys for read access (comma-separated).
 *
 * Each consumer (mobile app, LLM agent, marketing site, etc.) should get its
 * own key so you can rotate or revoke them independently. WordPress admins
 * always have read access regardless of whether they present a key.
 *
 * Write access (POST/PATCH) is NEVER granted by API key — it requires a
 * logged-in WP user with the `edit_users` capability.
 */
define( 'FRSOS_API_KEYS', 'key-for-mobile-app,key-for-llm-agent,key-for-marketing-site' );

// -----------------------------------------------------------------------------
// OPTIONAL — Rate limiting
// -----------------------------------------------------------------------------

/**
 * Per-IP rate limit, in requests per minute. Default: 120.
 *
 * Set to 0 to disable rate limiting entirely (not recommended in production).
 */
define( 'FRSOS_RATE_LIMIT_PER_MIN', 120 );

// -----------------------------------------------------------------------------
// OPTIONAL — Pagination
// -----------------------------------------------------------------------------

/**
 * Default page size when the caller doesn't specify `per_page`. Default: 20.
 */
define( 'FRSOS_DEFAULT_PER_PAGE', 20 );

/**
 * Maximum allowed page size. Requests above this are clamped. Default: 100.
 */
define( 'FRSOS_MAX_PER_PAGE', 100 );

// -----------------------------------------------------------------------------
// OPTIONAL — Self-documentation
// -----------------------------------------------------------------------------

/**
 * Whether to expose the public docs endpoints:
 *
 *   GET /wp-json/frs/v1/docs         (Swagger UI)
 *   GET /wp-json/frs/v1/openapi.yaml (OpenAPI 3.1 spec)
 *   GET /wp-json/frs/v1/swagger-ui   (alias for /docs)
 *   GET /wp-json/frs/v1/llms.txt     (LLM-friendly endpoint index)
 *
 * Default: true. Set to false to hide the docs in locked-down environments.
 */
define( 'FRSOS_ENABLE_DOCS', true );

// -----------------------------------------------------------------------------
// OPTIONAL — Site provisioning webhook (n8n)
// -----------------------------------------------------------------------------

/**
 * Webhook URL that fires when a site is provisioned or a rebuild is requested.
 * Typically points at an n8n workflow that runs the build pipeline.
 *
 * Leave undefined to disable site provisioning.
 */
// define( 'FRSOS_WEBHOOK_URL', 'https://n8n.example.com/webhook/frs-site-build' );

/**
 * Shared secret sent in the X-FRS-Webhook-Secret header on every webhook
 * call so n8n can verify the request came from this plugin.
 */
// define( 'FRSOS_WEBHOOK_SECRET', 'rotate-me-quarterly' );

// -----------------------------------------------------------------------------
// OPTIONAL — Darwin ingest (n8n -> WP)
// -----------------------------------------------------------------------------

/**
 * Shared HMAC secret for the internal Darwin ingest endpoints:
 *
 *   POST /wp-json/frs/v1/ingest/darwin/agents
 *   POST /wp-json/frs/v1/ingest/darwin/listings
 *   GET  /wp-json/frs/v1/sync/darwin/cursor
 *
 * n8n signs each request:
 *   X-FRS-Webhook-Timestamp: <unix seconds>
 *   X-FRS-Webhook-Signature: hash_hmac('sha256', "{timestamp}.{rawBody}", secret)
 *
 * Leave undefined (or empty) to DISABLE ingest entirely — every /ingest/* call
 * is rejected until this is set.
 */
// define( 'FRSOS_DARWIN_INGEST_SECRET', 'rotate-me-and-store-in-n8n-credentials' );

// -----------------------------------------------------------------------------
// OPTIONAL — Debug logging
// -----------------------------------------------------------------------------

/**
 * When true, every request to /wp-json/frs/v1/* is logged to the PHP error log
 * with timing + auth info. Useful for debugging; noisy in production.
 *
 * Default: false.
 */
define( 'FRSOS_LOG_REQUESTS', false );
