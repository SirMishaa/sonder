<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypts every secret column with this application's key, after a
 * database copied from another environment brought values encrypted with
 * that environment's key. Raw strings are carried over untouched, so it
 * holds for model casts (`encryptString`) and Fortify's serialized values
 * (`encrypt`) alike.
 */
final readonly class ReencryptSecrets
{
    /**
     * @var array<string, list<string>>
     */
    public const array COLUMNS = [
        'users' => ['two_factor_secret', 'two_factor_recovery_codes'],
        'youtube_music_accounts' => ['cookie'],
    ];

    public function __construct(private StringEncrypter $current) {}

    /**
     * Returns how many values were re-encrypted.
     */
    public function handle(StringEncrypter $previous): int
    {
        $reencrypted = 0;

        foreach (self::COLUMNS as $table => $columns) {
            DB::table($table)->lazyById()->each(function (object $row) use ($table, $columns, $previous, &$reencrypted): void {
                $values = [];

                foreach ($columns as $column) {
                    $value = $row->{$column};

                    if (is_string($value)) {
                        $values[$column] = $this->current->encryptString($previous->decryptString($value));
                    }
                }

                if ($values !== []) {
                    DB::table($table)->where('id', $row->id)->update($values);
                    $reencrypted += count($values);
                }
            });
        }

        return $reencrypted;
    }
}
