<?php

use App\Support\SettingsSchema;

function sampleSchema(): array
{
    return [
        'type' => 'object',
        'required' => ['min_bet_amount', 'house_edge'],
        'properties' => [
            'min_bet_amount' => ['type' => 'number', 'minimum' => 1, 'default' => 5, 'title' => 'Min bet', 'x-group' => 'game'],
            'betting_window_seconds' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 60, 'default' => 8, 'x-group' => 'game'],
            'house_edge' => ['type' => 'number', 'minimum' => 0, 'maximum' => 0.5, 'multipleOf' => 0.0001, 'default' => 0.04, 'x-group' => 'game'],
            'auto_cashout_enabled' => ['type' => 'boolean', 'default' => true, 'x-group' => 'game'],
            'geo_allowed_countries' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['NA'], 'x-group' => 'game'],
            'default_business_model' => ['type' => 'string', 'enum' => ['reseller', 'direct'], 'default' => 'reseller', 'x-group' => 'commercial', 'x-tenant-overridable' => false],
        ],
    ];
}

test('defaults come from each property default', function () {
    $schema = new SettingsSchema(sampleSchema());

    expect($schema->defaults())->toBe([
        'min_bet_amount' => 5,
        'betting_window_seconds' => 8,
        'house_edge' => 0.04,
        'auto_cashout_enabled' => true,
        'geo_allowed_countries' => ['NA'],
        'default_business_model' => 'reseller',
    ]);
});

test('global rules mark required keys required and the rest nullable, with type and bounds', function () {
    $rules = (new SettingsSchema(sampleSchema()))->rules();

    expect($rules['min_bet_amount'])->toBe(['required', 'numeric', 'min:1']);
    expect($rules['betting_window_seconds'])->toBe(['nullable', 'integer', 'min:5', 'max:60']);
    expect($rules['house_edge'])->toContain('required', 'numeric', 'min:0', 'max:0.5');
    expect($rules['auto_cashout_enabled'])->toBe(['nullable', 'boolean']);
    expect($rules['geo_allowed_countries'])->toBe(['nullable', 'array']);
    expect($rules['geo_allowed_countries.*'])->toBe(['string']);
    expect($rules['default_business_model'])->toBe(['nullable', 'string', 'in:reseller,direct']);
});

test('tenant override rules are all nullable and exclude non-overridable keys', function () {
    $rules = (new SettingsSchema(sampleSchema()))->rules(tenantOverride: true);

    expect($rules)->not->toHaveKey('default_business_model');
    expect($rules['min_bet_amount'])->toBe(['nullable', 'numeric', 'min:1']);
    expect($rules['house_edge'][0])->toBe('nullable');
});

test('keys are listed by group and overridable keys are separated', function () {
    $schema = new SettingsSchema(sampleSchema());

    expect($schema->keys())->toHaveCount(6);
    expect($schema->groups())->toBe(['game', 'commercial']);
    expect($schema->keysInGroup('commercial'))->toBe(['default_business_model']);
    expect($schema->overridableKeys())->not->toContain('default_business_model');
    expect($schema->overridableKeys())->toContain('min_bet_amount', 'auto_cashout_enabled');
});

test('coerce drops unknown keys and values of the wrong type, keeping valid ones', function () {
    $schema = new SettingsSchema(sampleSchema());

    $clean = $schema->coerce([
        'min_bet_amount' => '12.5',
        'betting_window_seconds' => 'ten',
        'auto_cashout_enabled' => 'yes',
        'geo_allowed_countries' => 'NA',
        'default_business_model' => 'reseller',
        'display_teams' => true,
        '0' => '{',
    ]);

    expect($clean)->toBe([
        'min_bet_amount' => 12.5,
        'default_business_model' => 'reseller',
    ]);
});

test('coerce accepts boolean-like and integer-like values', function () {
    $schema = new SettingsSchema(sampleSchema());

    expect($schema->coerce(['betting_window_seconds' => '12', 'auto_cashout_enabled' => 0]))
        ->toBe(['betting_window_seconds' => 12, 'auto_cashout_enabled' => false]);
});

test('assertValid rejects schemas outside the supported subset', function () {
    expect(fn () => SettingsSchema::assertValid(['type' => 'object']))->toThrow(InvalidArgumentException::class);
    expect(fn () => SettingsSchema::assertValid(['type' => 'object', 'properties' => ['x' => ['type' => 'object']]]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => SettingsSchema::assertValid(['type' => 'object', 'properties' => ['x' => ['type' => 'array', 'items' => ['type' => 'number']]]]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => SettingsSchema::assertValid(['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'x-group' => 'weird']]]))
        ->toThrow(InvalidArgumentException::class);

    SettingsSchema::assertValid(sampleSchema());
    expect(true)->toBeTrue();
});

test('an empty or missing schema yields no keys, no rules and no defaults', function () {
    $schema = new SettingsSchema(null);

    expect($schema->keys())->toBe([])
        ->and($schema->rules())->toBe([])
        ->and($schema->defaults())->toBe([]);
});
