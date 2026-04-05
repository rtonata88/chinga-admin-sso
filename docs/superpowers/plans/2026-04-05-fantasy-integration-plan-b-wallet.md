# Chinga Fantasy Integration — Plan B: Wallet Integration

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Chinga Fantasy's internal credit system (`user_statements`) with SSO Game Session API calls — every bet debits from the SSO wallet, every win credits back.

**Architecture:** The Fantasy game server manages a game session token per player. On bet placement, it calls `POST /api/v1/game/debit`. On win, it calls `POST /api/v1/game/credit`. The frontend fetches balance from the SSO. The old `user_statements`, `UserStatement`, and `User.getUserCredit()` are removed.

**Tech Stack:** SSO Game Session API, Node.js fetch, React

**Spec:** `docs/superpowers/specs/2026-04-05-chinga-fantasy-integration-design.md`

**Depends on:** Plan A (Foundation) — SSO auth and tenant integration must be working

**Projects involved:**
- Fantasy Game Server: `/Users/richard/Projects/chinga-fantasy`
- Fantasy Frontend: `/Users/richard/Projects/gambling`
- SSO: `/Users/richard/Projects/chinga-games-sso` (no changes needed — API already exists)

---

## File Structure

### Fantasy Game Server — New/Modified Files
| Action | File | Purpose |
|--------|------|---------|
| Create | `app/services/sessionManager.js` | Map players to SSO game sessions |
| Modify | `app/controllers/BetController.js` | Call SSO debit on bet, SSO credit on win |
| Modify | `app/models/Bet.js` | Remove internal credit system calls |
| Modify | `app/models/Round.js` | Update finalize to use SSO credits for jackpot |
| Modify | `app/routes/api.js` | Update credit endpoint to use session manager |
| Modify | `migrations/` | Add session_token column to bets table |

### Fantasy Frontend — Modified Files
| Action | File | Purpose |
|--------|------|---------|
| Modify | `client/src/services/creditService.ts` | Fetch balance from game server (which proxies SSO) |

---

## Task 1: Session Manager Service

The session manager maps each authenticated player to an SSO game session. A session is started when the player first needs to interact with their wallet (placing a bet) and kept alive across rounds.

**Files:**
- Create: `/Users/richard/Projects/chinga-fantasy/app/services/sessionManager.js`

- [ ] **Step 1: Create the session manager**

```javascript
// app/services/sessionManager.js
const ssoClient = require('./ssoClient');

/**
 * Maps player UUIDs to their active SSO game session tokens.
 * Sessions are created on-demand when a player places their first bet
 * and kept alive across rounds.
 */
const activeSessions = new Map(); // userUuid -> { sessionToken, currency }

/**
 * Get or create an SSO game session for a player.
 * @param {string} userUuid - SSO user UUID
 * @param {string} userAccessToken - Player's SSO OAuth access token
 * @returns {{ sessionToken: string, currency: string }}
 */
async function getOrCreateSession(userUuid, userAccessToken) {
  const existing = activeSessions.get(userUuid);
  if (existing) {
    // Verify session is still active by checking balance
    try {
      await ssoClient.getBalance(existing.sessionToken);
      return existing;
    } catch {
      // Session expired or invalid — remove and create new
      activeSessions.delete(userUuid);
    }
  }

  // Start a new wallet session
  const result = await ssoClient.startWalletSession(userAccessToken);

  const session = {
    sessionToken: result.session_token,
    currency: result.currency,
  };

  activeSessions.set(userUuid, session);
  return session;
}

/**
 * Get the active session for a player (without creating one).
 * @param {string} userUuid
 * @returns {{ sessionToken: string, currency: string } | null}
 */
function getSession(userUuid) {
  return activeSessions.get(userUuid) || null;
}

/**
 * Remove a player's session (on logout or session end).
 * @param {string} userUuid
 */
async function endSession(userUuid) {
  const session = activeSessions.get(userUuid);
  if (session) {
    try {
      await ssoClient.endSession(session.sessionToken, 'logout');
    } catch (error) {
      console.error(`Failed to end SSO session for ${userUuid}:`, error.message);
    }
    activeSessions.delete(userUuid);
  }
}

/**
 * Remove all sessions (server shutdown).
 */
async function endAllSessions() {
  for (const [userUuid] of activeSessions) {
    await endSession(userUuid);
  }
}

module.exports = {
  getOrCreateSession,
  getSession,
  endSession,
  endAllSessions,
};
```

