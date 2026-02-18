<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('instance settings are created when missing', function () {
    expect(InstanceSettings::query()->count())->toBe(0);

    $settings = InstanceSettings::get();

    expect($settings->id)->toBe(0);
    expect(InstanceSettings::query()->count())->toBe(1);
});
