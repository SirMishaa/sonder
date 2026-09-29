<?php

declare(strict_types=1);

it('shows guests the landing page, with a way in', function (): void {
    $page = visit('/');

    $page->assertSee('A listening room for the music you already love.')
        ->assertNoJavaScriptErrors()
        ->click('Log in')
        ->assertPathIs('/login');
});

it('lets visitors sort the showcase suggestions from the keyboard', function (): void {
    $page = visit('/');

    $page->click('Dissolved Girl')
        ->keys('[role=group]', 'a')
        ->assertSee('added to Late focus')
        ->assertNoJavaScriptErrors();
});
