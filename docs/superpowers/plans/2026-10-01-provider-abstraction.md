# Provider Abstraction (multi-provider plan 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put YouTube Music behind provider-agnostic contracts (enum, capability interfaces, DTOs, registry, translated exceptions) while the application keeps writing today's tables, so plan 2 can swap the schema without touching provider code.

**Architecture:** A `Provider` enum and a `ProviderRegistry` resolve adapters by enum case. Adapters implement small capability interfaces (`ProviderAdapter`, `ReadsPlaylists`) and exchange plain readonly DTOs (`Remote*`). The YouTube Music adapter is isolated in `App\Services\Music\YouTubeMusic` (gateway + rate-limit decorator + mapper + error translator). Existing actions, job and controllers are rewired onto the contracts and the `ProviderException` family; persistence is unchanged.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 5 (`./vendor/bin/pest`), PHPStan level 8, `ytmusicapi/ytmusicapi`, spatie/laravel-data (view models only).

**Spec:** `docs/superpowers/specs/2026-10-01-multi-provider-foundation-design.md` (this plan is "Delivery → 1").

## Global Constraints

- PHP files start with `declare(strict_types=1);`; classes are `final` (Pest strict arch preset forbids abstract classes, non-final classes and protected methods).
- Every class and public method gets a short docblock explaining purpose or why (not the obvious). Strongest PHPStan typing: `list<>`, array shapes, `class-string<>`, `@template` generics, `non-empty-string` where true.
- Run `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyze --level 8 <files>` on every modified PHP file before committing.
- Tests: `./vendor/bin/pest <path>` while iterating; `./vendor/bin/pest --parallel` for a task's final run.
- Commits: Conventional Commits, lowercase subject, no co-author trailer. Before every commit run `echo test | gpg --batch --pinentry-mode error --local-user $(git config user.signingkey) -s -o /dev/null; echo "exit=$?"`; non-zero → stop and ask the user to unlock the key.
- Nothing outside `app/Services/Music/YouTubeMusic` may reference that namespace or `Ytmusicapi` (Task 6 enforces it with an arch test). Actions depend on `App\Services\Music\Contracts` and `ProviderRegistry`, never on a concrete adapter.
- No new dependency. No schema change in this plan.

## Rulings against the spec (plan 1 only)

- **"Liked Music" stays in `playlists()` in plan 1.** The spec filters it out in favour of `ReadsFavorites`, but favourites have no table until plan 2; filtering now would flag the playlist removed. Plan 2 drops `LM` and adds `ReadsFavorites` together. Only `SE` ("Episodes for Later") is filtered now.
- **`ReadsFavorites` is not created in plan 1** (nothing consumes it yet).
- **`ProviderException` is an interface plus a trait, not an abstract class:** the strict arch preset forbids abstract and non-final classes. Each failure is a `final` class that `extends RuntimeException implements ProviderException`; callers catch the interface. The spec's `code()` is named `errorCode()` (`Exception::getCode()` already exists).
- **Fingerprints are computed on raw thumbnail URLs** (the spec stores raw URLs). The first sync after deploy re-downloads every playlist once (about 25 s for 17 playlists). The stored `thumbnail_url` columns keep the local proxy URL until plan 2.
- **The account lookup cache (`CachedClient`) is removed:** `account()` is always live (spec), and the display name is already stored on the account row.

## Review Focus

1. A sync failing with "service unavailable" (network blip) must **not** mark the cookie expired — only a refused session does. Pinned in Task 6 (`SyncYouTubeMusicLibraryTest`).
2. An empty raw library from YouTube Music means signed out and must raise `CredentialsRejected`, while a library holding only system playlists (after filtering) must still sync. Pinned in Task 4 (adapter tests).
3. A sync failure stored on the sync row must render in the viewer's locale and keep rendering legacy English messages already stored. Pinned in Task 6 (`YouTubeMusicSyncDataTest`).
4. Rate-limited calls must never be reported to the error log. Pinned in Task 1.
5. Credentials of one provider passed to another provider's adapter must fail loudly. Pinned in Task 4.

---

## File structure

Created:

```
app/Enums/Provider.php                                  provider identity (label)
app/Enums/SourceKind.php                                audio | video
app/Enums/ArtistRole.php                                main | featured
app/Enums/ProviderErrorCode.php                         normalised failure codes + translated messages
app/Exceptions/Providers/ProviderException.php     interface every provider failure implements
app/Exceptions/Providers/DescribesProviderFailure.php  trait: provider + userMessage()
app/Exceptions/Providers/CredentialsRejected.php
app/Exceptions/Providers/ProviderUnavailable.php
app/Exceptions/Providers/ProviderRateLimited.php
app/Exceptions/Providers/UnsupportedCapability.php
app/Services/Music/Contracts/ProviderCredentials.php
app/Services/Music/Contracts/ProviderAdapter.php
app/Services/Music/Contracts/ReadsPlaylists.php
app/Services/Music/Data/ProviderRef.php
app/Services/Music/Data/RemoteAccount.php
app/Services/Music/Data/RemoteArtist.php
app/Services/Music/Data/RemoteAlbum.php
app/Services/Music/Data/RemoteTrack.php
app/Services/Music/Data/RemotePlaylistSummary.php
app/Services/Music/Data/RemotePlaylist.php
app/Services/Music/ProviderRegistry.php
app/Services/Music/YouTubeMusic/YouTubeMusicCredentials.php
app/Services/Music/YouTubeMusic/Gateway/YouTubeMusicGateway.php
app/Services/Music/YouTubeMusic/Gateway/YtmusicapiGateway.php
app/Services/Music/YouTubeMusic/Gateway/RateLimitedGateway.php
app/Services/Music/YouTubeMusic/CookielessSession.php   (moved)
app/Services/Music/YouTubeMusic/YouTubeMusicErrorTranslator.php
app/Services/Music/YouTubeMusic/YouTubeMusicMapper.php
app/Services/Music/YouTubeMusic/YouTubeMusicAdapter.php
app/Services/Thumbnails/ThumbnailProxy.php              raw image URL → local proxy URL
tests/Support/FakeProviderAdapter.php
tests/Support/FakeYouTubeMusicGateway.php
tests/Unit/Services/Music/...                           one test file per unit
tests/Unit/Data/YouTubeMusicSyncDataTest.php            (extended if it exists)
```

Deleted at the end (Task 6): `app/Services/YouTubeMusic/*`, `app/Exceptions/YouTubeMusicException.php`, `app/Exceptions/YouTubeMusicRateLimitedException.php`, `app/Data/AccountData.php`, `tests/Support/FakeYouTubeMusicClient.php`, `tests/Unit/Services/YouTubeMusic/*` (ported).

Modified: `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `app/Models/YouTubeMusicAccount.php`, `app/Actions/{ConnectYouTubeMusicAccount,VerifyYouTubeMusicCookie,SyncPlaylistsFromYouTubeMusicAction,SyncPlaylistTracks,RefreshPlaylist}.php`, `app/Jobs/SyncYouTubeMusicLibrary.php`, `app/Console/Commands/VerifyYouTubeMusicCookiesCommand.php`, `app/Http/Controllers/{YouTubeMusicConnectionController,PlaylistRefreshController}.php`, `app/Data/{YouTubeMusicSyncData,PlaylistSummaryData}.php`, `lang/fr_BE.json`, `tests/TestCase.php`, `tests/Unit/ArchTest.php`, and the tests listed in Tasks 5–6.

---

### Task 1: Provider identity and normalised errors

**Files:**
- Create: `app/Enums/Provider.php`, `app/Enums/SourceKind.php`, `app/Enums/ArtistRole.php`, `app/Enums/ProviderErrorCode.php`
- Create: `app/Exceptions/Providers/{ProviderException,DescribesProviderFailure,CredentialsRejected,ProviderUnavailable,ProviderRateLimited,UnsupportedCapability}.php`
- Modify: `bootstrap/app.php` (dontReport), `lang/fr_BE.json`
- Test: `tests/Unit/Services/Music/ProviderExceptionTest.php`

**Interfaces:**
- Produces: `enum Provider: string { case YouTubeMusic = 'youtube_music'; public function label(): string }`; `enum SourceKind: string { Audio='audio'; Video='video' }`; `enum ArtistRole: string { Main='main'; Featured='featured' }`; `enum ProviderErrorCode: string { CredentialsRejected='credentials_rejected'; Unavailable='unavailable'; RateLimited='rate_limited'; public function userMessage(Provider $provider, array $context = []): string }`.
- Produces: `interface ProviderException extends Throwable { provider(): Provider; errorCode(): ProviderErrorCode; context(): array<string, int|string>; userMessage(): string }`; `new CredentialsRejected(Provider $provider, string $reason, ?Throwable $previous = null)`; `new ProviderUnavailable(Provider $provider, string $reason, ?Throwable $previous = null)`; `new ProviderRateLimited(Provider $provider, int $retryAfter)` with `public readonly int $retryAfter`; `new UnsupportedCapability(Provider $provider, string $capability)` (a `LogicException`, not a `ProviderException`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\Provider;
use App\Enums\ProviderErrorCode;
use App\Exceptions\Providers\CredentialsRejected;
use App\Exceptions\Providers\ProviderException;
use App\Exceptions\Providers\ProviderRateLimited;
use App\Exceptions\Providers\ProviderUnavailable;
use Illuminate\Support\Facades\Exceptions;

it('describes each failure with a code and a translated message naming the provider', function (ProviderException $exception, ProviderErrorCode $code, string $message): void {
    expect($exception->errorCode())->toBe($code)
        ->and($exception->provider())->toBe(Provider::YouTubeMusic)
        ->and($exception->userMessage())->toBe($message);
})->with([
    'credentials' => [
        fn () => new CredentialsRejected(Provider::YouTubeMusic, 'signed out'),
        ProviderErrorCode::CredentialsRejected,
        'YouTube Music refused these credentials. Make sure you are signed in, then connect the account again.',
    ],
    'unavailable' => [
        fn () => new ProviderUnavailable(Provider::YouTubeMusic, 'timeout'),
        ProviderErrorCode::Unavailable,
        'YouTube Music could not be reached. Try again in a few minutes.',
    ],
    'rate limited' => [
        fn () => new ProviderRateLimited(Provider::YouTubeMusic, 42),
        ProviderErrorCode::RateLimited,
        'Sonder is pacing its calls to YouTube Music. Try again in 42 seconds.',
    ],
]);

it('keeps the technical reason as the exception message', function (): void {
    $exception = new ProviderUnavailable(Provider::YouTubeMusic, 'HTTP 503 from youtubei/v1/browse');

    expect($exception->getMessage())->toBe('YouTube Music unavailable: HTTP 503 from youtubei/v1/browse');
});

it('translates the user message in the current locale', function (): void {
    app()->setLocale('fr_BE');

    expect((new ProviderRateLimited(Provider::YouTubeMusic, 42))->userMessage())
        ->toBe('Sonder espace ses appels à YouTube Music. Réessayez dans 42 secondes.');
});

it('never reports a rate limit', function (): void {
    Exceptions::fake();

    report(new ProviderRateLimited(Provider::YouTubeMusic, 42));
    report(new ProviderUnavailable(Provider::YouTubeMusic, 'timeout'));

    Exceptions::assertNotReported(ProviderRateLimited::class);
    Exceptions::assertReported(ProviderUnavailable::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Services/Music/ProviderExceptionTest.php`
Expected: FAIL, `Class "App\Enums\Provider" not found`.

- [ ] **Step 3: Write the enums**

`app/Enums/Provider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A music service Sonder can connect to. Carries identity only: the adapter
 * and credentials classes are associated in the ProviderRegistry, so this
 * enum never depends on a concrete integration.
 */
enum Provider: string
{
    case YouTubeMusic = 'youtube_music';

    /**
     * Brand name shown to the user; deliberately not translated.
     */
    public function label(): string
    {
        return match ($this) {
            self::YouTubeMusic => 'YouTube Music',
        };
    }
}
```

`app/Enums/SourceKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a playable source is: the bare recording, or a music video of it.
 */
enum SourceKind: string
{
    case Audio = 'audio';
    case Video = 'video';
}
```

`app/Enums/ArtistRole.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an artist takes part in a track.
 */
enum ArtistRole: string
{
    case Main = 'main';
    case Featured = 'featured';
}
```

`app/Enums/ProviderErrorCode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Provider failures, normalised across providers. Stored as is (for example
 * on a failed sync) and translated only when displayed, so the message
 * follows the viewer's locale rather than the queue worker's.
 */
enum ProviderErrorCode: string
{
    case CredentialsRejected = 'credentials_rejected';
    case Unavailable = 'unavailable';
    case RateLimited = 'rate_limited';

    /**
     * @param  array<string, int|string>  $context  Placeholders of the message (`seconds` for a rate limit).
     */
    public function userMessage(Provider $provider, array $context = []): string
    {
        $replace = ['provider' => $provider->label(), ...array_map(strval(...), $context)];

        return match ($this) {
            self::CredentialsRejected => __(':provider refused these credentials. Make sure you are signed in, then connect the account again.', $replace),
            self::Unavailable => __(':provider could not be reached. Try again in a few minutes.', $replace),
            self::RateLimited => __('Sonder is pacing its calls to :provider. Try again in :seconds seconds.', $replace),
        };
    }
}
```

