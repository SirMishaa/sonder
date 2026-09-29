<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('shows the design system to anyone', function (?bool $signedIn): void {
    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }

    $this->get(route('design-system'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('design-system/Index'));
})->with(['guest' => false, 'signed in' => true]);
