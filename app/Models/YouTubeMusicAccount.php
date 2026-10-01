<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Provider;
use App\Services\Music\Contracts\ProviderCredentials;
use App\Services\Music\ProviderRegistry;
use Carbon\CarbonInterface;
use Database\Factories\YouTubeMusicAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read string $id
 * @property-read string $user_id
 * @property-read string $cookie
 * @property-read string $account_name
 * @property-read CarbonInterface $last_verified_at
 * @property-read CarbonInterface|null $cookie_expired_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read User $user
 */
#[Hidden(['cookie'])]
#[Table(name: 'youtube_music_accounts')]
final class YouTubeMusicAccount extends Model
{
    /** @use HasFactory<YouTubeMusicAccountFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'user_id' => 'string',
            'cookie' => 'encrypted',
            'account_name' => 'string',
            'last_verified_at' => 'datetime',
            'cookie_expired_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Playlist, $this>
     */
    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class, 'youtube_music_account_id');
    }

    public function hasExpiredCookie(): bool
    {
        return $this->cookie_expired_at !== null;
    }

    /**
     * Records that YouTube Music just accepted the cookie.
     */
    public function markCookieWorking(): void
    {
        $this->forceFill([
            'last_verified_at' => now(),
            'cookie_expired_at' => null,
        ])->save();
    }

    /**
     * Records that YouTube Music refused the cookie, keeping the date of the
     * first refusal rather than the latest one.
     */
    public function markCookieExpired(): void
    {
        if ($this->hasExpiredCookie()) {
            return;
        }

        $this->forceFill(['cookie_expired_at' => now()])->save();
    }

    /**
     * The provider this (legacy, YouTube Music only) account belongs to.
     */
    public function provider(): Provider
    {
        return Provider::YouTubeMusic;
    }

    /**
     * The stored cookie as the provider's typed credentials.
     */
    public function credentials(): ProviderCredentials
    {
        return resolve(ProviderRegistry::class)->credentials($this->provider(), ['cookie' => $this->cookie]);
    }
}