- [ ] **Step 4: Write the exception contract, trait and failures**

`app/Exceptions/Providers/ProviderException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use App\Enums\Provider;
use App\Enums\ProviderErrorCode;
use Throwable;

/**
 * Every failure a provider adapter may raise. Domain code and controllers
 * catch this contract only, so they never depend on a provider's native
 * errors. `getMessage()` stays technical (logs, Grafana); `userMessage()` is
 * what a person reads.
 */
interface ProviderException extends Throwable
{
    public function provider(): Provider;

    public function errorCode(): ProviderErrorCode;

    /**
     * Placeholders for the user message.
     *
     * @return array<string, int|string>
     */
    public function context(): array;

    /**
     * Translated, in the current locale.
     */
    public function userMessage(): string;
}
```

`app/Exceptions/Providers/DescribesProviderFailure.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use App\Enums\Provider;

/**
 * Shared implementation of ProviderException for the concrete failures.
 *
 * @phpstan-require-implements ProviderException
 */
trait DescribesProviderFailure
{
    private Provider $failingProvider;

    public function provider(): Provider
    {
        return $this->failingProvider;
    }

    /**
     * @return array<string, int|string>
     */
    public function context(): array
    {
        return [];
    }

    public function userMessage(): string
    {
        return $this->errorCode()->userMessage($this->failingProvider, $this->context());
    }
}
```

`app/Exceptions/Providers/CredentialsRejected.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use App\Enums\Provider;
use App\Enums\ProviderErrorCode;
use RuntimeException;
use Throwable;

/**
 * The provider refused the session: expired cookie, revoked token, signed-out
 * response. The only failure that marks an account's credentials expired.
 */
final class CredentialsRejected extends RuntimeException implements ProviderException
{
    use DescribesProviderFailure;

    public function __construct(Provider $provider, string $reason, ?Throwable $previous = null)
    {
        $this->failingProvider = $provider;

        parent::__construct("{$provider->label()} rejected the credentials: {$reason}", previous: $previous);
    }

    public function errorCode(): ProviderErrorCode
    {
        return ProviderErrorCode::CredentialsRejected;
    }
}
```

`app/Exceptions/Providers/ProviderUnavailable.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use App\Enums\Provider;
use App\Enums\ProviderErrorCode;
use RuntimeException;
use Throwable;

/**
 * The provider could not be reached or answered something unreadable.
 * Transient by nature: it never marks credentials expired.
 */
final class ProviderUnavailable extends RuntimeException implements ProviderException
{
    use DescribesProviderFailure;

    public function __construct(Provider $provider, string $reason, ?Throwable $previous = null)
    {
        $this->failingProvider = $provider;

        parent::__construct("{$provider->label()} unavailable: {$reason}", previous: $previous);
    }

    public function errorCode(): ProviderErrorCode
    {
        return ProviderErrorCode::Unavailable;
    }
}
```

`app/Exceptions/Providers/ProviderRateLimited.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use App\Enums\Provider;
use App\Enums\ProviderErrorCode;
use RuntimeException;

/**
 * Sonder's own call budget for the provider is spent. Expected and frequent
 * under load, so it is excluded from error reporting (bootstrap/app.php).
 */
final class ProviderRateLimited extends RuntimeException implements ProviderException
{
    use DescribesProviderFailure;

    public function __construct(Provider $provider, public readonly int $retryAfter)
    {
        $this->failingProvider = $provider;

        parent::__construct("Too many calls to {$provider->label()}; the next one is allowed in {$retryAfter} seconds.");
    }

    public function errorCode(): ProviderErrorCode
    {
        return ProviderErrorCode::RateLimited;
    }

    /**
     * @return array{seconds: int}
     */
    public function context(): array
    {
        return ['seconds' => $this->retryAfter];
    }
}
```

`app/Exceptions/Providers/UnsupportedCapability.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use App\Enums\Provider;
use LogicException;

/**
 * Code required a capability the provider's adapter does not implement.
 * A programming error, not a provider failure.
 */
final class UnsupportedCapability extends LogicException
{
    public function __construct(Provider $provider, string $capability)
    {
        parent::__construct("{$provider->label()} does not support {$capability}.");
    }
}
```

- [ ] **Step 5: Stop reporting rate limits**

In `bootstrap/app.php`, replace the `withExceptions` body:

```php
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sonder's own call budget being spent is expected, not an incident.
        $exceptions->dontReport(App\Exceptions\Providers\ProviderRateLimited::class);
    })->create();
```

(Import the class at the top of the file if the file already uses imports; otherwise keep the fully qualified name.)

- [ ] **Step 6: Add the French translations**

