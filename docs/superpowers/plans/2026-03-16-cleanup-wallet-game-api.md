# Cleanup, Wallet & Unified Game API — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clean up unused features, add a wallet system for online users, and build a unified game session API for all games to integrate with.

**Architecture:** Laravel multi-tenant SSO with column-based tenancy. Wallet and voucher codes share a common game session layer. Games authenticate via OAuth2 or terminal keys, then interact through a single game API using session tokens for debit/credit operations.

**Tech Stack:** Laravel 12, MySQL, Laravel Passport (OAuth2), Inertia.js + React + PrimeReact, bcmath for monetary precision.

**Spec:** `docs/superpowers/specs/2026-03-16-cleanup-wallet-game-api-design.md`

---

## Chunk 1: Phase 1 — Backend Cleanup

### Task 1: Delete removed feature files

**Files to delete:**

```
# KYC
app/Models/KycDocument.php
app/Services/Kyc/KycService.php
app/Http/Controllers/Api/KycController.php
app/Http/Controllers/Admin/KycReviewController.php
app/Http/Controllers/Settings/KycController.php
app/Http/Middleware/CheckKycLevel.php

# Responsible Gambling
app/Models/ResponsibleGamblingSetting.php
app/Models/SelfExclusion.php
app/Services/ResponsibleGambling/ResponsibleGamblingService.php
app/Http/Controllers/Api/ResponsibleGamblingController.php
app/Http/Controllers/Settings/ResponsibleGamblingController.php
app/Http/Middleware/CheckSelfExclusion.php

# SMS MFA
app/Services/Auth/SmsMfaService.php
app/Http/Controllers/Auth/SmsMfaController.php

# Phone Verification
app/Models/PhoneVerification.php
app/Services/Auth/PhoneVerificationService.php
app/Http/Controllers/Auth/PhoneVerificationController.php

# Login Notifications & Device Detection
app/Models/LoginNotification.php
app/Services/Auth/LoginNotificationService.php
app/Services/Auth/DeviceDetectionService.php
app/Notifications/NewDeviceLogin.php
app/Notifications/NewLocationLogin.php

# Form Configuration
app/Models/FormConfiguration.php
app/Models/SavedFilter.php
app/Services/FormConfigService.php
app/Http/Controllers/FormConfigController.php
config/acumatica-ui.php

# Frontend pages
resources/js/pages/settings/kyc.tsx
resources/js/pages/settings/responsible-gambling.tsx
resources/js/pages/admin/kyc.tsx
```

- [ ] **Step 1: Delete all files listed above**

```bash
rm -f app/Models/KycDocument.php app/Services/Kyc/KycService.php \
  app/Http/Controllers/Api/KycController.php \
  app/Http/Controllers/Admin/KycReviewController.php \
  app/Http/Controllers/Settings/KycController.php \
  app/Http/Middleware/CheckKycLevel.php \
  app/Models/ResponsibleGamblingSetting.php app/Models/SelfExclusion.php \
  app/Services/ResponsibleGambling/ResponsibleGamblingService.php \
  app/Http/Controllers/Api/ResponsibleGamblingController.php \
  app/Http/Controllers/Settings/ResponsibleGamblingController.php \
  app/Http/Middleware/CheckSelfExclusion.php \
  app/Services/Auth/SmsMfaService.php app/Http/Controllers/Auth/SmsMfaController.php \
  app/Models/PhoneVerification.php app/Services/Auth/PhoneVerificationService.php \
  app/Http/Controllers/Auth/PhoneVerificationController.php \
  app/Models/LoginNotification.php app/Services/Auth/LoginNotificationService.php \
  app/Services/Auth/DeviceDetectionService.php \
  app/Notifications/NewDeviceLogin.php app/Notifications/NewLocationLogin.php \
  app/Models/FormConfiguration.php app/Models/SavedFilter.php \
  app/Services/FormConfigService.php app/Http/Controllers/FormConfigController.php \
  config/acumatica-ui.php \
  resources/js/pages/settings/kyc.tsx \
  resources/js/pages/settings/responsible-gambling.tsx \
  resources/js/pages/admin/kyc.tsx
```

- [ ] **Step 2: Delete form-config route file**

```bash
rm -f routes/form-config.php
```

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "chore: delete files for removed features (KYC, responsible gambling, SMS MFA, phone verification, login notifications, device detection, form config)"
```

---

### Task 2: Clean up route files

**Files:**
- Modify: `routes/api.php`
- Modify: `routes/admin.php`
- Modify: `routes/settings.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Clean routes/api.php**

Remove:
- Import lines for `KycController`, `ResponsibleGamblingController`, `PhoneVerificationController`
- Phone verification routes (sendOtp, verify under public group; update, resendOtp under authenticated group)
- All KYC routes (status, requirements, documents CRUD)
- All responsible gambling routes (status, options, deposit-limits, session-limits, reality-check, login-restrictions, pending-limits, self-exclude, self-exclude history)

- [ ] **Step 2: Clean routes/admin.php**

Remove:
- Import for `KycReviewController`
- Report routes: `reports/kyc` and `reports/responsible-gambling`
- Entire KYC review section (index, stats, show, approve, reject, set-level routes)
- `admin.kyc` dashboard page route

- [ ] **Step 3: Clean routes/settings.php**

Remove:
- Import lines for `SmsMfaController`, `KycController`, `ResponsibleGamblingController`
- Entire SMS MFA route group (show, enable, verify, disable, setPreferred)
- KYC verification route
- Responsible gambling route

- [ ] **Step 4: Clean routes/web.php**

Remove:
- `kyc` route under admin group (if present)
- Any reference to `form-config.php` include

- [ ] **Step 5: Commit**

```bash
git add routes/
git commit -m "chore: remove routes for deleted features"
```

---

### Task 3: Clean up User model

**Files:**
- Modify: `app/Models/User.php`

- [ ] **Step 1: Remove fillable attributes**

Remove from `$fillable` array: `phone_verified_at`, `kyc_level`, `kyc_verified_at`, `sms_mfa_phone`, `sms_mfa_enabled`, `preferred_mfa_method`

- [ ] **Step 2: Remove casts**

Remove from `casts()`: `phone_verified_at`, `sms_mfa_enabled`, `kyc_level`, `kyc_verified_at`

- [ ] **Step 3: Remove methods and relationships**

Remove these methods entirely:
- `hasVerifiedPhone()`, `markPhoneAsVerified()`
- `phoneVerifications()` relationship
- `loginNotifications()` relationship
- `hasSmsMfaEnabled()`, `getPreferredMfaMethod()`
- `kycDocuments()` relationship
- `hasBasicKyc()`, `hasEnhancedKyc()`, `hasFullKyc()`, `meetsKycLevel()`
- `isOfLegalAge()`, `getKycLevelNameAttribute()`
- `responsibleGamblingSettings()` relationship
- `selfExclusions()` relationship
- `activeSelfExclusion()`, `hasActiveSelfExclusion()`

Simplify `isSelfExcluded()` — remove the method entirely since `self_excluded` status is being removed.

- [ ] **Step 4: Verify file compiles**

