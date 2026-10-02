<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The platform's own company details as printed on invoices; one row. */
class CompanyProfile extends Model
{
    public const FIELDS = [
        'legal_name', 'trading_name', 'registration_number', 'vat_number',
        'address_line1', 'address_line2', 'city', 'country', 'email', 'phone',
        'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch_code',
        'payment_terms_days',
    ];

    protected $fillable = [...self::FIELDS, 'updated_by'];

    protected function casts(): array
    {
        return ['payment_terms_days' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->orderBy('id')->firstOrCreate([], ['payment_terms_days' => 14]);
    }

    /** The name to print: legal name, else trading name, else the platform default. */
    public function displayName(): string
    {
        return $this->legal_name ?: ($this->trading_name ?: 'Chinga Platform');
    }

    /** Address lines that are filled in, in print order. */
    public function addressLines(): array
    {
        return array_values(array_filter([
            $this->address_line1,
            $this->address_line2,
            trim(implode(' ', array_filter([$this->city]))),
            $this->country,
        ]));
    }

    public function hasBankDetails(): bool
    {
        return (bool) ($this->bank_account_number || $this->bank_name);
    }
}