Add to `lang/fr_BE.json` (keep the file's alphabetical ordering, case-insensitive):

```json
":provider could not be reached. Try again in a few minutes.": ":provider est injoignable. Réessayez dans quelques minutes.",
":provider refused these credentials. Make sure you are signed in, then connect the account again.": ":provider a refusé ces identifiants. Vérifiez que vous êtes connecté, puis reconnectez le compte.",
"Sonder is pacing its calls to :provider. Try again in :seconds seconds.": "Sonder espace ses appels à :provider. Réessayez dans :seconds secondes.",
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Services/Music/ProviderExceptionTest.php`
Expected: PASS (6 tests).

- [ ] **Step 8: Lint, analyse, full suite, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Enums app/Exceptions/Providers tests/Unit/Services/Music/ProviderExceptionTest.php
./vendor/bin/pest --parallel
git add app/Enums/Provider.php app/Enums/SourceKind.php app/Enums/ArtistRole.php app/Enums/ProviderErrorCode.php app/Exceptions/Providers bootstrap/app.php lang/fr_BE.json tests/Unit/Services/Music/ProviderExceptionTest.php
git commit -m "feat(providers): add the provider enum and normalised, translated failures"
```

Expected: PHPStan 0 errors; suite green (the strict arch preset accepts the trait and interface).

---

### Task 2: Exchange DTOs, contracts and the registry

**Files:**
- Create: `app/Services/Music/Data/{ProviderRef,RemoteAccount,RemoteArtist,RemoteAlbum,RemoteTrack,RemotePlaylistSummary,RemotePlaylist}.php`
- Create: `app/Services/Music/Contracts/{ProviderCredentials,ProviderAdapter,ReadsPlaylists}.php`
- Create: `app/Services/Music/ProviderRegistry.php`
- Test: `tests/Unit/Services/Music/ProviderRegistryTest.php`, `tests/Unit/Services/Music/RemotePlaylistSummaryTest.php`

**Interfaces:**
- Consumes: Task 1 enums and exceptions.
- Produces (exact signatures, all `final readonly` DTO classes with promoted public properties):
  - `ProviderRef(Provider $provider, non-empty-string $externalId)`
  - `RemoteAccount(ProviderRef $ref, string $displayName)`
  - `RemoteArtist(?ProviderRef $ref, string $name, ArtistRole $role)`
  - `RemoteAlbum(?ProviderRef $ref, string $title, ?int $year)`
  - `RemoteTrack(?ProviderRef $ref, string $title, list<RemoteArtist> $artists, ?RemoteAlbum $album, ?int $durationSeconds, ?string $isrc, SourceKind $kind, bool $isExplicit, bool $isAvailable, ?string $thumbnailUrl)` + `artistNames(): string`
  - `RemotePlaylistSummary(ProviderRef $ref, string $title, ?string $description, ?int $trackCount, ?string $thumbnailUrl, ?string $author)` + `fingerprint(): string`
  - `RemotePlaylist(RemotePlaylistSummary $summary, list<RemoteTrack> $tracks)`
  - `interface ProviderCredentials { provider(): Provider; toArray(): array<string, string>; static fromArray(array<string, string> $values): static }`
  - `interface ProviderAdapter { provider(): Provider; account(ProviderCredentials $credentials): RemoteAccount }`
  - `interface ReadsPlaylists { playlists(ProviderCredentials $credentials): list<RemotePlaylistSummary>; playlist(ProviderCredentials $credentials, string $externalId, ?int $trackCountHint = null): RemotePlaylist }`
  - `ProviderRegistry::register(Provider, class-string<ProviderAdapter>, class-string<ProviderCredentials>): self`, `adapter(Provider): ProviderAdapter`, `capability(Provider, class-string<T>): ?T`, `require(Provider, class-string<T>): T` (throws `UnsupportedCapability`), `credentials(Provider, array<string, string>): ProviderCredentials`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Services/Music/ProviderRegistryTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Provider;
use App\Services\Music\Contracts\ProviderAdapter;
use App\Services\Music\Contracts\ProviderCredentials;
use App\Services\Music\Contracts\ReadsPlaylists;
use App\Services\Music\Data\ProviderRef;
use App\Services\Music\Data\RemoteAccount;
use App\Exceptions\Providers\UnsupportedCapability;
use App\Services\Music\ProviderRegistry;

final class AccountOnlyAdapter implements ProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::YouTubeMusic;
    }

    public function account(ProviderCredentials $credentials): RemoteAccount
    {
        return new RemoteAccount(new ProviderRef(Provider::YouTubeMusic, 'UC1'), 'Listener');
    }
}

final readonly class TokenCredentials implements ProviderCredentials
{
    public function __construct(public string $token) {}

    public function provider(): Provider
    {
        return Provider::YouTubeMusic;
    }

    public function toArray(): array
    {
        return ['token' => $this->token];
    }

    public static function fromArray(array $values): static
    {
        return new self($values['token'] ?? '');
    }
}

function registry(): ProviderRegistry
{
    return (new ProviderRegistry(app()))->register(Provider::YouTubeMusic, AccountOnlyAdapter::class, TokenCredentials::class);
}

it('resolves the adapter registered for a provider', function (): void {
    expect(registry()->adapter(Provider::YouTubeMusic))->toBeInstanceOf(AccountOnlyAdapter::class);
});

it('returns null for a capability the adapter lacks', function (): void {
    expect(registry()->capability(Provider::YouTubeMusic, ReadsPlaylists::class))->toBeNull();
});

it('refuses to hand out a required capability the adapter lacks', function (): void {
    registry()->require(Provider::YouTubeMusic, ReadsPlaylists::class);
})->throws(UnsupportedCapability::class, 'YouTube Music does not support '.ReadsPlaylists::class.'.');

it('builds the provider credentials from stored values', function (): void {
    $credentials = registry()->credentials(Provider::YouTubeMusic, ['token' => 'abc']);

    expect($credentials)->toBeInstanceOf(TokenCredentials::class)
        ->and($credentials->toArray())->toBe(['token' => 'abc']);
});

it('fails loudly for a provider nobody registered', function (): void {
    (new ProviderRegistry(app()))->adapter(Provider::YouTubeMusic);
})->throws(LogicException::class, 'No adapter is registered for YouTube Music.');
```

`tests/Unit/Services/Music/RemotePlaylistSummaryTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Provider;
use App\Services\Music\Data\ProviderRef;
use App\Services\Music\Data\RemotePlaylistSummary;

function summary(?int $trackCount = 12, string $title = 'Deep Focus'): RemotePlaylistSummary
{
    return new RemotePlaylistSummary(new ProviderRef(Provider::YouTubeMusic, 'PL1'), $title, 'No lyrics', $trackCount, 'https://img.test/a.jpg', 'Mishaa');
}

it('keeps the same fingerprint while the listing is unchanged', function (): void {
    expect(summary()->fingerprint())->toBe(summary()->fingerprint());
});

it('changes the fingerprint when any listed detail changes', function (): void {
    expect(summary(trackCount: 13)->fingerprint())->not->toBe(summary()->fingerprint())
        ->and(summary(title: 'Late focus')->fingerprint())->not->toBe(summary()->fingerprint());
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Services/Music/ProviderRegistryTest.php tests/Unit/Services/Music/RemotePlaylistSummaryTest.php`
Expected: FAIL, `Class "App\Services\Music\ProviderRegistry" not found`.

- [ ] **Step 3: Write the DTOs**

`app/Services/Music/Data/ProviderRef.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

use App\Enums\Provider;

/**
 * Points at one object (track, playlist, artist, account…) inside a provider.
 */
final readonly class ProviderRef
{
    /**
     * @param  non-empty-string  $externalId  The provider's own identifier.
     */
    public function __construct(
        public Provider $provider,
        public string $externalId,
    ) {}
}
```

`app/Services/Music/Data/RemoteAccount.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * The account the credentials belong to, as the provider describes it.
 */
final readonly class RemoteAccount
{
    public function __construct(
        public ProviderRef $ref,
        public string $displayName,
    ) {}
}
```

`app/Services/Music/Data/RemoteArtist.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

use App\Enums\ArtistRole;

/**
 * An artist credited on a remote track. The ref is null when the provider
 * only gives a name.
 */
final readonly class RemoteArtist
{
    public function __construct(
        public ?ProviderRef $ref,
        public string $name,
        public ArtistRole $role,
    ) {}
}
```

`app/Services/Music/Data/RemoteAlbum.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * The release a remote track belongs to, as far as the provider tells.
 */
final readonly class RemoteAlbum
{
    public function __construct(
        public ?ProviderRef $ref,
        public string $title,
        public ?int $year,
    ) {}
}
```

`app/Services/Music/Data/RemoteTrack.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

use App\Enums\SourceKind;

/**
 * One track as a provider lists it. The ref is null for an item the provider
 * still lists but can no longer play (removed or region-locked upload).
 */
final readonly class RemoteTrack
{
    /**
     * @param  list<RemoteArtist>  $artists  Credited artists, in the provider's order.
     * @param  string|null  $thumbnailUrl  Raw provider image URL, never a local proxy URL.
     */
    public function __construct(
        public ?ProviderRef $ref,
        public string $title,
        public array $artists,
        public ?RemoteAlbum $album,
        public ?int $durationSeconds,
        public ?string $isrc,
        public SourceKind $kind,
        public bool $isExplicit,
        public bool $isAvailable,
        public ?string $thumbnailUrl,
    ) {}

    /**
     * The credited artists as one display string ("A, B").
     */
    public function artistNames(): string
    {
        return implode(', ', array_map(fn (RemoteArtist $artist): string => $artist->name, $this->artists));
    }
}
```

`app/Services/Music/Data/RemotePlaylistSummary.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * A playlist as the library listing shows it, without its tracks.
 */
final readonly class RemotePlaylistSummary
{
    /**
     * @param  int|null  $trackCount  Null when the provider gave no reliable count.
     * @param  string|null  $thumbnailUrl  Raw provider image URL.
     */
    public function __construct(
        public ProviderRef $ref,
        public string $title,
        public ?string $description,
        public ?int $trackCount,
        public ?string $thumbnailUrl,
        public ?string $author,
    ) {}

    /**
     * Identifies this version of the listing. Providers rarely expose a
     * modification date, so a change in any listed detail is the cheap
     * signal that the tracks may have changed; edits that leave every detail
     * intact are what the manual refresh is for.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->title,
            $this->description,
            $this->trackCount,
            $this->thumbnailUrl,
            $this->author,
        ], JSON_THROW_ON_ERROR));
    }
}
```

`app/Services/Music/Data/RemotePlaylist.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * A playlist with its tracks, in the provider's order.
 */
final readonly class RemotePlaylist
{
    /**
     * @param  list<RemoteTrack>  $tracks
     */
    public function __construct(
        public RemotePlaylistSummary $summary,
        public array $tracks,
    ) {}
}
```

- [ ] **Step 4: Write the contracts**

`app/Services/Music/Contracts/ProviderCredentials.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\Contracts;

use App\Enums\Provider;

/**
 * Whatever a provider needs to act for an account (a cookie, OAuth tokens…).
 * Stored encrypted as the array form.
 */
interface ProviderCredentials
{
    public function provider(): Provider;

    /**
     * @return array<string, string>
     */
    public function toArray(): array;

    /**
     * @param  array<string, string>  $values
     */
    public static function fromArray(array $values): static;
}
```

`app/Services/Music/Contracts/ProviderAdapter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\Contracts;

use App\Enums\Provider;
use App\Services\Music\Data\RemoteAccount;
use App\Exceptions\Providers\ProviderException;

/**
 * The minimum every provider integration offers. Further abilities are
 * separate capability interfaces (ReadsPlaylists, …) an adapter implements
 * when the provider supports them.
 *
 * Implementations must stay stateless: they take credentials per call, so a
 * long-lived Octane worker never carries one account's secrets into another
 * request.
 */
interface ProviderAdapter
{
    public function provider(): Provider;

    /**
     * Verifies the credentials with a live call and describes the account.
     *
     * @throws ProviderException
     */
    public function account(ProviderCredentials $credentials): RemoteAccount;
}
```

`app/Services/Music/Contracts/ReadsPlaylists.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\Contracts;

use App\Services\Music\Data\RemotePlaylist;
use App\Services\Music\Data\RemotePlaylistSummary;
use App\Exceptions\Providers\ProviderException;

/**
 * Capability: list the account's playlists and read one with its tracks.
 * System playlists that are not music are left out by the adapter.
 */
interface ReadsPlaylists
{
    /**
     * @return list<RemotePlaylistSummary>
     *
     * @throws ProviderException
     */
    public function playlists(ProviderCredentials $credentials): array;

    /**
     * @param  int|null  $trackCountHint  Count from the listing, used when the playlist itself reports none.
     *
     * @throws ProviderException
     */
    public function playlist(ProviderCredentials $credentials, string $externalId, ?int $trackCountHint = null): RemotePlaylist;
}
```

- [ ] **Step 5: Write the registry**

`app/Services/Music/ProviderRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music;

use App\Enums\Provider;
use App\Services\Music\Contracts\ProviderAdapter;
use App\Services\Music\Contracts\ProviderCredentials;
use App\Exceptions\Providers\UnsupportedCapability;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Knows, for each provider, which adapter and credentials classes implement
 * it. Holds class names only and resolves adapters through the container on
 * every call, so it is safe as a singleton under Octane.
 */
final class ProviderRegistry
{
    /** @var array<string, array{adapter: class-string<ProviderAdapter>, credentials: class-string<ProviderCredentials>}> */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<ProviderAdapter>  $adapter
     * @param  class-string<ProviderCredentials>  $credentials
     */
    public function register(Provider $provider, string $adapter, string $credentials): self
    {
        $this->providers[$provider->value] = ['adapter' => $adapter, 'credentials' => $credentials];

        return $this;
    }

    public function adapter(Provider $provider): ProviderAdapter
    {
        return $this->container->make($this->entry($provider)['adapter']);
    }

    /**
     * The adapter as the given capability, or null when the provider lacks it.
     *
     * @template TCapability of object
     *
     * @param  class-string<TCapability>  $capability
     * @return TCapability|null
     */
    public function capability(Provider $provider, string $capability): ?object
    {
        $adapter = $this->adapter($provider);

        return $adapter instanceof $capability ? $adapter : null;
    }

    /**
     * The adapter as the given capability, for code that cannot work without it.
     *
     * @template TCapability of object
     *
     * @param  class-string<TCapability>  $capability
     * @return TCapability
     *
     * @throws UnsupportedCapability
     */
    public function require(Provider $provider, string $capability): object
    {
        return $this->capability($provider, $capability)
            ?? throw new UnsupportedCapability($provider, $capability);
    }

    /**
     * Rebuilds a provider's typed credentials from their stored array form.
     *
     * @param  array<string, string>  $values
     */
    public function credentials(Provider $provider, array $values): ProviderCredentials
    {
        return $this->entry($provider)['credentials']::fromArray($values);
    }

    /**
     * @return array{adapter: class-string<ProviderAdapter>, credentials: class-string<ProviderCredentials>}
     */
    private function entry(Provider $provider): array
    {
        return $this->providers[$provider->value]
            ?? throw new LogicException("No adapter is registered for {$provider->label()}.");
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Services/Music/ProviderRegistryTest.php tests/Unit/Services/Music/RemotePlaylistSummaryTest.php`
Expected: PASS (7 tests).

- [ ] **Step 7: Lint, analyse, full suite, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Services/Music tests/Unit/Services/Music
./vendor/bin/pest --parallel
git add app/Services/Music tests/Unit/Services/Music
git commit -m "feat(providers): add exchange data, capability contracts and the provider registry"
```

Expected: PHPStan 0 errors (it must infer `capability()`/`require()` return types from the template); suite green. The test-local classes `AccountOnlyAdapter` / `TokenCredentials` are fine under the arch preset because `tests/` is not an app namespace.

---

### Task 3: YouTube Music gateway, error translation and call budget

**Files:**
- Create: `app/Services/Music/YouTubeMusic/Gateway/YouTubeMusicGateway.php`, `Gateway/YtmusicapiGateway.php`, `Gateway/RateLimitedGateway.php`, `YouTubeMusicErrorTranslator.php`
- Move: `app/Services/YouTubeMusic/CookielessSession.php` → `app/Services/Music/YouTubeMusic/CookielessSession.php` (namespace `App\Services\Music\YouTubeMusic`); `tests/Unit/Services/YouTubeMusic/CookielessSessionTest.php` → `tests/Unit/Services/Music/YouTubeMusic/CookielessSessionTest.php` (update the `use`)
- Create: `tests/Support/FakeYouTubeMusicGateway.php`
- Test: `tests/Unit/Services/Music/YouTubeMusic/YouTubeMusicErrorTranslatorTest.php`, `tests/Unit/Services/Music/YouTubeMusic/RateLimitedGatewayTest.php`

**Interfaces:**
- Consumes: Task 1 exceptions, `Provider`.
- Produces:
  - `interface YouTubeMusicGateway { account(string $cookie): object; library(string $cookie): list<object>; playlist(string $cookie, string $playlistId): object }` — raw ytmusicapi payloads; every failure already a `ProviderException`.
  - `YouTubeMusicErrorTranslator::translate(Throwable $failure): ProviderException`
  - `RateLimitedGateway(YouTubeMusicGateway $gateway, RateLimiter $limiter)` with `public const string LIMITER = 'youtube-music'`, throwing `ProviderRateLimited`.
  - `tests/Support/FakeYouTubeMusicGateway` with public `object $account`, `list<object> $library`, `array<string, object> $playlists`, `?ProviderException $failure`, `list<array{method: string, cookie: string, playlistId?: string}> $calls`, `callCount(string $method): int`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Services/Music/YouTubeMusic/YouTubeMusicErrorTranslatorTest.php`:

```php
<?php

declare(strict_types=1);

use App\Exceptions\Providers\CredentialsRejected;
use App\Exceptions\Providers\ProviderUnavailable;
use App\Services\Music\YouTubeMusic\YouTubeMusicErrorTranslator;
use Ytmusicapi\YTMusicServerError;
use Ytmusicapi\YTMusicUserError;

it('classifies ytmusicapi failures', function (Throwable $failure, string $expected): void {
    $translated = (new YouTubeMusicErrorTranslator())->translate($failure);

    expect($translated)->toBeInstanceOf($expected)
        ->and($translated->getPrevious())->toBe($failure);
})->with([
    'signed-out account menu' => [new Exception('Could not find account information.'), CredentialsRejected::class],
    'HTTP 401' => [new YTMusicServerError("Server returned HTTP 401: Unauthorized.\n"), CredentialsRejected::class],
    'HTTP 403' => [new YTMusicServerError("Server returned HTTP 403: Forbidden.\n"), CredentialsRejected::class],
    'unusable auth' => [new YTMusicUserError('Please provide authentication before using this function'), CredentialsRejected::class],
    'HTTP 503' => [new YTMusicServerError("Server returned HTTP 503: Unavailable.\n"), ProviderUnavailable::class],
    'transport' => [new WpOrg\Requests\Exception('cURL error 28: timed out', 'curlerror'), ProviderUnavailable::class],
    'anything else' => [new RuntimeException('Undefined property'), ProviderUnavailable::class],
]);
```

`tests/Unit/Services/Music/YouTubeMusic/RateLimitedGatewayTest.php` (port of `RateLimitedClientTest`):

```php
<?php

declare(strict_types=1);

use App\Exceptions\Providers\ProviderRateLimited;
use App\Services\Music\YouTubeMusic\Gateway\RateLimitedGateway;
use Illuminate\Cache\RateLimiter;
use Tests\Support\FakeYouTubeMusicGateway;

beforeEach(function (): void {
    $this->inner = new FakeYouTubeMusicGateway();
    $this->gateway = new RateLimitedGateway($this->inner, resolve(RateLimiter::class));
});

it('passes calls through while the minute budget lasts', function (): void {
    foreach (range(1, 30) as $call) {
        $this->gateway->library('cookie');
    }

    expect($this->inner->callCount('library'))->toBe(30);
});

it('refuses the call over the minute budget without reaching YouTube Music', function (): void {
    foreach (range(1, 30) as $call) {
        $this->gateway->library('cookie');
    }

    expect(fn () => $this->gateway->account('cookie'))->toThrow(ProviderRateLimited::class);
    expect($this->inner->callCount('account'))->toBe(0);
});

it('caps the calls of an hour even when each minute stays under budget', function (): void {
    foreach (range(1, 500) as $call) {
        $this->gateway->library('cookie');

        if ($call % 30 === 0) {
            $this->travel(61)->seconds();
        }
    }

    expect(fn () => $this->gateway->library('cookie'))->toThrow(ProviderRateLimited::class);
});

it('gives every account its own budget', function (): void {
    foreach (range(1, 30) as $call) {
        $this->gateway->library('cookie-a');
    }

    $this->gateway->library('cookie-b');

    expect($this->inner->callCount('library'))->toBe(31);
});
```

- [ ] **Step 2: Write the fake gateway (test support)**

`tests/Support/FakeYouTubeMusicGateway.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exceptions\Providers\ProviderException;
use App\Services\Music\YouTubeMusic\Gateway\YouTubeMusicGateway;
use RuntimeException;

/**
 * Raw-payload stand-in for ytmusicapi, for the adapter's own tests.
 */
final class FakeYouTubeMusicGateway implements YouTubeMusicGateway
{
    public object $account;

    /** @var list<object> */
    public array $library = [];

    /** @var array<string, object> */
    public array $playlists = [];

    public ?ProviderException $failure = null;

    /** @var list<array{method: string, cookie: string, playlistId?: string}> */
    public array $calls = [];

    public function __construct()
    {
        $this->account = (object) ['name' => 'Mishaa', 'channelId' => 'UC123', 'is_premium' => true];
    }

    public function account(string $cookie): object
    {
        $this->record(['method' => 'account', 'cookie' => $cookie]);

        return $this->account;
    }

    public function library(string $cookie): array
    {
        $this->record(['method' => 'library', 'cookie' => $cookie]);

        return $this->library;
    }

    public function playlist(string $cookie, string $playlistId): object
    {
        $this->record(['method' => 'playlist', 'cookie' => $cookie, 'playlistId' => $playlistId]);

        return $this->playlists[$playlistId] ?? throw new RuntimeException("No raw playlist registered for [{$playlistId}].");
    }

    public function callCount(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call): bool => $call['method'] === $method));
    }

    /**
     * @param  array{method: string, cookie: string, playlistId?: string}  $call
     */
    private function record(array $call): void
    {
        $this->calls[] = $call;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Services/Music/YouTubeMusic`
Expected: FAIL, `Interface "App\Services\Music\YouTubeMusic\Gateway\YouTubeMusicGateway" not found`.

- [ ] **Step 4: Write the gateway contract**

`app/Services/Music/YouTubeMusic/Gateway/YouTubeMusicGateway.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic\Gateway;

use App\Exceptions\Providers\ProviderException;

/**
 * Raw calls to YouTube Music's private API. Returns the untyped payloads of
 * ytmusicapi (mapping is YouTubeMusicMapper's job) and raises only
 * ProviderException. Takes the cookie per call to stay stateless on Octane.
 */
interface YouTubeMusicGateway
{
    /**
     * @throws ProviderException
     */
    public function account(string $cookie): object;

    /**
     * Every playlist of the library, system ones included, unfiltered.
     *
     * @return list<object>
     *
     * @throws ProviderException
     */
    public function library(string $cookie): array;

    /**
     * @throws ProviderException
     */
    public function playlist(string $cookie, string $playlistId): object;
}
```

- [ ] **Step 5: Write the error translator**

`app/Services/Music/YouTubeMusic/YouTubeMusicErrorTranslator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic;

use App\Enums\Provider;
use App\Exceptions\Providers\CredentialsRejected;
use App\Exceptions\Providers\ProviderException;
use App\Exceptions\Providers\ProviderUnavailable;
use Illuminate\Support\Str;
use Throwable;
use Ytmusicapi\YTMusicServerError;
use Ytmusicapi\YTMusicUserError;

/**
 * Sorts ytmusicapi's failures into Sonder's normalised ones. The package
 * throws generic exceptions, so the split relies on what each one says:
 * only a refused session may mark a cookie expired, everything else is
 * treated as a transient outage.
 */
final readonly class YouTubeMusicErrorTranslator
{
    public function translate(Throwable $failure): ProviderException
    {
        if ($failure instanceof ProviderException) {
            return $failure;
        }

        if ($this->isRefusedSession($failure)) {
            return new CredentialsRejected(Provider::YouTubeMusic, $failure->getMessage(), $failure);
        }

        return new ProviderUnavailable(Provider::YouTubeMusic, $failure->getMessage(), $failure);
    }

    /**
     * A signed-out response (the account menu has no account header), an
     * HTTP 401/403, or credentials the package itself cannot use.
     */
    private function isRefusedSession(Throwable $failure): bool
    {
        return $failure instanceof YTMusicUserError
            || ($failure instanceof YTMusicServerError && Str::contains($failure->getMessage(), ['HTTP 401', 'HTTP 403']))
            || $failure->getMessage() === 'Could not find account information.';
    }
}
```

- [ ] **Step 6: Write the ytmusicapi gateway and move the session**

Move `CookielessSession.php` (only the namespace line changes: `namespace App\Services\Music\YouTubeMusic;`) and its test (only the `use` line changes).

`app/Services/Music/YouTubeMusic/Gateway/YtmusicapiGateway.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic\Gateway;

use App\Enums\Provider;
use App\Exceptions\Providers\ProviderUnavailable;
use App\Services\Music\YouTubeMusic\CookielessSession;
use App\Services\Music\YouTubeMusic\YouTubeMusicErrorTranslator;
use Throwable;
use Ytmusicapi\YTMusic;

/**
 * The only class that talks to YouTube Music, through the ytmusicapi
 * package. It cannot be covered by tests; everything around it can.
 */
final readonly class YtmusicapiGateway implements YouTubeMusicGateway
{
    public function __construct(private YouTubeMusicErrorTranslator $errors) {}

    public function account(string $cookie): object
    {
        return $this->object($this->call($cookie, fn (YTMusic $music): mixed => $music->get_account()));
    }

    public function library(string $cookie): array
    {
        $payload = $this->call($cookie, fn (YTMusic $music): mixed => $music->get_library_playlists(limit: 200));

        if (! is_array($payload)) {
            throw new ProviderUnavailable(Provider::YouTubeMusic, 'the library listing is not a list');
        }

        return array_values(array_filter($payload, is_object(...)));
    }

    public function playlist(string $cookie, string $playlistId): object
    {
        return $this->object($this->call($cookie, fn (YTMusic $music): mixed => $music->get_playlist($playlistId, limit: 500)));
    }

    /**
     * The package declares no return types; an unexpected shape means the
     * private API changed, which is an outage from Sonder's point of view.
     */
    private function object(mixed $payload): object
    {
        return is_object($payload)
            ? $payload
            : throw new ProviderUnavailable(Provider::YouTubeMusic, 'expected an object payload, got '.get_debug_type($payload));
    }

    /**
     * @param  callable(YTMusic): mixed  $callback
     */
    private function call(string $cookie, callable $callback): mixed
    {
        try {
            return $callback(new YTMusic($cookie, requests_session: CookielessSession::create()));
        } catch (Throwable $failure) {
            throw $this->errors->translate($failure);
        }
    }
}
```

- [ ] **Step 7: Write the rate-limited decorator**

`app/Services/Music/YouTubeMusic/Gateway/RateLimitedGateway.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic\Gateway;

use App\Enums\Provider;
use App\Exceptions\Providers\ProviderRateLimited;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Arr;
use LogicException;

/**
 * Caps the calls made to YouTube Music for each account against the
 * `youtube-music` limiter (see AppServiceProvider), so a bug or a burst of
 * syncs can never hammer the private API hard enough to get the account
 * blocked. Over budget, a call is refused at once rather than waited for:
 * sleeping would hold a queue or Octane worker for up to an hour.
 */
final readonly class RateLimitedGateway implements YouTubeMusicGateway
{
    public const string LIMITER = 'youtube-music';

    public function __construct(
        private YouTubeMusicGateway $gateway,
        private RateLimiter $limiter,
    ) {}

    public function account(string $cookie): object
    {
        $this->spendCall($cookie);

        return $this->gateway->account($cookie);
    }

    public function library(string $cookie): array
    {
        $this->spendCall($cookie);

        return $this->gateway->library($cookie);
    }

    public function playlist(string $cookie, string $playlistId): object
    {
        $this->spendCall($cookie);

        return $this->gateway->playlist($cookie, $playlistId);
    }

    /**
     * @throws ProviderRateLimited
     */
    private function spendCall(string $cookie): void
    {
        $limits = $this->limits(mb_substr(hash('sha256', $cookie), 0, 16));

        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                throw new ProviderRateLimited(Provider::YouTubeMusic, $this->limiter->availableIn($limit->key));
            }
        }

        foreach ($limits as $limit) {
            $this->limiter->hit($limit->key, $limit->decaySeconds);
        }
    }

    /**
     * @return list<Limit>
     */
    private function limits(string $account): array
    {
        $limiter = $this->limiter->limiter(self::LIMITER)
            ?? throw new LogicException('The ['.self::LIMITER.'] rate limiter is not defined.');

        return array_values(array_filter(
            Arr::wrap($limiter($account)),
            fn (mixed $limit): bool => $limit instanceof Limit,
        ));
    }
}
```

In `app/Providers/AppServiceProvider.php::boot()`, change `RateLimitedClient::LIMITER` to `RateLimitedGateway::LIMITER` and its import (same limiter name, same limits). Leave the `Client` binding in `register()` untouched for now (Task 6 removes it).

- [ ] **Step 8: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Services/Music/YouTubeMusic`
Expected: PASS (translator 7, rate limit 4, cookieless session 1). Delete the old `tests/Unit/Services/YouTubeMusic/CookielessSessionTest.php` (moved) and keep the old `app/Services/YouTubeMusic/CookielessSession.php` until Task 6 only if `YtmusicapiClient` still references it — it does, so update `YtmusicapiClient`'s import to the new namespace instead and delete the old file now.

- [ ] **Step 9: Lint, analyse, full suite, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Services/Music/YouTubeMusic app/Services/YouTubeMusic/YtmusicapiClient.php app/Providers/AppServiceProvider.php tests/Support/FakeYouTubeMusicGateway.php tests/Unit/Services/Music/YouTubeMusic
./vendor/bin/pest --parallel
git add -A app/Services/Music/YouTubeMusic app/Services/YouTubeMusic app/Providers/AppServiceProvider.php tests/Support/FakeYouTubeMusicGateway.php tests/Unit/Services/Music/YouTubeMusic tests/Unit/Services/YouTubeMusic
git commit -m "feat(youtube-music): add the gateway, its error translation and call budget"
```

---

### Task 4: YouTube Music mapper, credentials and adapter

**Files:**
- Create: `app/Services/Music/YouTubeMusic/{YouTubeMusicCredentials,YouTubeMusicMapper,YouTubeMusicAdapter}.php`
- Test: `tests/Unit/Services/Music/YouTubeMusic/YouTubeMusicMapperTest.php` (port of `PayloadMapperTest` + new cases), `tests/Unit/Services/Music/YouTubeMusic/YouTubeMusicAdapterTest.php`

**Interfaces:**
- Consumes: Task 2 DTOs/contracts, Task 3 gateway, Task 1 exceptions/enums.
- Produces:
  - `YouTubeMusicCredentials(string $cookie)` implements `ProviderCredentials`.
  - `YouTubeMusicMapper::account(object): RemoteAccount` (throws `CredentialsRejected` without channel id), `playlistSummary(object): ?RemotePlaylistSummary` (null without id), `playlist(object, string $externalId, ?int $trackCountHint): RemotePlaylist`, `track(object): ?RemoteTrack` (null for podcast episodes).
  - `YouTubeMusicAdapter(YouTubeMusicGateway, YouTubeMusicMapper)` implements `ProviderAdapter, ReadsPlaylists`; `public const array SYSTEM_PLAYLIST_IDS = ['SE']`.

- [ ] **Step 1: Write the failing mapper test**

`tests/Unit/Services/Music/YouTubeMusic/YouTubeMusicMapperTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\ArtistRole;
use App\Enums\Provider;
use App\Enums\SourceKind;
use App\Exceptions\Providers\CredentialsRejected;
use App\Services\Music\YouTubeMusic\YouTubeMusicMapper;

$mapper = fn (): YouTubeMusicMapper => new YouTubeMusicMapper();

it('maps a track', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'videoId' => 'xXp4GnC1Z3Q',
        'title' => 'The Mandalorian',
        'artists' => [(object) ['name' => 'Ludwig Göransson', 'id' => 'UCa'], (object) ['name' => 'Joseph Shirley']],
        'album' => (object) ['name' => 'Season 1', 'id' => 'MPREb1'],
        'duration_seconds' => 199,
        'isExplicit' => true,
        'isAvailable' => true,
        'videoType' => 'MUSIC_VIDEO_TYPE_ATV',
    ]);

    expect($track->ref?->externalId)->toBe('xXp4GnC1Z3Q')
        ->and($track->ref?->provider)->toBe(Provider::YouTubeMusic)
        ->and($track->title)->toBe('The Mandalorian')
        ->and($track->artistNames())->toBe('Ludwig Göransson, Joseph Shirley')
        ->and($track->artists[0]->ref?->externalId)->toBe('UCa')
        ->and($track->artists[1]->ref)->toBeNull()
        ->and($track->album?->title)->toBe('Season 1')
        ->and($track->album?->ref?->externalId)->toBe('MPREb1')
        ->and($track->durationSeconds)->toBe(199)
        ->and($track->kind)->toBe(SourceKind::Audio)
        ->and($track->isExplicit)->toBeTrue();
});

it('reads the source kind from the video type', function (?string $videoType, SourceKind $kind) use ($mapper): void {
    expect($mapper()->track((object) ['videoId' => 'v1', 'videoType' => $videoType])?->kind)->toBe($kind);
})->with([
    'audio track' => ['MUSIC_VIDEO_TYPE_ATV', SourceKind::Audio],
    'upload' => ['MUSIC_VIDEO_TYPE_PRIVATELY_OWNED_TRACK', SourceKind::Audio],
    'official video' => ['MUSIC_VIDEO_TYPE_OMV', SourceKind::Video],
    'user video' => ['MUSIC_VIDEO_TYPE_UGC', SourceKind::Video],
    'unknown' => [null, SourceKind::Audio],
]);

it('drops podcast episodes', function () use ($mapper): void {
    expect($mapper()->track((object) ['videoId' => 'e1', 'videoType' => 'MUSIC_VIDEO_TYPE_PODCAST_EPISODE']))->toBeNull();
});

it('marks artists named in a feat. clause as featured', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'videoId' => 'v1',
        'title' => 'Under Pressure (feat. David Bowie)',
        'artists' => [(object) ['name' => 'Queen'], (object) ['name' => 'David Bowie']],
    ]);

    expect(array_map(fn ($artist) => $artist->role, $track?->artists ?? []))
        ->toBe([ArtistRole::Main, ArtistRole::Featured]);
});

it('keeps a track YouTube Music can no longer play, without a ref', function () use ($mapper): void {
    $track = $mapper()->track((object) ['title' => 'Gone', 'isAvailable' => false]);

    expect($track?->ref)->toBeNull()
        ->and($track?->isAvailable)->toBeFalse();
});

it('falls back when a track carries nothing usable', function () use ($mapper): void {
    $track = $mapper()->track((object) []);

    expect($track?->title)->toBe('Untitled track')
        ->and($track?->artistNames())->toBe('Unknown artist')
        ->and($track?->album)->toBeNull()
        ->and($track?->durationSeconds)->toBeNull()
        ->and($track?->isExplicit)->toBeFalse()
        ->and($track?->isAvailable)->toBeTrue();
});

it('ignores artist entries that carry no name', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'artists' => [(object) ['name' => 'Hans Zimmer'], (object) ['name' => '  '], 'not an object'],
    ]);

    expect($track?->artistNames())->toBe('Hans Zimmer');
});

it('keeps the raw thumbnail URL, picking the smallest one wide enough', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'thumbnails' => [
            (object) ['url' => 'https://img.test/small.jpg', 'width' => 60],
            (object) ['url' => 'https://img.test/right.jpg', 'width' => 240],
            (object) ['url' => 'https://img.test/huge.jpg', 'width' => 1080],
        ],
    ]);

    expect($track?->thumbnailUrl)->toBe('https://img.test/right.jpg');
});

it('falls back to the largest thumbnail when none is wide enough', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'thumbnails' => [
            (object) ['url' => 'https://img.test/tiny.jpg', 'width' => 32],
            (object) ['url' => 'https://img.test/biggest.jpg', 'width' => 120],
        ],
    ]);

    expect($track?->thumbnailUrl)->toBe('https://img.test/biggest.jpg');
});

it('survives thumbnails that are missing or malformed', function () use ($mapper): void {
    expect($mapper()->track((object) ['thumbnails' => []])?->thumbnailUrl)->toBeNull()
        ->and($mapper()->track((object) ['thumbnails' => 'nope'])?->thumbnailUrl)->toBeNull()
        ->and($mapper()->track((object) ['thumbnails' => [(object) ['width' => 240]]])?->thumbnailUrl)->toBeNull();
});

it('treats a zero track count as unknown rather than empty', function () use ($mapper): void {
    expect($mapper()->playlistSummary((object) ['playlistId' => 'PL1', 'count' => 0])?->trackCount)->toBeNull();
});

it('reads a track count exposed as a numeric string', function () use ($mapper): void {
    expect($mapper()->playlistSummary((object) ['playlistId' => 'PL1', 'count' => '42'])?->trackCount)->toBe(42);
});

it('skips a listed playlist without an id', function () use ($mapper): void {
    expect($mapper()->playlistSummary((object) ['title' => 'Ghost']))->toBeNull();
});

it('reads an author given either as an object or as a list', function () use ($mapper): void {
    expect($mapper()->playlistSummary((object) ['playlistId' => 'PL1', 'author' => (object) ['name' => 'Mishaa']])?->author)->toBe('Mishaa')
        ->and($mapper()->playlistSummary((object) ['playlistId' => 'PL1', 'author' => [(object) ['name' => 'Mishaa']]])?->author)->toBe('Mishaa')
        ->and($mapper()->playlistSummary((object) ['playlistId' => 'PL1'])?->author)->toBeNull();
});

it('maps a playlist and its tracks, leaving podcast episodes out', function () use ($mapper): void {
    $playlist = $mapper()->playlist((object) [
        'title' => 'Deep Focus',
        'description' => 'No lyrics',
        'trackCount' => 2,
        'tracks' => [
            (object) ['videoId' => 'a', 'title' => 'One'],
            (object) ['videoId' => 'b', 'title' => 'Two'],
            (object) ['videoId' => 'e', 'title' => 'Episode', 'videoType' => 'MUSIC_VIDEO_TYPE_PODCAST_EPISODE'],
            'not an object',
        ],
    ], 'PL1');

    expect($playlist->summary->ref->externalId)->toBe('PL1')
        ->and($playlist->summary->title)->toBe('Deep Focus')
        ->and($playlist->summary->trackCount)->toBe(2)
        ->and($playlist->tracks)->toHaveCount(2);
});

it('counts the tracks it received when the playlist reports no count', function () use ($mapper): void {
    expect($mapper()->playlist((object) ['tracks' => [(object) ['title' => 'One']]], 'PL1')->summary->trackCount)->toBe(1);
});

it('prefers the listing count over counting the tracks', function () use ($mapper): void {
    expect($mapper()->playlist((object) ['tracks' => []], 'PL1', trackCountHint: 300)->summary->trackCount)->toBe(300);
});

it('maps an account', function () use ($mapper): void {
    $account = $mapper()->account((object) ['name' => 'Mishaa', 'channelId' => 'UC123']);

    expect($account->displayName)->toBe('Mishaa')
        ->and($account->ref->externalId)->toBe('UC123');
});

it('names an account it cannot read', function () use ($mapper): void {
    expect($mapper()->account((object) ['channelId' => 'UC123'])->displayName)->toBe('Unknown account');
});

it('refuses an account without a channel id', function () use ($mapper): void {
    $mapper()->account((object) ['name' => 'Mishaa']);
})->throws(CredentialsRejected::class);
```

- [ ] **Step 2: Write the failing adapter test**

`tests/Unit/Services/Music/YouTubeMusic/YouTubeMusicAdapterTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Provider;
use App\Services\Music\Contracts\ProviderCredentials;
use App\Exceptions\Providers\CredentialsRejected;
use App\Services\Music\YouTubeMusic\YouTubeMusicAdapter;
use App\Services\Music\YouTubeMusic\YouTubeMusicCredentials;
use App\Services\Music\YouTubeMusic\YouTubeMusicMapper;
use Tests\Support\FakeYouTubeMusicGateway;

beforeEach(function (): void {
    $this->gateway = new FakeYouTubeMusicGateway();
    $this->adapter = new YouTubeMusicAdapter($this->gateway, new YouTubeMusicMapper());
    $this->credentials = new YouTubeMusicCredentials('SID=abc');
});

it('verifies the account with a live call', function (): void {
    $account = $this->adapter->account($this->credentials);

    expect($account->ref->externalId)->toBe('UC123')
        ->and($this->gateway->calls[0])->toBe(['method' => 'account', 'cookie' => 'SID=abc']);
});

it('treats an empty raw library as a signed-out session', function (): void {
    $this->adapter->playlists($this->credentials);
})->throws(CredentialsRejected::class);

it('accepts a library holding only system playlists', function (): void {
    $this->gateway->library = [(object) ['playlistId' => 'SE', 'title' => 'Episodes for Later']];

    expect($this->adapter->playlists($this->credentials))->toBe([]);
});

it('lists the playlists, system ones left out and Liked Music kept', function (): void {
    $this->gateway->library = [
        (object) ['playlistId' => 'LM', 'title' => 'Liked Music'],
        (object) ['playlistId' => 'SE', 'title' => 'Episodes for Later'],
        (object) ['playlistId' => 'PL1', 'title' => 'Deep Focus'],
    ];

    $ids = array_map(fn ($summary) => $summary->ref->externalId, $this->adapter->playlists($this->credentials));

    expect($ids)->toBe(['LM', 'PL1']);
});

it('reads a playlist with the listing count as hint', function (): void {
    $this->gateway->playlists['PL1'] = (object) ['title' => 'Deep Focus', 'tracks' => []];

    expect($this->adapter->playlist($this->credentials, 'PL1', 7)->summary->trackCount)->toBe(7);
});

it('refuses credentials of another provider', function (): void {
    $foreign = new class implements ProviderCredentials
    {
        public function provider(): Provider
        {
            return Provider::YouTubeMusic;
        }

        public function toArray(): array
        {
            return [];
        }

        public static function fromArray(array $values): static
        {
            return new self();
        }
    };

    $this->adapter->account($foreign);
})->throws(LogicException::class);

it('never prints the cookie when dumped', function (): void {
    expect(print_r($this->credentials, true))->not->toContain('SID=abc');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Services/Music/YouTubeMusic/YouTubeMusicMapperTest.php tests/Unit/Services/Music/YouTubeMusic/YouTubeMusicAdapterTest.php`
Expected: FAIL, `Class "App\Services\Music\YouTubeMusic\YouTubeMusicMapper" not found`.

- [ ] **Step 4: Write the credentials**

`app/Services/Music/YouTubeMusic/YouTubeMusicCredentials.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic;

use App\Enums\Provider;
use App\Services\Music\Contracts\ProviderCredentials;
use SensitiveParameter;

/**
 * The browser `cookie` header of a signed-in YouTube Music session. OAuth is
 * refused by YouTube Music's API for this use, so the cookie is the only
 * credential that works.
 */
final readonly class YouTubeMusicCredentials implements ProviderCredentials
{
    public function __construct(#[SensitiveParameter] public string $cookie) {}

    public function provider(): Provider
    {
        return Provider::YouTubeMusic;
    }

    /**
     * @return array{cookie: string}
     */
    public function toArray(): array
    {
        return ['cookie' => $this->cookie];
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function fromArray(array $values): static
    {
        return new self($values['cookie'] ?? '');
    }

    /**
     * Keeps the cookie out of dumps and logs.
     *
     * @return array{cookie: string}
     */
    public function __debugInfo(): array
    {
        return ['cookie' => '[redacted]'];
    }
}
```

- [ ] **Step 5: Write the mapper**

`app/Services/Music/YouTubeMusic/YouTubeMusicMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic;

use App\Enums\ArtistRole;
use App\Enums\Provider;
use App\Enums\SourceKind;
use App\Services\Music\Data\ProviderRef;
use App\Services\Music\Data\RemoteAccount;
use App\Services\Music\Data\RemoteAlbum;
use App\Services\Music\Data\RemoteArtist;
use App\Services\Music\Data\RemotePlaylist;
use App\Services\Music\Data\RemotePlaylistSummary;
use App\Services\Music\Data\RemoteTrack;
use App\Exceptions\Providers\CredentialsRejected;

/**
 * Turns ytmusicapi's untyped payloads into Sonder's exchange data.
 *
 * These payloads come from a renderer tree Google reshapes without notice,
 * so every field is treated as optional: a missing one means YouTube changed
 * something, not that the caller did anything wrong.
 */
final readonly class YouTubeMusicMapper
{
    private const int THUMBNAIL_WIDTH = 240;

    /** Video types that are a recording rather than a video of it. */
    private const array AUDIO_TYPES = ['MUSIC_VIDEO_TYPE_ATV', 'MUSIC_VIDEO_TYPE_PRIVATELY_OWNED_TRACK'];

    private const string PODCAST_EPISODE = 'MUSIC_VIDEO_TYPE_PODCAST_EPISODE';

    /**
     * @throws CredentialsRejected Without a channel id there is no account to act for.
     */
    public function account(object $payload): RemoteAccount
    {
        $channelId = $this->string($payload, 'channelId')
            ?? throw new CredentialsRejected(Provider::YouTubeMusic, 'the account has no channel id');

        return new RemoteAccount(
            ref: new ProviderRef(Provider::YouTubeMusic, $channelId),
            displayName: $this->string($payload, 'name') ?? 'Unknown account',
        );
    }

    /**
     * Null for an entry YouTube Music lists without an id.
     */
    public function playlistSummary(object $payload): ?RemotePlaylistSummary
    {
        $id = $this->string($payload, 'playlistId');

        return $id === null ? null : $this->summary($payload, $id, $this->trackCount($payload));
    }

    public function playlist(object $payload, string $externalId, ?int $trackCountHint = null): RemotePlaylist
    {
        $tracks = [];

        foreach (is_array($payload->tracks ?? null) ? $payload->tracks : [] as $track) {
            if (is_object($track) && ($mapped = $this->track($track)) !== null) {
                $tracks[] = $mapped;
            }
        }

        $trackCount = $this->trackCount($payload) ?? $trackCountHint ?? count($tracks);

        return new RemotePlaylist($this->summary($payload, $externalId !== '' ? $externalId : 'unknown', $trackCount), $tracks);
    }

    /**
     * Null for podcast episodes, which are not music.
     */
    public function track(object $payload): ?RemoteTrack
    {
        $videoType = $this->string($payload, 'videoType');

        if ($videoType === self::PODCAST_EPISODE) {
            return null;
        }

        $title = $this->string($payload, 'title') ?? 'Untitled track';
        $videoId = $this->string($payload, 'videoId');
        $album = $payload->album ?? null;

        return new RemoteTrack(
            ref: $videoId === null ? null : new ProviderRef(Provider::YouTubeMusic, $videoId),
            title: $title,
            artists: $this->artists($payload, $title),
            album: is_object($album) && ($albumTitle = $this->string($album, 'name')) !== null
                ? new RemoteAlbum($this->ref($album, 'id'), $albumTitle, null)
                : null,
            durationSeconds: is_int($payload->duration_seconds ?? null) ? $payload->duration_seconds : null,
            isrc: null,
            kind: $videoType === null || in_array($videoType, self::AUDIO_TYPES, true) ? SourceKind::Audio : SourceKind::Video,
            isExplicit: (bool) ($payload->isExplicit ?? false),
            isAvailable: (bool) ($payload->isAvailable ?? true),
            thumbnailUrl: $this->thumbnail($payload),
        );
    }

    /**
     * @param  non-empty-string  $id
     */
    private function summary(object $payload, string $id, ?int $trackCount): RemotePlaylistSummary
    {
        return new RemotePlaylistSummary(
            ref: new ProviderRef(Provider::YouTubeMusic, $id),
            title: $this->string($payload, 'title') ?? 'Untitled playlist',
            description: $this->string($payload, 'description'),
            trackCount: $trackCount,
            thumbnailUrl: $this->thumbnail($payload),
            author: $this->author($payload),
        );
    }

    /**
     * Artists named in a "feat." / "ft." clause of the title are featured;
     * YouTube Music lists them alongside the main artists without telling.
     *
     * @return list<RemoteArtist>
     */
    private function artists(object $payload, string $title): array
    {
        $featuring = preg_match('/\b(?:feat\.?|ft\.?|featuring)\s+([^)\]]+)/iu', $title, $match) === 1
            ? mb_strtolower($match[1])
            : '';

        $artists = [];

        foreach (is_array($payload->artists ?? null) ? $payload->artists : [] as $artist) {
            if (! is_object($artist) || ($name = $this->string($artist, 'name')) === null) {
                continue;
            }

            $role = $featuring !== '' && str_contains($featuring, mb_strtolower($name)) ? ArtistRole::Featured : ArtistRole::Main;
            $artists[] = new RemoteArtist($this->ref($artist, 'id'), $name, $role);
        }

        return $artists === [] ? [new RemoteArtist(null, 'Unknown artist', ArtistRole::Main)] : $artists;
    }

    /**
     * The count is scraped out of a subtitle string and silently defaults to
     * zero, so zero means unknown rather than empty.
     */
    private function trackCount(object $payload): ?int
    {
        $count = $payload->trackCount ?? $payload->count ?? null;

        if (is_string($count) && is_numeric($count)) {
            $count = (int) $count;
        }

        return is_int($count) && $count > 0 ? $count : null;
    }

    private function author(object $payload): ?string
    {
        $author = $payload->author ?? null;

        if (is_object($author)) {
            return $this->string($author, 'name');
        }

        if (is_array($author) && isset($author[0]) && is_object($author[0])) {
            return $this->string($author[0], 'name');
        }

        return null;
    }

    /**
     * The smallest raw thumbnail still wide enough to render sharply, or the
     * largest one available.
     */
    private function thumbnail(object $payload): ?string
    {
        $candidates = array_values(array_filter(
            is_array($payload->thumbnails ?? null) ? $payload->thumbnails : [],
            fn (mixed $thumbnail): bool => is_object($thumbnail) && $this->string($thumbnail, 'url') !== null,
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (object $a, object $b): int => $this->width($a) <=> $this->width($b));

        foreach ($candidates as $candidate) {
            if ($this->width($candidate) >= self::THUMBNAIL_WIDTH) {
                return $this->string($candidate, 'url');
            }
        }

        return $this->string($candidates[count($candidates) - 1], 'url');
    }

    private function width(object $thumbnail): int
    {
        $width = $thumbnail->width ?? 0;

        return is_int($width) ? $width : 0;
    }

    private function ref(object $payload, string $key): ?ProviderRef
    {
        $id = $this->string($payload, $key);

        return $id === null ? null : new ProviderRef(Provider::YouTubeMusic, $id);
    }

    /**
     * @return non-empty-string|null
     */
    private function string(object $payload, string $key): ?string
    {
        $value = $payload->{$key} ?? null;

        return is_string($value) && mb_trim($value) !== '' ? $value : null;
    }
}
```

- [ ] **Step 6: Write the adapter**

`app/Services/Music/YouTubeMusic/YouTubeMusicAdapter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic;

use App\Enums\Provider;
use App\Services\Music\Contracts\ProviderAdapter;
use App\Services\Music\Contracts\ProviderCredentials;
use App\Services\Music\Contracts\ReadsPlaylists;
use App\Services\Music\Data\RemoteAccount;
use App\Services\Music\Data\RemotePlaylist;
use App\Exceptions\Providers\CredentialsRejected;
use App\Services\Music\YouTubeMusic\Gateway\YouTubeMusicGateway;
use LogicException;

/**
 * YouTube Music behind Sonder's provider contracts.
 */
final readonly class YouTubeMusicAdapter implements ProviderAdapter, ReadsPlaylists
{
    /**
     * Library entries that are not music. "Liked Music" (LM) stays listed
     * until favourites exist (multi-provider plan 2).
     */
    public const array SYSTEM_PLAYLIST_IDS = ['SE'];

    public function __construct(
        private YouTubeMusicGateway $gateway,
        private YouTubeMusicMapper $mapper,
    ) {}

    public function provider(): Provider
    {
        return Provider::YouTubeMusic;
    }

    public function account(ProviderCredentials $credentials): RemoteAccount
    {
        return $this->mapper->account($this->gateway->account($this->cookie($credentials)));
    }

    /**
     * A signed-in library always lists "Liked Music", so an empty raw listing
     * means YouTube Music served the session signed out. Checked before
     * filtering: a library of system playlists only is still a valid one.
     */
    public function playlists(ProviderCredentials $credentials): array
    {
        $library = $this->gateway->library($this->cookie($credentials));

        if ($library === []) {
            throw new CredentialsRejected(Provider::YouTubeMusic, 'an empty library means the session is signed out');
        }

        $summaries = [];

        foreach ($library as $entry) {
            $summary = $this->mapper->playlistSummary($entry);

            if ($summary !== null && ! in_array($summary->ref->externalId, self::SYSTEM_PLAYLIST_IDS, true)) {
                $summaries[] = $summary;
            }
        }

        return $summaries;
    }

    public function playlist(ProviderCredentials $credentials, string $externalId, ?int $trackCountHint = null): RemotePlaylist
    {
        return $this->mapper->playlist(
            $this->gateway->playlist($this->cookie($credentials), $externalId),
            $externalId,
            $trackCountHint,
        );
    }

    private function cookie(ProviderCredentials $credentials): string
    {
        if (! $credentials instanceof YouTubeMusicCredentials) {
            throw new LogicException('YouTube Music received credentials of another provider: '.$credentials::class);
        }

        return $credentials->cookie;
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Services/Music/YouTubeMusic`
Expected: PASS (mapper 24, adapter 7, plus Task 3's tests).

- [ ] **Step 8: Lint, analyse, full suite, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Services/Music tests/Unit/Services/Music
./vendor/bin/pest --parallel
git add app/Services/Music/YouTubeMusic tests/Unit/Services/Music/YouTubeMusic
git commit -m "feat(youtube-music): map payloads to provider data behind the adapter contracts"
```

---

### Task 5: Wiring, the fake adapter and the account flows

**Files:**
- Create: `app/Services/Thumbnails/ThumbnailProxy.php`, `tests/Support/FakeProviderAdapter.php`
- Modify: `app/Providers/AppServiceProvider.php`, `tests/TestCase.php`, `app/Models/YouTubeMusicAccount.php`, `app/Actions/ConnectYouTubeMusicAccount.php`, `app/Actions/VerifyYouTubeMusicCookie.php`, `app/Console/Commands/VerifyYouTubeMusicCookiesCommand.php`, `app/Http/Controllers/YouTubeMusicConnectionController.php`
- Test: `tests/Unit/Services/ThumbnailProxyTest.php`; update `tests/Unit/Actions/ConnectYouTubeMusicAccountTest.php`, `tests/Unit/Actions/VerifyYouTubeMusicCookieTest.php`, `tests/Feature/Commands/VerifyYouTubeMusicCookiesCommandTest.php`, `tests/Feature/Controllers/YouTubeMusicConnectionControllerTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–4.
- Produces:
  - `ThumbnailProxy::url(?string $originalUrl): ?string` (moves `PayloadMapper::localThumbnailUrl`).
  - `YouTubeMusicAccount::provider(): Provider` and `credentials(): ProviderCredentials`.
  - `tests/Support/FakeProviderAdapter` implementing `ProviderAdapter, ReadsPlaylists`, with public `?RemoteAccount $account`, `list<RemotePlaylistSummary> $playlists`, `array<string, RemotePlaylist> $tracks`, `bool $shouldFail` (→ `CredentialsRejected`), `bool $unavailable` (→ `ProviderUnavailable`), `?int $rateLimitedFor` (→ `ProviderRateLimited`), `list<array{method: string, credentials: array<string, string>, playlistId?: string, trackCount?: int|null}> $calls`, `callCount()`, and statics `anAccount(string $name = 'Test Listener')`, `aPlaylistSummary(string $id = 'PL_TEST', string $title = 'Deep Focus', ?int $trackCount = 12)`, `aPlaylist(string $id = 'PL_TEST', string $title = 'Deep Focus', int $trackCount = 1, ?array $tracks = null)`, `aTrack(string $title = 'Rendezvous', ?string $videoId = 'xXp4GnC1Z3Q')`.
  - `TestCase::fakeProvider(): FakeProviderAdapter`.

- [ ] **Step 1: Write the failing ThumbnailProxy test**

`tests/Unit/Services/ThumbnailProxyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Thumbnails\ThumbnailProxy;

it('points a raw image URL at the local thumbnail proxy', function (): void {
    expect(ThumbnailProxy::url('https://img.test/a.jpg'))
        ->toBe(route('thumbnail.show', ['hash' => hash('md5', 'https://img.test/a.jpg'), 'url' => 'https://img.test/a.jpg']));
});

it('leaves a missing image missing', function (): void {
    expect(ThumbnailProxy::url(null))->toBeNull();
});
```

Run: `./vendor/bin/pest tests/Unit/Services/ThumbnailProxyTest.php` → FAIL (class not found).

- [ ] **Step 2: Write ThumbnailProxy**

`app/Services/Thumbnails/ThumbnailProxy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Thumbnails;

/**
 * Serves provider images through Sonder's own thumbnail route, so the
 * browser never loads them from the provider directly.
 */
final class ThumbnailProxy
{
    public static function url(?string $originalUrl): ?string
    {
        if ($originalUrl === null) {
            return null;
        }

        return route('thumbnail.show', ['hash' => hash('md5', $originalUrl), 'url' => $originalUrl]);
    }
}
```

Run the test → PASS.

- [ ] **Step 3: Register the provider and bind the gateway**

In `app/Providers/AppServiceProvider.php::register()`, add (keep the legacy `Client` binding until Task 6):

```php
        $this->app->bind(YouTubeMusicGateway::class, fn (): YouTubeMusicGateway => new RateLimitedGateway(
            $this->app->make(YtmusicapiGateway::class),
            $this->app->make(CacheRateLimiter::class),
        ));

        $this->app->singleton(ProviderRegistry::class, fn (): ProviderRegistry => (new ProviderRegistry($this->app))
            ->register(Provider::YouTubeMusic, YouTubeMusicAdapter::class, YouTubeMusicCredentials::class));
```

with the matching imports (`App\Enums\Provider`, `App\Services\Music\ProviderRegistry`, `App\Services\Music\YouTubeMusic\{YouTubeMusicAdapter,YouTubeMusicCredentials}`, `App\Services\Music\YouTubeMusic\Gateway\{YouTubeMusicGateway,YtmusicapiGateway,RateLimitedGateway}`).

- [ ] **Step 4: Write the fake adapter**

`tests/Support/FakeProviderAdapter.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ArtistRole;
use App\Enums\Provider;
use App\Enums\SourceKind;
use App\Services\Music\Contracts\ProviderAdapter;
use App\Services\Music\Contracts\ProviderCredentials;
use App\Services\Music\Contracts\ReadsPlaylists;
use App\Services\Music\Data\ProviderRef;
use App\Services\Music\Data\RemoteAccount;
use App\Services\Music\Data\RemoteAlbum;
use App\Services\Music\Data\RemoteArtist;
use App\Services\Music\Data\RemotePlaylist;
use App\Services\Music\Data\RemotePlaylistSummary;
use App\Services\Music\Data\RemoteTrack;
use App\Exceptions\Providers\CredentialsRejected;
use App\Exceptions\Providers\ProviderRateLimited;
use App\Exceptions\Providers\ProviderUnavailable;
use RuntimeException;

/**
 * Stands in for every provider throughout the suite.
 *
 * Registered in TestCase for every test, so no test can reach a real service
 * by accident. That guard has to live here rather than rely on
 * `Http::preventStrayRequests()`: ytmusicapi ships its own HTTP layer.
 */
final class FakeProviderAdapter implements ProviderAdapter, ReadsPlaylists
{
    /** Throws CredentialsRejected on every call (an expired cookie). */
    public bool $shouldFail = false;

    /** Throws ProviderUnavailable on every call (an outage). */
    public bool $unavailable = false;

    /** Seconds until the next call is allowed; null when calls go through. */
    public ?int $rateLimitedFor = null;

    public ?RemoteAccount $account = null;

    /** @var list<RemotePlaylistSummary> */
    public array $playlists = [];

    /** @var array<string, RemotePlaylist> */
    public array $tracks = [];

    /** @var list<array{method: string, credentials: array<string, string>, playlistId?: string, trackCount?: int|null}> */
    public array $calls = [];

    public static function anAccount(string $name = 'Test Listener'): RemoteAccount
    {
        return new RemoteAccount(new ProviderRef(Provider::YouTubeMusic, 'UC0000000000000000000000'), $name);
    }

    public static function aPlaylistSummary(string $id = 'PL_TEST', string $title = 'Deep Focus', ?int $trackCount = 12): RemotePlaylistSummary
    {
        return new RemotePlaylistSummary(
            ref: new ProviderRef(Provider::YouTubeMusic, $id !== '' ? $id : 'PL_TEST'),
            title: $title,
            description: 'Instrumental tracks for long sessions',
            trackCount: $trackCount,
            thumbnailUrl: 'https://example.test/cover.jpg',
            author: 'Test Listener',
        );
    }

    /**
     * @param  list<RemoteTrack>|null  $tracks
     */
    public static function aPlaylist(string $id = 'PL_TEST', string $title = 'Deep Focus', int $trackCount = 1, ?array $tracks = null): RemotePlaylist
    {
        return new RemotePlaylist(self::aPlaylistSummary($id, $title, $trackCount), $tracks ?? [self::aTrack()]);
    }

    public static function aTrack(string $title = 'Rendezvous', ?string $videoId = 'xXp4GnC1Z3Q'): RemoteTrack
    {
        return new RemoteTrack(
            ref: $videoId === null || $videoId === '' ? null : new ProviderRef(Provider::YouTubeMusic, $videoId),
            title: $title,
            artists: [new RemoteArtist(null, 'Ludwig Göransson', ArtistRole::Main)],
            album: new RemoteAlbum(null, 'The Mandalorian', null),
            durationSeconds: 199,
            isrc: null,
            kind: SourceKind::Audio,
            isExplicit: false,
            isAvailable: true,
            thumbnailUrl: 'https://example.test/track.jpg',
        );
    }

    public function provider(): Provider
    {
        return Provider::YouTubeMusic;
    }

    public function account(ProviderCredentials $credentials): RemoteAccount
    {
        $this->record(['method' => 'account', 'credentials' => $credentials->toArray()]);

        return $this->account ?? self::anAccount();
    }

    public function playlists(ProviderCredentials $credentials): array
    {
        $this->record(['method' => 'playlists', 'credentials' => $credentials->toArray()]);

        return $this->playlists;
    }

    public function playlist(ProviderCredentials $credentials, string $externalId, ?int $trackCountHint = null): RemotePlaylist
    {
        $this->record(['method' => 'playlist', 'credentials' => $credentials->toArray(), 'playlistId' => $externalId, 'trackCount' => $trackCountHint]);

        return $this->tracks[$externalId] ?? throw new RuntimeException("The fake provider has no playlist registered for [{$externalId}].");
    }

    public function callCount(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call): bool => $call['method'] === $method));
    }

    /**
     * @param  array{method: string, credentials: array<string, string>, playlistId?: string, trackCount?: int|null}  $call
     */
    private function record(array $call): void
    {
        $this->calls[] = $call;

        if ($this->shouldFail) {
            throw new CredentialsRejected(Provider::YouTubeMusic, 'Faked refusal.');
        }

        if ($this->unavailable) {
            throw new ProviderUnavailable(Provider::YouTubeMusic, 'Faked outage.');
        }

        if ($this->rateLimitedFor !== null) {
            throw new ProviderRateLimited(Provider::YouTubeMusic, $this->rateLimitedFor);
        }
    }
}
```

In `tests/TestCase.php::setUp()`, after the existing `Client` binding (kept until Task 6), add:

```php
        $this->app->instance(FakeProviderAdapter::class, new FakeProviderAdapter());
        $this->app->make(ProviderRegistry::class)
            ->register(Provider::YouTubeMusic, FakeProviderAdapter::class, YouTubeMusicCredentials::class);
```

and the helper:

```php
    /**
     * The fake every provider resolves to in tests.
     */
    protected function fakeProvider(): FakeProviderAdapter
    {
        return $this->app->make(FakeProviderAdapter::class);
    }
```

- [ ] **Step 5: Give the account model its provider and credentials**

In `app/Models/YouTubeMusicAccount.php` add:

```php
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
```

- [ ] **Step 6: Update the account tests (they fail first)**

Apply in the four test files listed under **Files**:

| Old | New |
|---|---|
| `use Tests\Support\FakeYouTubeMusicClient;` | `use Tests\Support\FakeProviderAdapter;` |
| `FakeYouTubeMusicClient::` | `FakeProviderAdapter::` |
| `$this->fakeYouTubeMusic()` | `$this->fakeProvider()` |
| `$client->calls[0]['cookie']` | `$client->calls[0]['credentials']['cookie']` |
| `use App\Exceptions\YouTubeMusicException;` + `YouTubeMusicException::class` in `assertReported` | `use App\Exceptions\Providers\CredentialsRejected;` + `CredentialsRejected::class` |

Add to `tests/Unit/Actions/VerifyYouTubeMusicCookieTest.php`:

```php
it('leaves the cookie alone when YouTube Music cannot be reached', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeProvider()->unavailable = true;

    expect(fn () => resolve(VerifyYouTubeMusicCookie::class)->handle($account))
        ->toThrow(App\Exceptions\Providers\ProviderUnavailable::class);
    expect($account->refresh()->cookie_expired_at)->toBeNull();
});

it('checks the cookie with a live account call', function (): void {
    $account = YouTubeMusicAccount::factory()->create();

    resolve(VerifyYouTubeMusicCookie::class)->handle($account);

    expect($this->fakeProvider()->callCount('account'))->toBe(1)
        ->and($this->fakeProvider()->callCount('playlists'))->toBe(0);
});
```

Add to `tests/Feature/Commands/VerifyYouTubeMusicCookiesCommandTest.php`:

```php
it('keeps going when YouTube Music cannot be reached', function (): void {
    YouTubeMusicAccount::factory()->count(2)->create();
    $this->fakeProvider()->unavailable = true;

    $exitCode = Artisan::call('youtube-music:verify-cookies');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('0 expired, 2 unreachable');
});
```

Run: `./vendor/bin/pest tests/Unit/Actions/ConnectYouTubeMusicAccountTest.php tests/Unit/Actions/VerifyYouTubeMusicCookieTest.php tests/Feature/Commands/VerifyYouTubeMusicCookiesCommandTest.php tests/Feature/Controllers/YouTubeMusicConnectionControllerTest.php`
Expected: FAIL (actions still use the legacy client; new tests fail on behaviour).

- [ ] **Step 7: Rewire the account actions, command and controller**

`app/Actions/ConnectYouTubeMusicAccount.php` (body of the class):

```php
final readonly class ConnectYouTubeMusicAccount
{
    public function __construct(private ProviderRegistry $providers) {}

    /**
     * Verifies the cookie against YouTube Music before storing it. A cookie
     * that cannot read the account is worthless, and finding that out at
     * connection time is far kinder than failing on every later page.
     *
     * @throws ProviderException
     */
    public function handle(User $user, #[SensitiveParameter] string $cookie): YouTubeMusicAccount
    {
        $account = $this->providers->adapter(Provider::YouTubeMusic)
            ->account($this->providers->credentials(Provider::YouTubeMusic, ['cookie' => $cookie]));

        return YouTubeMusicAccount::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'cookie' => $cookie,
                'account_name' => $account->displayName,
                'last_verified_at' => now(),
                'cookie_expired_at' => null,
            ],
        );
    }
}
```

`app/Actions/VerifyYouTubeMusicCookie.php`:

```php
final readonly class VerifyYouTubeMusicCookie
{
    public function __construct(private ProviderRegistry $providers) {}

    /**
     * Checks the stored cookie with a live account call. Only a refused
     * session flags it expired; an outage propagates so the caller can tell
     * "expired" from "could not check".
     *
     * @return bool Whether the cookie still works.
     *
     * @throws ProviderException When the provider cannot be reached.
     */
    public function handle(YouTubeMusicAccount $account): bool
    {
        try {
            $this->providers->adapter($account->provider())->account($account->credentials());
        } catch (CredentialsRejected $exception) {
            report($exception);
            $account->markCookieExpired();

            return false;
        }

        $account->markCookieWorking();

        return true;
    }
}
```

`app/Console/Commands/VerifyYouTubeMusicCookiesCommand.php::handle()`:

```php
    public function handle(VerifyYouTubeMusicCookie $verify): int
    {
        $expired = 0;
        $unreachable = 0;

        YouTubeMusicAccount::query()->lazyById()->each(function (YouTubeMusicAccount $account) use ($verify, &$expired, &$unreachable): void {
            try {
                if (! $verify->handle($account)) {
                    $expired++;
                }
            } catch (ProviderException $exception) {
                report($exception);
                $unreachable++;
            }
        });

        $this->components->info("Verified YouTube Music cookies, {$expired} expired, {$unreachable} unreachable.");

        return self::SUCCESS;
    }
```

`app/Http/Controllers/YouTubeMusicConnectionController.php::store()` try/catch becomes:

```php
        try {
            $account = $action->handle($user, $request->string('cookie')->value());
        } catch (ProviderException $exception) {
            report($exception);

            return back()->withErrors(['cookie' => $exception->userMessage()]);
        }
```

(Remove the `YouTubeMusicException` / `YouTubeMusicRateLimitedException` imports; `report()` skips rate limits thanks to Task 1.) Update the existing "2 expired" expectation in the command test to `'2 expired, 0 unreachable'`.

- [ ] **Step 8: Run the account tests to verify they pass**

Run the same command as Step 6. Expected: PASS.

- [ ] **Step 9: Lint, analyse, full suite, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Services/Thumbnails app/Providers/AppServiceProvider.php app/Models/YouTubeMusicAccount.php app/Actions/ConnectYouTubeMusicAccount.php app/Actions/VerifyYouTubeMusicCookie.php app/Console/Commands/VerifyYouTubeMusicCookiesCommand.php app/Http/Controllers/YouTubeMusicConnectionController.php tests/Support/FakeProviderAdapter.php
./vendor/bin/pest --parallel
git add -A app tests lang
git commit -m "refactor(providers): connect and verify accounts through the provider registry"
```

---

### Task 6: Library flows on the contracts, legacy removal, architecture rules

**Files:**
- Modify: `app/Actions/SyncPlaylistsFromYouTubeMusicAction.php`, `app/Actions/SyncPlaylistTracks.php`, `app/Actions/RefreshPlaylist.php`, `app/Jobs/SyncYouTubeMusicLibrary.php`, `app/Http/Controllers/PlaylistRefreshController.php`, `app/Data/YouTubeMusicSyncData.php`, `app/Data/PlaylistSummaryData.php` (drop `fingerprint()`), `app/Providers/AppServiceProvider.php` (drop `Client` binding), `tests/TestCase.php` (drop `Client` binding), `tests/Unit/ArchTest.php`, `lang/fr_BE.json`
- Delete: `app/Services/YouTubeMusic/` (whole folder), `app/Exceptions/YouTubeMusicException.php`, `app/Exceptions/YouTubeMusicRateLimitedException.php`, `app/Data/AccountData.php`, `tests/Support/FakeYouTubeMusicClient.php`, `tests/Unit/Services/YouTubeMusic/` (whole folder: `CachedClientTest`, `PayloadMapperTest`, `RateLimitedClientTest` are ported or obsolete)
- Test: update `tests/Unit/Actions/{SyncPlaylistsFromYouTubeMusicActionTest,SyncPlaylistTracksTest,CheckLibraryFreshnessTest}.php`, `tests/Unit/Jobs/SyncYouTubeMusicLibraryTest.php`, `tests/Feature/Controllers/{PlaylistRefreshControllerTest,PlaylistControllerTest,DiscoverControllerTest}.php`; create or extend `tests/Unit/Data/YouTubeMusicSyncDataTest.php`

**Interfaces:**
- Consumes: Tasks 1–5.
- Produces: `SyncPlaylistTracks::handle(Playlist $playlist, RemotePlaylist $data): bool`; `SyncPlaylistsFromYouTubeMusicAction::handle(YouTubeMusicAccount, ?Closure)` throwing `ProviderException`; the job storing `ProviderErrorCode` values in `error_message`.

- [ ] **Step 1: Update the library tests (they fail first)**

Apply the Task 5 replacement table to every test file listed under **Files**, plus:

| Old | New |
|---|---|
| `use App\Exceptions\YouTubeMusicException;` / `YouTubeMusicException::class` | `use App\Exceptions\Providers\CredentialsRejected;` / `CredentialsRejected::class` |
| `use App\Exceptions\YouTubeMusicRateLimitedException;` / its `::class` | `use App\Exceptions\Providers\ProviderRateLimited;` / `ProviderRateLimited::class` |
| Track built with `new TrackData(videoId: …, artists: 'A, B', duration: '3:19', …)` in `SyncPlaylistTracksTest` | `FakeProviderAdapter::aTrack(title: …, videoId: …)` (artists `Ludwig Göransson`, 199 s → stored `duration` `3:19`) |
| A test asserting the "signed out on empty library" behaviour of the action | delete it: the adapter owns that rule (covered in Task 4) |

Add to `tests/Unit/Jobs/SyncYouTubeMusicLibraryTest.php`:

```php
it('fails without expiring the cookie when YouTube Music cannot be reached', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);

    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeProvider()->unavailable = true;

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    expect($sync->refresh()->status)->toBe(YouTubeMusicSyncStatus::Failed)
        ->and($sync->error_message)->toBe('unavailable')
        ->and($account->refresh()->cookie_expired_at)->toBeNull();
});

