<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Locale;
use App\Http\Requests\UpdateUserLocaleRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final readonly class UserLocaleController
{
    public function update(UpdateUserLocaleRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        $user->forceFill(['locale' => $request->enum('locale', Locale::class)])->save();

        return back();
    }
}
