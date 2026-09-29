<?php

declare(strict_types=1);

use App\Enums\Locale;
use App\Models\User;

it('saves the chosen language', function (): void {
    $user = User::factory()->create(['locale' => null]);

    $response = $this->actingAs($user)
        ->fromRoute('user-profile.edit')
        ->put(route('user-locale.update'), ['locale' => 'en_US']);

    $response->assertRedirectToRoute('user-profile.edit');

    expect($user->refresh()->locale)->toBe(Locale::English);
});

it('rejects a language Sonder is not translated into', function (): void {
    $user = User::factory()->create(['locale' => Locale::French]);

    $response = $this->actingAs($user)
        ->fromRoute('user-profile.edit')
        ->put(route('user-locale.update'), ['locale' => 'de_DE']);

    $response->assertRedirectToRoute('user-profile.edit')
        ->assertSessionHasErrors(['locale' => 'Le champ locale sélectionné est invalide.']);

    expect($user->refresh()->locale)->toBe(Locale::French);
});

it('redirects guests to the login page', function (): void {
    $this->put(route('user-locale.update'), ['locale' => 'en_US'])
        ->assertRedirectToRoute('login');
});

it('shares the language of the user with the pages', function (): void {
    $user = User::factory()->create(['locale' => Locale::French]);

    $this->actingAs($user)
        ->get(route('user-profile.edit'))
        ->assertInertia(fn ($page) => $page->where('locale', 'fr_BE'));
});