it('stores the failure as a code when the cookie is refused', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);

    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeProvider()->shouldFail = true;

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    expect($sync->refresh()->error_message)->toBe('credentials_rejected')
        ->and($account->refresh()->cookie_expired_at)->not->toBeNull();
});
```

`tests/Unit/Data/YouTubeMusicSyncDataTest.php` (create, or add these cases if the file exists):

```php
<?php

declare(strict_types=1);

use App\Data\YouTubeMusicSyncData;
use App\Models\YouTubeMusicSync;

it('translates a stored failure code in the viewer locale', function (): void {
    $sync = YouTubeMusicSync::factory()->create(['error_message' => 'unavailable']);
    app()->setLocale('fr_BE');

    expect(YouTubeMusicSyncData::fromModel($sync)->errorMessage)
        ->toBe('YouTube Music est injoignable. Réessayez dans quelques minutes.');
});

it('still renders a legacy English failure message', function (): void {
    $sync = YouTubeMusicSync::factory()->create(['error_message' => 'Something legacy happened.']);

    expect(YouTubeMusicSyncData::fromModel($sync)->errorMessage)->toBe('Something legacy happened.');
});
```

Add to `tests/Feature/Controllers/PlaylistRefreshControllerTest.php` (the existing rate-limit test keeps its exact English message):

```php
it('shows the outage message when YouTube Music cannot be reached', function (): void {
    $user = User::factory()->create();
    refreshablePlaylist($user);
    $this->fakeProvider()->unavailable = true;

    $response = $this->actingAs($user)->post(route('playlist-refresh.store', 'PL1'));

    $response->assertSessionHasErrors(['refresh' => 'YouTube Music could not be reached. Try again in a few minutes.']);
});
```

Run: `./vendor/bin/pest tests/Unit/Actions tests/Unit/Jobs tests/Unit/Data tests/Feature/Controllers`
Expected: FAIL (library actions still on the legacy client).

- [ ] **Step 2: Rewire `SyncPlaylistTracks` onto `RemotePlaylist`**

In `app/Actions/SyncPlaylistTracks.php`: change the signature to `handle(Playlist $playlist, RemotePlaylist $data): bool`; replace `$playlist->fill(['duration' => $data->duration]);` with `$playlist->fill(['duration' => $this->playlistDuration($data)]);`; build `$incoming` from `$data->tracks` with identity `fn (RemoteTrack $track): array => [$track->ref?->externalId, $track->title, $track->artistNames()]`; replace `attributes()` and add the two formatters:

```php
    /**
     * @return array<string, mixed>
     */
    private function attributes(RemoteTrack $track, int $position): array
    {
        return [
            'youtube_video_id' => $track->ref?->externalId,
            'title' => $track->title,
            'artists' => $track->artistNames(),
            'album' => $track->album?->title,
            'duration' => $track->durationSeconds === null ? null : $this->clock($track->durationSeconds),
            'duration_seconds' => $track->durationSeconds,
            'thumbnail_url' => ThumbnailProxy::url($track->thumbnailUrl),
            'is_explicit' => $track->isExplicit,
            'is_available' => $track->isAvailable,
            'position' => $position,
        ];
    }

    /**
     * "3:19", or "1:02:03" past an hour, as YouTube Music displays it.
     */
    private function clock(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $clock = sprintf('%d:%02d', intdiv($seconds % 3600, 60), $seconds % 60);

        return $hours > 0 ? sprintf('%d:%02d:%02d', $hours, intdiv($seconds % 3600, 60), $seconds % 60) : $clock;
    }

    /**
     * Total length in the app locale ("48 min", "1h 12m"); null when no
     * track reports a duration.
     */
    private function playlistDuration(RemotePlaylist $data): ?string
    {
        $total = array_sum(array_map(fn (RemoteTrack $track): int => $track->durationSeconds ?? 0, $data->tracks));

        return $total === 0 ? null : CarbonInterval::seconds($total)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }
