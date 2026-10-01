<?php

declare(strict_types=1);

namespace App\Enums;

enum CreditType: string
{
    case Artist = 'artist';
    case Songwriter = 'songwriter';
    case Publisher = 'publisher';
    case Producer = 'producer';
    case Performer = 'performer';
}
