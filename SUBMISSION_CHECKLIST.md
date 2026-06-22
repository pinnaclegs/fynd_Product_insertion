# Submission Checklist

Use this checklist before zipping or pushing the Part A submission.

## 1. Code Package

- [ ] Include the full private extension project folder
- [ ] Include `README.md`
- [ ] Include `PLATFORM_BEHAVIOURS_AND_BLOCKERS.md`
- [ ] Include sample CSV files

## 2. Sample Input Files

- [ ] Include `legacy_products.csv` for dirty-data validation proof
- [ ] Include `sample_products_template.csv` for clean happy-path ingestion
- [ ] Include `sample_products_template_v2.csv` for fresh retry/demo ingestion

## 3. Proof of Product Creation

- [ ] Capture screenshots from the Fynd product detail pages
- [ ] Make sure the screenshots show product name and SKU
- [ ] Make sure the screenshots show price
- [ ] Make sure the screenshots show size
- [ ] Make sure the screenshots show HSN
- [ ] Make sure the screenshots show tax if visible on the product page

Suggested screenshot filenames:

- `01-product-overview.png`
- `02-product-pricing.png`
- `03-product-size-tax-hsn.png`
- `04-product-listing-visible-on-fynd.png`

## 4. Platform Behaviour Note

- [ ] Include `PLATFORM_BEHAVIOURS_AND_BLOCKERS.md`
- [ ] Confirm it mentions OAuth flow
- [ ] Confirm it mentions template/category validation
- [ ] Confirm it mentions payload debugging and final successful ingestion

## 5. Final Sanity Check

- [ ] README explains setup and run flow clearly
- [ ] At least one product is confirmed created on Fynd
- [ ] API success proof is preserved in notes or screenshots
- [ ] No unnecessary junk files are added to the final zip

## 6. Suggested Final Zip Name

- `fynd-catalog-transformer-case-study.zip`

## 7. Suggested Folder Layout

```text
fynd-extension/
  README.md
  PLATFORM_BEHAVIOURS_AND_BLOCKERS.md
  SUBMISSION_CHECKLIST.md
  legacy_products.csv
  sample_products_template.csv
  sample_products_template_v2.csv
  screenshots/
    01-product-overview.png
    02-product-pricing.png
    03-product-size-tax-hsn.png
    04-product-listing-visible-on-fynd.png
```
