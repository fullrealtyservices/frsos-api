# Darwin (AccountTECH / TransactionPlan) integration

Discovery doc. Phase 0 deliverable. Captures what we know about Darwin's API surface before any code is written, so Phase 1 (schema + crosswalk) can land without ambiguity.

**Vendor canonical docs:** https://darwinapidoc.transactionplan.com/
**Postman collection (public):** https://www.getpostman.com/collections/4d76764f22815bf88441 (4.5 MB JSON, full request library)

## What Darwin owns in the FRSOS architecture

Per https://design.frs.works/arch.html, Darwin is **back-office accounting** — commissions, agent billing, general ledger. It's the first vendor we're mirroring into our canonical store.

## Base URL

| Environment | Base URL |
|---|---|
| Production | `https://api.darwin.cloud` |
| Sandbox | `https://api.darwin.cloud` (same URL — sandbox is selected by the credential set, not the host) |

Sandbox access requires a **separate set of credentials** issued by the client; request these through the client (FRS) manager before any non-trivial integration work.

## Authentication

Darwin uses a two-step flow: OAuth-style login → Basic Auth with username + token for every subsequent call.

### Credentials issued per (vendor, client) pair

| Constant | Purpose |
|---|---|
| `client_id` | Vendor-level API access |
| `client_secret` | Vendor-level API access |
| `username` | Per-client integration user |
| `password` | Per-client integration password |
| `api_key` | Identifies the Darwin client (brokerage/firm) the request acts against |

All six are required to log in. FRS has its own set; sandbox has a separate set.

### Step 1 — Login

```
POST https://api.darwin.cloud/api/auth/login/{username}
Body (JSON):
  {
    "client_id":     "...",
    "client_secret": "...",
    "api_key":       "...",
    "password":      "..."
  }

Response:
  {
    "token":        "315c7649520edde96c5cbad59a5b265f",   ← 24-hour lifetime
    "refreshToken": "..."
  }
```

### Step 2 — Authorization header for all subsequent requests

HTTP Basic Auth, where the **username** is the integration `username` and the **password** is the issued `token` (not the original `password`). Concatenate with `:`, Base64-encode, prefix with `Basic `.

```
Authorization: Basic <Base64(username + ":" + token)>
```

Required on every request except `login` and `refresh-token`.

### Step 3 — Token renewal

Tokens expire every 24 hours. Renew before expiry:

```
POST https://api.darwin.cloud/api/auth/refresh-token
Body: { "refreshToken": "..." }

Response: { "token": "...", "refreshToken": "..." }   ← BOTH rotate; persist the new refreshToken
```

The `refreshToken` itself rotates on every call. We must persist the latest value or future renewals fail.

## Endpoint surface — what we need

Darwin organizes endpoints by domain (Security, Company, Office, CodeValues, Person, Property, Accounting, Dashboards). Only a subset is relevant to the FRSOS commissions mirror.

### Person — agent records

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/person/?personId={id}` | Single agent record. Returns `personId`, `guid` (UUID), `agentNumber`, `personType`, `office*`, `company*`, `terminatedDate`, etc. |
| `GET` | `/api/personreport?personId=&dateStart=&dateEnd=&showDeductions=true&showOverrides=true&showTotals=true&...` | Period report for one agent. Includes `agent_Gross`, `agent_Deduct1..5`, `agent_Net`, overrides, expenses recovered. |
| `POST` | `/api/person` | Add an agent record. Out of scope for read-only mirror. |

### Property — transactions and commissions

This is where commissions live in Darwin. **There is no flat `/api/commissions` endpoint.** Commission rows are nested inside the property report's `propertyAgentListInfo[]` array, one row per agent per side per transaction.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/property/{propertyId}` | Single property/transaction detail. |
| `GET` | `/api/property?companyId=&statusCode=&trType=&dateStart=&dateEnd=&pageIndex=&pageSize=...` | Search properties — used to enumerate closed transactions for sync. |
| `GET` | `/api/property/advance_search?...` | Same surface with more filters. |
| `GET` | `/api/propertyreport?PropertyId=&showAccounting=true&showAgentNetList=true&showAgentNetSell=true&showOverrides=true&showReimbs=true&showReferrals=true&show3rdPartyPayments=true` | **The commission-bearing call.** Returns full property + nested `propertyAgentListInfo[]` with per-agent gross / deductions / net per side. Many `show*` toggles — only enable the sections we use. |
| `GET` | `/api/propertyreport/summary?dateStart=&dateEnd=&pageIndex=&pageSize=` | Summary across many transactions. |
| `GET` | `/api/property/people?propertyID=&typeName=&side=` | List people on a transaction (agents, customers, etc.). |

