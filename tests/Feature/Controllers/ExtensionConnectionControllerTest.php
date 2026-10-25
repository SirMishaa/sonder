<?php

declare(strict_types=1);

use App\Models\User;

it('renders the extension connection page with the user\'s own tokens', function (): void {
    $user = User::factory()->create();
    $user->createToken('extension', ['extension']);
    User::factory()->create()->createToken('extension', ['extension']);

    $this->actingAs($user)
        ->get(route('extension-connection.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('extension-connection/Edit')
            ->has('tokens', 1)
            ->has('tokens.0', fn ($token) => $token
                ->has('id')
                ->has('createdAt')
                ->has('lastUsedAt')));
});

it('creates an extension token for the signed-in user', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('extension-connection.store'));

    $response->assertOk()->assertJsonStructure(['token', 'id']);

    $token = $user->tokens()->sole();
    expect($response->json('id'))->toBe((string) $token->id);
    expect($token->name)->toBe('extension')
        ->and($token->abilities)->toBe(['extension'])
        ->and($response->json('token'))->toBeString()->toContain('|');
});

it('revokes a token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('extension', ['extension'])->accessToken;

    $this->actingAs($user)
        ->delete(route('extension-connection.destroy', ['tokenId' => $token->id]))
        ->assertRedirect();

    expect($user->tokens()->count())->toBe(0);
});

it('cannot revoke another user\'s token', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $token = $other->createToken('extension', ['extension'])->accessToken;

    $this->actingAs($user)
        ->delete(route('extension-connection.destroy', ['tokenId' => $token->id]))
        ->assertNotFound();

    expect($other->tokens()->count())->toBe(1);
});

it('requires a signed-in user', function (): void {
    $this->get(route('extension-connection.edit'))->assertRedirectToRoute('login');
    $this->postJson(route('extension-connection.store'))->assertUnauthorized();
});
