# Voucher Print Receipt Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add print receipt functionality for voucher codes — batch print from the generation dialog and individual print from table rows.

**Architecture:** A `printVoucherReceipts()` utility function opens a new browser window with print-optimized content built using safe DOM methods. Called from two places: a "Print All" button in the generation dialog and a print icon on each voucher row. No backend changes.

**Tech Stack:** React, TypeScript, browser `window.open()` + `window.print()`

**Spec:** `docs/superpowers/specs/2026-03-18-voucher-print-design.md`

**Security note:** All user-supplied values (venue name, code, etc.) are inserted using `textContent` to prevent XSS. No innerHTML or document.write is used with user data.

---

### Task 1: Add print utility function and integrate into voucher-codes page

**Files:**
- Modify: `resources/js/Pages/Admin/voucher-codes.tsx`

- [ ] **Step 1: Add the `printVoucherReceipts` function**

Add this interface and function after the `exportCodes` function (after line 226) in `resources/js/Pages/Admin/voucher-codes.tsx`:

```typescript
interface PrintableVoucher {
    code: string;
    balance: number;
    currency: string;
    venueName: string;
    pin?: string;
    expiresAt?: string | null;
    createdAt: string;
}

const printVoucherReceipts = (vouchers: PrintableVoucher[]) => {
    const printWindow = window.open('', '_blank');
    if (!printWindow) return;

    const doc = printWindow.document;
    doc.title = 'Voucher Receipts';

    // Add print styles
    const style = doc.createElement('style');
    style.textContent = [
        '@page { margin: 10mm; }',
        'body { font-family: "Courier New", monospace; margin: 0; padding: 0; }',
        '.receipt { width: 80mm; padding: 5mm 0; page-break-after: always; }',
        '.receipt:last-child { page-break-after: auto; }',
        '.divider { font-size: 12px; text-align: center; margin: 4px 0; }',
        '.venue { font-size: 14px; font-weight: bold; text-align: center; margin: 8px 0; }',
        '.section { margin: 8px 0; padding: 0 4px; }',
        '.label { font-size: 11px; color: #666; }',
        '.code { font-size: 16px; font-weight: bold; letter-spacing: 2px; margin-top: 2px; }',
        '.value { font-size: 13px; font-weight: bold; margin-top: 2px; }',
    ].join('\n');
    doc.head.appendChild(style);

    const createTextDiv = (className: string, text: string): HTMLDivElement => {
        const div = doc.createElement('div');
        div.className = className;
        div.textContent = text;
        return div;
    };

    const createSection = (label: string, value: string, valueClass: string = 'value'): HTMLDivElement => {
        const section = doc.createElement('div');
        section.className = 'section';
        section.appendChild(createTextDiv('label', label));
        section.appendChild(createTextDiv(valueClass, value));
        return section;
    };

    for (const v of vouchers) {
        const receipt = doc.createElement('div');
        receipt.className = 'receipt';

        const formatBalance = `${v.currency} ${v.balance.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

        receipt.appendChild(createTextDiv('divider', '================================'));
        receipt.appendChild(createTextDiv('venue', v.venueName));
        receipt.appendChild(createTextDiv('divider', '================================'));
        receipt.appendChild(createSection('Voucher Code:', v.code, 'code'));
        receipt.appendChild(createSection('Balance:', formatBalance));
        if (v.pin) {
            receipt.appendChild(createSection('PIN:', v.pin));
        }
        if (v.expiresAt) {
            receipt.appendChild(createSection('Expires:', new Date(v.expiresAt).toLocaleDateString()));
        }
        receipt.appendChild(createSection('Created:', new Date(v.createdAt).toLocaleDateString()));
        receipt.appendChild(createTextDiv('divider', '================================'));

        doc.body.appendChild(receipt);
    }

    doc.close();
    setTimeout(() => printWindow.print(), 200);
};
```

- [ ] **Step 2: Add "Print All" button to the generation dialog**

In the generated codes section (the `{generatedCodes.length > 0 ? (` block around line 469), add a "Print All" button next to the "Export as CSV" button. Replace the single Export button with a row of two buttons:

Find this code (around line 496-501):
```tsx
                        <Button
                            label="Export as CSV"
                            icon="pi pi-download"
                            onClick={exportCodes}
                            className="w-full"
                        />
```

Replace with:
```tsx
                        <div className="flex gap-2">
                            <Button
                                label="Export as CSV"
                                icon="pi pi-download"
                                onClick={exportCodes}
                                className="flex-1"
                            />
                            <Button
                                label="Print All"
                                icon="pi pi-print"
                                severity="secondary"
                                onClick={() => {
                                    const venueName = venues.find((v) => v.uuid === selectedVenue)?.name || 'Unknown Venue';
                                    printVoucherReceipts(
                                        generatedCodes.map((c) => ({
                                            code: c.code,
                                            balance: c.balance,
                                            currency: 'NAD',
                                            venueName,
                                            createdAt: new Date().toISOString(),
                                        })),
                                    );
                                }}
                                className="flex-1"
                            />
                        </div>
```

- [ ] **Step 3: Add print button to the actions column in the table**

Update the `actionsTemplate` function (around line 288) to add a print icon button for every row. Replace:

```tsx
    const actionsTemplate = (rowData: VoucherCode) => (
        <>
            {rowData.status === 'active' && (
                <Button
                    icon="pi pi-times-circle"
                    severity="danger"
                    text
                    size="small"
                    tooltip="Void code"
                    onClick={() => handleVoidCode(rowData.venue.uuid, rowData.uuid)}
                />
            )}
        </>
    );
```

With:

```tsx
    const actionsTemplate = (rowData: VoucherCode) => (
        <div className="flex gap-1">
            <Button
                icon="pi pi-print"
                text
                severity="info"
                size="small"
                tooltip="Print receipt"
                onClick={() =>
                    printVoucherReceipts([
                        {
                            code: rowData.code,
                            balance: rowData.balance,
                            currency: rowData.currency,
                            venueName: rowData.venue.name,
                            expiresAt: rowData.expires_at,
                            createdAt: rowData.created_at,
                        },
                    ])
                }
            />
            {rowData.status === 'active' && (
                <Button
                    icon="pi pi-times-circle"
                    severity="danger"
                    text
                    size="small"
                    tooltip="Void code"
                    onClick={() => handleVoidCode(rowData.venue.uuid, rowData.uuid)}
                />
            )}
        </div>
    );
```

- [ ] **Step 4: Widen the actions column**

Update the actions Column width from `5rem` to `7rem`:

Find:
```tsx
<Column header="Actions" body={actionsTemplate} style={{ width: '5rem' }} />
```

Replace with:
```tsx
<Column header="Actions" body={actionsTemplate} style={{ width: '7rem' }} />
```

- [ ] **Step 5: Build and verify**

```bash
npm run build
```

Expected: Build succeeds with no TypeScript errors.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Admin/voucher-codes.tsx
git commit -m "feat: add print receipt for voucher codes"
```