```

Update the `keyed()` docblock template callable type to `callable(T): array{0: ?string, 1: string, 2: string}` (unchanged) and the imports (`App\Services\Music\Data\{RemotePlaylist,RemoteTrack}`, `App\Services\Thumbnails\ThumbnailProxy`, `Carbon\CarbonInterval`; drop `App\Data\{PlaylistData,TrackData}`).

- [ ] **Step 3: Rewire the library sync and refresh actions**

`app/Actions/SyncPlaylistsFromYouTubeMusicAction.php`: constructor `public function __construct(private ProviderRegistry $providers, private SyncPlaylistTracks $syncTracks) {}`; in `handle()`:

```php
        $playlists = $this->providers->require($account->provider(), ReadsPlaylists::class);
        $credentials = $account->credentials();
        $summaries = $playlists->playlists($credentials);
```

(remove the `if ($summaries === []) throw YouTubeMusicException::signedOut();` block — the adapter owns signed-out detection and throws `CredentialsRejected` on an empty raw library, so an empty list reaching the action is a genuinely empty library (system playlists only) and `flagRemovedPlaylists` must still run on it; change its `@param non-empty-array<…>` to `@param list<RemotePlaylistSummary>`. Keep the `$onProgress(0, 0, null)` call working with a zero total.) Pass `$playlists` and `$credentials` down to `syncPlaylist(…)`. In `syncPlaylist()`:

```php
        $fingerprint = $summary->fingerprint();
        // fill(): 'thumbnail_url' => ThumbnailProxy::url($summary->thumbnailUrl), identifiers from $summary->ref->externalId
        $playlistData = $playlists->playlist($credentials, $summary->ref->externalId, $summary->trackCount);
