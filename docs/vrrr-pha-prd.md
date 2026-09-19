# Vrrr Pha — Product Requirements Document

**Product:** Vrrr Pha, a real-time crash game
**Platform:** Chinga Games (chinga-games-sso, multi-tenant)
**Position:** Second game on the platform; first real-time game
**Status:** Draft v1, for build with Claude Code
**Owner:** Richard Chambula, Chinga Games Investment CC

---

## 0. Why this document exists

Chinga Fantasy proved the platform works: SSO, two-pool wallet, game sessions, multi-tenancy, back-office. Vrrr Pha is the second game, and boxing and motorbike racing are behind it. That changes the goal.

This is not "build one more game." It is **"build the second game in a way that makes the third and fourth cheap."** Every decision below is weighed against that. Where Vrrr Pha could reuse a Fantasy pattern, it does. Where the Fantasy pattern would not survive a third game or a real-time game, this document says so explicitly and replaces it.

---

## 1. Product summary

### 1.1 The game

A GTI accelerates. The rev counter climbs, and the multiplier climbs with it. The player cashes out before the engine tops out. Cash out in time and they take stake × multiplier. Leave it too long and the engine blows and the stake is gone.

The DSG gear-shift crackle — the *vrrr pha* — fires at multiplier milestones. It is the entire brand identity and it costs nothing to build.

### 1.2 Why this game, and why now

| | Chinga Fantasy | Vrrr Pha |
| --- | --- | --- |
| Round length | 90 s | 10–30 s typical |
| Player decision | Before the round | **During** the round |
| Tension | Reveal | Continuous |
| Assets needed | Team icons | One gauge, one sound |
| Rounds per hour | 40 | 150–350 |

Vrrr Pha needs no 3D models, no rigged characters, no clip library. It ships far faster than boxing, and it forces the platform to grow the seams that boxing will need anyway: a real-time engine, horizontal socket scaling, latency-aware settlement, and a generic per-game admin layer.

### 1.3 Non-goals for v1

- No jackpot / bonus pool (see §12 for the v2 case and its regulatory caveat)
- No multiplier leaderboards or social feed
- No player-to-player features
- No native mobile app (responsive web only)
- No 3D. The gauge is 2D canvas and stays that way.

---

## 2. Regulatory position

Vrrr Pha is a casino game by any regulator's classification: the outcome is a random number with no real-world event behind it. This has two hard consequences that shape the build.

**Design target: UK Gambling Commission RTS.** Building to the strictest standard makes every later licence application easier and costs almost nothing if done from the start. The specific requirements are mapped in §9 and are treated as functional requirements, not as a compliance appendix.

**Launch market: Namibia only, geo-blocked elsewhere.** South Africa has no online casino licence class, and the October 2025 *Portapa* SCA judgment closed the fixed-odds-on-casino-games route that crash games were using. Offshore hosting does not help — *Casino Enterprises v Gauteng Gambling Board* settled that gambling occurs where the player is. The engine must therefore support **hard geo and residence blocking as a first-class feature**, not a bolt-on. See §8.6.

Nothing in this document is legal advice. The RTS mapping must be confirmed by a gambling compliance lawyer and an approved test house (eCOGRA, GLI, BMM, iTech Labs) before any live launch.

---

## 3. Tech stack

### 3.1 Decisions and reasoning

