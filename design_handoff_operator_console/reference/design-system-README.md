# Chinga Games Design System

> A boutique-private-bank register for a regulated Namibian gaming SSO + admin platform.
> **Direction:** Ink + Brass + Paper.  Hairlines instead of shadows.  Editorial italic instead of decoration.
> Built atop the `rtonata88/chinga-admin-sso` codebase (Laravel 12 + Inertia + React + shadcn).

## What is Chinga?

Chinga Games operates a regulated **gaming / fantasy / wagering** product for the **Namibian market** (NAD currency, +264 phone numbers, Windhoek/Walvis Bay/Oshakati locales). Surfaces:

1. **User-facing SSO portal** — sign-in, KYC, 2FA, sessions, responsible-gaming limits.
2. **Auth flows** — login, 2FA, register.
3. **Admin (ops + compliance)** — players, voucher codes, transactions, KYC review queue, AML/risk.

## Design direction · "Boutique"

The first pass was a warm cream + slate sidebar with a saturated gold. This version pushes the brand from "warm professional" to **"private-bank quiet"** — the register associated with bonded vaults, fine watch faces, and Tiempos-set financial editorials, not casino floors.

| Axis | Choice |
|---|---|
| **Reference** | Private bank · boutique wealth · editorial |
| **Color** | Single brass accent on near-black ink; warm paper as the light alt |
| **Type** | **Instrument Serif** display (regular & italic) + **Instrument Sans** body |
| **Density** | Generous — 32px card padding, 48px page gutter, 80px section breaks |
| **Motif** | Hairline rules · italic editorial labels · brass corners |
| **Imagery** | None. Type, color, and space carry the design |
| **Default mode** | Dark (Ink). Paper is the alt |

## Sources

