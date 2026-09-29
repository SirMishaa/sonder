<?php

declare(strict_types=1);

it('sends a guest opening the home page to the login screen', function (): void {
    $page = visit('/');

    $page->assertPathIs('/login')
        ->assertSee('Log in to your account')
        ->assertNoJavaScriptErrors();
});