| Layer | Choice | Why this, and not the Fantasy pattern |
| --- | --- | --- |
| **Engine runtime** | Node 22 LTS + **TypeScript**, strict | Fantasy is plain JS with no tests, no linter, no build step. Acceptable for a 90-second parlay; not acceptable where a floating-point error in a tick loop is money leaving the wallet. Types on the round contract are the cheapest bug prevention available, and a test house will ask to see typed money handling. |
| **HTTP** | **Fastify 5** | JSON Schema validation on every route, in and out. That schema doubles as API documentation for certification, and rejects malformed cashouts at the edge. Express 5 is acceptable if consistency with Fantasy matters more to you than schema validation — but take Fastify. |
| **Realtime** | Socket.IO 4 + **Redis adapter** | Fantasy runs one global in-process loop, which caps it at one engine instance. A tick game with hundreds of concurrent players cannot be single-instance. Redis adapter allows N socket nodes. |
| **Round authority** | **Redis** (state + pub/sub + leader lock) | Exactly one process runs the round loop, elected by a Redis lock with TTL. Socket nodes are stateless subscribers. This is the single biggest departure from Fantasy and the reason Vrrr Pha can scale. |
| **Database** | Postgres (`pg` + **knex**) | Same as Fantasy. Same migration convention. No reason to diverge. |
| **RNG** | Node `crypto` (HMAC-SHA256 commit–reveal) | Already built and Monte-Carlo verified. |
| **Testing** | **Vitest** + Supertest | Fantasy has none. Vrrr Pha cannot ship without them (§10). |
| **Quality** | ESLint + Prettier, strict TS, CI gate | Non-negotiable for a certifiable codebase. |
| **Frontend** | React 19 + TS + Vite 7 + Zustand + TanStack Query + Tailwind 4 + shadcn/Radix | Aligns with the SSO admin stack (React 19 / Tailwind 4) rather than the older `gambling` SPA (React 18 / Tailwind 3). Pick the newer line; the SPA will be modernised eventually anyway. |
| **Gauge rendering** | **Canvas 2D**, `requestAnimationFrame` | Not Three.js, not WebGL, not SVG. A rev counter is a needle on a dial. Canvas 2D holds 60fps on the low-end Android hardware that dominates the Namibian market. WebGL is a battery and compatibility tax for zero visual gain here. |
| **Audio** | Howler.js | Sprite-based sample playback, mobile unlock handling, low latency. The DSG shifts must be gated (§9). |
| **UI motion** | framer-motion | Chrome only — dialogs, panels, transitions. **Never the gauge.** The gauge is driven by the multiplier value, not by an animation library. |
| **Monorepo** | pnpm workspaces + Turborepo | Boxing and racing are coming. Shared packages now or four copies of the SSO client later. |

### 3.2 Repository layout

```
chinga-platform/                    # new monorepo
├─ apps/
│  ├─ vrrr-pha-engine/              # Fastify + Socket.IO + round loop
│  └─ vrrr-pha-web/                 # React SPA
├─ packages/
│  ├─ sso-client/                   # @chinga/sso-client
│  ├─ game-admin-contract/          # @chinga/game-admin-contract
│  ├─ web-platform/                 # @chinga/web-platform
│  └─ crash-math/                   # @chinga/crash-math  (already built)
├─ turbo.json
└─ CLAUDE.md
```

Existing repos (`chinga-games-sso`, `chinga-fantasy`, `gambling`) stay where they are. Fantasy is **not** migrated into the monorepo in v1 — it works, and moving it buys nothing. It can be pulled in later once the shared packages have proven themselves on a second consumer.

### 3.3 Shared packages — extracted, not copied

**`@chinga/sso-client`** — lifted from `chinga-fantasy`'s SSO module and typed:
- JWT verification via JWKS (JOSE), with key caching
- `ssoAuth`, `ssoClientAuth(scope)`, `ssoTenant` middleware, ported to Fastify hooks
- Game session cache (per user, 30-min TTL matching SSO)
- `debit(reference, amount)`, `credit(reference, amount)`, `serviceCredit(sessionToken, reference, amount)`
- `getConfig(gameUuid, tenantUuid)`
- Three-tier credit fallback with the same semantics Fantasy settlement uses

This package is the single highest-value artefact in the whole project. Boxing and racing consume it unchanged.

**`@chinga/game-admin-contract`** — TypeScript types plus a Fastify plugin implementing the admin endpoint shape Fantasy already established (`/api/admin/stats/summary`, `/stats/by-day`, `/stats/by-tenant`, `/rounds`, `/bets/recent`, `/users/:uuid/bets`, `/api/health`). Any game implementing this contract works with the existing SSO dashboards for free.

**`@chinga/web-platform`** — auth context, tenant context (subdomain resolution + `X-Tenant-ID`), socket service pattern, wallet balance and cash-out components, config hook. Extracted from the `gambling` SPA.

