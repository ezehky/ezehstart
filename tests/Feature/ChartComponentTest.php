<?php

use App\Enums\UserRoleEnum;
use Illuminate\Support\Facades\Blade;

test('a chart serialises its rows into the alpine component', function () {
    $html = Blade::render(
        '<x-chart :value="$rows" gutter="8 8 28 40"><x-chart.svg><x-chart.bar field="total" /></x-chart.svg></x-chart>',
        ['rows' => [['monthShort' => 'Jan', 'total' => 4]]],
    );

    expect($html)
        ->toContain('chart({ data:')
        ->toContain('monthShort')
        ->toContain('renderBars')
        ->toContain('28');
});

test('a chart bound with wire:model entangles rather than embedding its rows', function () {
    $html = Blade::render('<x-chart wire:model="series"><x-chart.svg /></x-chart>');

    expect($html)
        ->toContain("\$wire.entangle('series')")
        ->not->toContain('wire:model');
});

test('an axis passes its name down to the parts nested inside it', function () {
    $html = Blade::render(<<<'BLADE'
        <x-chart :value="[]">
            <x-chart.svg>
                <x-chart.axis axis="x" field="monthShort">
                    <x-chart.axis.grid />
                    <x-chart.axis.tick />
                </x-chart.axis>
            </x-chart.svg>
        </x-chart>
    BLADE);

    expect($html)
        ->toContain("renderGrid('x')")
        ->toContain("renderTicks('x')")
        ->toContain('monthShort');
});

test('the admin dashboard renders the sign-up chart from real rows', function () {
    $admin = userWithRole(UserRoleEnum::ADMIN);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertSuccessful()
        ->assertSee('renderBars', escape: false)
        ->assertSee('Sign-ups');
});
