<?php

declare(strict_types=1);

use App\Application\Approvals\Commands\DecideApproval;
use App\Application\Approvals\Commands\RequestApproval;
use App\Application\Approvals\Handlers\DecideApprovalHandler;
use App\Application\Approvals\Handlers\RequestApprovalHandler;
use App\Application\Projects\Commands\StartProject;
use App\Application\Projects\Handlers\StartProjectHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Command Handlers
    |--------------------------------------------------------------------------
    |
    | Register each synchronous application command with exactly one handler.
    | The command bus resolves handler constructor dependencies through the
    | Laravel service container.
    |
    */

    'handlers' => [
        RequestApproval::class => RequestApprovalHandler::class,

        DecideApproval::class => DecideApprovalHandler::class,

        StartProject::class => StartProjectHandler::class,
    ],

];
