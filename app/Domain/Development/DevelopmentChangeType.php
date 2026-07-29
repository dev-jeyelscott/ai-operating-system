<?php

declare(strict_types=1);

namespace App\Domain\Development;

enum DevelopmentChangeType: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
}
