<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Persistence aggregate for a project-owned logical document.
 *
 * @property int $id
 * @property int $project_id
 * @property string $title
 * @property string|null $document_class
 * @property-read Project $project
 * @property-read Collection<int, DocumentVersion> $versions
 * @property-read DocumentVersion|null $latestVersion
 */
#[Fillable(['project_id', 'title', 'document_class'])]
final class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeForOrganization(
        Builder $query,
        int $organizationId,
    ): Builder {
        return $query->whereHas(
            'project',
            fn (Builder $projectQuery): Builder => $projectQuery
                ->where('organization_id', $organizationId),
        );
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('version');
    }

    /** @return HasOne<DocumentVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->ofMany('version', 'max');
    }
}
