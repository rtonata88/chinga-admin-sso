<?php

namespace App\Support;

use App\Models\Game;
use InvalidArgumentException;

/**
 * The JSON Schema subset a game's settings form is rendered from and
 * validated against (PRD §4 P3). Stored on games.settings_schema.
 *
 * Supported: root `type: object` with `properties` whose `type` is
 * number | integer | boolean | string | array (of strings), keywords
 * minimum, maximum, multipleOf, enum, default, title, description, and a
 * root `required` list. Vendor keys: `x-group` ("game" | "commercial",
 * decides which panel a field sits in) and `x-tenant-overridable`
 * (default true; commercial keys are typically false).
 *
 * Deliberately not a JSON Schema validator: the schema translates straight
 * to Laravel validation rules, so no new dependency.
 */
class SettingsSchema
{
    public const TYPES = ['number', 'integer', 'boolean', 'string', 'array'];
    public const GROUPS = ['game', 'commercial'];
    public const DEFAULT_GROUP = 'game';

    /** @var array<string, array<string, mixed>> */
    private array $properties;

    /** @var list<string> */
    private array $required;

    public function __construct(?array $schema)
    {
        $this->properties = $schema['properties'] ?? [];
        $this->required = array_values($schema['required'] ?? []);
    }

    public static function fromGame(Game $game): self
    {
        return new self($game->settings_schema);
    }

    /**
     * @throws InvalidArgumentException when the schema is outside the subset
     */
    public static function assertValid(array $schema): void
    {
        if (($schema['type'] ?? 'object') !== 'object') {
            throw new InvalidArgumentException('settings_schema root type must be "object".');
        }
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties) || $properties === []) {
            throw new InvalidArgumentException('settings_schema must declare at least one property.');
        }
        foreach ($properties as $key => $property) {
            if (!is_string($key) || $key === '' || !is_array($property)) {
                throw new InvalidArgumentException('settings_schema property keys must be non-empty strings.');
            }
            $type = $property['type'] ?? null;
            if (!in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException("settings_schema property \"{$key}\": type must be one of ".implode(', ', self::TYPES).'.');
            }
            if ($type === 'array' && ($property['items']['type'] ?? null) !== 'string') {
                throw new InvalidArgumentException("settings_schema property \"{$key}\": arrays must declare items.type = \"string\".");
            }
            if (isset($property['x-group']) && !in_array($property['x-group'], self::GROUPS, true)) {
                throw new InvalidArgumentException("settings_schema property \"{$key}\": x-group must be one of ".implode(', ', self::GROUPS).'.');
            }
            if (isset($property['enum']) && (!is_array($property['enum']) || $property['enum'] === [])) {
                throw new InvalidArgumentException("settings_schema property \"{$key}\": enum must be a non-empty list.");
            }
            foreach (['minimum', 'maximum', 'multipleOf'] as $bound) {
                if (isset($property[$bound]) && !is_numeric($property[$bound])) {
                    throw new InvalidArgumentException("settings_schema property \"{$key}\": {$bound} must be numeric.");
                }
            }
        }
        foreach ($schema['required'] ?? [] as $key) {
            if (!isset($properties[$key])) {
                throw new InvalidArgumentException("settings_schema requires unknown property \"{$key}\".");
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function properties(): array
    {
        return $this->properties;
    }

    public function property(string $key): ?array
    {
        return $this->properties[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->properties);
    }

    /** Groups in order of first appearance. @return list<string> */
    public function groups(): array
    {
        $groups = [];
        foreach ($this->properties as $property) {
            $group = $property['x-group'] ?? self::DEFAULT_GROUP;
            if (!in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /** @return list<string> */
    public function keysInGroup(string $group): array
    {
        $keys = [];
        foreach ($this->properties as $key => $property) {
            if (($property['x-group'] ?? self::DEFAULT_GROUP) === $group) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function isOverridable(string $key): bool
    {
        return (bool) ($this->properties[$key]['x-tenant-overridable'] ?? true);
    }

    /** @return list<string> */
    public function overridableKeys(): array
    {
        return array_values(array_filter($this->keys(), fn (string $key) => $this->isOverridable($key)));
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->properties as $key => $property) {
            if (array_key_exists('default', $property)) {
                $defaults[$key] = $property['default'];
            }
        }

        return $defaults;
    }

    /**
     * Laravel validation rules. Global rules honour the schema's `required`
     * list; tenant-override rules are all nullable (an override is sparse)
     * and omit keys marked not overridable.
     *
     * @return array<string, list<string>>
     */
    public function rules(bool $tenantOverride = false): array
    {
        $rules = [];
        foreach ($this->properties as $key => $property) {
            if ($tenantOverride && !$this->isOverridable($key)) {
                continue;
            }
            $presence = (!$tenantOverride && in_array($key, $this->required, true)) ? 'required' : 'nullable';
            $set = [$presence];
            switch ($property['type']) {
                case 'number':
                    $set[] = 'numeric';
                    break;
                case 'integer':
                    $set[] = 'integer';
                    break;
                case 'boolean':
                    $set[] = 'boolean';
                    break;
                case 'string':
                    $set[] = 'string';
                    break;
                case 'array':
                    $set[] = 'array';
                    $rules["{$key}.*"] = isset($property['items']['enum'])
                        ? ['string', 'in:'.implode(',', $property['items']['enum'])]
                        : ['string'];
                    break;
            }
            if (isset($property['minimum'])) {
                $set[] = 'min:'.$property['minimum'];
            }
            if (isset($property['maximum'])) {
                $set[] = 'max:'.$property['maximum'];
            }
            if (isset($property['multipleOf']) && in_array($property['type'], ['number', 'integer'], true)) {
                $set[] = 'multiple_of:'.$property['multipleOf'];
            }
            if (isset($property['enum']) && $property['type'] !== 'array') {
                $set[] = 'in:'.implode(',', $property['enum']);
            }
            $rules[$key] = $set;
        }

        return $rules;
    }

    /**
     * Keep only known keys whose values fit their declared type, casting
     * numeric strings and boolean-like scalars. Used to clean data written
     * before the schema existed; anything that does not fit is dropped.
     *
     * @return array<string, mixed>
     */
    public function coerce(array $values): array
    {
        $clean = [];
        foreach ($this->properties as $key => $property) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $coerced = $this->coerceValue($values[$key], $property);
            if ($coerced !== null) {
                $clean[$key] = $coerced;
            }
        }

        return $clean;
    }

    private function coerceValue(mixed $value, array $property): mixed
    {
        switch ($property['type']) {
            case 'number':
                if (is_int($value) || is_float($value)) {
                    return $value;
                }
                if (is_string($value) && is_numeric($value)) {
                    return str_contains($value, '.') ? (float) $value : (int) $value;
                }

                return null;
            case 'integer':
                if (is_int($value)) {
                    return $value;
                }
                if ((is_float($value) && floor($value) === $value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
                    return (int) $value;
                }

                return null;
            case 'boolean':
                if (is_bool($value)) {
                    return $value;
                }
                if (in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    return in_array($value, [1, '1', 'true'], true);
                }

                return null;
            case 'string':
                if (!is_string($value)) {
                    return null;
                }
                if (isset($property['enum']) && !in_array($value, $property['enum'], true)) {
                    return null;
                }

                return $value;
            case 'array':
                if (!is_array($value)) {
                    return null;
                }
                foreach ($value as $item) {
                    if (!is_string($item)) {
                        return null;
                    }
                }

                return array_values($value);
        }

        return null;
    }
}