### Accounting — GL + QuickBooks

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/ledgerreport/GL?dateStart=&dateEnd=&companyID=&officeId=&ledgerType=&accountID=&transactionID=&code=...` | General Ledger report. Out of scope for v1; revisit when canonical GL projections are needed. |
| `GET` | `/api/quickbook/{date}` | Daily QuickBooks export. Out of scope. |

### Security, Office, Company, CodeValues, Dashboards

Used during ingest for context (resolving office IDs, type codes), but no canonical mirror tables yet. Document on demand.

## Agent identifier — the crosswalk key

Three identifiers travel with every Darwin agent:

| Field | Type | Where it appears | Use |
|---|---|---|---|
| `personId` | integer | `Person.personId`, `propertyAgentListInfo[].agent_PersonID` (caps!) | Primary integer key — what we query commissions by. Store as `wp_usermeta.darwin_id`. |
| `guid` / `atGuid` | UUID | `Person.guid`, `propertyAgentListInfo[].agent_ATGUID` | Stable cross-system identifier. Store as `wp_usermeta.darwin_atguid` as a secondary crosswalk in case `personId` is ever re-sequenced. |
| `agentNumber` | string (e.g. `"10032"`) | `Person.agentNumber` | Darwin's user-facing agent number. Display-only, not a crosswalk key. |

**Plan implication:** the `Formatter::vendor_ids` projection adds `darwin_id` AND `darwin_atguid`. The `wp_frsos_commissions.darwin_agent_id` column stores `agent_PersonID` (integer) since that's the join key the property report returns. `GET /people/by-darwin-id/{id}` resolves `personId → wp_users.ID`.

### Field-name case gotcha

Darwin's API mixes camelCase and PascalCase in confusing ways. Watch for:
- `personId` (Person Report) vs `agent_PersonID` (Property Agent List)
- `guid` (Person Report) vs `agent_ATGUID` (Property Agent List)
- `propertyID` (Property Search) vs `propertyId` (Property Report)
- `PropertyId` as a URL query param

The normalizer must accept both casings on every field it cares about.

## Sample payloads (redacted from public Postman collection)

### Person Report (agent record)

```json
{
  "personId":          59147,
  "personName":        "Roya Nousample",
  "firstName":         "Roya",
  "lastName":          "Nousample",
  "personType":        "Agent",
  "personTypeId":      1368,
  "agentNumber":       "10032",
  "officeId":          19,
  "office":            "Dorchester",
  "companyId":         1,
  "company":           "Company 1",
  "startDate":         "1996-12-18",
  "active":            true,
  "guid":              "f73baf7d-b855-4c10-96c1-a811a2be14c9",
  "emailAddress":      "roya@example.com"
}
```

### Property Report (transaction with commissions)

```json
[{
  "propertyId":        252,
  "propertyAddress":   "11030 GULFSHORE DR, NAPLES, FL 34108-1745",
  "mlsNumber1":        "211002521",
  "createDate":        "2011-09-27 11:55",
  "modifyDate":        "2021-06-21 05:52",

  "propertyBasicInfo": [{
    "propertyID":       252,
    "companyID":        1,
    "company":          "Company 1",
    "trType":           "OL",
    "status":           "Processed",
    "commissionPrice":  350000,
    "sellingPrice":     350000,
    "listingPrice":     379990,
    "listCommission":   10500,
    "sellCommission":   0,
    "refListCommission": 0,
    "refSellCommission": 0,
    "listDate":         "2009-01-22",
    "pendingDate":      "2011-12-08",
    "closingDate":      "2011-12-30",
    "processedDate":    "2012-01-04",
    "transactionID":    "0003000000000014",
    "propertyType":     "Condominium"
  }],

  "propertyAgentListInfo": [{
    "propertyDeductionID": 328,            ← Darwin's PK for this commission row
    "propertyID":          252,
    "agent_officeID":      19,
    "agent_officeName":    "Dorchester",
    "agent_PersonID":      52446,          ← crosswalk key (int)
    "agent_ATGUID":        "CED0A44D-C2F5-4A25-ADA5-5CB164AA0600",
    "agent_FullName":      "Kelly Ksamplet",
    "personType":          "agent",
    "side":                "L",            ← L = list, S = sell
    "agent_ColumnNumber":  1,
    "agent_CommPercent":   0.5,
    "agent_Gross":         5250,           ← amounts are dollars-as-decimal in Darwin
    "agent_Deduct1":       0,
    "agent_Deduct2":       315,
    "agent_Deduct3":       1480.5,
    "agent_Deduct4":       0,
    "agent_Deduct5":       0
    // additional fields trail: net, etc.
  }]
}]
```

**Money in Darwin is decimal dollars (`5250`, `1480.5`), not cents.** The normalizer multiplies by 100 and `intval`s to get the BIGINT cents the canonical schema expects.

## Mapping to `wp_frsos_commissions`

| Canonical column | Source in Darwin payload | Notes |
|---|---|---|
| `source_vendor` | constant `'darwin'` | |
| `vendor_record_id` | `propertyAgentListInfo[i].propertyDeductionID` | Darwin's per-row PK; ensures upsert idempotency |
| `user_id` | `wp_users.ID` resolved from `agent_PersonID → darwin_id` user_meta | NULL when crosswalk misses → `sync_status = 'orphaned'` |
| `darwin_agent_id` | `agent_PersonID` | Snapshot for retry-resolve later |
| `transaction_id` | `propertyBasicInfo.transactionID` | string form, not propertyId |
| `transaction_close_date` | `propertyBasicInfo.closingDate` | DATE |
| `gross_amount_cents` | `agent_Gross * 100` | integer cents |
| `net_amount_cents` | `agent_Gross - SUM(agent_Deduct1..5)` × 100 | we compute the net; Darwin returns deductions, not net |
| `currency` | constant `'USD'` | confirm before non-US use |
| `role` | `side` ∈ {`L` → `list_side`, `S` → `buy_side`} | normalize the enum |
| `status` | derived from `propertyBasicInfo.status` (`"Processed"` → `paid`, `"Pending"` → `pending`, etc.) | |
| `vendor_updated_at` | `modifyDate` | Darwin's mtime |
| `last_synced_at` | now() at ingest | |
| `last_raw_id` | FK to the raw buffer row that wrote this | |
| `sync_status` | `'fresh'` on success, `'orphaned'` if `user_id` NULL | |

## Sync strategy

Darwin has no flat commissions feed and no webhook capability we've found in the public docs. Sync is **pull-based, two-pass**:

1. **Enumerate closed transactions** in the period: `GET /api/property?statusCode=Processed&dateStart=&dateEnd=&pageSize=50`
2. **Per property**, pull the full report: `GET /api/propertyreport?PropertyId={id}&showAgentNetList=true&showAgentNetSell=true&showOverrides=true&showReimbs=true&showReferrals=true`
3. **For each row in `propertyAgentListInfo[]`**, POST the raw payload to our ingest endpoint (`POST /wp-json/frs/v1/ingest/darwin/commissions`), batched 100 at a time.

This is what the n8n workflow in `fullrealtyservices/frs-automations` will encode.

## Rate limiting

**Not documented in the public Postman collection.** Need to confirm with Darwin support before running a large historical backfill. Conservative defaults for the n8n workflow until confirmed:

- Pause 250 ms between requests
- Concurrency limit: 1 (sequential, no parallel pulls)
- On HTTP 429: exponential backoff (1s, 5s, 30s, abort)

## Webhooks

**Not documented as available** in the public collection. Sync is pull-only. The high-water mark `wp_options['frsos_darwin_high_water_mark']` stores the most recent `modifyDate` we've seen on the property side; subsequent runs filter by `dateStart={high_water_mark}` on the property search.

## Versioning

Postman collection version: `1.0` (per `info.version` in the JSON). No explicit deprecation policy documented. The API base path `/api/...` suggests an unversioned URL — breaking changes would presumably ship under a new path.

## Open issues / things to confirm before Phase 2

1. **Rate limits** — get an official answer from Darwin support; the conservative defaults above are a guess.
2. **Sandbox access** — request a separate cred set for FRS sandbox before the n8n workflow runs against real prod tokens.
3. **Decimal precision on money** — confirm Darwin never sends sub-cent precision (e.g. `1480.555`). Spec rounding rule: round-half-up to nearest cent. Add a test case.
4. **Currency** — `USD` assumed. If any record turns up non-USD (FRS is California so unlikely), the normalizer needs the currency from somewhere — Darwin doesn't return one in the sample.
5. **Identifier stability** — confirm `personId` (integer) never gets re-sequenced. If it can, `atGuid` becomes the primary crosswalk and `personId` becomes a hint.
6. **Status enum** — full list of `propertyBasicInfo.status` values. Sample shows `Processed`; need `Pending`, `Withdrawn`, `Expired`, `Cancelled` to map cleanly to canonical `status`.
7. **Refresh token rotation** — n8n must persist the rotated refresh token between runs. Plan: cursor endpoint includes the latest token, encrypted at rest. Out of scope of the discovery doc but called out for Phase 2.

## Related

- [[../frsos_platform_architecture.md]] — governing FRSOS architecture
- Plan: `/Users/cedarstone/.claude/plans/fancy-bouncing-raccoon.md`