```

and in `handle()` key lookups use `$summary->ref->externalId` (stored map and `youtube_playlist_id`). Type hints: `RemotePlaylistSummary` instead of `PlaylistSummaryData`; `@param non-empty-array<int, RemotePlaylistSummary>` becomes `@param list<RemotePlaylistSummary>`; `@throws ProviderException` replaces both legacy `@throws`.

`app/Actions/RefreshPlaylist.php`:

```php
final readonly class RefreshPlaylist
{
    public function __construct(
        private ProviderRegistry $providers,
        private SyncPlaylistTracks $syncTracks,
    ) {}

    /**
     * Re-reads one playlist regardless of its fingerprint, to catch edits the
     * library listing cannot reveal. The fingerprint is left alone on purpose:
     * it describes the library listing, which this call does not read.
     *
     * @return bool Whether anything about the playlist changed.
     *
     * @throws ProviderException
     */
    public function handle(Playlist $playlist): bool
    {
        $account = $playlist->youtubeMusicAccount()->firstOrFail();

        $playlistData = $this->providers->require($account->provider(), ReadsPlaylists::class)
            ->playlist($account->credentials(), $playlist->youtube_playlist_id, $playlist->track_count);

        $playlist->last_checked_at = now();

        return $this->syncTracks->handle($playlist, $playlistData);
    }
}
```

- [ ] **Step 4: Rewire the job, the refresh controller and the sync data**

`app/Jobs/SyncYouTubeMusicLibrary.php` catch blocks:

```php
        } catch (ProviderRateLimited $exception) {
            // Picks up where it stopped: playlists already checked keep
            // their fingerprint and are skipped on the next attempt.
            $this->release($exception->retryAfter);

            return;
        } catch (ProviderException $exception) {
            report($exception);

            if ($exception instanceof CredentialsRejected) {
                $sync->youtubeMusicAccount->markCookieExpired();
            }

            $sync->update([
                'status' => YouTubeMusicSyncStatus::Failed,
                'error_message' => $exception->errorCode()->value,
                'finished_at' => now(),
            ]);
            broadcast(new YouTubeMusicSyncUpdated($sync));

            return;
        }