**`@chinga/crash-math`** — the sampler, verifier and Monte Carlo harness. Already written and verified: 3M rounds, RTP 96.0% ± noise across every strategy including martingale, chi-squared 5.55 on 9 df.

---

## 4. Platform work (chinga-games-sso)

Section 8 of the platform brief lists what is hardwired to Fantasy. This is the subset that must be generalised **before** Vrrr Pha can ship, plus what can wait.

### 4.1 Required for Vrrr Pha

| # | Change | Detail |
| --- | --- | --- |
| P1 | `games.backend_url` | Per-game admin API base URL. Replaces the single `CHINGA_FANTASY_API_URL` env var. |
| P2 | `games.launch_url` | Player-facing URL, needed by any future lobby and by the admin "open game" action. |
| P3 | `games.settings_schema` (JSON Schema) | Replaces the hardcoded Fantasy settings form. Admin UI renders the form from the schema. Vrrr Pha ships its schema on registration. |
| P4 | `GameAdminClient` | Interface + factory resolving by game UUID to `games.backend_url`. `FantasyAdminClient` becomes one instance of it, not a special case. |
| P5 | Per-game health probe | The 30-second health banner iterates enabled games instead of polling one hardcoded URL. |
| P6 | Revenue keyed by `game_uuid` | Today revenue reports attribute everything to Fantasy. Aggregate by game, with a per-game breakdown on the dashboard. |
| P7 | Per-game nav | Admin nav generated from the enabled game catalogue. |
| P8 | OAuth client + binding | New `client_credentials` client for the Vrrr Pha engine, scopes `wallet:write` + `gaming:read`, bound via `oauth_client_games`. |

### 4.2 Deferred

- **Player lobby.** Real gap — players currently reach a game only through its own frontend URL. Worth building, but Vrrr Pha can launch on its own subdomain (`vrrrpha.chingagames.com` / `<tenant>.vrrrpha...`) exactly as Fantasy does. Schedule it once game three exists.
- **Migrating Fantasy onto the shared packages.** Do it after Vrrr Pha is live and the packages have earned their shape.

### 4.3 Bug to not repeat

The Fantasy engine stores the SSO tenant **slug** in its `tenant_uuid` columns, and SSO admin controllers carry shims to map it. **Vrrr Pha stores real UUIDs.** The engine resolves slug → UUID once at tenant resolution and never persists a slug in a UUID column. Do not port the shim.

---

## 5. Game mechanics

### 5.1 Round lifecycle

```
IDLE ──▶ BETTING ──▶ LOCKED ──▶ FLYING ──▶ CRASHED ──▶ SETTLING ──▶ IDLE
         (≥ 8s)      (~1s)      (variable)  (~3s)       (async)
```

| Phase | Duration | What happens |
| --- | --- | --- |
| **BETTING** | `betting_window_seconds`, default 8, **hard floor 5** | Round row created by the scheduler, seed commitment published, bets accepted and wallet-debited. Countdown broadcast. |
| **LOCKED** | ~1 s | Betting closed. Exposure computed and recorded. Engine noise, launch animation. |
| **FLYING** | until crash point | Multiplier ticks. Cashouts accepted, stamped server-side. |
| **CRASHED** | ~3 s | Engine blows. Crash point and revealed server seed broadcast. Open bets lost. |
| **SETTLING** | async | Wallet credits with three-tier fallback, audit rows, history written. Next round can begin. |

Rounds are **created by the scheduler**, per tenant, on a fixed cadence. This differs from Fantasy, where rounds are created lazily by the first `GET /api/teams` request of the cycle. Lazy creation cannot work here: the multiplier curve needs a fixed, server-owned `t0`, and every player in a tenant must share it exactly.

### 5.2 Crash point

```
P(crash ≥ x) = (1 − house_edge) / x     for x ≥ 1.00
```

Drawn at round creation via HMAC-SHA256 commit–reveal, floored to 2dp (always down), capped at `max_multiplier`. Implementation in `@chinga/crash-math`, already verified.

