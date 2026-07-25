<?php

declare(strict_types=1);

namespace App\Domain\Documents;

enum DocumentClassification: string
{
    case Unclassified = 'unclassified';
    case Specification = 'specification';
    case Architecture = 'architecture';
    case Roadmap = 'roadmap';
    case Policy = 'policy';
    case Operational = 'operational';
    case Other = 'other';
}
