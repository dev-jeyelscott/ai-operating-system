<?php

use App\Support\Health\ArtifactStorageProbe;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\mock;

test('artifact storage readiness probes one fixed object without listing artifacts', function () {
    $disk = mock(FilesystemAdapter::class);

    config()->set('filesystems.artifact', 'artifact');

    Storage::shouldReceive('disk')
        ->once()
        ->with('artifact')
        ->andReturn($disk);

    $disk->shouldReceive('exists')
        ->once()
        ->with('.aios/health/readiness-probe')
        ->andReturnFalse();

    expect(app(ArtifactStorageProbe::class)->probe())->toBeTrue();
});
