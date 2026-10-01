<?php

declare(strict_types=1);

namespace App\Enums;

enum LookupOutcome: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case Failed = 'failed';
}