- **Repo:** [rtonata88/chinga-admin-sso](https://github.com/rtonata88/chinga-admin-sso)
- The source palette, status taxonomy, and surface vocabulary were lifted from `resources/css/app.css`, then re-cast in this premium register.

## Index

| File / folder | What it is |
|---|---|
| `colors_and_type.css` | v2 tokens — Ink / Brass / Paper, Instrument Serif + Sans, hairlines, generous spacing |
| `assets/` | Brand marks (placeholder Laravel logo — see Caveats), favicons |
| `preview/` | Sixteen atomic cards used by the Design System tab |
| `ui_kits/user-portal/index.html` | Player dashboard recreation *(uses v1 tokens — pending re-skin)* |
| `ui_kits/auth/index.html` | Login · 2FA · register *(uses v1 tokens — pending re-skin)* |
| `SKILL.md` | Agent skill manifest |

---

## Content fundamentals

**Voice.** Plain, regulatory, second-person. Players are addressed as "you"; the system never says "we" except when establishing trust ("We'll send a verification code"). Compliance is **stated, not softened**.

**Casing.** Sentence case for headings ("Welcome back", "Two-factor authentication") — never Title Case. Buttons are sentence-case verbs ("Verify identity", "Continue", "Generate codes"). Acronyms remain capitalised: KYC, AML, SSO, OTP, 2FA, NAD.

**Editorial italic.** This system *uses italic display serif as a voice*, not as decoration. Section labels in dossier views (`Account holder`, `Verification level`) are set in italic Instrument Serif. One-line subtitles under headings carry the same warmth.

**Status taxonomy.** Active · Pending · Completed · Declined · Inactive. Set in UPPERCASE with `letter-spacing: 0.08em`, in a hairline pill that takes the status' color (positive moss, caution amber, negative oxblood, info slate).

**Numbers.** `NAD 1,247.50`. Voucher codes uppercase mono, hyphenated every four: `CHGA-9F4K-2P7M-X8Q1`. Phone numbers partially masked: `+264 81 ••• 4422`.

**No emoji. No exclamation marks. No marketing flourish.**

---

## Visual foundations

**The defining choice.** Near-black `#0E0D0B` page, a single brass accent `#B68A3E`, paper `#F2EDE3` as the alt. **The system has one accent.** Every other "color" in the system (semantics included) is a muted earth-tone — moss, amber, oxblood, slate — used at 100% only for state, never for hierarchy.

**Surfaces ladder up by ~5–8% lightness:** Rail (darkest) → Page → Elevated → Card. The hierarchy is felt rather than seen — you read the difference, you don't measure it.

**Type.** **Instrument Serif** for display (regular and italic) — used at 30px+ and never below. **Instrument Sans** for everything else: body, labels, buttons, navigation. System mono for codes / IDs / amounts. There is no third typeface.

**Hairlines, not shadows.** A 1px paper-on-ink rule at 8% does the structural work that shadows usually do. Three weights: standard (`rgba(242,237,227,0.08)`), strong (`0.16`), brass (`rgba(182,138,62,0.32)`). The brass rule is the loudest line in the system.

**Lift.** Three layers, all hairline-led:
- `--cg-lift-0` — `0 0 0 1px rgba(...,.08)` — resting card
- `--cg-lift-1` — `0 0 0 1px rgba(...,.16)` — hovered card
- `--cg-lift-brass` — brass hairline + a soft `24px 60px` brass glow at 35% — used **once per screen**, on the most important card

There are no other shadows. A modal gets a `0 40px 100px -20px rgba(0,0,0,.7)` haze, and that is all.

**Radii.** `0 / 2 / 4 / 8 / 16 / pill`. Default is **flat** — radius is a softening, not a default. Buttons, cards, and inputs are 8px. Status badges are pills.

**Spacing.** `4 / 8 / 12 / 16 / 20 / 24 / 32 / 40 / 56 / 80 / 120`. Card padding is **32px**. Page gutter is **48px**. Section breaks are **80px**. The system breathes; if a screen feels crowded, double the spacing before you change anything else.

**Inputs are bare.** No box. Label above (uppercase, 11px, 0.18em tracking, muted). A single hairline beneath. Focus turns the hairline brass. Errors turn it oxblood. The typography does the framing.

**Buttons.** Three only:
- **Primary** — solid brass, ink text, brass-hi on hover. One per region.
- **Ghost** — hairline border, brass on hover.
- **Text** — no chrome, brass-on-hover, used for inline links and "Resend code →"-style affordances.

**Motion.** 220ms · `cubic-bezier(0.32, 0.72, 0.24, 1)`. Properties animated: `background`, `border-color`, `color`, `box-shadow`. **Never transform or scale.** Buttons darken; cards' borders strengthen; nothing shifts position.

**Imagery.** None ships with the system. If imagery is later added it should be **photographic, warm-graded, low-saturation**, set against ink with generous margin. **No illustrations. No gradients. No mascots. No grain noise.**

**Cards.** 8px radius, 1px hairline border, ink-card fill (`#1A1814`), 32px padding. The brass-washed variant adds a soft radial wash from the top-right and switches to the brass hairline — used only for the most important card.

**Patterns.** Two recurring patterns are in the kit:
- **Dossier line** — italic-serif key on the left, plain-sans value, mono meta on the right, separated by dotted hairlines.
- **Hairline frame with brass corners** — used for monograms and standalone marks.

---

## Iconography

The codebase doesn't ship a custom icon set. Two libraries are sanctioned:

- **Lucide React** for player + auth surfaces. 1.5–2px stroke, rounded line caps, 16–20px nominal. CDN: `https://unpkg.com/lucide@latest`. **Stroke only — no filled variants.**
- **PrimeIcons** historically for admin chrome. *Note:* the Acumatica admin UI kit was deliberately removed in this redesign — the admin surface should be re-imagined in the boutique register before re-introducing.

Rules: **no emoji**, **no unicode glyphs as icons**, **no hand-rolled SVG** beyond the monogram pattern. Stroke icons inherit `currentColor`.

---

## Caveats / open asks

1. **Logo is still a Laravel placeholder.** Need a real wordmark + monogram + light/dark variants.
2. **Fonts are CDN-substituted.** Instrument Serif + Sans (both Rodrigo Fuenzalida, free, Google Fonts). If Chinga has licensed brand faces, send the `.woff2`s and I'll swap.
3. **UI kits not yet re-skinned.** `ui_kits/user-portal/` and `ui_kits/auth/` still ship the v1 (warm cream + saturated gold) treatment. They're functional reference but visually behind the v2 tokens. Want me to bring them forward in the next pass.
4. **Admin (Acumatica) UI kit removed**, per your direction. Reintroducing it boutique-style is a worthwhile next milestone.
5. **No imagery yet.** Direction calls for photographic, warm-graded — ask me to compose treatment guidelines + placeholder slots when ready.

---

## How to use

- Always link `colors_and_type.css` from the project root and use `--cg-*` properties — never hand-pick hex codes.
- Default to `<body class="cg-ink">` for player and admin surfaces; `<body class="cg-paper">` for documents and exports.
- Reach for `cg-eyebrow`, `cg-display`, `cg-h1`, `cg-h2`, `cg-h3`, `cg-body`, `cg-meta`, `cg-mono`, `cg-italic`.
- Use `<hr class="cg-rule">` / `cg-rule-x` / `cg-rule-brass` to structure — these are the spine.
- One brass accent per region. If a screen has more than one brass element, you're off-brand.
