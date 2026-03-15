<?php

namespace App\Services;

use App\Models\FormConfiguration;
use Illuminate\Support\Arr;

class FormConfigService
{
    public function getConfig(string $formName, ?int $userId = null): array
    {
        $system = FormConfiguration::forForm($formName)->system()->first();
        $user = $userId
            ? FormConfiguration::forForm($formName)->forUser($userId)->first()
            : null;

        $base = $this->getCodeDefault($formName);

        if ($system) {
            $base = $this->mergeFieldsets($base, $system->fieldsets ?? []);
            $base['grid_columns'] = $system->grid_columns ?? $base['grid_columns'] ?? [];
            $base['tab_order'] = $system->tab_order ?? $base['tab_order'] ?? [];
        }

        if ($user) {
            $base = $this->mergeFieldsets($base, $user->fieldsets ?? []);
            if ($user->grid_columns) $base['grid_columns'] = $user->grid_columns;
            if ($user->tab_order) $base['tab_order'] = $user->tab_order;
        }

        return $base;
    }

    public function saveSystemConfig(string $formName, array $data): FormConfiguration
    {
        return FormConfiguration::updateOrCreate(
            ['form_name' => $formName, 'scope' => 'system', 'user_id' => null],
            [
                'fieldsets'    => $data['fieldsets'] ?? [],
                'grid_columns' => $data['grid_columns'] ?? null,
                'tab_order'    => $data['tab_order'] ?? null,
            ]
        );
    }

    public function saveUserConfig(string $formName, int $userId, array $data): FormConfiguration
    {
        return FormConfiguration::updateOrCreate(
            ['form_name' => $formName, 'scope' => 'user', 'user_id' => $userId],
            [
                'fieldsets'    => $data['fieldsets'] ?? [],
                'grid_columns' => $data['grid_columns'] ?? null,
                'tab_order'    => $data['tab_order'] ?? null,
            ]
        );
    }

    public function resetUserConfig(string $formName, int $userId): void
    {
        FormConfiguration::forForm($formName)->forUser($userId)->delete();
    }

    private function getCodeDefault(string $formName): array
    {
        return ['fieldsets' => [], 'grid_columns' => [], 'tab_order' => []];
    }

    private function mergeFieldsets(array $base, array $overrideFieldsets): array
    {
        $baseFieldsets = collect($base['fieldsets'] ?? []);

        foreach ($overrideFieldsets as $override) {
            $index = $baseFieldsets->search(fn($fs) => ($fs['id'] ?? '') === ($override['id'] ?? ''));

            if ($index !== false) {
                $existing = $baseFieldsets[$index];
                $merged = array_merge($existing, Arr::except($override, ['fields']));
                if (isset($override['fields'])) {
                    $merged['fields'] = $this->mergeFields($existing['fields'] ?? [], $override['fields']);
                }
                $baseFieldsets[$index] = $merged;
            } else {
                $baseFieldsets->push($override);
            }
        }

        $base['fieldsets'] = $baseFieldsets->sortBy('order')->values()->toArray();
        return $base;
    }

    private function mergeFields(array $baseFields, array $overrideFields): array
    {
        $base = collect($baseFields)->keyBy('name');
        foreach ($overrideFields as $field) {
            $name = $field['name'] ?? null;
            if ($name && $base->has($name)) {
                $base[$name] = array_merge($base[$name], $field);
            } elseif ($name) {
                $base[$name] = $field;
            }
        }
        return $base->sortBy('order')->values()->toArray();
    }
}
