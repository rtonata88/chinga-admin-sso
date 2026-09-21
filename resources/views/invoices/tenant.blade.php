<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice['number'] }} · {{ $tenant['name'] }}</title>
    <style>
        /* A4 invoice — same document on screen and on paper. The .sheet
         * box is the printable page; everything outside it (toolbar,
         * page background) is suppressed in print. */
        :root {
            --ink: #111418;
            --ink-soft: #4a5058;
            --muted: #8a8f96;
            --rule: #d8dade;
            --rule-strong: #b6b9be;
            --paper: #ffffff;
            --bg: #f3f4f6;
            --accent: #8a6a2c;
            --accent-bg: #faf3e2;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Inter",
                         "Helvetica Neue", Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            -webkit-font-smoothing: antialiased;
        }
        .toolbar {
            position: sticky; top: 0; z-index: 10;
            display: flex; justify-content: space-between; align-items: center;
            gap: 12px;
            max-width: 210mm;
            margin: 0 auto;
            padding: 14px 8mm;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--rule);
        }
        .toolbar h1 {
            font-size: 13px; font-weight: 600; margin: 0;
            color: var(--muted); letter-spacing: 0.04em;
        }
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            height: 32px; padding: 0 14px;
            font: inherit; font-size: 13px; font-weight: 500;
            color: var(--ink);
            background: #fff;
            border: 1px solid var(--rule-strong);
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: border-color 120ms ease, background 120ms ease;
        }
        .btn:hover { border-color: var(--ink); }
        .btn--primary {
            background: var(--ink); color: #fff; border-color: var(--ink);
        }
        .btn--primary:hover { background: #000; border-color: #000; }
        .btn-row { display: flex; gap: 8px; }

        /* The printable page itself. A4 = 210x297mm; margins handled
         * by @page so the on-screen sheet uses the same content area. */
        .sheet {
            width: 210mm;
            margin: 16px auto;
            padding: 14mm 16mm;
            background: var(--paper);
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 8px 24px rgba(0,0,0,0.06);
        }

        .doc-head {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            padding-bottom: 7mm;
            border-bottom: 1px solid var(--rule);
        }
        .brand .logo {
            font-size: 18pt; font-weight: 700;
            letter-spacing: -0.01em; color: var(--ink);
            line-height: 1;
        }
        .brand .tagline {
            font-size: 9pt; color: var(--muted);
            margin-top: 3px;
        }
        .invoice-title {
            text-align: right;
        }
        .invoice-title .word {
            font-size: 22pt;
            font-weight: 200;
            letter-spacing: 0.16em;
            color: var(--ink);
            line-height: 1;
        }
        .invoice-title .number {
            margin-top: 4px;
            font-size: 10pt;
            color: var(--muted);
            font-variant-numeric: tabular-nums;
            letter-spacing: 0.04em;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8mm;
            margin-top: 6mm;
        }
        .meta-grid .label {
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: var(--muted);
            margin-bottom: 2px;
            font-weight: 600;
        }
        .meta-grid .value {
            font-size: 10pt;
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }
        .meta-grid .value.strong { font-weight: 600; }

        .parties {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12mm;
            margin-top: 6mm;
        }
        .parties .label {
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: var(--muted);
            font-weight: 600;
            margin-bottom: 3px;
        }
        .parties .name {
            font-size: 11.5pt;
            font-weight: 600;
            color: var(--ink);
            margin-bottom: 1px;
        }
        .parties .sub {
            font-size: 9pt;
            color: var(--ink-soft);
        }

        .section {
            margin-top: 6mm;
        }
        .section h2 {
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            color: var(--muted);
            font-weight: 600;
            margin: 0 0 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table td {
            padding: 5px 0;
            font-size: 10pt;
            color: var(--ink);
            border-bottom: 1px solid var(--rule);
            font-variant-numeric: tabular-nums;
            vertical-align: middle;
        }
        table tr:last-child td { border-bottom: 0; }
        td.r, th.r { text-align: right; }
        td.label-cell { color: var(--ink-soft); }
        table.games th {
            padding: 4px 0;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            border-bottom: 1px solid var(--rule-strong);
            text-align: left;
        }
        table.games tr.unavailable td { color: var(--muted); font-style: italic; }
        .incomplete {
            border: 1px solid #b42318;
            color: #b42318;
            padding: 8px 12px;
            margin-bottom: 16px;
            font-size: 10pt;
        }

        .calc tr.subtotal td {
            font-weight: 600;
            color: var(--ink);
            border-top: 1px solid var(--rule-strong);
            border-bottom-color: var(--rule);
        }
        .calc tr.deduction td {
            color: var(--ink-soft);
        }

        /* Total banner — the headline number. Visually distinct without
         * being garish. */
        .total {
            margin-top: 4mm;
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: baseline;
            gap: 16px;
            padding: 10px 14px;
            background: var(--accent-bg);
            border: 1px solid var(--accent);
            border-radius: 4px;
        }
        .total .label {
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            color: var(--accent);
            font-weight: 700;
        }
        .total .amount {
            font-size: 18pt;
            font-weight: 700;
            color: var(--ink);
            font-variant-numeric: tabular-nums;
            letter-spacing: -0.01em;
            line-height: 1;
        }

        .terms {
            margin-top: 6mm;
            padding-top: 4mm;
            border-top: 1px solid var(--rule);
            font-size: 8.5pt;
            color: var(--ink-soft);
            line-height: 1.5;
        }
        .terms strong { color: var(--ink); }

        @page { size: A4; margin: 0; }
        @media print {
            html, body { background: #fff; }
            .toolbar { display: none !important; }
            .sheet {
                width: auto;
                margin: 0;
                padding: 12mm 14mm;
                box-shadow: none;
            }
            .total {
                /* Some browsers strip backgrounds — force it for the
                 * total banner so it stays visually distinct. */
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    @php
        $fmtMoney = fn($n) => 'N$' . number_format((float) $n, 2, '.', ',');
        $fmtDate  = fn($iso) => \Carbon\Carbon::parse($iso)->format('j M Y');
    @endphp

    <div class="toolbar">
        <h1>Invoice preview</h1>
        <div class="btn-row">
            <a href="{{ url('/tenant-overview') }}" class="btn">Back</a>
            <button type="button" class="btn btn--primary" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>

    <div class="sheet">
        <div class="doc-head">
            <div class="brand">
                <div class="logo">Chinga</div>
                <div class="tagline">Gaming platform · Windhoek, Namibia</div>
            </div>
            <div class="invoice-title">
                <div class="word">INVOICE</div>
                <div class="number">{{ $invoice['number'] }}</div>
            </div>
        </div>

        <div class="meta-grid">
            <div>
                <div class="label">Issued</div>
                <div class="value">{{ $fmtDate($invoice['issued_at']) }}</div>
            </div>
            <div>
                <div class="label">Due</div>
                <div class="value strong">{{ $fmtDate($invoice['due_at']) }}</div>
            </div>
            <div>
                <div class="label">Period</div>
                <div class="value">{{ $fmtDate($period['from']) }} – {{ $fmtDate($period['to']) }}</div>
            </div>
            <div>
                <div class="label">Reference</div>
                <div class="value">{{ $invoice['number'] }}</div>
            </div>
        </div>

        <div class="parties">
            <div>
                <div class="label">From</div>
                <div class="name">Chinga Platform</div>
                <div class="sub">platform@playchinga.com</div>
            </div>
            <div>
                <div class="label">Bill to</div>
                <div class="name">{{ $tenant['name'] }}</div>
            </div>
        </div>

        @if (! $invoice['complete'])
            <div class="incomplete">
                <strong>Incomplete.</strong> Figures could not be fetched for
                {{ implode(', ', $activity['unavailable']) }}. Regenerate this invoice once the backend is reachable; the amount below excludes that activity.
            </div>
        @endif

        <div class="section">
            <h2>Activity by game</h2>
            <table class="games">
                <thead>
                    <tr>
                        <th>Game</th>
                        <th class="r">Bets</th>
                        <th class="r">Players</th>
                        <th class="r">Wagered</th>
                        <th class="r">Paid out</th>
                        <th class="r">GGR</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($activity['games'] as $g)
                        <tr>
                            <td class="label-cell">{{ $g['name'] }}</td>
                            <td class="r">{{ number_format($g['bets_placed']) }}</td>
                            <td class="r">{{ number_format($g['active_players']) }}</td>
                            <td class="r">{{ $fmtMoney($g['total_wagered']) }}</td>
                            <td class="r">{{ $fmtMoney($g['total_paid_out']) }}</td>
                            <td class="r">{{ $fmtMoney($g['ggr']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="label-cell" colspan="6">No game backends in the catalogue.</td></tr>
                    @endforelse
                    @foreach ($activity['unavailable'] as $name)
                        <tr class="unavailable">
                            <td class="label-cell">{{ $name }}</td>
                            <td class="r" colspan="5">unavailable</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>Activity</h2>
            <table>
                <tbody>
                    <tr>
                        <td class="label-cell">Bets placed</td>
                        <td class="r">{{ number_format($activity['bets_placed']) }}</td>
                    </tr>
                    <tr>
                        <td class="label-cell">Active players</td>
                        <td class="r">{{ number_format($activity['active_players']) }}</td>
                    </tr>
                    <tr>
                        <td class="label-cell">Total wagered</td>
                        <td class="r">{{ $fmtMoney($activity['total_wagered']) }}</td>
                    </tr>
                    <tr>
                        <td class="label-cell">Total paid out (wins)</td>
                        <td class="r">{{ $fmtMoney($activity['total_paid_out']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>Calculation</h2>
            <table class="calc">
                <tbody>
                    <tr>
                        <td>Gross Gaming Revenue (Wagered − Wins)</td>
                        <td class="r">{{ $fmtMoney($breakdown['ggr']) }}</td>
                    </tr>
                    <tr class="deduction">
                        <td>Less: Tax ({{ number_format($tenant['tax_pct'], 0) }}%)</td>
                        <td class="r">− {{ $fmtMoney($breakdown['tax']) }}</td>
                    </tr>
                    <tr class="subtotal">
                        <td>Net Gaming Revenue</td>
                        <td class="r">{{ $fmtMoney($breakdown['ngr']) }}</td>
                    </tr>
                    <tr class="deduction">
                        <td>Less: Tenant share ({{ number_format($tenant['revenue_share_pct'], 0) }}%)</td>
                        <td class="r">− {{ $fmtMoney($breakdown['tenant_profit']) }}</td>
                    </tr>
                </tbody>
            </table>

            <div class="total">
                <div class="label">Amount due</div>
                <div class="amount">{{ $fmtMoney($breakdown['amount_due']) }}</div>
            </div>
        </div>

        <div class="terms">
            <strong>Payment terms.</strong> This invoice is payable within
            14 days of the issue date. Please reference invoice number
            <strong>{{ $invoice['number'] }}</strong> on the bank transfer.
            Late payments may incur interest at the rate set out in the
            reseller agreement. Queries: platform@playchinga.com.
        </div>
    </div>
</body>
</html>
