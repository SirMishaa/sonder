<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MusicText;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A genre or a free tag, shared by every source.
 *
 * @property-read string $id
 * @property-read string $name
 * @property-read string $slug
 * @property-read bool $is_genre
 */
final class Tag extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'slug', 'is_genre'];

    /**
     * The tag for this name, created when new. A name once seen as a genre
     * stays a genre.
     */
    public static function named(string $name, bool $isGenre): self
    {
        $tag = self::query()->createOrFirst(
            ['slug' => MusicText::tagSlug($name)],
            ['name' => mb_strtolower(mb_trim($name)), 'is_genre' => $isGenre],
        );

        if ($isGenre && ! $tag->is_genre) {
            $tag->update(['is_genre' => true]);
        }

        return $tag;
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return ['id' => 'string', 'is_genre' => 'boolean'];
    }
}