```

Update the `$maxExceptions` docblock: "A refused cookie will not start working on retry…" stays true.

`app/Http/Controllers/PlaylistRefreshController.php` try/catch:

```php
        try {
            $refresh->handle($playlist);
        } catch (ProviderException $exception) {
            report($exception);

            return back()->withErrors(['refresh' => $exception->userMessage()]);
        }
```

`app/Data/YouTubeMusicSyncData.php::translate()`:

```php
    /**
     * Failures are stored as a ProviderErrorCode and translated when shown,
     * in the viewer's locale. Rows written before codes existed hold an
     * English sentence, translated through the JSON catalogue as before.
     */
    private static function translate(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $code = ProviderErrorCode::tryFrom($message);

        if ($code !== null) {
            return $code->userMessage(Provider::YouTubeMusic);
        }

        $translation = __($message);

        return is_string($translation) ? $translation : $message;
    }
```

Remove `fingerprint()` from `app/Data/PlaylistSummaryData.php` (now a view model only; confirm with `grep -rn "fingerprint()" app` that only `RemotePlaylistSummary` remains).

- [ ] **Step 5: Delete the legacy client and its bindings**

```bash
git rm -r app/Services/YouTubeMusic tests/Unit/Services/YouTubeMusic
git rm app/Exceptions/YouTubeMusicException.php app/Exceptions/YouTubeMusicRateLimitedException.php app/Data/AccountData.php tests/Support/FakeYouTubeMusicClient.php
```

Remove the `Client` binding and its imports from `AppServiceProvider::register()` and from `tests/TestCase.php` (keep the `fakeProvider()` helper; delete `fakeYouTubeMusic()`). Then:

```bash
grep -rn "YouTubeMusicException\|YouTubeMusicRateLimitedException\|Services\\\\YouTubeMusic\|FakeYouTubeMusicClient\|fakeYouTubeMusic\|AccountData" app tests routes config bootstrap
```

Expected: no output. Remove from `lang/fr_BE.json` the keys no code uses any more: "YouTube Music rejected this cookie. Make sure you are signed in, and copy the header again.", "YouTube Music refused the request. The stored cookie may have expired.", "Sonder is pacing its calls to YouTube Music. Try again in :seconds seconds." — **keep** the three legacy exception sentences ("YouTube Music could not be reached, or it returned something…", "YouTube Music no longer recognises this cookie…", "YouTube Music returned something other than the expected :expected…") only if they exist in the catalogue, because old sync rows may still display them. Regenerate types: `php artisan typescript:transform` (AccountData disappears from `resources/js/types/generated.d.ts`; it had no front-end consumer).

- [ ] **Step 6: Add the architecture rules**

Append to `tests/Unit/ArchTest.php`:

```php
arch('only the YouTube Music adapter knows ytmusicapi')
    ->expect('Ytmusicapi')
    ->toOnlyBeUsedIn('App\Services\Music\YouTubeMusic');

