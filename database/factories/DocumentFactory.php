<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
final class DocumentFactory extends Factory
{
    /** @var class-string<Document> */
    protected $model = Document::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(3),
        ];
    }
}
