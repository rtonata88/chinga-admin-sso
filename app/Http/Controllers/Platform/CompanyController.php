<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The platform's own company details, printed on every tenant invoice. */
class CompanyController extends Controller
{
    public function index(): Response
    {
        $c = CompanyProfile::current();

        return Inertia::render('platform/company', [
            'profile' => array_intersect_key($c->toArray(), array_flip(CompanyProfile::FIELDS)) + ['updated_at' => $c->updated_at?->toIso8601String()],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $str = ['nullable', 'string', 'max:160'];
        $data = $request->validate([
            'legal_name' => $str,
            'trading_name' => $str,
            'registration_number' => ['nullable', 'string', 'max:64'],
            'vat_number' => ['nullable', 'string', 'max:64'],
            'address_line1' => $str,
            'address_line2' => $str,
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'bank_name' => $str,
            'bank_account_name' => $str,
            'bank_account_number' => ['nullable', 'string', 'max:40'],
            'bank_branch_code' => ['nullable', 'string', 'max:20'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        CompanyProfile::current()->update($data + ['updated_by' => $request->user()->id]);

        return redirect()->route('platform.company')->with('success', 'Company details saved.');
    }
}
