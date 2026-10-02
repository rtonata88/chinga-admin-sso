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
        /* Numeric headings sit over right-aligned figures; this rule must outrank table.games th above. */
        table.games th.r { text-align: right; }
        table.games th.r, table.games td.r { padding-left: 12px; white-space: nowrap; }
        table.games tr.unavailable td { color: var(--muted); font-style: italic; }
        table.games tfoot td { border-top: 1px solid var(--rule-strong); border-bottom: 0; font-weight: 600; }
        table.games tfoot td.label-cell { color: var(--ink); }
        .stamp {
            display: inline-block; padding: 3px 10px; border-radius: 3px;
            font-size: 8pt; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase;
            border: 1.5px solid currentColor;
        }
        .stamp.preview { color: var(--muted); }
        .stamp.issued { color: #1d4ed8; }
        .stamp.part_paid { color: #b45309; }
        .stamp.paid { color: #15803d; }
        .stamp.void { color: #b42318; }
        .stamp.overdue { color: #b42318; }
        .payto {
            margin-top: 5mm; padding: 8px 12px; border: 1px solid var(--rule-strong); border-radius: 4px;
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 6mm; font-size: 9pt;
        }
        .payto .label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.14em; color: var(--muted); font-weight: 600; margin-bottom: 2px; }
        .payto .value { color: var(--ink); font-variant-numeric: tabular-nums; }
        .ops { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .ops form { display: inline-flex; gap: 6px; align-items: center; margin: 0; }
        .ops input, .ops select {
            height: 32px; padding: 0 8px; font: inherit; font-size: 13px; border: 1px solid var(--rule-strong); border-radius: 6px; background: #fff;
        }
        .ops input[name=amount] { width: 110px; } .ops input[name=reference] { width: 140px; } .ops input[name=note] { width: 160px; }
        .flash { max-width: 210mm; margin: 10px auto 0; padding: 8px 8mm; font-size: 13px; color: #15803d; }
        .flash.error { color: #b42318; }
        @media print { .flash { display: none !important; } }
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

    @php
        $status = $invoice['status'] ?? 'preview';
        $isPreview = $status === 'preview';
        $outstanding = (float) ($invoice['outstanding'] ?? $breakdown['amount_due']);
    @endphp
    <div class="toolbar">
        <h1>{{ $isPreview ? 'Invoice preview · not yet issued' : 'Invoice '.$invoice['number'] }}</h1>
        <div class="btn-row ops">
            <a href="{{ url('/invoices') }}" class="btn">All invoices</a>
            @if ($isPreview && ! empty($invoice['can_issue']))
                <form method="post" action="{{ $invoice['issue_url'] }}" onsubmit="return confirm('Issue invoice {{ $invoice['number'] }} for this period? The figures are frozen as shown.');">
                    @csrf
                    <input type="hidden" name="from" value="{{ $invoice['period_query']['from'] }}">
                    <input type="hidden" name="to" value="{{ $invoice['period_query']['to'] }}">
                    <button type="submit" class="btn">Issue invoice</button>
                </form>
            @endif
            @if (! $isPreview && ! empty($invoice['can_manage']) && ! in_array($status, ['paid', 'void'], true))
                <form method="post" action="{{ $invoice['payment_url'] }}">
                    @csrf
                    <input type="number" name="amount" step="0.01" min="0.01" max="{{ number_format($outstanding, 2, '.', '') }}" value="{{ number_format($outstanding, 2, '.', '') }}" required aria-label="Amount">
                    <input type="date" name="paid_at" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required aria-label="Paid on">
                    <select name="method" aria-label="Method">
                        <option value="bank_transfer">Bank transfer</option>
                        <option value="cash">Cash</option>
                        <option value="other">Other</option>
                    </select>
                    <input type="text" name="reference" placeholder="Reference" aria-label="Reference">
                    <button type="submit" class="btn btn--primary">Record payment</button>
                </form>
                @if (empty($invoice['amount_paid']))
                    <form method="post" action="{{ $invoice['void_url'] }}" onsubmit="return confirm('Void invoice {{ $invoice['number'] }}? It stays on file as void.');">
                        @csrf
                        <button type="submit" class="btn">Void</button>
                    </form>
                @endif
            @endif
            <button type="button" class="btn {{ $isPreview ? '' : 'btn--primary' }}" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>
    @if (session('success'))
        <div class="flash">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash error">{{ $errors->first() }}</div>
    @endif

    <div class="sheet">
        <div class="doc-head">
            <div class="brand">
                <div class="logo">{{ $company['trading_name'] ?? ($company['name'] === 'Chinga Platform' ? 'Chinga' : $company['name']) }}</div>
                <div class="tagline">{{ $company['address_lines'] ? implode(' · ', array_slice($company['address_lines'], -2)) : 'Gaming platform · Windhoek, Namibia' }}</div>
            </div>
            <div class="invoice-title">
                <div class="word">INVOICE</div>
                <div class="number">{{ $invoice['number'] }}</div>
                <div style="margin-top: 6px;">
                    @if ($isPreview)
                        <span class="stamp preview">Preview</span>
                    @elseif ($status === 'paid')
                        <span class="stamp paid">Paid{{ ! empty($invoice['paid_at']) ? ' · '.$fmtDate($invoice['paid_at']) : '' }}</span>
                    @elseif ($status === 'void')
                        <span class="stamp void">Void</span>
                    @elseif (! empty($invoice['overdue']))
                        <span class="stamp overdue">Overdue</span>
                    @elseif ($status === 'part_paid')
                        <span class="stamp part_paid">Part paid</span>
                    @else
                        <span class="stamp issued">Issued</span>
                    @endif
                </div>
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
                <div class="name">{{ $company['name'] }}</div>
                @if ($company['registration_number'])<div class="sub">Reg. no. {{ $company['registration_number'] }}</div>@endif
                @if ($company['vat_number'])<div class="sub">VAT no. {{ $company['vat_number'] }}</div>@endif
                @foreach ($company['address_lines'] as $line)<div class="sub">{{ $line }}</div>@endforeach
                <div class="sub">{{ $company['email'] }}{{ $company['phone'] ? ' · '.$company['phone'] : '' }}</div>
            </div>
            <div>
                <div class="label">Bill to</div>
                <div class="name">{{ $tenant['name'] }}</div>
                @if (! empty($tenant['legal_name']) && $tenant['legal_name'] !== $tenant['name'])<div class="sub">{{ $tenant['legal_name'] }}</div>@endif
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
                @if (count($activity['games']) > 0)
                <tfoot>
                    <tr class="games-total">
                        <td class="label-cell">Total</td>
                        <td class="r">{{ number_format($activity['bets_placed']) }}</td>
                        <td class="r">{{ number_format($activity['active_players']) }}</td>
                        <td class="r">{{ $fmtMoney($activity['total_wagered']) }}</td>
                        <td class="r">{{ $fmtMoney($activity['total_paid_out']) }}</td>
                        <td class="r">{{ $fmtMoney($breakdown['ggr']) }}</td>
                    </tr>
                </tfoot>
                @endif
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
                <div class="label">{{ ! $isPreview && ! empty($invoice['amount_paid']) && $status !== 'void' ? 'Outstanding' : 'Amount due' }}</div>
                <div class="amount">{{ $fmtMoney(! $isPreview && $status !== 'void' ? $outstanding : $breakdown['amount_due']) }}</div>
            </div>
            @if (! $isPreview && ! empty($invoice['amount_paid']))
                <table class="calc" style="margin-top: 3mm;">
                    <tbody>
                        <tr class="subtotal"><td>Invoiced</td><td class="r">{{ $fmtMoney($breakdown['amount_due']) }}</td></tr>
                        @foreach ($invoice['payments'] ?? [] as $p)
                            <tr class="deduction">
                                <td>Payment {{ $fmtDate($p['paid_at']) }} · {{ str_replace('_', ' ', $p['method']) }}{{ $p['reference'] ? ' · '.$p['reference'] : '' }}{{ $p['note'] ? ' · '.$p['note'] : '' }}</td>
                                <td class="r">− {{ $fmtMoney($p['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @if ($company['bank'] && $status !== 'void')
                <div class="payto">
                    <div><div class="label">Pay to</div><div class="value">{{ $company['bank']['account_name'] ?? $company['name'] }}</div></div>
                    <div><div class="label">Bank</div><div class="value">{{ $company['bank']['bank_name'] }}</div></div>
                    <div><div class="label">Account</div><div class="value">{{ $company['bank']['account_number'] }}</div></div>
                    <div><div class="label">Branch</div><div class="value">{{ $company['bank']['branch_code'] ?: '—' }}</div></div>
                </div>
            @endif
        </div>

        <div class="terms">
            @if ($status === 'void')
                <strong>Void.</strong> This invoice was cancelled{{ ! empty($invoice['voided_at']) ? ' on '.$fmtDate($invoice['voided_at']) : '' }}{{ ! empty($invoice['void_reason']) ? ': '.$invoice['void_reason'] : '' }}. Nothing is payable against it.
            @else
                <strong>Payment terms.</strong> This invoice is payable within
                {{ $company['payment_terms_days'] }} days of the issue date. Please reference invoice number
                <strong>{{ $invoice['number'] }}</strong> on the bank transfer.
                Late payments may incur interest at the rate set out in the
                reseller agreement. Queries: {{ $company['email'] }}.
            @endif
        </div>
    </div>
</body>
</html>
