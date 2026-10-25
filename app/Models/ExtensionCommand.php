<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerCommandAction;
use App\Enums\PlayerCommandKind;
use Carbon\CarbonInterface;
use Database\Factories\ExtensionCommandFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A command the browser extension sent, kept so Sonder can tell whether a
 * mix was already sent.
 *
 * @property-read string $id
 * @property-read string $user_id
 * @property-read PlayerCommandAction $action
 * @property-read PlayerCommandKind $kind
 * @property-read string|null $list_id
 * @property-read list<string> $video_ids
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read User $user
 */
final class ExtensionCommand extends Model
{
    /** @use HasFactory<ExtensionCommandFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'user_id',
        'action',
        'kind',
        'list_id',
        'video_ids',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'user_id' => 'string',
            'action' => PlayerCommandAction::class,
            'kind' => PlayerCommandKind::class,
            'video_ids' => 'array',
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
}
