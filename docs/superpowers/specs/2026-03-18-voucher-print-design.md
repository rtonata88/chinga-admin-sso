# Voucher Print Receipt — Design Spec

## Overview

Add a print-ready receipt for voucher codes. Available from the generation dialog (batch) and individual voucher rows. Receipt is simple till-slip style.

## Receipt Content

- Venue name
- Voucher code (full, unmasked)
- Initial balance + currency
- PIN (if set — shown once)
- Expiry date (if set)
- Date created

## Print Triggers

### 1. Generation Dialog — Batch Print

After codes are generated, add a "Print All" button next to the existing "Export CSV" button. Opens a print-optimized popup with all generated codes, one receipt per code, separated by page breaks.

### 2. Individual Row — Print Button

Add a printer icon button to each voucher code row in the table. Opens a print popup for that single voucher.

## Implementation

**Frontend only** — no backend changes needed. All data is already available:
- Generated codes come back in the API response with full code, balance, currency, venue info
- Table rows have code, balance, venue, status, dates

**Print mechanism:**
1. Open a new window with inline HTML
2. Style with `@media print` CSS for clean receipt output
3. Auto-trigger `window.print()` on load
4. Receipt width: ~80mm (standard thermal receipt width), also works on A4

**Receipt layout (per voucher):**
```
================================
        [VENUE NAME]
================================

  Voucher Code:
  XXXX-XXXX-XXXX-XXXX

  Balance: NAD 100.00

  PIN: 1234          (if set)
  Expires: 2026-04-18 (if set)

  Created: 2026-03-18

================================
```

For batch print: each receipt separated by `page-break-after: always`.

## Files to Modify

- `resources/js/Pages/Admin/voucher-codes.tsx` — add print button to generation dialog and individual rows, add print utility function