Expected return is `1 − house_edge` for every cash-out target, so no multiplier band is exploitable. Verified empirically against fixed targets from 1.10x to 100x, random targets and martingale.

### 5.3 Multiplier curve

```
multiplier(t) = e^(growth_rate_k · t_seconds)
```

Default `growth_rate_k = 0.154`, putting 2.00x at ~4.5 s.

Broadcast tick every `tick_interval_ms` (default 100 ms). The client **interpolates between ticks locally** for smooth needle motion but never derives an authoritative multiplier — the server value always wins on reconciliation.

### 5.4 Rev counter mapping

The needle position is derived from the multiplier, never the reverse. If the render loop stalls, the multiplier is unaffected.

Gear band mapping (indicative, tunable):

| Gear | Multiplier | Needle |
| --- | --- | --- |
| 1st | 1.00 – 2.00x | sweep to redline, shift |
| 2nd | 2.00 – 5.00x | drop, sweep, shift |
| 3rd | 5.00 – 10.00x | drop, sweep, shift |
| 4th | 10.00 – 20.00x | drop, sweep, shift |
| 5th | 20.00 – 50.00x | drop, sweep, shift |
| 6th | 50.00x + | held near redline, rising strain |

The *vrrr pha* fires on each shift — **subject to the gate in §9**.

### 5.5 Cashout

- Player taps CASH OUT; client sends `{ betId, clientSentAt }` and **never a multiplier**
- Server stamps `serverReceivedAt`, computes the multiplier from its own clock, validates the round is still FLYING, records the cashout
- If the round has already crashed, the cashout is rejected and the bet is lost
- Payout = `stake × cashoutMultiplier`, capped by `max_win_per_round`

### 5.6 Auto-cashout — build behind a flag

The player may set a target before the round; the server executes it at the tick where the multiplier first meets or exceeds it.

**This is a genuine open regulatory question.** RTS 8, as amended in January 2025, extends the autoplay prohibition to all online gaming products and requires the customer to commit to each game cycle individually.

- **Auto-rebet is not built.** Not now, not behind a flag. Rolling a stake into the next round without a fresh player action is autoplay.
- **Auto-cashout is ambiguous.** It automates the exit, not the entry, and it arguably reduces the reflex-speed harm RTS 4 is concerned with. A regulator could read it either way.

Therefore: `auto_cashout_enabled` config flag, target set **per round** and never persisted across rounds, and the client must degrade gracefully when the flag is off. Put the question to the test house before certification.

### 5.7 One bet per player per round

Matches Fantasy. Also required by RTS 14C, which prohibits functionality facilitating simultaneous play — this rules out the dual-bet panel that Aviator and most crash clones ship with.

---

## 6. Configuration

Registered as `games.settings` in the SSO catalogue, rendered from `settings_schema` (P3), merged with `tenant_games.custom_settings`, re-read by the engine at the top of each round — the same contract Fantasy uses.

| Key | Default | Effect |
| --- | --- | --- |
| `min_bet_amount` | 5 | NAD, server-enforced before wallet debit |
| `max_bet_amount` | 50 | NAD |
| `house_edge` | 0.04 | 96% nominal RTP. Published per RTS 3. |
| `max_multiplier` | 10000 | Tail truncation |
| `max_win_per_round` | 50000 | Absolute NAD ceiling per bet |
| `max_total_stake_per_round` | 25000 | Per-tenant exposure control |
| `betting_window_seconds` | 8 | **Floor 5**, enforced in code, not just validation |
| `crash_display_seconds` | 3 | |
| `growth_rate_k` | 0.154 | 2.00x at ~4.5 s |
| `tick_interval_ms` | 100 | Broadcast cadence |
| `auto_cashout_enabled` | true | Feature flag, see §5.6 |
| `geo_allowed_countries` | `["NA"]` | ISO codes; empty means blocked |

---

## 7. Data model

All tables prefixed `vp_`, in the existing `chingadb` Postgres instance, knex-migrated.

