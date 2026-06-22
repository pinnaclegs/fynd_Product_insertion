# Fynd Catalog Transformer Extension

Private Fynd extension built in PHP for the TIM case study. It reads a messy legacy product CSV, validates the records, transforms accepted rows into Fynd-compatible catalog payloads, and creates products in Fynd using the Platform Catalog API.

## Submission Summary

This project covers the required flow end to end:

1. Private extension setup and OAuth install flow
2. Legacy CSV validation with intentional dirty-data handling
3. Product transformation into Fynd catalog format
4. Product ingestion into Fynd from inside the extension
5. Proof of successful creation on the platform

Confirmed successful ingestion example:

- Product UID: `95035483`
- API response: HTTP `200`

## Included Files

```text
config.example.php                  Safe template for App, DB, Fynd, and catalog defaults
setup.php                           Creates required MySQL tables
index.php                           Dashboard
transformer.php                     CSV validation and transformation UI
ingestor.php                        Token test, payload preflight, product ingestion
metadata.php                        Metadata inspection helpers
fynd_oauth.php                      Private extension OAuth helpers
fynd_catalog.php                    Catalog payload preparation and metadata checks
legacy_products.csv                 Messy sample input with defects
sample_products_template.csv        Clean sample input for successful ingestion
PLATFORM_BEHAVIOURS_AND_BLOCKERS.md Observed platform behaviours and workarounds
README.md                           Run and submission notes
```

## Sample Input Strategy

Two CSV files are included so the reviewer can see both validation and successful ingestion:

- `legacy_products.csv`
  Includes intentional defects:
  - duplicate SKU
  - missing mandatory field
  - invalid image URL
  - questionable negative price

- `sample_products_template.csv`
  Cleaned sample input intended for successful case-study ingestion

## Validation Rules Implemented

The transformer checks:

- duplicate SKUs
- missing mandatory fields
- invalid or empty image URLs
- non-positive pricing
- suspicious compare-at pricing
- normalized size values
- normalized color values

Accepted rows are stored in MySQL as pending products. Rejected rows are stored in `validation_errors`.

## Working Catalog Mapping

For the case study, accepted products are mapped to a single working Fynd configuration:

- `template_tag`: `supplementary`
- `item_type`: `standard`
- `brand_uid`: `5989`
- `category_slug`: `others-level-3`
- `department_uid`: `3`
- `tax_rule_id`: `6a2f6ed9c58cfdddece68e9b`

This was the stable path that successfully created products on the target company.

## Local Setup

Workspace path:

```text
C:\xampp\htdocs\Apps\fynd-extension
```

Local URL:

```text
http://localhost/Apps/fynd-extension
```

Create your local config:

```text
copy config.example.php config.php
```

Then update `config.php` with your local database and Fynd extension credentials. The real
`config.php` file is intentionally ignored by Git because it contains environment-specific secrets.

Create a local MySQL database named `fynd`, then open:

```text
http://localhost/Apps/fynd-extension/setup.php
```

## Live Setup

Live URL:

```text
https://fynd.rishikeshjagdale.com
```

Run:

```text
https://fynd.rishikeshjagdale.com/setup.php
```

## Fynd Private Extension Configuration

Use these URLs in Fynd Partner settings:

```text
Extension URL:
https://fynd.rishikeshjagdale.com

Install / Launch URL:
https://fynd.rishikeshjagdale.com/fp/install

OAuth Redirect URI:
https://fynd.rishikeshjagdale.com/fp/auth
```

OAuth flow used by this project:

```text
Fynd -> /fp/install -> Fynd consent -> /fp/auth?code=... -> token storage -> catalog API access
```

## How To Run The Working Demo

1. Open `setup.php` and create the tables.
2. Launch the private extension from Fynd so OAuth completes.
3. Open `ingestor.php` and confirm token status is healthy.
4. Open `transformer.php`.
5. Upload `legacy_products.csv` to demonstrate validation, or upload `sample_products_template.csv` to demonstrate the happy path.
6. Review accepted and rejected rows.
7. Open `ingestor.php`.
8. Save or verify Catalog Defaults.
9. Click `Check All Payloads`.
10. Click `Ingest Pending Products`.

## Required PHP Features

- PHP with cURL
- PDO
- `pdo_mysql`
- MySQL or MariaDB

No Node.js runtime is required for this implementation.

## Important API Endpoints Used

Authentication:

```text
GET  /fp/install
GET  /fp/auth
POST https://api.fynd.com/service/panel/authentication/v1.0/company/15749/oauth/offline-token
```

Catalog:

```text
POST https://api.fynd.com/service/platform/catalog/v3.0/company/15749/products/
GET  https://api.fynd.com/service/platform/catalog/v1.0/company/15749/products/templates/
GET  https://api.fynd.com/service/platform/catalog/v1.0/company/15749/products/templates/categories/
GET  https://api.fynd.com/service/platform/catalog/v1.0/company/15749/marketplaces/company-brand-details/
GET  https://api.fynd.com/service/platform/catalog/v1.0/company/15749/taxes/rules
```

## Troubleshooting

### No stored Fynd token

Launch the private extension from Fynd first so `/fp/auth` receives `?code=...`.

### Metadata resolution failure

Open `metadata.php`, confirm the working brand/category/department/tax mapping, save Catalog Defaults, and retry preflight.

### Product payload rejected

Check `ingest_debug.txt` and compare the last request payload against the known working structure.

### Setup error

Verify:

- PHP version
- `pdo_mysql` enabled
- database credentials
- cPanel error log

## Deliverables Checklist

- Extension and transformer code: included
- Sample input file: included
- Working platform ingestion proof: successful API response confirmed
- Platform behaviours / blockers note: included in `PLATFORM_BEHAVIOURS_AND_BLOCKERS.md`
- Product screenshots from Fynd product detail pages: capture after successful product creation

## Screenshot Checklist

Take screenshots from Fynd product detail pages showing:

- product name
- SKU / item code
- brand
- category
- tax / HSN
- size
- pricing
- successful product state in catalog

## Note

This submission intentionally keeps the ingestion model simple and reliable for the case study: one validated CSV flow, one stable catalog mapping, and direct product creation through the private extension.

Do not commit or publish production credentials. This project currently reads secrets from `config.php`; move them to environment variables or cPanel-protected config storage for production use. Rotate credentials that have been shared outside the hosting environment.

## Debug Files

The app may write:

```text
token_debug.txt
ingest_debug.txt
metadata_debug.txt
```

These are useful during setup but should not be public or committed.
