<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameSession;
use App\Models\User;
use App\Models\VenueTerminal;
use App\Models\VoucherCode;
use App\Models\Wallet;
use App\Services\Venue\VoucherCodeService;
use Illuminate\Support\Facades\DB;

class GameSessionService
{
    public function __construct(
        protected WalletService $walletService,
        protected VoucherCodeService $voucherCodeService
    ) {}

    /**
     * Start a wallet-based game session (online user).
     */
    public function startWalletSession(User $user, Game $game, ?string $ipAddress = null): GameSession
    {
        $wallet = $user->getOrCreateWallet();

        $this->validateGameForTenant($game, $user->tenant_id);
        $this->ensureWalletActive($wallet);
        $this->ensureNoActiveSession($wallet);

        return GameSession::create([
            'tenant_id' => $user->tenant_id,
            'game_id' => $game->id,
            'source_type' => Wallet::class,
            'source_id' => $wallet->id,
            'ip_address' => $ipAddress,
            'balance_start' => $wallet->balance,
        ]);
    }

    /**
     * Start a voucher code-based game session (terminal/in-store).
     */
    public function startVoucherSession(
        VoucherCode $code,
        Game $game,
        VenueTerminal $terminal,
        ?string $pin = null,
        ?string $ipAddress = null
    ): GameSession {
        $this->validateGameForTenant($game, $code->tenant_id);

        if (!$code->canBeUsed()) {
            throw new \RuntimeException('Voucher code cannot be used.');
        }

        // Verify PIN if required
        if ($code->hasPin()) {
            if (!$pin) {
                throw new \RuntimeException('PIN is required for this voucher code.');
            }
            if (!$code->verifyPin($pin)) {
                throw new \RuntimeException('Invalid PIN.');
            }
        }

        $this->ensureNoActiveSession($code);

        return DB::transaction(function () use ($code, $game, $terminal, $ipAddress) {
            $session = GameSession::create([
                'tenant_id' => $code->tenant_id,
                'game_id' => $game->id,
                'source_type' => VoucherCode::class,
                'source_id' => $code->id,
                'terminal_id' => $terminal->id,
                'ip_address' => $ipAddress,
                'balance_start' => $code->balance,
            ]);

            $code->update([
                'status' => 'in_use',
                'current_terminal_id' => $terminal->id,
                'current_session_id' => $session->id,
                'last_activity_at' => now(),
            ]);

            return $session;
        });
    }

    /**
     * End an active game session.
     */
    public function endSession(string $sessionToken, string $reason): GameSession
    {
        $session = $this->findActiveSession($sessionToken);
        $balance = $this->getSourceBalance($session);

        $session->end($reason, $balance);

        return $session->fresh();
    }

    /**
     * Debit the session source (bet).
     */
    public function debit(string $sessionToken, string $amount, ?string $reference = null)
    {
        $session = $this->findActiveSession($sessionToken);
        $session->touch();

        if ($session->source_type === Wallet::class) {
            return $this->walletService->debit($session->source, $amount, $session, $reference);
        }

        return $this->voucherCodeService->debit($session->source, $amount, $session, $reference);
    }

    /**
     * Credit the session source (win).
     */
    public function credit(string $sessionToken, string $amount, ?string $reference = null)
    {
        $session = $this->findActiveSession($sessionToken);
        $session->touch();

        if ($session->source_type === Wallet::class) {
            return $this->walletService->credit($session->source, $amount, $session, $reference);
        }

        return $this->voucherCodeService->credit($session->source, $amount, $session, $reference);
    }

    /**
     * Get the current balance for a session.
     */
    public function getBalance(string $sessionToken): array
    {
        $session = $this->findActiveSession($sessionToken);

        return [
            'balance' => $this->getSourceBalance($session),
            'currency' => $this->getSourceCurrency($session),
        ];
    }

    /**
     * Get session info with game and terminal loaded.
     */
    public function getSessionInfo(string $sessionToken): GameSession
    {
        $session = $this->findActiveSession($sessionToken);

        return $session->load(['game', 'terminal']);
    }

    /**
     * Get transactions for a session.
     */
    public function getTransactions(string $sessionToken, int $limit = 20)
    {
        $session = $this->findActiveSession($sessionToken);

        if ($session->source_type === Wallet::class) {
            return $session->walletTransactions()
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        }

        return $session->voucherTransactions()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Find an active session by token.
     */
    private function findActiveSession(string $sessionToken): GameSession
    {
        $session = GameSession::where('session_token', $sessionToken)->first();

        if (!$session) {
            throw new \RuntimeException('Session not found.');
        }

        if (!$session->isActive()) {
            throw new \RuntimeException('Session is no longer active.');
        }

        return $session;
    }

    /**
     * Validate that the game is active and enabled for the tenant.
     */
    private function validateGameForTenant(Game $game, int $tenantId): void
    {
        if (!$game->isActive()) {
            throw new \RuntimeException('Game is not active.');
        }

        $enabled = $game->tenants()
            ->where('tenants.id', $tenantId)
            ->wherePivot('enabled', true)
            ->exists();

        if (!$enabled) {
            throw new \RuntimeException('Game is not enabled for this tenant.');
        }
    }

    /**
     * Ensure the wallet is active.
     */
    private function ensureWalletActive(Wallet $wallet): void
    {
        if (!$wallet->isActive()) {
            throw new \RuntimeException('Wallet is not active.');
        }
    }

    /**
     * Ensure there is no active game session for this source.
     */
    private function ensureNoActiveSession($source): void
    {
        $activeSession = GameSession::where('source_type', get_class($source))
            ->where('source_id', $source->id)
            ->whereNull('ended_at')
            ->where('updated_at', '>=', now()->subMinutes(30))
            ->exists();

        if ($activeSession) {
            throw new \RuntimeException('An active game session already exists for this source.');
        }
    }

    /**
     * Get the current balance from the session source.
     */
    private function getSourceBalance(GameSession $session): string
    {
        return $session->source->fresh()->balance;
    }

    /**
     * Get the currency from the session source.
     */
    private function getSourceCurrency(GameSession $session): string
    {
        return $session->source->fresh()->currency;
    }
}
