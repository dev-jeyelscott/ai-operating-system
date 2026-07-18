<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class LayerDependencyTest extends TestCase
{
    /**
     * Ensure domain rules remain independent of orchestration,
     * infrastructure, and HTTP delivery concerns.
     */
    public function test_domain_does_not_reference_outer_layers(): void
    {
        $this->assertLayerDoesNotReference('app/Domain', [
            'App\\Application\\',
            'App\\Infrastructure\\',
            'App\\Http\\',
        ]);
    }

    /**
     * Ensure application use cases depend on contracts rather than
     * concrete infrastructure or HTTP delivery classes.
     */
    public function test_application_does_not_reference_infrastructure_or_http(): void
    {
        $this->assertLayerDoesNotReference('app/Application', [
            'App\\Infrastructure\\',
            'App\\Http\\',
        ]);
    }

    /**
     * Prevent inner layers from bypassing module ownership with raw
     * query-builder access.
     */
    public function test_inner_layers_do_not_use_the_database_facade(): void
    {
        foreach (['app/Domain', 'app/Application'] as $layer) {
            $this->assertLayerDoesNotReference($layer, [
                'Illuminate\\Support\\Facades\\DB',
                'DB::table(',
            ]);
        }
    }

    /**
     * Assert that PHP files below a layer do not contain forbidden
     * namespace or API references.
     *
     * @param  array<int, string>  $forbiddenReferences
     */
    private function assertLayerDoesNotReference(
        string $relativePath,
        array $forbiddenReferences,
    ): void {
        foreach ($this->phpFiles($relativePath) as $file) {
            $contents = file_get_contents($file->getPathname());

            self::assertIsString($contents);

            foreach ($forbiddenReferences as $forbiddenReference) {
                self::assertStringNotContainsString(
                    $forbiddenReference,
                    $contents,
                    sprintf(
                        '%s contains the forbidden reference %s.',
                        $file->getPathname(),
                        $forbiddenReference,
                    ),
                );
            }
        }
    }

    /**
     * Yield every PHP file below a repository-relative directory.
     *
     * @return iterable<int, SplFileInfo>
     */
    private function phpFiles(string $relativePath): iterable
    {
        $directory = base_path($relativePath);

        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $file) {
            if (
                $file instanceof SplFileInfo
                && $file->getExtension() === 'php'
            ) {
                yield $file;
            }
        }
    }
}
