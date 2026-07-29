<?php

declare(strict_types=1);

namespace App\Domain\Development;

enum DevelopmentStage: string
{
    case Plan = 'plan';
    case Implementation = 'implementation';
    case Validation = 'validation';
    case Commit = 'commit';
    case Push = 'push';
    case PullRequest = 'pull_request';
}
