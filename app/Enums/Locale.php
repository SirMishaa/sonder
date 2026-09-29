<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The languages Sonder is translated into, as ISO 639-1 language and
 * ISO 3166-1 region codes joined the way Laravel names its lang files
 * (`fr_BE`). Belgian French is the default (`app.locale`).
 */
#[TypeScript]
enum Locale: string
{
    case French = 'fr_BE';
    case English = 'en_US';
}