arch('the YouTube Music adapter stays behind the provider contracts')
    ->expect('App\Services\Music\YouTubeMusic')
    ->toOnlyBeUsedIn([
        'App\Services\Music\YouTubeMusic',
        'App\Providers',
    ]);

arch('actions depend on contracts, never on a concrete adapter')
    ->expect('App\Actions')
    ->not->toUse('App\Services\Music\YouTubeMusic');
```

- [ ] **Step 7: Run everything**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app tests/Support tests/Unit/ArchTest.php
./vendor/bin/pest --parallel
bun run test
```

Expected: PHPStan 0 errors on `app`; Pest green including the three arch rules; Vitest green (no front-end change expected). If `tests/Feature` uses `assertSessionHasErrors('cookie')` with an exact legacy sentence, update it to the new `userMessage()` text from Task 1.

- [ ] **Step 8: Manual check against the real service**

With `composer run dev` running and the stored cookie still valid:

```bash
php artisan tinker --execute 'echo count(resolve(App\Services\Music\ProviderRegistry::class)->require(App\Enums\Provider::YouTubeMusic, App\Services\Music\Contracts\ReadsPlaylists::class)->playlists(App\Models\YouTubeMusicAccount::firstOrFail()->credentials())), "\n";'
```

Expected: `17` (16 playlists + Liked Music, "Episodes for Later" filtered out — the number may differ by the user's library, but SE must be absent). Then open `/playlists` (URL from Boost `get-absolute-url`), trigger a sync, and confirm it completes; the first sync re-reads every playlist once (fingerprint ruling).

- [ ] **Step 9: Commit**

```bash
git add -A app tests lang resources/js/types/generated.d.ts
git commit -m "refactor(providers): sync libraries through the provider contracts and drop the legacy client"
```

---

## Self-review notes

- Spec coverage for plan 1: enum ✔ (T1), contracts/DTOs/registry ✔ (T2), exceptions + translation + no rate-limit reporting ✔ (T1), gateway/rate limit/translator ✔ (T3), mapper/adapter/signed-out/filtering/credentials ✔ (T4), rewiring + persisted error codes ✔ (T5–T6), arch rules ✔ (T6). Deferred by ruling: `ReadsFavorites`, LM filtering (plan 2).
- Review Focus pins: outage vs refusal (T6 job tests, T5 verify tests), empty library vs system-only library (T4), locale of stored errors (T6 data test), rate limits not reported (T1), foreign credentials (T4).