Run: `php artisan tinker --execute="new App\Models\User"`
Expected: No errors

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php
git commit -m "chore: clean up User model — remove KYC, RG, SMS MFA, phone verification methods"
```

---

### Task 4: Clean up Tenant model and FortifyServiceProvider

**Files:**
- Modify: `app/Models/Tenant.php`
- Modify: `app/Providers/FortifyServiceProvider.php`

- [ ] **Step 1: Clean Tenant model**

Remove relationships: `kycDocuments()`, `selfExclusions()`

- [ ] **Step 2: Clean FortifyServiceProvider**

Remove:
- Import lines for `DeviceDetectionService` and `LoginNotificationService`
- The `processLoginNotification()` method entirely
- The call to `$this->processLoginNotification($user, $request)` in the authentication pipeline
- The `isSelfExcluded()` / `self_excluded` status check in the authentication logic

- [ ] **Step 3: Verify app boots**

Run: `php artisan route:list --compact`
Expected: No errors, removed routes absent from list

- [ ] **Step 4: Commit**

```bash
git add app/Models/Tenant.php app/Providers/FortifyServiceProvider.php
git commit -m "chore: clean up Tenant model and FortifyServiceProvider"
```

---

### Task 5: Clean up middleware registrations and any remaining references

**Files:**
- Modify: `bootstrap/app.php` (or wherever middleware is registered)
- Modify: `app/Http/Controllers/Admin/ReportController.php` (remove `kyc()` and `responsibleGambling()` methods)

- [ ] **Step 1: Remove middleware aliases**

Check `bootstrap/app.php` for aliases/registrations of `CheckKycLevel` and `CheckSelfExclusion`. Remove them.

- [ ] **Step 2: Clean ReportController**

Remove `kyc()` and `responsibleGambling()` methods and any imports for removed models.

- [ ] **Step 3: Search for remaining references**

```bash
grep -r "KycDocument\|ResponsibleGamblingSetting\|SelfExclusion\|PhoneVerification\|LoginNotification\|SmsMfa\|DeviceDetection\|FormConfiguration\|SavedFilter\|CheckKycLevel\|CheckSelfExclusion" app/ routes/ resources/js/ --include="*.php" --include="*.tsx" --include="*.ts" -l
```

Fix any remaining references found.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: remove remaining references to deleted features"
```

---

### Task 6: Create cleanup migration

**Files:**
- Create: `database/migrations/2026_03_16_000001_cleanup_removed_features.php`

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration cleanup_removed_features
```

- [ ] **Step 2: Write migration content**

```php
public function up(): void
{
    // Drop tables for removed features
    Schema::dropIfExists('kyc_documents');
    Schema::dropIfExists('responsible_gambling_settings');
    Schema::dropIfExists('self_exclusions');
    Schema::dropIfExists('phone_verifications');
    Schema::dropIfExists('login_notifications');
    Schema::dropIfExists('form_configurations');
    Schema::dropIfExists('saved_filters');

    // Remove columns from users table
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn([
            'sms_mfa_phone',
            'sms_mfa_enabled',
            'preferred_mfa_method',
        ]);
    });
}
```

No `down()` — these are irreversible cleanup changes for a pre-production system.

- [ ] **Step 3: Run migration**

```bash
php artisan migrate
```

Expected: Migration runs successfully, tables dropped, columns removed.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/
git commit -m "chore: add migration to drop removed feature tables and columns"
```

---

## Chunk 2: Phase 2 — Wallet System

### Task 7: Create wallet migration

**Files:**
- Create: `database/migrations/2026_03_16_000002_create_wallets_table.php`
- Create: `database/migrations/2026_03_16_000003_create_wallet_transactions_table.php`

- [ ] **Step 1: Create wallets migration**

```bash
php artisan make:migration create_wallets_table
```

- [ ] **Step 2: Write wallets table schema**

```php
public function up(): void
{
    Schema::create('wallets', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->decimal('balance', 15, 2)->default(0);
        $table->string('currency', 3)->default('NAD');
        $table->enum('status', ['active', 'frozen', 'closed'])->default('active');
        $table->decimal('total_deposited', 15, 2)->default(0);
        $table->decimal('total_withdrawn', 15, 2)->default(0);
        $table->decimal('total_won', 15, 2)->default(0);
        $table->decimal('total_lost', 15, 2)->default(0);
        $table->timestamps();

        $table->unique(['tenant_id', 'user_id']);
        $table->index('status');
    });
}
```

- [ ] **Step 3: Create wallet_transactions migration**

```bash
php artisan make:migration create_wallet_transactions_table
```

- [ ] **Step 4: Write wallet_transactions table schema**

```php
public function up(): void
{
    Schema::create('wallet_transactions', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
        $table->unsignedBigInteger('game_session_id')->nullable();
        $table->enum('type', ['deposit', 'withdrawal', 'bet', 'win', 'adjustment']);
        $table->decimal('amount', 15, 2);
        $table->decimal('balance_before', 15, 2);
        $table->decimal('balance_after', 15, 2);
        $table->string('reference')->nullable()->index();
        $table->string('description')->nullable();
        $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
        $table->json('metadata')->nullable();
        $table->timestamps();

        $table->index(['wallet_id', 'created_at']);
        $table->index('type');
        // game_session_id FK added in game_sessions migration (Task 14)
    });
}
```

- [ ] **Step 5: Run migrations**

```bash
php artisan migrate
```

Expected: Both tables created successfully.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/
git commit -m "feat: add wallets and wallet_transactions tables"
```

---

### Task 8: Create Wallet and WalletTransaction models

**Files:**
- Create: `app/Models/Wallet.php`
- Create: `app/Models/WalletTransaction.php`

- [ ] **Step 1: Write Wallet model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Wallet extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'balance',
        'currency',
        'status',
        'total_deposited',
        'total_withdrawn',
        'total_won',
        'total_lost',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'total_deposited' => 'decimal:2',
            'total_withdrawn' => 'decimal:2',
            'total_won' => 'decimal:2',
            'total_lost' => 'decimal:2',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Wallet $wallet) {
            if (empty($wallet->uuid)) {
                $wallet->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function gameSessions()
    {
        return $this->morphMany(GameSession::class, 'source');
    }

    // Status methods

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }

    public function hasSufficientBalance(string $amount): bool
    {
        return bccomp($this->balance, $amount, 2) >= 0;
    }

    public function hasBalance(): bool
    {
        return bccomp($this->balance, '0', 2) > 0;
    }
}
```

