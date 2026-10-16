<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\TreasurySetting;
use App\Services\TreasuryService;
use App\Support\Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Player liability and distributable profit for platform admins; see TreasuryService. */
class TreasuryController extends Controller
{
    public function __construct(protected TreasuryService $treasury) {}

    public function index(): Response
    {
        return Inertia::render('platform/treasury', $this->treasury->position());
    }

    public function update(Request $request): RedirectResponse
    {
        // Inputs are per currency: each currency's bank account, reserve and tax rate.
        $data = $request->validate([
            'currency' => ['required', Rule::in(Currencies::codes())],
            'bank_balance' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'bank_balance_as_of' => ['nullable', 'date'],
            'variance_reserve' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'tax_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        TreasurySetting::current($data['currency'])->update($data + ['updated_by' => $request->user()->id]);

        return redirect()->route('platform.treasury')->with('success', "Treasury inputs saved ({$data['currency']}).");
    }
}
