<?php

namespace App\Services\Venue;

use App\Models\GameSession;
use App\Models\Venue;
use App\Models\VenueStaff;
use App\Models\VenueTerminal;
use App\Models\VoucherCode;
use App\Models\VoucherTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoucherCodeService
{
    /**
     * Characters excluded from code generation to avoid confusion.
     * 0/O, 1/I/L are excluded.
     */
    private const AMBIGUOUS_CHARS = ['0', 'O', '1', 'I', 'L'];

    private const ALPHANUMERIC_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const NUMERIC_CHARS = '23456789';
    private const ALPHA_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * Generate a new voucher code for a venue.
     */
    public function createCode(
        Venue $venue,
        VenueStaff $createdBy,
        ?string $initialBalance = null,
        ?string $pin = null,
        ?int $expiryHours = null
    ): VoucherCode {
        $code = $this->generateUniqueCode($venue);
        $initialBalance = $initialBalance ?? '0.00';

        $voucherCode = VoucherCode::create([
            'venue_id' => $venue->id,
            'code' => $code,
            'pin' => $pin ? bcrypt($pin) : null,
            'balance' => $initialBalance,
            'currency' => $venue->currency,
            'status' => bccomp($initialBalance, '0', 2) > 0 ? 'active' : 'created',
            'created_by_staff_id' => $createdBy->id,
            'total_loaded' => $initialBalance,
            'expires_at' => $this->calculateExpiry($venue, $expiryHours),
        ]);

        // Record initial load transaction if balance provided
        if (bccomp($initialBalance, '0', 2) > 0) {
            $this->recordTransaction(
                $voucherCode,
                'load',
                $initialBalance,
                '0.00',
                $initialBalance,
                'Initial balance',
                $createdBy
            );
        }

        // Auto-create a voucher user linked to this code
        $voucherUser = \App\Models\User::create([
            'name' => "Voucher {$code}",
            'email' => "voucher-{$code}@voucher.local",
            'username' => "voucher-{$code}",
            'password' => \Illuminate\Support\Str::random(32),
            'user_type' => 'voucher',
            'tenant_id' => $venue->tenant_id,
            'status' => 'active',
        ]);

        // Assign player role
        $voucherUser->assignRole('player', $venue->tenant_id);

        // Create wallet and deposit initial balance if > 0
        $wallet = $voucherUser->getOrCreateWallet($venue->currency);
        if (bccomp($initialBalance, '0', 2) > 0) {
            app(\App\Services\WalletService::class)->deposit(
                $wallet,
                $initialBalance,
                null,
                "voucher_initial_{$voucherCode->uuid}"
            );
        }

        // Link user to voucher
        $voucherCode->update(['user_id' => $voucherUser->id]);

        return $voucherCode;
    }

    /**
     * Create a voucher code from the admin panel (no VenueStaff required).
     */
    public function createCodeForAdmin(
        Venue $venue,
        \App\Models\User $admin,
        ?string $initialBalance = null,
        ?string $pin = null,
        ?int $expiryHours = null
    ): VoucherCode {
        $code = $this->generateUniqueCode($venue);
        $initialBalance = $initialBalance ?? '0.00';

        $voucherCode = VoucherCode::create([
            'venue_id' => $venue->id,
            'code' => $code,
            'pin' => $pin ? bcrypt($pin) : null,
            'balance' => $initialBalance,
            'currency' => $venue->currency,
            'status' => bccomp($initialBalance, '0', 2) > 0 ? 'active' : 'created',
            'created_by_admin_id' => $admin->id,
            'total_loaded' => $initialBalance,
            'expires_at' => $this->calculateExpiry($venue, $expiryHours),
        ]);

        if (bccomp($initialBalance, '0', 2) > 0) {
            $this->recordTransaction(
                $voucherCode,
                'load',
                $initialBalance,
                '0.00',
                $initialBalance,
                'Initial balance (admin)',
                null
            );
        }

        // Auto-create voucher user + wallet (same as createCode)
        $voucherUser = \App\Models\User::create([
            'name' => "Voucher {$code}",
            'email' => "voucher-{$code}@voucher.local",
            'username' => "voucher-{$code}",
            'password' => \Illuminate\Support\Str::random(32),
            'user_type' => 'voucher',
            'tenant_id' => $venue->tenant_id,
            'status' => 'active',
        ]);

        $voucherUser->assignRole('player', $venue->tenant_id);

        $wallet = $voucherUser->getOrCreateWallet($venue->currency);
        if (bccomp($initialBalance, '0', 2) > 0) {
            app(\App\Services\WalletService::class)->deposit(
                $wallet,
                $initialBalance,
                null,
                "voucher_initial_{$voucherCode->uuid}"
            );
        }

        $voucherCode->update(['user_id' => $voucherUser->id]);

        return $voucherCode;
    }

    /**
     * Generate a unique code string.
     */
    private function generateUniqueCode(Venue $venue): string
    {
        $length = $venue->getCodeLength();
        $type = $venue->getCodeType();
        $maxAttempts = 10;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = $this->generateCodeString($length, $type);

            if (!VoucherCode::where('code', $code)->exists()) {
                return $code;
            }
        }

        // Fallback: add random suffix
        return $this->generateCodeString($length, $type) . Str::random(2);
    }

    /**
     * Generate a random code string.
     */
    private function generateCodeString(int $length, string $type): string
    {
        $chars = match ($type) {
            'numeric' => self::NUMERIC_CHARS,
            'alpha' => self::ALPHA_CHARS,
            default => self::ALPHANUMERIC_CHARS,
        };

        $code = '';
        $charLength = strlen($chars);

        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $charLength - 1)];
        }

        return $code;
    }

    /**
     * Calculate expiration time.
     */
    private function calculateExpiry(Venue $venue, ?int $hours = null): \DateTime
    {
        $hours = $hours ?? $venue->getDefaultCodeExpiryHours();

        return now()->addHours($hours);
    }

    /**
     * Load credits onto a voucher code.
     */
    public function loadCredits(
        VoucherCode $voucherCode,
        string $amount,
        VenueStaff $performedBy,
        ?string $description = null
    ): VoucherTransaction {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        // Check venue max balance limit
        $venue = $voucherCode->venue;
        $maxBalance = (string) $venue->getMaxCodeBalance();

        if (bccomp(bcadd($voucherCode->balance, $amount, 2), $maxBalance, 2) > 0) {
            throw new \RuntimeException("Loading would exceed maximum balance of {$maxBalance}.");
        }

        return DB::transaction(function () use ($voucherCode, $amount, $performedBy, $description) {
            $voucherCode = VoucherCode::lockForUpdate()->find($voucherCode->id);

            $balanceBefore = $voucherCode->balance;
            $balanceAfter = bcadd($balanceBefore, $amount, 2);

            $voucherCode->update([
                'balance' => $balanceAfter,
                'total_loaded' => bcadd($voucherCode->total_loaded, $amount, 2),
                'status' => 'active',
                'last_activity_at' => now(),
            ]);

            return $this->recordTransaction(
                $voucherCode,
                'load',
                $amount,
                $balanceBefore,
                $balanceAfter,
                $description ?? 'Credit load',
                $performedBy
            );
        });
    }

    /**
     * Cash out a voucher code.
     */
    public function cashout(
        VoucherCode $voucherCode,
        VenueStaff $performedBy,
        ?string $amount = null,
        ?string $description = null
    ): VoucherTransaction {
        $amount = $amount ?? $voucherCode->balance;

        if (bccomp($amount, '0', 2) <= 0) {
            throw new \InvalidArgumentException('Cashout amount must be positive.');
        }

        if (bccomp($amount, $voucherCode->balance, 2) > 0) {
            throw new \RuntimeException('Insufficient balance for cashout.');
        }

        return DB::transaction(function () use ($voucherCode, $amount, $performedBy, $description) {
            $voucherCode = VoucherCode::lockForUpdate()->find($voucherCode->id);

            $balanceBefore = $voucherCode->balance;
            $balanceAfter = bcsub($balanceBefore, $amount, 2);

            $voucherCode->update([
                'balance' => $balanceAfter,
                'total_cashed_out' => bcadd($voucherCode->total_cashed_out, $amount, 2),
                'status' => bccomp($balanceAfter, '0', 2) === 0 ? 'cashed_out' : 'active',
                'last_activity_at' => now(),
            ]);

            // End any active session
            if ($voucherCode->current_session_id) {
                $voucherCode->currentSession->end('cashed_out', $balanceAfter);
            }

            return $this->recordTransaction(
                $voucherCode,
                'cashout',
                '-' . $amount,
                $balanceBefore,
                $balanceAfter,
                $description ?? 'Cash out',
                $performedBy
            );
        });
    }

    /**
     * Debit credits (for gameplay - bet).
     */
    public function debit(
        VoucherCode $voucherCode,
        string $amount,
        GameSession $session,
        ?string $reference = null
    ): VoucherTransaction {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        if (!$voucherCode->hasSufficientBalance($amount)) {
            throw new \RuntimeException('Insufficient balance.');
        }

        return DB::transaction(function () use ($voucherCode, $amount, $session, $reference) {
            // Idempotency check
            if ($reference) {
                $existing = VoucherTransaction::where('game_session_id', $session->id)
                    ->where('reference', $reference)
                    ->where('type', 'bet')
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $voucherCode = VoucherCode::lockForUpdate()->find($voucherCode->id);

            if (!$voucherCode->hasSufficientBalance($amount)) {
                throw new \RuntimeException('Insufficient balance.');
            }

            $balanceBefore = $voucherCode->balance;
            $balanceAfter = bcsub($balanceBefore, $amount, 2);

            $voucherCode->update([
                'balance' => $balanceAfter,
                'total_lost' => bcadd($voucherCode->total_lost, $amount, 2),
                'last_activity_at' => now(),
            ]);

            return $this->recordTransaction(
                $voucherCode,
                'bet',
                '-' . $amount,
                $balanceBefore,
                $balanceAfter,
                'Game bet/wager',
                null,
                null,
                $session->id,
                $reference
            );
        });
    }

    /**
     * Credit winnings (for gameplay - win).
     */
    public function credit(
        VoucherCode $voucherCode,
        string $amount,
        GameSession $session,
        ?string $reference = null
    ): VoucherTransaction {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        return DB::transaction(function () use ($voucherCode, $amount, $session, $reference) {
            // Idempotency check
            if ($reference) {
                $existing = VoucherTransaction::where('game_session_id', $session->id)
                    ->where('reference', $reference)
                    ->where('type', 'win')
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $voucherCode = VoucherCode::lockForUpdate()->find($voucherCode->id);

            $balanceBefore = $voucherCode->balance;
            $balanceAfter = bcadd($balanceBefore, $amount, 2);

            $voucherCode->update([
                'balance' => $balanceAfter,
                'total_won' => bcadd($voucherCode->total_won, $amount, 2),
                'last_activity_at' => now(),
            ]);

            return $this->recordTransaction(
                $voucherCode,
                'win',
                $amount,
                $balanceBefore,
                $balanceAfter,
                'Game winnings',
                null,
                null,
                $session->id,
                $reference
            );
        });
    }

    /**
     * Deactivate a voucher code.
     */
    public function deactivate(VoucherCode $voucherCode, ?VenueStaff $deactivatedBy = null): void
    {
        // End any active session
        if ($voucherCode->current_session_id) {
            $voucherCode->currentSession->end('forced');
        }

        $voucherCode->update([
            'status' => 'deactivated',
            'current_terminal_id' => null,
            'current_session_id' => null,
        ]);
    }

    /**
     * Expire codes that have passed their expiration date.
     */
    public function expireOldCodes(): int
    {
        return VoucherCode::whereIn('status', ['created', 'active'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    /**
     * Transfer balance between codes.
     */
    public function transfer(
        VoucherCode $fromCode,
        VoucherCode $toCode,
        string $amount,
        VenueStaff $performedBy
    ): array {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        if (!$fromCode->hasSufficientBalance($amount)) {
            throw new \RuntimeException('Insufficient balance for transfer.');
        }

        if ($fromCode->venue_id !== $toCode->venue_id) {
            throw new \RuntimeException('Cannot transfer between different venues.');
        }

        return DB::transaction(function () use ($fromCode, $toCode, $amount, $performedBy) {
            $fromCode = VoucherCode::lockForUpdate()->find($fromCode->id);
            $toCode = VoucherCode::lockForUpdate()->find($toCode->id);

            // Debit from source
            $fromBalanceBefore = $fromCode->balance;
            $fromBalanceAfter = bcsub($fromBalanceBefore, $amount, 2);

            $fromCode->update([
                'balance' => $fromBalanceAfter,
                'last_activity_at' => now(),
            ]);

            $outTransaction = $this->recordTransaction(
                $fromCode,
                'transfer_out',
                '-' . $amount,
                $fromBalanceBefore,
                $fromBalanceAfter,
                "Transfer to {$toCode->masked_code}",
                $performedBy
            );

            // Credit to destination
            $toBalanceBefore = $toCode->balance;
            $toBalanceAfter = bcadd($toBalanceBefore, $amount, 2);

            $toCode->update([
                'balance' => $toBalanceAfter,
                'status' => 'active',
                'last_activity_at' => now(),
            ]);

            $inTransaction = $this->recordTransaction(
                $toCode,
                'transfer_in',
                $amount,
                $toBalanceBefore,
                $toBalanceAfter,
                "Transfer from {$fromCode->masked_code}",
                $performedBy
            );

            return [$outTransaction, $inTransaction];
        });
    }

    /**
     * Record a transaction.
     */
    private function recordTransaction(
        VoucherCode $voucherCode,
        string $type,
        string $amount,
        string $balanceBefore,
        string $balanceAfter,
        ?string $description = null,
        ?VenueStaff $performedBy = null,
        ?VenueTerminal $terminal = null,
        ?int $gameSessionId = null,
        ?string $reference = null,
    ): VoucherTransaction {
        return VoucherTransaction::create([
            'voucher_code_id' => $voucherCode->id,
            'game_session_id' => $gameSessionId,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reference' => $reference,
            'description' => $description,
            'performed_by_staff_id' => $performedBy?->id,
            'terminal_id' => $terminal?->id,
        ]);
    }

    /**
     * Find a voucher code by its code string.
     */
    public function findByCode(string $code): ?VoucherCode
    {
        return VoucherCode::where('code', strtoupper($code))->first();
    }

    /**
     * Get code for venue (case-insensitive).
     */
    public function findCodeAtVenue(string $code, Venue $venue): ?VoucherCode
    {
        return VoucherCode::where('code', strtoupper($code))
            ->where('venue_id', $venue->id)
            ->first();
    }
}