```sql
vp_rounds (
  id, tenant_uuid uuid, state,
  server_seed, server_seed_hash, client_seed, nonce,
  crash_point numeric(12,2),
  house_edge numeric(5,4), max_multiplier int,   -- config snapshot at draw
  growth_rate_k numeric(8,6),
  opened_at, locked_at, crashed_at, settled_at,
  total_stake numeric(14,2), total_payout numeric(14,2),
  max_exposure numeric(14,2)
)

vp_bets (
  id, round_id, user_uuid uuid, tenant_uuid uuid,
  stake numeric(12,2), amount_from_deposit numeric(12,2),
  auto_cashout_target numeric(12,2) null,
  cashout_multiplier numeric(12,2) null,
  payout numeric(14,2) null,
  outcome,          -- pending | cashed | busted
  credit_status,    -- pending | paid | failed
  session_token, placed_at
)

vp_cashouts (
  id, bet_id, multiplier numeric(12,2), payout numeric(14,2),
  trigger,          -- manual | auto
  client_sent_at, server_received_at, latency_ms int
)

vp_audit_log (
  id bigserial, tenant_uuid, actor, event_type, payload jsonb,
  prev_hash, hash, created_at
)
```

**Money is `numeric`, never float.** The multiplier is `numeric(12,2)` in storage; only the in-flight tick value is a double.

`amount_from_deposit` is captured on every debit even though v1 has no jackpot, because the admin "real GGR" figure depends on it.

`vp_audit_log` is append-only and hash-chained: every state transition, wallet movement and config change. Regulators and test houses ask for this, and retrofitting it is painful.

---

## 8. Interfaces

### 8.1 Socket.IO events

**Server → client**

| Event | Payload |
| --- | --- |
| `roundState` | `{ roundId, phase, timeLeftMs, commitment, multiplier }` |
| `tick` | `{ roundId, multiplier, elapsedMs }` |
| `crashed` | `{ roundId, crashPoint, serverSeed, clientSeed, nonce }` |
| `betAccepted` | `{ betId, stake, balance }` |
| `cashedOut` | `{ betId, multiplier, payout, balance }` |
| `roundSummary` | `{ roundId, playerCount, totalStake, biggestMultiplier }` |
| `latencyPong` | `{ sentAt, serverAt }` |

**Client → server**

| Event | Payload |
| --- | --- |
| `placeBet` | `{ stake, autoCashoutTarget? }` |
| `cashout` | `{ betId, clientSentAt }` |
| `getRoundState` | — |
| `latencyPing` | `{ sentAt }` |

Unauthenticated sockets connect in spectator mode (they see ticks, cannot bet) — same as Fantasy.

### 8.2 Player REST

`GET /api/round/current`, `GET /api/history?limit=` (last N crash points), `GET /api/me/bets`, `GET /api/config` (public subset incl. published RTP), `GET /api/verify/:roundId` (seeds + recomputation), `GET /api/user/credit`, `GET /api/health`.

Bet placement and cashout are socket-only — REST fallback would introduce a second latency path for a time-critical action.

### 8.3 Admin REST

Implements `@chinga/game-admin-contract` exactly, so existing SSO dashboards work unmodified. `client_credentials`, scope `gaming:read`, optional `?tenant_uuid=`.

Plus Vrrr-Pha-specific: `/api/admin/rounds/:id/verify` (seed audit), `/api/admin/exposure` (live per-tenant exposure), `/api/admin/rtp?from=&to=` (realised vs theoretical RTP).

### 8.4 Wallet integration

Unchanged from the Fantasy contract:

| Step | Call | Reference |
| --- | --- | --- |
| Stake | `POST /api/v1/game/debit` with `gs_` token | `vp_bet_<betId>` |
| Payout | `POST /api/v1/game/credit` with `gs_` token | `vp_win_<betId>` |
| Payout fallback | `POST /api/v1/game/service/credit`, `client_credentials` + `wallet:write` | `vp_win_<betId>` |

Three-tier credit fallback identical to Fantasy: original session token → current cached session → service credit. Failures marked `credit_status = failed` and retried each round.

### 8.5 Liability controls

