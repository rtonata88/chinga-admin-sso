<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Kyc\KycService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KycController extends Controller
{
    public function __construct(
        protected KycService $kycService
    ) {}

    /**
     * Display KYC verification page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = $this->kycService->getStatus($user);
        $documents = $this->kycService->getDocuments($user);

        return Inertia::render('settings/kyc', [
            'kyc' => $status,
            'limits' => $this->kycService->getLimits($status['level']),
            'documents' => $documents->map(fn ($doc) => [
                'uuid' => $doc->uuid,
                'document_type' => $doc->document_type,
                'document_type_label' => $doc->document_type_label,
                'status' => $doc->status,
                'rejection_reason' => $doc->rejection_reason,
                'verified_at' => $doc->verified_at?->toIso8601String(),
                'document_expiry' => $doc->document_expiry?->toDateString(),
                'is_expired' => $doc->isExpired(),
                'is_expiring_soon' => $doc->isExpiringSoon(),
                'created_at' => $doc->created_at->toIso8601String(),
            ]),
            'document_types' => [
                ['value' => 'passport', 'label' => 'Passport'],
                ['value' => 'national_id', 'label' => 'National ID'],
                ['value' => 'drivers_license', 'label' => 'Driver\'s License'],
                ['value' => 'proof_of_address', 'label' => 'Proof of Address'],
                ['value' => 'selfie', 'label' => 'Selfie with ID'],
                ['value' => 'source_of_funds', 'label' => 'Source of Funds'],
            ],
        ]);
    }
}
