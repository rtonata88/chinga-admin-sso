<?php

namespace App\Http\Controllers;

use App\Models\SavedFilter;
use App\Services\FormConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormConfigController extends Controller
{
    public function __construct(private FormConfigService $configService) {}

    public function show(string $formName): JsonResponse
    {
        return response()->json($this->configService->getConfig($formName, auth()->id()));
    }

    public function updateSystem(Request $request, string $formName): JsonResponse
    {
        $data = $request->validate([
            'fieldsets' => 'required|array',
            'grid_columns' => 'nullable|array',
            'tab_order' => 'nullable|array',
        ]);
        $config = $this->configService->saveSystemConfig($formName, $data);
        return response()->json(['message' => 'System configuration saved.', 'config' => $config]);
    }

    public function updateUser(Request $request, string $formName): JsonResponse
    {
        $data = $request->validate([
            'fieldsets' => 'nullable|array',
            'grid_columns' => 'nullable|array',
            'tab_order' => 'nullable|array',
        ]);
        $config = $this->configService->saveUserConfig($formName, auth()->id(), $data);
        return response()->json(['message' => 'Personal configuration saved.', 'config' => $config]);
    }

    public function resetUser(string $formName): JsonResponse
    {
        $this->configService->resetUserConfig($formName, auth()->id());
        return response()->json(['message' => 'Reset to defaults.']);
    }

    public function filters(string $filterableType): JsonResponse
    {
        $filters = SavedFilter::forGrid($filterableType)
            ->visibleTo(auth()->id())
            ->orderByDesc('is_favorite')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        return response()->json($filters);
    }

    public function storeFilter(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filterable_type' => 'required|string|max:100',
            'name' => 'required|string|max:100',
            'is_shared' => 'boolean',
            'is_favorite' => 'boolean',
            'is_default' => 'boolean',
            'criteria' => 'required|array',
            'sort_config' => 'nullable|array',
        ]);

        if ($data['is_default'] ?? false) {
            SavedFilter::where('user_id', auth()->id())
                ->where('filterable_type', $data['filterable_type'])
                ->update(['is_default' => false]);
        }

        $filter = SavedFilter::create([...$data, 'user_id' => auth()->id()]);
        return response()->json($filter, 201);
    }

    public function updateFilter(Request $request, SavedFilter $filter): JsonResponse
    {
        abort_unless($filter->user_id === auth()->id(), 403);
        $filter->update($request->validate([
            'name' => 'sometimes|string|max:100',
            'is_shared' => 'sometimes|boolean',
            'is_favorite' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'criteria' => 'sometimes|array',
            'sort_config' => 'nullable|array',
        ]));
        return response()->json($filter);
    }

    public function destroyFilter(SavedFilter $filter): JsonResponse
    {
        abort_unless($filter->user_id === auth()->id(), 403);
        $filter->delete();
        return response()->json(['message' => 'Filter deleted.']);
    }
}
