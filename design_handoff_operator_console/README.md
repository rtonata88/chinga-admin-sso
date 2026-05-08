# Handoff: Chinga Games — Operator Console (Live Wagers Monitor)

## Overview

This bundle contains the visual design for the **Chinga Games operator console** — the internal backend used by trading / ops staff to monitor live wagers, payouts, and player activity. The first screen designed is the **Live Wagers Monitor**, the trading desk's main daily view.

The bundle also includes the full **Chinga Games design system** (colors, type, spacing, components) so the same visual language can be applied to every other screen in the admin going forward.

**Target codebase:** `rtonata88/chinga-admin-sso` (Laravel).

---

## About the Design Files

The HTML files in this bundle are **design references**, not production code. They are static prototypes that show the intended look, layout, and behavior.

Your job is to **recreate them in the target codebase's existing environment** — Laravel Blade templates (with Livewire, Alpine.js, or whatever the project uses), styled with the bundled CSS tokens. Do not copy the HTML markup verbatim into Blade; instead, rebuild each screen using the project's existing component patterns, partials, and layout structure.

The bundled CSS file (`colors_and_type.css`) **is** intended for production use — drop it into the project's asset pipeline (e.g. `resources/css/`), and import it from the main app stylesheet.

---

## Fidelity

**High-fidelity (hifi).** Final colors, typography, spacing, and interactions are locked in. Recreate pixel-perfectly using the codebase's existing tooling. All values are tokenized in `colors_and_type.css` — use those CSS variables rather than hardcoding hex/px values.

---

## What's in this bundle

```
design_handoff_operator_console/
├── README.md                              ← you are here
├── colors_and_type.css                    ← THE design system. Drop into resources/css/.
├── reference/
│   ├── live-wagers-monitor.html           ← Sample screen (visual reference)
│   └── design-system-README.md            ← Full design system docs
```

---

## Implementation Order (suggested)

1. **Install the design system.** Copy `colors_and_type.css` into `resources/css/` and `@import` it from the project's main stylesheet. Verify Google Fonts (Archivo, Archivo Narrow, Manrope, JetBrains Mono) load. Replace any existing color/typography variables with the new ones.
2. **Build the layout shell.** Sidebar + topbar (see "Layout Shell" below). This becomes the base layout for every admin screen.
3. **Build the Live Wagers Monitor screen.** Use the reference HTML to match the page structure: page header → KPI strip → filter bar → table → pagination.
4. **Replace mock data with real Eloquent queries** from the existing wagers / players / events models.

---

## Design Tokens

All tokens are defined as CSS custom properties in `colors_and_type.css` under `:root`. Reference them like `var(--cg-brass)` — never hardcode hex values.

### Colors

**Background (ink — dark is the hero theme)**
| Token | Hex | Use |
|---|---|---|
| `--cg-ink` | `#0E0D0B` | Page background |
| `--cg-ink-elevated` | `#15130F` | Hover surfaces, table headers |
| `--cg-ink-card` | `#1A1814` | Card background |
| `--cg-ink-rail` | `#0A0907` | Sidebar (deepest) |

**Brass (the only accent)**
| Token | Hex | Use |
|---|---|---|
| `--cg-brass` | `#B68A3E` | Primary buttons, logo mark, active state |
| `--cg-brass-hi` | `#D6B782` | Hover, large numbers, "potential payout" |
| `--cg-brass-lo` | `#8A6826` | Pressed state |
| `--cg-brass-wash` | `rgba(182, 138, 62, 0.06)` | Active nav background tint |
| `--cg-brass-wash-2` | `rgba(182, 138, 62, 0.12)` | Stronger active tint |

**Paper (light theme alt — not used in operator console v1)**
| Token | Hex |
|---|---|
| `--cg-paper` | `#F2EDE3` |
| `--cg-paper-elevated` | `#F6F1E6` |
| `--cg-paper-card` | `#FBF7EE` |
| `--cg-ink-on-paper` | `#1A1813` |