- [ ] **Step 2: Verify module loads**

```bash
cd /Users/richard/Projects/chinga-fantasy
node -e "const sm = require('./app/services/sessionManager'); console.log('sessionManager loaded:', Object.keys(sm))"
```

Expected: `sessionManager loaded: [ 'getOrCreateSession', 'getSession', 'endSession', 'endAllSessions' ]`

- [ ] **Step 3: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add app/services/sessionManager.js
git commit -m "feat: add SSO game session manager for player session tracking"
```

---

## Task 2: Add session_token Column to Bets Table

**Files:**
- Create: `/Users/richard/Projects/chinga-fantasy/migrations/002_add_session_token_to_bets.sql`

- [ ] **Step 1: Create migration**

```sql
-- Add session_token and payout_status to bets table for SSO integration.
BEGIN;

ALTER TABLE bets ADD COLUMN IF NOT EXISTS session_token VARCHAR(100);
ALTER TABLE bets ADD COLUMN IF NOT EXISTS payout_status VARCHAR(20) DEFAULT 'pending';

-- payout_status: 'pending' (default), 'paid', 'failed'
-- Used for retry logic when SSO credit call fails

CREATE INDEX IF NOT EXISTS idx_bets_session_token ON bets(session_token);
CREATE INDEX IF NOT EXISTS idx_bets_payout_status ON bets(payout_status);

COMMIT;
```

- [ ] **Step 2: Run migration**

```bash
psql -U richard -d chingadb -f /Users/richard/Projects/chinga-fantasy/migrations/002_add_session_token_to_bets.sql
```

- [ ] **Step 3: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add migrations/002_add_session_token_to_bets.sql
git commit -m "feat: add session_token and payout_status columns to bets"
```

---

## Task 3: Update BetController.placeBet to Call SSO Debit

This is the core change. When a player places a bet, instead of debiting from `user_statements`, we call the SSO Game Session API.

**Files:**
- Modify: `/Users/richard/Projects/chinga-fantasy/app/controllers/BetController.js`

- [ ] **Step 1: Replace the bet placement logic**

In `BetController.placeBet()`, replace the internal credit check and `user_statements` creation with SSO debit calls. The new flow:

```javascript
// At the top of BetController.js, add:
const sessionManager = require('../services/sessionManager');
const ssoClient = require('../services/ssoClient');
const { v4: uuidv4 } = require('uuid');

// In placeBet():
async placeBet(req, res) {
  try {
    const userUuid = req.ssoUser.sub;
    const userAccessToken = req.ssoUser.accessToken;
    const { amount, roundId, selectedTeams } = req.body;

    if (!amount || !roundId || !selectedTeams || selectedTeams.length === 0) {
      return res.status(400).json({ message: 'Missing required fields.' });
    }

    // Check for duplicate bet in this round
    const existingBet = await Bet.getBetsByUserAndRound(userUuid, roundId, req);
    if (existingBet && existingBet.length > 0) {
      return res.status(400).json({ message: 'You already placed a bet for this round.' });
    }

    // Get or create SSO game session
    const session = await sessionManager.getOrCreateSession(userUuid, userAccessToken);

    // Generate unique bet reference for idempotency
    const betUuid = uuidv4();
    const reference = `bet_${betUuid}`;

    // Call SSO debit (this validates balance too)
    let debitResult;
    try {
      debitResult = await ssoClient.debit(session.sessionToken, amount.toString(), reference);
    } catch (error) {
      if (error.message.includes('Insufficient balance')) {
        return res.status(400).json({ message: 'Insufficient balance.' });
      }
      throw error;
    }

    // Record bet in Fantasy database
    const bet = await Bet.create({
      uuid: betUuid,
      user_uuid: userUuid,
      tenant_uuid: req.tenantUuid,
      round_id: roundId,
      amount: amount,
      session_token: session.sessionToken,
      selected_teams: selectedTeams,
    }, req);

    return res.json({
      message: 'Bet placed successfully.',
      bet: {
        id: bet.id,
        uuid: betUuid,
        amount: amount,
        balance_after: debitResult.balance_after,
      },
    });
  } catch (error) {
    console.error('placeBet error:', error);
    return res.status(500).json({ message: 'Failed to place bet.' });
  }
}
```