- Per-bet cap: `max_win_per_round`
- Per-round per-tenant cap: `max_total_stake_per_round`. Once reached, further bets rejected for that round with a clear message.
- Rejection is on **stake volume only**, never on the drawn crash point — rejecting based on the outcome would be both unfair and detectable.
- `/api/admin/exposure` surfaces live exposure; alert at 80% of cap.

### 8.6 Geo and residence blocking

First-class, enforced at two layers:

1. **Network** — IP geolocation at the socket handshake and on every REST request. Countries outside `geo_allowed_countries` are refused with an explicit message.
2. **Identity** — the SSO `country` field captured at registration. A player whose registered country is outside the allowed list cannot open a game session, regardless of IP.

Both layers log to `vp_audit_log`. A VPN defeats layer 1; layer 2 is what you show a regulator.

---

## 9. RTS compliance requirements

These are functional requirements. Each has an acceptance test in §10.

| RTS | Requirement | Implementation |
| --- | --- | --- |
| **RTS 3** | Publish rules, RTP and likelihood of winning | Rules page + published RTP (the **truncated** figure, not nominal) + last-100 crash distribution, reachable before and during play |
| **RTS 4** | Time-critical events: assess and disclose latency risk | Cashout stamped server-side only; live latency indicator; `latency_ms` logged on every cashout for dispute resolution |
| **RTS 7** | RNG independently tested | HMAC-SHA256 commit–reveal, public verifier, `crypto.randomBytes` only. Submit to approved test house. |
| **RTS 8** | Customer commits to each game cycle individually | **No auto-rebet, ever.** Auto-cashout per-round only, behind flag. Every round requires a fresh bet action. |
| **RTS 10** | Interrupted gambling | Disconnection policy: an open bet at disconnect settles at the crash point (lost) unless an auto-cashout target was set and met. Stated plainly in the rules. Reconnect restores round state. |
| **RTS 11** | Detect one player across multiple accounts in a round | Bets keyed on SSO `user_uuid`; duplicate detection per round |
| **RTS 12** | Financial limits | Deposit and loss limits enforced server-side at the SSO wallet layer |
| **RTS 13** | Session time and net position; reality checks | Persistent header: elapsed session time and net position. Player-set reality-check intervals. |
| **RTS 14A** | No encouragement to chase losses | No "double it back", no stake-increase prompts, nothing in the loss path that suggests re-staking |
| **RTS 14B** | No reverse withdrawals | SSO wallet layer; withdrawal requests cannot be cancelled back into play |
| **RTS 14C** | No simultaneous play | One bet per player per round. **No dual-bet panel.** |
| **RTS 14E** | Player cannot shorten time to result | No skip-animation. Cashing out is the player choosing an outcome and is permitted. |
| **RTS 14F** | **Must not celebrate a return ≤ total stake** | Audio and visual reward gated on `payout > stake`, not on multiplier milestones. See below. |
| **RTS 14G** | Minimum 5 s between game cycle starts | Enforced in the scheduler with a monotonic clock, not derived from animation length |

### The 14F gate matters more than it looks

The Monte Carlo showed that **4.93% of rounds crash at exactly 1.00x** — a consequence of flooring raw draws below 1.01 down to 1.00. A player who cashes out at 1.00x gets exactly their stake back.

So roughly one round in twenty produces a return equal to stake, which is precisely the case RTS 14F prohibits celebrating. The shift crackle, the flare, the upward audio sweep — all must be gated on `payout > stake`. Build the gate into the audio layer on day one; retrofitting it into a finished sound engine is miserable.

---

## 10. Testing

No route to production without these green.

**Math** (`@chinga/crash-math`, already passing)
1. Monte Carlo RTP, 10M rounds, all strategies converge on truncated RTP within tolerance
2. Chi-squared distribution fit
3. Verifier round-trip, 100k rounds, zero mismatches, forged seeds rejected

**Engine**
4. Cycle timing: no round starts under 5 s from the previous start, under load and clock skew
5. Server authority fuzzing: forged cashout multipliers, replayed messages, tampered timestamps, cashout after crash — all rejected
6. Wallet idempotency: duplicate debit and credit references are no-ops
7. Credit fallback: expired session falls through to service credit and pays exactly once
8. Exposure cap: bets rejected once `max_total_stake_per_round` is reached
9. Leader election: killing the scheduler mid-round elects a new leader and the round settles correctly
10. Reconnect: socket drop mid-flight restores correct state and does not double-settle