**Text on dark**
| Token | Hex | Use |
|---|---|---|
| `--cg-fg-1` | `#F2EDE3` | Primary text |
| `--cg-fg-2` | `#C8BDA3` | Secondary text |
| `--cg-fg-3` | `#8A8068` | Tertiary / labels / muted |
| `--cg-fg-4` | `#5C5440` | Subtle hint, very muted |

**Hairlines (the brand's signature element — used everywhere instead of shadows)**
| Token | Value |
|---|---|
| `--cg-rule` | `rgba(242, 237, 227, 0.08)` |
| `--cg-rule-strong` | `rgba(242, 237, 227, 0.16)` |
| `--cg-rule-brass` | `rgba(182, 138, 62, 0.32)` |

**Semantic**
| Token | Hex | Use |
|---|---|---|
| `--cg-pos` | `#6FA86A` | Live, positive deltas, success |
| `--cg-warn` | `#C99441` | Pending |
| `--cg-neg` | `#B5524C` | Flagged, AML alerts, negative deltas |
| `--cg-info` | `#6B8AB8` | Settled / informational |

### Typography

**Fonts (loaded from Google Fonts in the CSS)**
- **Archivo** — display headlines (weights 500, 600, 700, 800, 900)
- **Archivo Narrow** — big numbers / KPIs (weights 600, 700)
- **Manrope** — body, UI text (weights 400, 500, 600, 700)
- **JetBrains Mono** — IDs, timestamps, deltas (weights 400, 500, 600)

**Variables**
| Token | Value |
|---|---|
| `--cg-display` | Archivo, sans-serif |
| `--cg-condensed` | Archivo Narrow, sans-serif |
| `--cg-body` | Manrope, sans-serif |
| `--cg-mono` | JetBrains Mono, monospace |

**Sizes**
| Token | Value | Use |
|---|---|---|
| `--cg-fs-micro` | 11px | Eyebrows, badges |
| `--cg-fs-small` | 12px | Captions, meta |
| `--cg-fs-body` | 14px | Default body |
| `--cg-fs-md` | 15px | Generous body |
| `--cg-fs-lg` | 18px | Card titles |
| `--cg-fs-xl` | 24px | H3 |
| `--cg-fs-2xl` | 34px | H2 |
| `--cg-fs-3xl` | 52px | H1 |
| `--cg-fs-4xl` | 76px | Hero |

**Tabular numbers (CRITICAL):** any element displaying numeric data (stakes, odds, payouts, counts) must use `font-feature-settings: 'tnum' 1;` so digits align in columns. This is already baked into `.cg-mono` and `.cg-num`. Use those classes.

### Spacing

8px scale. Tokens: `--cg-s-1` (4px), `--cg-s-2` (8px), `--cg-s-3` (12px), `--cg-s-4` (16px), `--cg-s-5` (20px), `--cg-s-6` (24px), `--cg-s-7` (32px), `--cg-s-8` (40px), `--cg-s-9` (56px), `--cg-s-10` (80px), `--cg-s-11` (120px).

### Radii

Sharp corners — flat is premium. `--cg-r-0` (0), `--cg-r-1` (2px), `--cg-r-2` (4px), `--cg-r-3` (8px — cards/inputs), `--cg-r-4` (16px — dialogs), `--cg-r-pill` (9999px — status pills).

### Lift (almost-no-shadow elevation)

Use hairlines, not drop shadows.
- `--cg-lift-0` — default card outline
- `--cg-lift-1` — hover outline
- `--cg-lift-brass` — featured card (brass border + soft brass glow)
- `--cg-lift-modal` — dialogs only

### Motion

- `--cg-ease`: `cubic-bezier(0.32, 0.72, 0.24, 1)`
- `--cg-dur-fast`: 140ms (hover, focus)
- `--cg-dur`: 220ms (default)
- `--cg-dur-slow`: 420ms (page transitions)

### Layout constants

- `--cg-rail`: 280px (sidebar width — operator console uses 248px override)
- `--cg-topbar`: 64px
- `--cg-page-max`: 1240px
- `--cg-gutter`: 48px

---

## Layout Shell (used by every admin screen)

```
┌─────────┬──────────────────────────────────────┐
│         │  Topbar (64px) — breadcrumb · ⌘K · 🔔 │
│ Sidebar ├──────────────────────────────────────┤
│ 248px   │                                      │
│         │  Page content (32px gutter)          │
│         │                                      │
└─────────┴──────────────────────────────────────┘
```

### Sidebar (`.rail`)

- Width: `248px`, full height, `position: sticky; top: 0;`
- Background: `var(--cg-ink-rail)` (darker than page)
- Right border: `1px solid var(--cg-rule)`
- Brand block at top (logo + product name), divided by hairline
- Nav grouped into labelled sections: **Trading**, **Customer**, **Finance** (extend as needed)
- Group label: 10px uppercase, letter-spacing 0.22em, color `--cg-fg-4`
- Nav item: 10px 12px padding, 13.5px text, `--cg-fg-2`. On hover: bg `rgba(242,237,227,0.04)`, color `--cg-fg-1`. Active state: bg `--cg-brass-wash-2`, color `--cg-brass-hi`, `box-shadow: inset 2px 0 0 var(--cg-brass)` (left-edge brass strip).
- Right-aligned numeric badges use `--cg-neg` background — for counts that need attention.
- Footer: avatar + operator name + role, separated by hairline.

### Topbar (`.topbar`)

- Height: 64px, sticky, page background, bottom hairline.
- Left: breadcrumb (`Trading / Live wagers`).
- Right (in order): search input (320px wide, hairline border, 6px radius, `⌘K` kbd hint), notification bell with red dot, settings/clock icon.

---

## Screen: Live Wagers Monitor

### Purpose
Trading desk's primary view. Operators monitor every open wager in real time, spot AML/velocity flags, and trigger settlements.

### Layout (top to bottom)

1. **Page header** (28px bottom margin) — eyebrow, title, "Live · auto-refresh 5s" pulse pill, last-updated timestamp, action buttons aligned right.
2. **KPI strip** — 4 cards in a row, hairline-separated. 32px bottom margin.
3. **Filter bar** — chips, attached to the top of the table.
4. **Wagers table** — full data, hairline-separated rows, hoverable.
5. **Table footer** — count + pagination.

### Page header

- **Eyebrow**: "Trading desk" — `.cg-eyebrow` style
- **Title**: 32px Archivo 800, letter-spacing -0.015em
- **Live pill**: inline next to title — green dot pulsing 1.4s ease-in-out, "Live · auto-refresh 5s" text in `--cg-pos`, 11px uppercase 0.18em
- **Subtitle**: 13.5px `--cg-fg-3`, 8px below title
- **Action buttons** (right-aligned): "New rule" (ghost), "Export CSV" (ghost), "Settle batch" (primary brass)

### KPI strip

4 equal columns. Each KPI:
- 24px padding
- Background: `--cg-ink-card`
- 1px hairlines between (use a `1px` gap on the grid + container background `--cg-rule`)
- 10px uppercase label (`--cg-fg-3`)
- Big number: Archivo Narrow 700, 44px, line-height 1, letter-spacing -0.015em, **tabular numbers required**
- Footer row: delta (with ▲/▼ in `--cg-pos` or `--cg-neg`) + meta in `--cg-fg-3`, both 11px JetBrains Mono

The 4 KPIs (in order):
1. Live wagers — count, `--cg-pos` delta vs. yesterday
2. Handle · 24h — currency in NAD (use brass color), with sparkline (8 vertical bars, brass at 70% opacity)
3. Pending payouts — count, with `--cg-neg` "▲ N over SLA" delta
4. Active players · now — count, `--cg-pos` delta + peak meta

### Filter bar

- 14px 18px padding, 1px hairline, top corners 8px
- Background: `--cg-ink-elevated`
- Chip: 30px tall, 12px horizontal padding, 999px radius, 1px hairline, 12px text
- Active chip: brass border + brass-hi text + `--cg-brass-wash` bg + small brass dot prefix
- Chip with count: count is 10px JetBrains Mono `--cg-fg-3` after the label
- Order: All / Live / Pending / Flagged / Settled, then divider, then dropdowns: Sport / Stake range / Time range
- Right side: "Sort" label + sort chip

### Wagers table

Columns (left to right):
1. **Time** (130px) — JetBrains Mono 11.5px, two lines (`HH:MM:SS` then date in `--cg-fg-4` 10px)
2. **Player** — avatar (28px circle, brand mark style) + name (13px 600) + ID/tag (11px JetBrains Mono `--cg-fg-4`)
3. **Event & market** — event name 13px 500, market in 11.5px `--cg-fg-3` below
4. **Selection** — pick highlighted in `--cg-brass-hi` 600
5. **Stake** (right-aligned) — Archivo Narrow 700 18px, currency code in 10px `--cg-fg-3` 0.1em letter-spacing prefix
6. **Odds** (right-aligned) — JetBrains Mono 13.5px, tabular
7. **Potential** (right-aligned) — Archivo Narrow 700 17px in `--cg-brass-hi`
8. **Status** — pill (see Status Pills below)
9. **Actions** — "⋯" icon button

**Row treatment**
- Padding 16px 18px
- Hairline below each row
- Hover: `rgba(242, 237, 227, 0.025)` bg
- Flagged rows: `rgba(181, 82, 76, 0.05)` bg, `0.09` on hover

**Header**
- Background `--cg-ink-elevated`
- 10px uppercase 0.18em `--cg-fg-3` 600
- Hairline below

### Status pills

```
.pill { padding: 3px 10px; 10px 600 uppercase 0.14em; 999px radius; 1px border currentColor; }
.pill::before { 5px dot, currentColor }

.pill.live      → --cg-pos
.pill.pending   → --cg-warn
.pill.settled   → --cg-info
.pill.flagged   → --cg-neg
.pill.void      → --cg-fg-3
```

### Table footer

- Background `--cg-ink-elevated`, top hairline, bottom corners 8px
- Left: "Showing 1–10 of 1,284 wagers"
- Right: pagination buttons 28×28px with hairline borders. Current page: brass background, ink text.

---

## Components Reference (in `colors_and_type.css`)

These utility classes are pre-built — use them rather than re-styling.

| Class | Purpose |
|---|---|
| `.cg-ink` / `.cg-paper` | Body theme containers |
| `.cg-display` / `.cg-h1` / `.cg-h2` / `.cg-h3` / `.cg-h4` | Display + heading scale |
| `.cg-body` / `.cg-meta` / `.cg-eyebrow` | Body / meta / labels |
| `.cg-mono` / `.cg-num` | Monospace + condensed-number with tabular figures |
| `.cg-rule` / `.cg-rule-x` / `.cg-rule-brass` | Horizontal hairlines |
| `.cg-status` + modifiers | Status pills (alt to `.pill` system) |
| `.cg-btn` + `.cg-btn--primary/--ghost/--text` + `.cg-btn--sm/--lg` | Buttons |
| `.cg-input` / `.cg-select` / `.cg-textarea` | Hairline-beneath form fields |
| `.cg-card` + `.cg-card--brass` | Card surfaces |

---

## Interactions & Behavior

- **Sidebar nav**: clicking changes the active section (left-edge brass strip indicator). Use Laravel routes; the active class can be set via `request()->routeIs('wagers.*') ? 'active' : ''` on each nav item.
- **Live indicator**: green dot pulses 1.4s ease-in-out infinite (CSS keyframes already in the reference HTML — `@keyframes pulse`).
- **Auto-refresh**: live wagers should refresh every 5 seconds. Use Livewire polling (`wire:poll.5s`) or a Laravel Echo (Pusher/Reverb) websocket subscription on the `wagers` channel. Refresh should be silent — no spinner — and the "Live · auto-refresh 5s" pill is the only signal.
- **Filter chips**: clicking a chip filters the table and updates the URL query string (`?status=flagged`). Active chip is the one matching the current query.
- **Search (⌘K)**: global command palette. Out of scope for v1 — leave the input present but inert, or scope to "search this table" only.
- **Row hover**: subtle background lift (already specified).
- **Row click**: opens a side drawer with full wager detail + actions (settle, void, escalate). Out of scope for v1 — the `⋯` action button can be a no-op for now.
- **Status pill colors are not toggleable** — they reflect the wager's actual state.
- **Tabular numbers**: every numeric column must use `font-feature-settings: 'tnum' 1;` so digits align. The `.cg-num` and `.cg-mono` utility classes already handle this.

---

## State Management (for the live wagers screen)

Minimum viable state:
- `wagers[]` — current page of wagers (from the `wagers` table, filtered by status + sport + stake + time range)
- `filters{}` — active filter values (read from query string, written back on chip click)
- `kpis{}` — the 4 top numbers, recomputed on the same poll cycle
- `currentPage` / `perPage` — pagination

If using Livewire: a `LiveWagersMonitor` component with `wire:poll.5s` and the filters as public properties (with `#[Url]` to bind them to the query string).

---

## Data shape (sample wager)

The reference HTML uses placeholder Namibian names + a mix of EPL/AFCON/NBA/F1/casino markets. Real data will come from your existing wager model. Per row, the table needs:

- `placed_at` (datetime, displayed as `HH:MM:SS` + `DD MMM` in CAT)
- `player.full_name`, `player.id` (formatted `PL-NNNNN`), `player.is_vip`, `player.initials` (for avatar)
- `event.name`, `event.competition`, `market.name`, `market.line` (e.g. "Over 224.5")
- `selection.label`, `selection.is_pick` (for the brass highlight)
- `stake.amount`, `stake.currency` (NAD / ZAR / USD)
- `odds` (decimal, 2 dp)
- `potential_payout` (= `stake * odds`, brass-highlighted)
- `status` — one of `live` / `pending` / `settled-win` / `settled-loss` / `flagged-aml` / `flagged-velocity` / `void`
- For accumulators: `legs.total`, `legs.resolved` (rendered as "Live · 2/4")

---

## Assets

- **Logo mark**: `<div class="brand-mark">C</div>` — solid brass square, 32×32, 4px radius, white "C" in Archivo 900. This is a placeholder. Replace with the real Chinga Games SVG logo when available.
- **Icons**: hand-rolled inline SVGs in the reference HTML (16×16, 1.5px stroke). For production, use a consistent icon library — recommended **Lucide** (`lucide-laravel` package or via CDN `https://unpkg.com/lucide-static`). All icons used in the reference (dashboard grid, line graph, clock, ticket, person, shield, bar chart, target, search, bell, plus, arrow) exist in Lucide.
- **Fonts**: loaded from Google Fonts CDN in `colors_and_type.css`. For production performance, consider self-hosting via `@font-face` and `font-display: swap`.

---

## Files in this bundle

- `colors_and_type.css` — production-ready CSS tokens. **Drop this into the codebase.**
- `reference/live-wagers-monitor.html` — visual reference for the Live Wagers screen.
- `reference/design-system-README.md` — full design system docs (colors, content tone, visual foundations, iconography). For broader context.

---

## Caveats / known gaps

- **Logo is a placeholder** (the letter "C" on a brass square). Need the real brand mark.
- **Fonts are Google Fonts** (Archivo, Manrope, JetBrains Mono). If Chinga Games has licensed brand fonts, swap them in via `@font-face` and update `--cg-display` / `--cg-body` / `--cg-mono` to point at the local files.
- **Iconography is hand-rolled SVG** in the reference HTML. Replace with Lucide (or another consistent icon set) in production.
- **Only one screen designed so far** — the Live Wagers Monitor. Other admin screens (Voucher queue, AML alerts, Player profile, KYC review, Reports, Payouts, Settlements) follow the same shell + token system but need to be designed separately.