Note: The exact implementation depends on the current structure of `Bet.create()`. The key changes are:
1. Replace `req.user.id` with `req.ssoUser.sub` (user UUID)
2. Replace `User.getUserCredit()` balance check with the SSO debit call (which validates balance)
3. Remove all `UserStatement` creation for the debit — SSO handles this
4. Store `session_token` on the bet record
5. Generate a UUID reference for idempotency

- [ ] **Step 2: Update BetController.updateBetOutcome to call SSO credit on win**

In `updateBetOutcome()`, when a bet wins, instead of creating a `UserStatement` credit entry, call the SSO credit API:

```javascript
// In updateBetOutcome(), for winning bets:
if (outcome === 'win') {
  const session = sessionManager.getSession(bet.user_uuid);
  if (session) {
    const reference = `win_${bet.uuid || bet.id}`;
    try {
      await ssoClient.credit(session.sessionToken, winningAmount.toString(), reference);
      // Mark payout as successful
      await pool.query('UPDATE bets SET payout_status = $1 WHERE id = $2', ['paid', bet.id]);
    } catch (error) {
      console.error(`Failed to credit win for bet ${bet.id}:`, error.message);
      // Mark for retry
      await pool.query('UPDATE bets SET payout_status = $1 WHERE id = $2', ['failed', bet.id]);
    }
  }
}
```

Remove the `UserStatement` creation for wins. The SSO `credit` call with the `win_` reference handles it.

- [ ] **Step 3: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add app/controllers/BetController.js
git commit -m "feat: integrate SSO debit/credit into bet placement and outcome"
```

---

## Task 4: Update Jackpot Distribution to Use SSO Credit

**Files:**
- Modify: `/Users/richard/Projects/chinga-fantasy/app/models/Round.js`

- [ ] **Step 1: Update finalizeRoundAndDistributeBonus**

In `Round.finalizeRoundAndDistributeBonus()`, the jackpot distribution currently creates `UserStatement` entries. Replace with SSO credit calls:

```javascript
// In the jackpot distribution section:
const sessionManager = require('../services/sessionManager');
const ssoClient = require('../services/ssoClient');

// For each jackpot winner:
for (const winner of jackpotWinners) {
  const session = sessionManager.getSession(winner.user_uuid);
  if (session) {
    const reference = `jackpot_${roundId}_${winner.user_uuid}`;
    try {
      await ssoClient.credit(session.sessionToken, shareAmount.toString(), reference);
    } catch (error) {
      console.error(`Jackpot credit failed for ${winner.user_uuid}:`, error.message);
      // Jackpot credit failures should be logged for manual resolution
    }
  }
}
```

Keep the `JackpotTransaction` records for pool tracking — they track the jackpot pool balance, not player wallets.

- [ ] **Step 2: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add app/models/Round.js
git commit -m "feat: distribute jackpot winnings via SSO credit API"
```

---

## Task 5: Update Credit/Balance Endpoint

**Files:**
- Modify: `/Users/richard/Projects/chinga-fantasy/app/routes/api.js`

- [ ] **Step 1: Update the /user/credit endpoint**

The `/api/user/credit` route in `api.js` (updated in Plan A Task 5) already has a placeholder that tries to get balance from SSO. Update it to use the session manager:

```javascript
router.get('/user/credit', ssoAuth, async (req, res) => {
  try {
    const sessionManager = require('../services/sessionManager');
    const session = sessionManager.getSession(req.ssoUser.sub);

    if (session) {
      const ssoClient = require('../services/ssoClient');
      const data = await ssoClient.getBalance(session.sessionToken);
      return res.json({ balance: data.balance, currency: data.currency });
    }

    // No active session yet — player hasn't placed a bet yet.
    // Fetch wallet balance by starting a temp session or returning 0.
    // For now, start a session to get the balance.
    const newSession = await sessionManager.getOrCreateSession(
      req.ssoUser.sub,
      req.ssoUser.accessToken
    );
    const ssoClient = require('../services/ssoClient');
    const data = await ssoClient.getBalance(newSession.sessionToken);
    return res.json({ balance: data.balance, currency: data.currency });
  } catch (error) {
    console.error('Credit check error:', error.message);
    return res.json({ balance: '0.00' });
  }
});
```

- [ ] **Step 2: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add app/routes/api.js
git commit -m "feat: proxy balance endpoint through SSO game session"
```

---

## Task 6: Update Fantasy Frontend Credit Service

**Files:**
- Modify: `/Users/richard/Projects/gambling/client/src/services/creditService.ts`

- [ ] **Step 1: Simplify credit service to use SSO token**

Replace the contents of `creditService.ts`:

```typescript
// client/src/services/creditService.ts

const BACKEND_URL = import.meta.env.VITE_BACKEND_URL || 'http://localhost:3001';

/**
 * Fetch user balance from the game server (which proxies to SSO).
 */
export async function fetchUserCredit(): Promise<number> {
  const token = sessionStorage.getItem('sso_access_token');
  if (!token) return 0;

  try {
    const response = await fetch(`${BACKEND_URL}/api/user/credit`, {
      headers: {
        Authorization: `Bearer ${token}`,
      },
    });

    if (!response.ok) return 0;

    const data = await response.json();
    return parseFloat(data.balance) || 0;
  } catch (error) {
    console.error('Failed to fetch credit:', error);
    return 0;
  }
}
```

- [ ] **Step 2: Commit**

```bash
cd /Users/richard/Projects/gambling
git add client/src/services/creditService.ts
git commit -m "feat: update credit service to use SSO-backed balance"
```

---

## Task 7: Clean Up Old Wallet Code

**Files:**
- Modify: `/Users/richard/Projects/chinga-fantasy/app/models/Bet.js` (remove UserStatement imports/calls)

- [ ] **Step 1: Remove UserStatement references from Bet model**

In `Bet.create()`, remove the lines that create `UserStatement` entries for real money and bonus money debits. The SSO debit call in the controller now handles this.

Remove imports of `UserStatement` if present.

In `Bet.create()`, the method should only:
1. INSERT into `bets` table (with `user_uuid`, `tenant_uuid`, `session_token`)
2. INSERT into `bet_teams` table
3. Return the created bet

Remove the 70%/30% real/bonus money split logic — the SSO wallet is a single balance.

- [ ] **Step 2: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add app/models/Bet.js
git commit -m "refactor: remove internal credit system from Bet model"
```

---

## Task 8: End-to-End Wallet Integration Test

- [ ] **Step 1: Manual test — bet placement with SSO debit**

1. Start all three services (SSO, Fantasy game server, Fantasy frontend)
2. Login via SSO OAuth2 flow
3. Ensure the test user has a wallet with balance in SSO (use SSO admin to deposit)
4. Wait for a betting round to start
5. Select teams and place a bet
6. Verify in SSO database that a `wallet_transactions` record was created with type `bet`
7. Verify in Fantasy database that the `bets` record has a `session_token`

- [ ] **Step 2: Manual test — win payout with SSO credit**

1. Wait for the round to resolve
2. If the bet wins, verify in SSO database that a `wallet_transactions` record was created with type `win`
3. Verify the player's wallet balance increased by the winning amount
4. Check the frontend displays the updated balance

- [ ] **Step 3: Manual test — insufficient balance**

1. Try to place a bet larger than the wallet balance
2. Verify the game server returns "Insufficient balance"
3. Verify no bet record was created in Fantasy database

- [ ] **Step 4: Document results and any issues**

Record any issues for follow-up. Common issues to check:
- SSO token doesn't include `tenant_id` claim → need to add custom claim
- Game session timeout during long play → session manager needs to handle re-creation
- Jackpot distribution for offline players → need retry queue