**Compliance**
11. Celebration gate: no positive audio or visual fires when `payout ≤ stake` — asserted at the audio API boundary
12. Auto-rebet absence: no code path stakes a new round without a fresh player action
13. Geo blocking: both IP and registered-country layers refuse, and log
14. Audit chain: `vp_audit_log` hash chain validates end to end after a full session

**Load**
15. 1,000 concurrent sockets, 3 engine nodes, tick jitter under 50 ms p99

---

## 11. Milestones

| # | Milestone | Contents | Est. |
| --- | --- | --- | --- |
| **M0** | Platform seams | P1–P8 in SSO. Vrrr Pha registered in catalogue, OAuth client bound, enabled for one test tenant. | 2 wks |
| **M1** | Math core | `@chinga/crash-math`. **Done.** | ✓ |
| **M2** | Headless engine | Round state machine with injectable clock, Redis leader election, Postgres persistence, full test suite. No sockets, no UI. | 2 wks |
| **M3** | Wallet + realtime | `@chinga/sso-client` extracted and typed. Debit, settle, three-tier credit. Socket layer, server-authoritative cashout, latency measurement. | 2 wks |
| **M4** | Client | Canvas gauge, DSG audio with 14F gate, bet panel, cashout, history, verifier page, reality checks and session display. | 3 wks |
| **M5** | Admin | `@chinga/game-admin-contract` implemented. Existing SSO dashboards working against Vrrr Pha. Exposure and RTP consoles. | 1 wk |
| **M6** | Compliance hardening | Geo blocking both layers, audit chain, rules and RTP publication, load test, test-house submission pack. | 2 wks |

M2 and M3 must not be compressed. Everything visible lives downstream of them, and everything expensive to fix lives inside them.

---

## 12. Open questions

1. **Auto-cashout classification.** §5.6. Ask the test house before M4 — it changes the client design.
2. **Jackpot for v1?** Fantasy's Chinga Bonus works well and the pattern is proven. But a progressive pool on a fast-cycle crash game is exactly the mechanic RTS 14A scrutinises for encouraging chased play. Recommendation: ship v1 without it, evaluate with compliance input for v2.
3. **Round cadence per tenant vs global.** Per-tenant matches Fantasy and keeps exposure isolated, but multiplies scheduler load. If tenant count grows past ~20, consider a shared round with per-tenant settlement.
4. **Namibian Gambling Board position.** Whether a pure-RNG crash game falls within a permitted online game class depends on ministerial policy directives, not the Act text. Confirm before M6, ideally before M4.
5. **Fantasy migration.** When to pull Fantasy onto the shared packages. Recommendation: after Vrrr Pha is live and stable.

---

## Appendix A — CLAUDE.md starting content

```markdown
# Chinga Platform Monorepo

## Non-negotiables
- Money is `numeric` in Postgres and integer minor units or Decimal in code. NEVER float.
- The crash point is drawn server-side at round creation and is never influenced by client input.
- Cashout multipliers are computed from the SERVER clock. A client-supplied multiplier is always rejected.
- No auto-rebet. No code path may stake a round without a fresh player action.
- Reward audio/visual is gated on `payout > stake` (RTS 14F).
- Minimum 5 seconds between round starts (RTS 14G), enforced with a monotonic clock.
- Never store an SSO tenant slug in a `tenant_uuid` column.

## Conventions
- TypeScript strict. No `any` in money or round-state paths.
- Wallet references: `vp_bet_<betId>`, `vp_win_<betId>`. Idempotent by reference.
- Every state transition and wallet movement writes to `vp_audit_log`.
- Migrations are knex `.sql` files, matching chinga-fantasy convention.

## Before changing round or settlement logic
Run `pnpm --filter @chinga/crash-math test` and the engine suite. RTP regressions are release blockers.
```
