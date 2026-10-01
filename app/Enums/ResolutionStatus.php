<?php

declare(strict_types=1);

namespace App\Enums;

enum ResolutionStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case NotFound = 'not_found';
    case Failed = 'failed';
}