- [ ] **Step 2: Write WalletTransaction model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'wallet_id',
        'game_session_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'reference',
        'description',
        'performed_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (WalletTransaction $txn) {
            if (empty($txn->uuid)) {
                $txn->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function gameSession()
    {
        return $this->belongsTo(GameSession::class);
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // Helpers

    public function isCredit(): bool
    {
        return in_array($this->type, ['deposit', 'win']) ||
            ($this->type === 'adjustment' && bccomp($this->amount, '0', 2) > 0);
    }

    public function isDebit(): bool
    {
        return in_array($this->type, ['withdrawal', 'bet']) ||
            ($this->type === 'adjustment' && bccomp($this->amount, '0', 2) < 0);
    }
}
```

- [ ] **Step 3: Verify models load**

```bash
php artisan tinker --execute="new App\Models\Wallet; new App\Models\WalletTransaction;"
```

Expected: No errors.

- [ ] **Step 4: Commit**

```bash
git add app/Models/Wallet.php app/Models/WalletTransaction.php
git commit -m "feat: add Wallet and WalletTransaction models"
```

---

### Task 9: Add wallet relationship to User model

**Files:**
- Modify: `app/Models/User.php`

- [ ] **Step 1: Add wallet relationship and helper**

Add import for `Wallet` model. Add these methods to User:

```php
public function wallet()
{
    return $this->hasOne(Wallet::class);
}

public function getOrCreateWallet(?string $currency = null): Wallet
{
    $wallet = $this->wallet;

    if ($wallet) {
        return $wallet;
    }

    $tenant = app('current_tenant');
    $currency = $currency ?? $tenant?->currency ?? 'NAD';

    return $this->wallet()->create([
        'tenant_id' => $this->tenant_id,
        'currency' => $currency,
        'status' => 'active',
    ]);
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/User.php
git commit -m "feat: add wallet relationship and getOrCreateWallet to User model"
```

---

### Task 10: Create WalletService

**Files:**
- Create: `app/Services/WalletService.php`

- [ ] **Step 1: Write the WalletService**

```php
<?php

namespace App\Services;

use App\Models\GameSession;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WalletService
{
    public function createWallet(User $user, ?string $currency = null): Wallet
    {
        $tenant = app('current_tenant');
        $currency = $currency ?? $tenant?->currency ?? 'NAD';

        return Wallet::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'currency' => $currency,
            'status' => 'active',
        ]);
    }

    public function deposit(Wallet $wallet, string $amount, ?User $performedBy = null, ?string $reference = null): WalletTransaction
    {
        $this->validateAmount($amount);

        return DB::transaction(function () use ($wallet, $amount, $performedBy, $reference) {
            $wallet = Wallet::lockForUpdate()->find($wallet->id);

            if (! $wallet->isActive()) {
                throw new InvalidArgumentException('Wallet is not active.');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcadd($balanceBefore, $amount, 2);

            $wallet->update([
                'balance' => $balanceAfter,
                'total_deposited' => bcadd($wallet->total_deposited, $amount, 2),
            ]);

            return $this->recordTransaction($wallet, [
                'type' => 'deposit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => $reference,
                'description' => 'Credit deposit',
                'performed_by' => $performedBy?->id,
            ]);
        });
    }

    public function withdraw(Wallet $wallet, string $amount, ?User $performedBy = null, ?string $reference = null): WalletTransaction
    {
        $this->validateAmount($amount);

        return DB::transaction(function () use ($wallet, $amount, $performedBy, $reference) {
            $wallet = Wallet::lockForUpdate()->find($wallet->id);

            if (! $wallet->isActive()) {
                throw new InvalidArgumentException('Wallet is not active.');
            }

            if (! $wallet->hasSufficientBalance($amount)) {
                throw new InvalidArgumentException('Insufficient balance.');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcsub($balanceBefore, $amount, 2);

            $wallet->update([
                'balance' => $balanceAfter,
                'total_withdrawn' => bcadd($wallet->total_withdrawn, $amount, 2),
            ]);

            return $this->recordTransaction($wallet, [
                'type' => 'withdrawal',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => $reference,
                'description' => 'Credit withdrawal',
                'performed_by' => $performedBy?->id,
            ]);
        });
    }

    public function debit(Wallet $wallet, string $amount, GameSession $session, ?string $reference = null): WalletTransaction
    {
        $this->validateAmount($amount);

        return DB::transaction(function () use ($wallet, $amount, $session, $reference) {
            // Idempotency: check for duplicate reference in same session
            if ($reference) {
                $existing = WalletTransaction::where('game_session_id', $session->id)
                    ->where('reference', $reference)
                    ->where('type', 'bet')
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $wallet = Wallet::lockForUpdate()->find($wallet->id);

            if (! $wallet->isActive()) {
                throw new InvalidArgumentException('Wallet is not active.');
            }

            if (! $wallet->hasSufficientBalance($amount)) {
                throw new InvalidArgumentException('Insufficient balance.');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcsub($balanceBefore, $amount, 2);

            $wallet->update([
                'balance' => $balanceAfter,
                'total_lost' => bcadd($wallet->total_lost, $amount, 2),
            ]);

            return $this->recordTransaction($wallet, [
                'type' => 'bet',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'game_session_id' => $session->id,
                'reference' => $reference,
                'description' => 'Game bet',
            ]);
        });
    }

    public function credit(Wallet $wallet, string $amount, GameSession $session, ?string $reference = null): WalletTransaction
    {
        $this->validateAmount($amount);

        return DB::transaction(function () use ($wallet, $amount, $session, $reference) {
            // Idempotency: check for duplicate reference in same session
            if ($reference) {
                $existing = WalletTransaction::where('game_session_id', $session->id)
                    ->where('reference', $reference)
                    ->where('type', 'win')
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $wallet = Wallet::lockForUpdate()->find($wallet->id);

            if (! $wallet->isActive()) {
                throw new InvalidArgumentException('Wallet is not active.');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcadd($balanceBefore, $amount, 2);

            $wallet->update([
                'balance' => $balanceAfter,
                'total_won' => bcadd($wallet->total_won, $amount, 2),
            ]);

            return $this->recordTransaction($wallet, [
                'type' => 'win',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'game_session_id' => $session->id,
                'reference' => $reference,
                'description' => 'Game win',
            ]);
        });
    }

    public function getBalance(Wallet $wallet): string
    {
        return $wallet->fresh()->balance;
    }

    private function validateAmount(string $amount): void
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
    }

    private function recordTransaction(Wallet $wallet, array $data): WalletTransaction
    {
        return $wallet->transactions()->create($data);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/WalletService.php
git commit -m "feat: add WalletService with deposit, withdraw, debit, credit operations"
```

---

### Task 11: Create WalletManagementController and routes

**Files:**
- Create: `app/Http/Controllers/Admin/WalletManagementController.php`
- Modify: `routes/admin.php`

- [ ] **Step 1: Write WalletManagementController**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletManagementController extends Controller
{
    public function __construct(private WalletService $walletService) {}

    public function index(Request $request): JsonResponse
    {
        $query = Wallet::with('user:id,name,email,uuid')
            ->where('tenant_id', app('current_tenant')->id);

        if ($search = $request->input('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $wallets = $query->orderByDesc('updated_at')->paginate(15);

        $stats = [
            'total_wallets' => Wallet::where('tenant_id', app('current_tenant')->id)->count(),
            'total_balance' => Wallet::where('tenant_id', app('current_tenant')->id)
                ->where('status', 'active')->sum('balance'),
            'active_wallets' => Wallet::where('tenant_id', app('current_tenant')->id)
                ->where('status', 'active')->count(),
            'frozen_wallets' => Wallet::where('tenant_id', app('current_tenant')->id)
                ->where('status', 'frozen')->count(),
        ];

        return response()->json([
            'wallets' => $wallets,
            'stats' => $stats,
        ]);
    }

    public function show(Wallet $wallet): JsonResponse
    {
        $wallet->load('user:id,name,email,uuid');
        $transactions = $wallet->transactions()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'wallet' => $wallet,
            'transactions' => $transactions,
        ]);
    }

    public function deposit(Request $request, Wallet $wallet): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
        ]);

        $transaction = $this->walletService->deposit(
            $wallet,
            $request->input('amount'),
            $request->user(),
            $request->input('reference'),
        );

        return response()->json([
            'transaction' => $transaction,
            'balance' => $wallet->fresh()->balance,
        ]);
    }

    public function withdraw(Request $request, Wallet $wallet): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
        ]);

        $transaction = $this->walletService->withdraw(
            $wallet,
            $request->input('amount'),
            $request->user(),
            $request->input('reference'),
        );

        return response()->json([
            'transaction' => $transaction,
            'balance' => $wallet->fresh()->balance,
        ]);
    }

    public function freeze(Wallet $wallet): JsonResponse
    {
        $wallet->update(['status' => 'frozen']);

        return response()->json(['wallet' => $wallet->fresh()]);
    }

    public function activate(Wallet $wallet): JsonResponse
    {
        $wallet->update(['status' => 'active']);

        return response()->json(['wallet' => $wallet->fresh()]);
    }
}
```

- [ ] **Step 2: Add wallet routes to routes/admin.php**

Add inside the admin route group:

```php
use App\Http\Controllers\Admin\WalletManagementController;

// Wallet management
Route::get('wallets', [WalletManagementController::class, 'index'])->name('wallets.index');
Route::get('wallets/{wallet}', [WalletManagementController::class, 'show'])->name('wallets.show');
Route::post('wallets/{wallet}/deposit', [WalletManagementController::class, 'deposit'])->name('wallets.deposit');
Route::post('wallets/{wallet}/withdraw', [WalletManagementController::class, 'withdraw'])->name('wallets.withdraw');
Route::post('wallets/{wallet}/freeze', [WalletManagementController::class, 'freeze'])->name('wallets.freeze');
Route::post('wallets/{wallet}/activate', [WalletManagementController::class, 'activate'])->name('wallets.activate');
```

- [ ] **Step 3: Verify routes**

```bash
php artisan route:list --path=admin/wallets
```

Expected: 6 wallet routes listed.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Admin/WalletManagementController.php routes/admin.php
git commit -m "feat: add wallet management controller and admin routes"
```

---

## Chunk 3: Phase 3 — Unified Game Session API

### Task 12: Retrofit VoucherCodeService with bcmath and locking

**Files:**
- Modify: `app/Services/Venue/VoucherCodeService.php`
- Modify: `app/Models/VoucherCode.php`

- [ ] **Step 1: Update VoucherCode model**

Change `hasSufficientBalance()` to use bccomp:

```php
public function hasSufficientBalance(string $amount): bool
{
    return bccomp($this->balance, $amount, 2) >= 0;
}
```

- [ ] **Step 2: Change debit/credit method signatures to accept GameSession**

The existing `debit()` signature is:
```php
public function debit(VoucherCode $voucherCode, float $amount, ?string $reference = null, ?VenueTerminal $terminal = null, ?array $metadata = null)
```

Change to:
```php
public function debit(VoucherCode $voucherCode, string $amount, GameSession $session, ?string $reference = null): VoucherTransaction
```

Same for `credit()`. The terminal and metadata are now accessible via the GameSession. Also add the idempotency check (same pattern as WalletService):

```php
if ($reference) {
    $existing = VoucherTransaction::where('game_session_id', $session->id)
        ->where('reference', $reference)
        ->where('type', 'bet')
        ->first();
    if ($existing) {
        return $existing;
    }
}
```

Update the `recordTransaction()` call inside debit/credit to pass `$session->id` as the `game_session_id`.

- [ ] **Step 3: Retrofit all monetary methods with bcmath**

Replace all float arithmetic in the service (loadCredits, cashout, transfer, recordTransaction, debit, credit):
- `$balanceBefore + $amount` → `bcadd($balanceBefore, $amount, 2)`
- `$balanceBefore - $amount` → `bcsub($balanceBefore, $amount, 2)`
- Change all method signatures from `float $amount` to `string $amount`
- Add `lockForUpdate()` inside each DB::transaction, same pattern as WalletService

- [ ] **Step 4: Verify no float references remain**

```bash
grep -n "float \$amount\|float \$balance" app/Services/Venue/VoucherCodeService.php
```

Expected: No matches.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Venue/VoucherCodeService.php app/Models/VoucherCode.php
git commit -m "refactor: retrofit VoucherCodeService with bcmath, locking, and GameSession params"
```

---

### Task 13: Rename voucher transaction type 'loss' to 'bet'

**Files:**
- Modify: `app/Models/VoucherTransaction.php`
- Modify: `app/Services/Venue/VoucherCodeService.php`
- Modify: any other files referencing `'loss'` transaction type

- [ ] **Step 1: Update VoucherTransaction model**

Change the type constant/enum from `'loss'` to `'bet'`. Update `isDebit()` and `isCredit()` methods. Update `getTypeLabelAttribute()`.

- [ ] **Step 2: Update VoucherCodeService**

Replace all `'loss'` references with `'bet'` in the `debit()` method and `recordTransaction()` calls.

- [ ] **Step 3: Search for remaining 'loss' type references**

```bash
grep -rn "'loss'" app/ --include="*.php"
```

Fix any remaining references (except `total_lost` column name which stays).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor: rename voucher transaction type 'loss' to 'bet' for consistency"
```

---

### Task 14: Create game sessions migration

**Files:**
- Create: `database/migrations/2026_03_16_000004_create_game_sessions_table.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration create_game_sessions_and_refactor_voucher_sessions
```

- [ ] **Step 2: Write migration**

```php
public function up(): void
{
    // Create game_sessions table
    Schema::create('game_sessions', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->string('session_token')->unique();
        $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
        $table->foreignId('game_id')->constrained()->cascadeOnDelete();
        $table->string('source_type'); // App\Models\Wallet or App\Models\VoucherCode
        $table->unsignedBigInteger('source_id');
        $table->foreignId('terminal_id')->nullable()->constrained('venue_terminals')->nullOnDelete();
        $table->string('ip_address', 45)->nullable();
        $table->decimal('balance_start', 15, 2);
        $table->decimal('balance_end', 15, 2)->nullable();
        $table->timestamp('started_at');
        $table->timestamp('ended_at')->nullable();
        $table->enum('end_reason', ['logout', 'timeout', 'cashed_out', 'forced'])->nullable();
        $table->timestamps();

        $table->index(['source_type', 'source_id']);
        $table->index(['tenant_id', 'game_id']);
    });

    // Add FK from wallet_transactions to game_sessions
    Schema::table('wallet_transactions', function (Blueprint $table) {
        $table->foreign('game_session_id')->references('id')->on('game_sessions')->nullOnDelete();
    });

    // Refactor voucher_codes: drop old session FK, add new one
    Schema::table('voucher_codes', function (Blueprint $table) {
        $table->dropForeign(['current_session_id']);
    });

    // Drop voucher_sessions table
    Schema::dropIfExists('voucher_sessions');

    // Re-add current_session_id FK to game_sessions
    Schema::table('voucher_codes', function (Blueprint $table) {
        $table->foreign('current_session_id')->references('id')->on('game_sessions')->nullOnDelete();
    });

    // Refactor voucher_transactions: rename session_id to game_session_id
    Schema::table('voucher_transactions', function (Blueprint $table) {
        $table->dropForeign(['session_id']);
        $table->renameColumn('session_id', 'game_session_id');
    });

    Schema::table('voucher_transactions', function (Blueprint $table) {
        $table->foreign('game_session_id')->references('id')->on('game_sessions')->nullOnDelete();
    });

    // Rename 'loss' to 'bet' in voucher_transactions type enum
    // Must update data BEFORE changing the enum, otherwise MySQL rejects rows with old value
    DB::statement("UPDATE voucher_transactions SET type = 'bet' WHERE type = 'loss'");
    DB::statement("ALTER TABLE voucher_transactions MODIFY COLUMN type ENUM('load', 'win', 'bet', 'cashout', 'adjustment', 'transfer_in', 'transfer_out')");
}
```

- [ ] **Step 3: Run migration**

```bash
php artisan migrate
```

Expected: Migration succeeds, voucher_sessions dropped, game_sessions created, voucher_transactions refactored.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/
git commit -m "feat: create game_sessions table, refactor voucher_transactions"
```

---

### Task 15: Create GameSession model

**Files:**
- Create: `app/Models/GameSession.php`

- [ ] **Step 1: Write GameSession model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GameSession extends Model
{
    use HasFactory;

    // Does NOT use BelongsToTenant — lookups are by globally-unique session_token.
    // tenant_id exists for revenue aggregation queries with explicit where clauses.

    protected $fillable = [
        'uuid',
        'session_token',
        'tenant_id',
        'game_id',
        'source_type',
        'source_id',
        'terminal_id',
        'ip_address',
        'balance_start',
        'balance_end',
        'started_at',
        'ended_at',
        'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'balance_start' => 'decimal:2',
            'balance_end' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (GameSession $session) {
            if (empty($session->uuid)) {
                $session->uuid = (string) Str::uuid();
            }
            if (empty($session->session_token)) {
                $session->session_token = 'gs_' . Str::random(64);
            }
            if (empty($session->started_at)) {
                $session->started_at = now();
            }
        });
    }

    // Relationships

    public function source()
    {
        return $this->morphTo();
    }

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function terminal()
    {
        return $this->belongsTo(VenueTerminal::class, 'terminal_id');
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function voucherTransactions()
    {
        return $this->hasMany(VoucherTransaction::class, 'game_session_id');
    }

    // Status

    public function isActive(): bool
    {
        if ($this->ended_at !== null) {
            return false;
        }

        // Timed out if no activity for 30 minutes
        return $this->updated_at->diffInMinutes(now()) < 30;
    }

    public function end(string $reason, ?string $balanceEnd = null): void
    {
        $this->update([
            'ended_at' => now(),
            'end_reason' => $reason,
            'balance_end' => $balanceEnd,
        ]);

        // If voucher code, reset its status
        if ($this->source_type === VoucherCode::class) {
            $this->source->update([
                'status' => 'active',
                'current_terminal_id' => null,
                'current_session_id' => null,
            ]);
        }
    }

    // Computed attributes

    public function getNetResultAttribute(): ?string
    {
        if ($this->balance_end === null) {
            return null;
        }

        return bcsub($this->balance_end, $this->balance_start, 2);
    }

    public function getDurationMinutesAttribute(): ?float
    {
        $end = $this->ended_at ?? now();

        return $this->started_at->diffInSeconds($end) / 60;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/GameSession.php
git commit -m "feat: add GameSession model with polymorphic source support"
```

---

### Task 16: Update VoucherCode and VoucherTransaction models for game_sessions

**Files:**
- Modify: `app/Models/VoucherCode.php`
- Modify: `app/Models/VoucherTransaction.php`
- Delete: `app/Models/VoucherSession.php`

- [ ] **Step 1: Delete VoucherSession model**

```bash
rm -f app/Models/VoucherSession.php
```

- [ ] **Step 2: Update VoucherCode relationships**

Replace `sessions()` and `currentSession()` to reference GameSession:

```php
public function currentSession()
{
    return $this->belongsTo(GameSession::class, 'current_session_id');
}

public function gameSessions()
{
    return $this->morphMany(GameSession::class, 'source');
}
```

Remove the old `sessions()` relationship that referenced VoucherSession.

- [ ] **Step 3: Update VoucherTransaction model**

Change `session()` relationship to reference GameSession via `game_session_id`:

```php
public function gameSession()
{
    return $this->belongsTo(GameSession::class, 'game_session_id');
}
```

Update the type enum — replace `'loss'` with `'bet'` in the type constants and helper methods.

- [ ] **Step 4: Search for remaining VoucherSession references**

```bash
grep -rn "VoucherSession" app/ routes/ --include="*.php"
```

Fix any remaining references.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor: replace VoucherSession references with GameSession"
```

---

### Task 17: Create GameSessionService

**Files:**
- Create: `app/Services/GameSessionService.php`

- [ ] **Step 1: Write GameSessionService**

```php
<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameSession;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\VenueTerminal;
use App\Models\Wallet;
use App\Services\Venue\VoucherCodeService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class GameSessionService
{
    public function __construct(
        private WalletService $walletService,
        private VoucherCodeService $voucherCodeService,
    ) {}

    public function startWalletSession(User $user, Game $game, ?string $ipAddress = null): GameSession
    {
        $wallet = $user->getOrCreateWallet();

        $this->validateGameForTenant($game, $wallet->tenant_id);
        $this->ensureNoActiveSession($wallet);

        return GameSession::create([
            'tenant_id' => $wallet->tenant_id,
            'game_id' => $game->id,
            'source_type' => Wallet::class,
            'source_id' => $wallet->id,
            'ip_address' => $ipAddress,
            'balance_start' => $wallet->balance,
        ]);
    }

    public function startVoucherSession(
        VoucherCode $code,
        Game $game,
        VenueTerminal $terminal,
        ?string $pin = null,
        ?string $ipAddress = null,
    ): GameSession {
        $this->validateGameForTenant($game, $code->tenant_id);

        if (! $code->canBeUsed()) {
            throw new InvalidArgumentException('Voucher code is not available for use.');
        }

        if ($code->hasPin() && ! $code->verifyPin($pin)) {
            throw new InvalidArgumentException('Invalid PIN.');
        }

        $this->ensureNoActiveSession($code);

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
        ]);

        return $session;
    }

    public function endSession(string $sessionToken, string $reason): GameSession
    {
        $session = $this->findActiveSession($sessionToken);
        $balance = $this->getSourceBalance($session);
        $session->end($reason, $balance);

        return $session->fresh();
    }

    public function debit(string $sessionToken, string $amount, ?string $reference = null): array
    {
        $session = $this->findActiveSession($sessionToken);
        $session->touch(); // extend timeout

        if ($session->source_type === Wallet::class) {
            $txn = $this->walletService->debit($session->source, $amount, $session, $reference);
        } else {
            $txn = $this->voucherCodeService->debit($session->source, $amount, $session, $reference);
        }

        return [
            'success' => true,
            'balance' => $this->getSourceBalance($session),
            'transaction_id' => $txn->uuid,
        ];
    }

    public function credit(string $sessionToken, string $amount, ?string $reference = null): array
    {
        $session = $this->findActiveSession($sessionToken);
        $session->touch(); // extend timeout

        if ($session->source_type === Wallet::class) {
            $txn = $this->walletService->credit($session->source, $amount, $session, $reference);
        } else {
            $txn = $this->voucherCodeService->credit($session->source, $amount, $session, $reference);
        }

        return [
            'success' => true,
            'balance' => $this->getSourceBalance($session),
            'transaction_id' => $txn->uuid,
        ];
    }

    public function getBalance(string $sessionToken): array
    {
        $session = $this->findActiveSession($sessionToken);

        return [
            'balance' => $this->getSourceBalance($session),
            'currency' => $this->getSourceCurrency($session),
        ];
    }

    public function getSessionInfo(string $sessionToken): GameSession
    {
        return $this->findActiveSession($sessionToken)->load(['game', 'terminal']);
    }

    public function getTransactions(string $sessionToken, int $limit = 20): Collection
    {
        $session = $this->findActiveSession($sessionToken);

        if ($session->source_type === Wallet::class) {
            return $session->walletTransactions()->orderByDesc('created_at')->limit($limit)->get();
        }

        return $session->voucherTransactions()->orderByDesc('created_at')->limit($limit)->get();
    }

    // Private helpers

    private function findActiveSession(string $sessionToken): GameSession
    {
        $session = GameSession::where('session_token', $sessionToken)->first();

        if (! $session) {
            throw new InvalidArgumentException('Session not found.');
        }

        if (! $session->isActive()) {
            throw new InvalidArgumentException('Session is no longer active.');
        }

        return $session;
    }

    private function validateGameForTenant(Game $game, int $tenantId): void
    {
        if (! $game->isActive()) {
            throw new InvalidArgumentException('Game is not active.');
        }

        $enabled = $game->tenants()
            ->where('tenants.id', $tenantId)
            ->wherePivot('enabled', true)
            ->exists();

        if (! $enabled) {
            throw new InvalidArgumentException('Game is not enabled for this tenant.');
        }
    }

    private function ensureNoActiveSession($source): void
    {
        $sourceType = $source instanceof Wallet ? Wallet::class : VoucherCode::class;

        $activeSession = GameSession::where('source_type', $sourceType)
            ->where('source_id', $source->id)
            ->whereNull('ended_at')
            ->where('updated_at', '>=', now()->subMinutes(30))
            ->exists();

        if ($activeSession) {
            throw new InvalidArgumentException('An active session already exists for this source.');
        }
    }

    private function getSourceBalance(GameSession $session): string
    {
        if ($session->source_type === Wallet::class) {
            return $session->source->fresh()->balance;
        }

        return $session->source->fresh()->balance;
    }

    private function getSourceCurrency(GameSession $session): string
    {
        if ($session->source_type === Wallet::class) {
            return $session->source->currency;
        }

        return $session->source->venue?->currency ?? 'NAD';
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/GameSessionService.php
git commit -m "feat: add GameSessionService with wallet and voucher code delegation"
```

---

### Task 18: Create AuthenticateGameSession middleware

**Files:**
- Create: `app/Http/Middleware/AuthenticateGameSession.php`
- Delete: `app/Http/Middleware/AuthenticateVoucherSession.php`

- [ ] **Step 1: Write AuthenticateGameSession middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Models\GameSession;
use App\Models\VoucherCode;
use App\Models\Wallet;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateGameSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token || ! str_starts_with($token, 'gs_')) {
            return response()->json(['error' => 'Invalid game session token.'], 401);
        }

        $session = GameSession::where('session_token', $token)->first();

        if (! $session) {
            return response()->json(['error' => 'Session not found.'], 401);
        }

        if ($session->ended_at !== null) {
            return response()->json(['error' => 'Session has ended.'], 401);
        }

        // Check 30-minute inactivity timeout
        if ($session->updated_at->diffInMinutes(now()) >= 30) {
            return response()->json(['error' => 'Session has timed out.'], 401);
        }

        // Verify source is still active
        $source = $session->source;
        if (! $source) {
            return response()->json(['error' => 'Session source not found.'], 401);
        }

        if ($session->source_type === Wallet::class && ! $source->isActive()) {
            return response()->json(['error' => 'Wallet is not active.'], 403);
        }

        if ($session->source_type === VoucherCode::class && $source->isExpired()) {
            return response()->json(['error' => 'Voucher code has expired.'], 403);
        }

        // If terminal session, verify terminal is active
        if ($session->terminal_id) {
            $terminal = $session->terminal;
            if (! $terminal || ! $terminal->isActive()) {
                return response()->json(['error' => 'Terminal is not active.'], 403);
            }
        }

        // Touch to extend timeout
        $session->touch();

        // Bind to request
        $request->merge(['gameSession' => $session]);
        $request->attributes->set('gameSession', $session);

        return $next($request);
    }
}
```

- [ ] **Step 2: Delete old middleware**

```bash
rm -f app/Http/Middleware/AuthenticateVoucherSession.php
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Middleware/AuthenticateGameSession.php
git add -A
git commit -m "feat: add AuthenticateGameSession middleware, remove AuthenticateVoucherSession"
```

---

### Task 19: Create GameSessionController and routes

**Files:**
- Create: `app/Http/Controllers/Api/GameSessionController.php`
- Create: `routes/game.php`
- Modify: `routes/terminal.php` (remove old player endpoints)
- Modify: `bootstrap/app.php` or route service provider (include game.php)

- [ ] **Step 1: Write GameSessionController**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\VoucherCode;
use App\Services\GameSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class GameSessionController extends Controller
{
    public function __construct(private GameSessionService $gameSessionService) {}

    public function startWalletSession(Request $request): JsonResponse
    {
        $request->validate(['game_id' => 'required|string']);

        $game = Game::where('uuid', $request->input('game_id'))->firstOrFail();
        $user = $request->user();

        try {
            $session = $this->gameSessionService->startWalletSession(
                $user,
                $game,
                $request->ip(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $balance = $this->gameSessionService->getBalance($session->session_token);

        return response()->json([
            'session_token' => $session->session_token,
            'balance' => $balance['balance'],
            'currency' => $balance['currency'],
            'game' => [
                'uuid' => $game->uuid,
                'name' => $game->name,
            ],
        ]);
    }

    public function startTerminalSession(Request $request): JsonResponse
    {
        $request->validate([
            'game_id' => 'required|string',
            'code' => 'required|string',
            'pin' => 'nullable|string',
        ]);

        $game = Game::where('uuid', $request->input('game_id'))->firstOrFail();
        $code = VoucherCode::where('code', $request->input('code'))->firstOrFail();
        $terminal = $request->input('terminal');

        try {
            $session = $this->gameSessionService->startVoucherSession(
                $code,
                $game,
                $terminal,
                $request->input('pin'),
                $request->ip(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $balance = $this->gameSessionService->getBalance($session->session_token);

        return response()->json([
            'session_token' => $session->session_token,
            'balance' => $balance['balance'],
            'currency' => $balance['currency'],
            'game' => [
                'uuid' => $game->uuid,
                'name' => $game->name,
            ],
        ]);
    }

    public function endSession(Request $request): JsonResponse
    {
        $session = $request->attributes->get('gameSession');

        try {
            $ended = $this->gameSessionService->endSession($session->session_token, 'logout');
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ended' => true,
            'balance_start' => $ended->balance_start,
            'balance_end' => $ended->balance_end,
            'duration_minutes' => $ended->duration_minutes,
        ]);
    }

    public function sessionInfo(Request $request): JsonResponse
    {
        $session = $request->attributes->get('gameSession');
        $info = $this->gameSessionService->getSessionInfo($session->session_token);

        return response()->json(['session' => $info]);
    }

    public function balance(Request $request): JsonResponse
    {
        $session = $request->attributes->get('gameSession');
        $balance = $this->gameSessionService->getBalance($session->session_token);

        return response()->json($balance);
    }

    public function debit(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
        ]);

        $session = $request->attributes->get('gameSession');

        try {
            $result = $this->gameSessionService->debit(
                $session->session_token,
                $request->input('amount'),
                $request->input('reference'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 402);
        }

        return response()->json($result);
    }

    public function credit(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
        ]);

        $session = $request->attributes->get('gameSession');

        try {
            $result = $this->gameSessionService->credit(
                $session->session_token,
                $request->input('amount'),
                $request->input('reference'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function transactions(Request $request): JsonResponse
    {
        $session = $request->attributes->get('gameSession');
        $transactions = $this->gameSessionService->getTransactions($session->session_token);

        return response()->json(['transactions' => $transactions]);
    }
}
```

- [ ] **Step 2: Create routes/game.php**

```php
<?php

use App\Http\Controllers\Api\GameSessionController;
use App\Http\Middleware\AuthenticateGameSession;
use App\Http\Middleware\AuthenticateTerminal;
use Illuminate\Support\Facades\Route;

// All game routes get the 'api' middleware group for rate limiting, etc.

// Session start — wallet (requires OAuth token)
Route::middleware(['api', 'auth:api'])->prefix('api/v1/game/session/start')->group(function () {
    Route::post('/wallet', [GameSessionController::class, 'startWalletSession'])->name('api.game.session.start.wallet');
});

// Session start — terminal (requires terminal key)
Route::middleware(['api', AuthenticateTerminal::class])->prefix('api/v1/game/session/start')->group(function () {
    Route::post('/terminal', [GameSessionController::class, 'startTerminalSession'])->name('api.game.session.start.terminal');
});

// Gameplay endpoints (requires game session token)
Route::middleware(['api', AuthenticateGameSession::class])->prefix('api/v1/game')->name('api.game.')->group(function () {
    Route::post('/session/end', [GameSessionController::class, 'endSession'])->name('session.end');
    Route::get('/session/info', [GameSessionController::class, 'sessionInfo'])->name('session.info');
    Route::get('/balance', [GameSessionController::class, 'balance'])->name('balance');
    Route::post('/debit', [GameSessionController::class, 'debit'])->name('debit');
    Route::post('/credit', [GameSessionController::class, 'credit'])->name('credit');
    Route::get('/transactions', [GameSessionController::class, 'transactions'])->name('transactions');
});
```

- [ ] **Step 3: Register game routes**

Add to `bootstrap/app.php` or the route service provider where other route files are loaded:

```php
->withRouting(
    // ... existing routes ...
    then: function () {
        // ... existing ...
        require base_path('routes/game.php');
    },
)
```

- [ ] **Step 4: Clean up routes/terminal.php**

Remove:
- All routes under `AuthenticateVoucherSession` middleware group (`/venue/player/*` and `/venue/auth/code/*` session routes)
- Import for `AuthenticateVoucherSession` and `PlayerController`

Keep:
- `POST /terminal/auth` (terminal authentication)
- `POST /terminal/heartbeat` (heartbeat)

- [ ] **Step 5: Delete PlayerController**

```bash
rm -f app/Http/Controllers/Terminal/PlayerController.php
```

- [ ] **Step 6: Clean Terminal AuthController**

Remove methods: `authenticateCode()`, `verifyPin()`, `logout()`, `sessionInfo()` — these are now handled by GameSessionController. Keep: `authenticateTerminal()` and `heartbeat()`.

- [ ] **Step 7: Verify all routes**

```bash
php artisan route:list --path=api/v1/game
```

Expected: 8 game routes listed.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: add unified game API routes and controller, remove old terminal player endpoints"
```

---

## Chunk 4: Phase 4 — Revenue & Scheduled Commands

### Task 20: Create CalculateRevenueCommand

**Files:**
- Create: `app/Console/Commands/CalculateRevenueCommand.php`

- [ ] **Step 1: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\GameSession;
use App\Models\Tenant;
use App\Models\TenantRevenueRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculateRevenueCommand extends Command
{
    protected $signature = 'revenue:calculate {--period=daily}';
    protected $description = 'Calculate revenue records from game session transactions';

    public function handle(): int
    {
        $periodType = $this->option('period');
        [$periodStart, $periodEnd] = $this->getPeriodBounds($periodType);

        $this->info("Calculating {$periodType} revenue for {$periodStart->toDateString()} to {$periodEnd->toDateString()}");

        $tenants = Tenant::where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            $this->calculateForTenant($tenant, $periodType, $periodStart, $periodEnd);
        }

        $this->info('Revenue calculation complete.');

        return self::SUCCESS;
    }

    private function calculateForTenant(Tenant $tenant, string $periodType, $periodStart, $periodEnd): void
    {
        // Get all games with sessions in this period for this tenant
        $gameIds = GameSession::where('tenant_id', $tenant->id)
            ->where('started_at', '>=', $periodStart)
            ->where('started_at', '<', $periodEnd)
            ->distinct()
            ->pluck('game_id');

        foreach ($gameIds as $gameId) {
            $sessionIds = GameSession::where('tenant_id', $tenant->id)
                ->where('game_id', $gameId)
                ->where('started_at', '>=', $periodStart)
                ->where('started_at', '<', $periodEnd)
                ->pluck('id');

            // Sum wallet transaction bets and wins
            $walletBets = DB::table('wallet_transactions')
                ->whereIn('game_session_id', $sessionIds)
                ->where('type', 'bet')
                ->sum('amount');

            $walletWins = DB::table('wallet_transactions')
                ->whereIn('game_session_id', $sessionIds)
                ->where('type', 'win')
                ->sum('amount');

            // Sum voucher transaction bets and wins
            $voucherBets = DB::table('voucher_transactions')
                ->whereIn('game_session_id', $sessionIds)
                ->where('type', 'bet')
                ->sum('amount');

            $voucherWins = DB::table('voucher_transactions')
                ->whereIn('game_session_id', $sessionIds)
                ->where('type', 'win')
                ->sum('amount');

            $totalBets = bcadd((string) $walletBets, (string) $voucherBets, 2);
            $totalWins = bcadd((string) $walletWins, (string) $voucherWins, 2);
            $ggr = bcsub($totalBets, $totalWins, 2);

            $revenueSharePct = $tenant->revenue_share_pct ?? 70;
            $tenantShare = bcdiv(bcmul($ggr, (string) $revenueSharePct, 2), '100', 2);
            $chingaShare = bcsub($ggr, $tenantShare, 2);

            TenantRevenueRecord::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'game_id' => $gameId,
                    'period_type' => $periodType,
                    'period_start' => $periodStart,
                ],
                [
                    'period_end' => $periodEnd,
                    'total_bets' => $totalBets,
                    'total_wins' => $totalWins,
                    'gross_gaming_revenue' => $ggr,
                    'revenue_share_pct' => $revenueSharePct,
                    'tenant_share' => $tenantShare,
                    'chinga_share' => $chingaShare,
                    'status' => 'pending',
                    'calculated_at' => now(),
                ],
            );

            $this->line("  Tenant {$tenant->name}, Game #{$gameId}: GGR={$ggr}, Tenant={$tenantShare}, Chinga={$chingaShare}");
        }
    }

    private function getPeriodBounds(string $periodType): array
    {
        return match ($periodType) {
            'daily' => [now()->subDay()->startOfDay(), now()->startOfDay()],
            'weekly' => [now()->subWeek()->startOfWeek(), now()->startOfWeek()],
            'monthly' => [now()->subMonth()->startOfMonth(), now()->startOfMonth()],
            default => throw new \InvalidArgumentException("Invalid period type: {$periodType}"),
        };
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Console/Commands/CalculateRevenueCommand.php
git commit -m "feat: add revenue:calculate Artisan command"
```

---

### Task 21: Create CleanupGameSessionsCommand

**Files:**
- Create: `app/Console/Commands/CleanupGameSessionsCommand.php`

- [ ] **Step 1: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\GameSession;
use App\Models\VoucherCode;
use Illuminate\Console\Command;

class CleanupGameSessionsCommand extends Command
{
    protected $signature = 'game-sessions:cleanup';
    protected $description = 'End timed-out game sessions (inactive for 30+ minutes)';

    public function handle(): int
    {
        $staleSessions = GameSession::whereNull('ended_at')
            ->where('updated_at', '<', now()->subMinutes(30))
            ->get();

        $count = 0;

        foreach ($staleSessions as $session) {
            $balance = $session->source?->fresh()?->balance ?? '0.00';

            $session->update([
                'ended_at' => now(),
                'end_reason' => 'timeout',
                'balance_end' => $balance,
            ]);

            // Reset voucher code status if applicable
            if ($session->source_type === VoucherCode::class && $session->source) {
                $session->source->update([
                    'status' => 'active',
                    'current_terminal_id' => null,
                    'current_session_id' => null,
                ]);
            }

            $count++;
        }

        if ($count > 0) {
            $this->info("Cleaned up {$count} timed-out game sessions.");
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Console/Commands/CleanupGameSessionsCommand.php
git commit -m "feat: add game-sessions:cleanup Artisan command"
```

---

### Task 22: Register scheduled commands

**Files:**
- Modify: `routes/console.php` (or wherever schedule is registered)

- [ ] **Step 1: Add schedule**

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('revenue:calculate --period=daily')->dailyAt('02:00');
Schedule::command('game-sessions:cleanup')->hourly();
```

- [ ] **Step 2: Verify commands exist**

```bash
php artisan list | grep -E "revenue:|game-sessions:"
```

Expected: Both commands listed.

- [ ] **Step 3: Commit**

```bash
git add routes/console.php
git commit -m "feat: register revenue and session cleanup scheduled commands"
```

---

## Chunk 5: Phase 5 — Frontend Changes

### Task 23: Clean up user dashboard

**Files:**
- Modify: `resources/js/pages/dashboard.tsx`
- Modify: `app/Http/Controllers/UserDashboardController.php`

- [ ] **Step 1: Clean backend controller**

In `UserDashboardController.php`:
- Remove imports for `KycDocument` and `LoginNotification`
- Remove `phone_verified` from the `account` array
- Remove `kyc_documents` fetching block
- Remove `login_alerts` fetching block
- Remove `kyc_documents` and `login_alerts` from Inertia props

- [ ] **Step 2: Clean frontend dashboard**

In `dashboard.tsx`:
- Remove `KycDocument`, `LoginAlert` interfaces
- Remove `getKycLevelInfo()` and `getKycStatusVariant()` functions
- Remove `kyc_documents` and `login_alerts` from props destructuring
- Remove KYC Level stats card
- Simplify Security % card (only email_verified + two_factor_enabled)
- Remove "Security Alerts" section (login_alerts)
- Remove "Identity Documents" section (KYC documents)
- Simplify verification checklist (remove phone_verified, kyc_verified items)
- Remove "Verify Identity" and "Responsible Gaming" quick actions

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/dashboard.tsx app/Http/Controllers/UserDashboardController.php
git commit -m "chore: clean up user dashboard — remove KYC, RG, login alerts"
```

---

### Task 24: Clean up admin dashboard

**Files:**
- Modify: `resources/js/pages/admin/dashboard.tsx`
- Modify: `app/Http/Controllers/Admin/DashboardController.php`

- [ ] **Step 1: Clean backend controller**

In `DashboardController.php`:
- Remove KycDocument import
- Remove `kyc` stats calculation
- Remove `pending_kyc` fetching
- Remove `kyc_level` from recent users mapping
- Remove `pending_kyc` from Inertia props

- [ ] **Step 2: Clean frontend dashboard**

In `admin/dashboard.tsx`:
- Remove `KycStats` and `PendingKyc` interfaces
- Remove `getKycLevelName()` function
- Remove Pending KYC stats card
- Remove KYC level column from Recent Registrations table
- Remove "Pending KYC Review" section

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/admin/dashboard.tsx app/Http/Controllers/Admin/DashboardController.php
git commit -m "chore: clean up admin dashboard — remove KYC references"
```

---

### Task 25: Clean up admin users page

**Files:**
- Modify: `resources/js/pages/admin/users.tsx`

- [ ] **Step 1: Remove KYC references**

- Remove `kyc_levels` from Stats interface
- Remove `getKycLevelName()` function
- Remove `kycOptions` constant and `kycFilter` state
- Remove KYC filter from `fetchUsers` params and useEffect dependencies
- Remove `kycTemplate()` function
- Remove KYC Level column from DataTable
- Remove KYC dropdown filter from toolbar

- [ ] **Step 2: Commit**

```bash
git add resources/js/pages/admin/users.tsx
git commit -m "chore: clean up admin users page — remove KYC filtering"
```

---

### Task 26: Update navigation sidebar

**Files:**
- Modify: `resources/js/layouts/user-layout.tsx`

- [ ] **Step 1: Update sidebar navigation**

Remove from Settings group:
- "Identity (KYC)" item
- "Responsible Gaming" item

Remove from Administration group:
- "KYC Review" item

Add to Administration group:
- `{ label: 'Wallets', icon: 'pi pi-wallet', href: '/admin/wallets' }`

- [ ] **Step 2: Commit**

```bash
git add resources/js/layouts/user-layout.tsx
git commit -m "chore: update sidebar — remove KYC/RG links, add Wallets"
```

---

### Task 27: Clean up audit log and platform users

**Files:**
- Modify: `resources/js/pages/settings/security/audit-log.tsx`
- Modify: `resources/js/pages/platform/users/index.tsx`

- [ ] **Step 1: Update audit log**

Remove event type handling for `new_device`, `new_location`, `phone_changed` from the icon/color mapping functions.

- [ ] **Step 2: Update platform users page**

Remove KYC level breakdown from stats and KYC display from user rows (if present).

- [ ] **Step 3: Update TypeScript types**

In `resources/js/types/` — remove `kyc_level`, `kyc_verified_at`, `phone_verified_at` from User type. Remove unused interfaces for deleted features.

- [ ] **Step 4: Commit**

```bash
git add resources/js/
git commit -m "chore: clean up audit log, platform users, and TypeScript types"
```

---

### Task 28: Create wallet management admin page

**Files:**
- Create: `resources/js/pages/admin/wallets.tsx`
- Modify: `routes/web.php` or `routes/admin.php` (add Inertia page route)
- Modify: `app/Http/Controllers/Admin/DashboardController.php` (add wallets page render)

- [ ] **Step 1: Add wallets page route**

In the admin web routes (where other admin Inertia pages are rendered), add:

```php
Route::get('wallets', [DashboardController::class, 'wallets'])->name('admin.wallets');
```

- [ ] **Step 2: Add controller method**

In `DashboardController.php`, add:

```php
public function wallets()
{
    return Inertia::render('admin/wallets');
}
```

- [ ] **Step 3: Create the wallets page**

Create `resources/js/pages/admin/wallets.tsx` with:
- Stats cards: Total Wallets, Total Balance, Active, Frozen
- Search bar and status filter
- DataTable with columns: User, Balance, Currency, Status, Total Deposited, Total Withdrawn, Actions
- Deposit dialog (amount + reference inputs)
- Withdraw dialog (amount + reference inputs)
- Freeze/Activate action buttons
- API calls to `/api/v1/admin/wallets/*` endpoints

Follow the same patterns used in `admin/users.tsx` and `admin/voucher-codes.tsx` for DataTable, dialogs, and API calls.

- [ ] **Step 4: Verify page renders**

Navigate to `/admin/wallets` in browser. Expected: Page renders without errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/admin/wallets.tsx routes/ app/Http/Controllers/Admin/DashboardController.php
git commit -m "feat: add wallet management admin page"
```

---

### Task 29: Final verification

- [ ] **Step 1: Run full grep for removed feature references**

```bash
grep -r "KycDocument\|ResponsibleGambling\|SelfExclusion\|PhoneVerification\|LoginNotification\|SmsMfa\|DeviceDetection\|VoucherSession\|CheckKycLevel\|CheckSelfExclusion" app/ routes/ resources/js/ --include="*.php" --include="*.tsx" --include="*.ts" -l
```

Expected: No matches (or only intentional references like status enum values).

- [ ] **Step 2: Run migrations fresh**

```bash
php artisan migrate:fresh --seed
```

Expected: All migrations run, seeder completes.

- [ ] **Step 3: Run route list**

```bash
php artisan route:list --compact
```

Expected: No errors, all routes resolve. Game API routes present, old terminal player routes absent.

- [ ] **Step 4: Build frontend**

```bash
npm run build
```

Expected: No TypeScript/build errors.

- [ ] **Step 5: Run tests (if any exist)**

```bash
php artisan test
```

Expected: All tests pass (some may need updating for removed features).

- [ ] **Step 6: Final commit if any remaining fixes**

```bash
git add -A
git commit -m "chore: final cleanup and verification"
```
