# Platform Behaviours and Blockers Note

This note captures the platform behaviours observed while building the private Fynd extension and how they were handled in the working submission.

## 1. Private extension launch flow vs OAuth callback

Observed behaviour:
The private extension must be launched from Fynd so the callback includes `?code=...`. Hitting token endpoints directly without first completing the extension launch flow returns errors such as missing authorization code or missing stored token.

Workaround:
Configured the live private extension flow to use:

- Install / launch entry: `/fp/install`
- OAuth callback: `/fp/auth`

The app now exchanges the authorization code using Fynd's offline-token flow and stores the resulting token in MySQL for later catalog API calls.

## 2. Supplementary template requires a valid template-category pair

Observed behaviour:
The Catalog API rejected categories that looked valid globally but were not attached to the `supplementary` product template. For example, `others3` and other attempted slugs returned template/category mismatch errors.

Workaround:
The app was simplified to a fixed-mapping ingestion path for the case study. Metadata validation now checks the configured `category_slug`, `department_uid`, `template_tag`, and tax rule before the app sends the create-product request. The working pair used for successful ingestion was:

- `template_tag`: `supplementary`
- `category_slug`: `others-level-3`
- `department_uid`: `3`

## 3. Product create payload shape was stricter than the early draft

Observed behaviour:
Early requests failed with validation errors for missing identifiers, invalid price shape, and unsupported GTIN type values.

Workaround:
The final payload was adjusted to match what the Fynd product create API accepted:

- size-level `identifiers` are sent as GTIN-style objects
- `gtin_type` is sent as `sku_code`
- size-level `price` is numeric
- `price_effective`, `price_marked`, and dimensions are sent at size level
- `item_type` is explicitly sent as `standard`

After these changes, product creation returned HTTP 200 with a successful product UID.

## 4. Metadata discovery endpoints were not always enough on their own

Observed behaviour:
The product-template metadata endpoints did not always return a directly usable category for the target template in a way that made full auto-pick reliable on shared hosting.

Workaround:
The app keeps metadata tools for inspection, but the actual ingestion flow was reduced to a simpler and more reliable fixed configuration path. Once one valid brand, tax rule, category slug, and department UID were identified, those values were reused for the remaining case-study products.

## 5. Shared hosting could time out during heavier metadata scans

Observed behaviour:
Repeated automatic metadata scans and retries on cPanel hosting could trigger 503 responses or long-running requests.

Workaround:
The flow was simplified:

- single-product ingestion batches
- fewer automatic retries
- metadata preflight before sending create requests
- reliance on saved catalog defaults once a working mapping was found

This made the app stable enough for the case-study demonstration.

## Outcome

The final working model satisfies the core objective of the assignment:

- read a legacy CSV
- validate dirty rows
- transform accepted rows into Fynd-compatible payloads
- ingest products into Fynd from inside the private extension

Proof of success was confirmed by a successful create-product response returning:

- HTTP `200`
- product UID `95035483`
