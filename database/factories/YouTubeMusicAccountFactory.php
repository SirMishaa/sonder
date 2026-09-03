<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<YouTubeMusicAccount>
 */
final class YouTubeMusicAccountFactory extends Factory
{
    /**
     * A syntactically valid cookie. It authenticates nothing: tests must never
     * reach YouTube Music, they go through the fake client instead.
     */
    public static function cookie(): string
    {
        return sprintf(
            '__Secure-3PAPISID=%s; SAPISID=%s; SID=%s',
            Str::random(34),
            Str::random(34),
            Str::random(71),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'cookie' => self::cookie(),
            'account_name' => fake()->name(),
            'last_verified_at' => now(),
        ];
    }
}
