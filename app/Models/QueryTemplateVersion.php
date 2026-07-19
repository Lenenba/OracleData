<?php

namespace App\Models;

use App\Enums\QueryTemplateVersionStatus;
use Database\Factories\QueryTemplateVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable-after-submission snapshot of an official query template.
 *
 * @property int $id
 * @property int $query_template_id
 * @property int|null $restored_from_version_id
 * @property int $version_number
 * @property QueryTemplateVersionStatus $status
 * @property int|null $open_slot
 * @property array<string, mixed> $definition
 * @property array<string, array<string, mixed>> $translations
 * @property string $content_hash
 * @property string|null $change_summary
 * @property int|null $created_by_user_id
 * @property int|null $submitted_by_user_id
 * @property int|null $published_by_user_id
 * @property Carbon|null $submitted_at
 * @property Carbon|null $published_at
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QueryTemplate $queryTemplate
 * @property-read QueryTemplateVersion|null $restoredFromVersion
 * @property-read User|null $createdBy
 * @property-read User|null $submittedBy
 * @property-read User|null $publishedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, QueryTemplateCertification> $certifications
 */
#[Fillable([
    'query_template_id',
    'restored_from_version_id',
    'version_number',
    'status',
    'open_slot',
    'definition',
    'translations',
    'content_hash',
    'change_summary',
    'created_by_user_id',
    'submitted_by_user_id',
    'published_by_user_id',
    'submitted_at',
    'published_at',
    'lock_version',
])]
class QueryTemplateVersion extends Model
{
    /** @use HasFactory<QueryTemplateVersionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (QueryTemplateVersion $version): void {
            $contentFields = ['definition', 'translations', 'content_hash', 'change_summary'];
            $contentChanged = collect($contentFields)->contains(
                fn (string $field): bool => $version->isDirty($field),
            );
            $originalStatus = QueryTemplateVersionStatus::tryFrom(
                (string) $version->getRawOriginal('status'),
            );

            if ($version->isDirty('restored_from_version_id')) {
                throw new LogicException('La provenance de restauration est immuable.');
            }

            if ($contentChanged && $originalStatus !== QueryTemplateVersionStatus::DRAFT) {
                throw new LogicException('Le contenu d’une version soumise est immuable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Les versions de modèles ne peuvent pas être supprimées.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => QueryTemplateVersionStatus::class,
            'open_slot' => 'integer',
            'definition' => 'array',
            'translations' => 'array',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<QueryTemplate, $this> */
    public function queryTemplate(): BelongsTo
    {
        return $this->belongsTo(QueryTemplate::class);
    }

    /** @return BelongsTo<QueryTemplateVersion, $this> */
    public function restoredFromVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_version_id');
    }

    /** @return HasMany<QueryTemplateVersion, $this> */
    public function restoredVersions(): HasMany
    {
        return $this->hasMany(self::class, 'restored_from_version_id');
    }

    /** @return HasMany<QueryTemplateCertification, $this> */
    public function certifications(): HasMany
    {
        return $this->hasMany(QueryTemplateCertification::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function belongsToTemplate(QueryTemplate $template): bool
    {
        return $this->query_template_id === $template->id;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, mixed>>  $translations
     */
    public static function contentHash(array $definition, array $translations): string
    {
        return hash('sha256', json_encode(
            [$definition, $translations],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }
}
