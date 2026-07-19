<?php

namespace App\Models;

use App\Enums\SemanticCatalogVersionStatus;
use Database\Factories\SemanticCatalogVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'version_number',
    'status',
    'open_slot',
    'published_slot',
    'catalog',
    'content_hash',
    'change_summary',
    'created_by_user_id',
    'submitted_by_user_id',
    'published_by_user_id',
    'submitted_at',
    'published_at',
    'lock_version',
])]
class SemanticCatalogVersion extends Model
{
    /** @use HasFactory<SemanticCatalogVersionFactory> */
    use HasFactory;

    public const int OPEN_SLOT = 1;

    public const int PUBLISHED_SLOT = 1;

    protected static function booted(): void
    {
        static::updating(function (SemanticCatalogVersion $version): void {
            $originalStatus = SemanticCatalogVersionStatus::tryFrom(
                (string) $version->getRawOriginal('status'),
            );
            $contentChanged = collect(['catalog', 'content_hash', 'change_summary'])
                ->contains(fn (string $field): bool => $version->isDirty($field));

            if ($version->isDirty(['version_number', 'created_by_user_id'])) {
                throw new LogicException('La provenance d’une version du catalogue sémantique est immuable.');
            }

            if ($contentChanged && $originalStatus !== SemanticCatalogVersionStatus::Draft) {
                throw new LogicException('Le contenu d’une version soumise du catalogue sémantique est immuable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Les versions du catalogue sémantique ne peuvent pas être supprimées.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SemanticCatalogVersionStatus::class,
            'open_slot' => 'integer',
            'published_slot' => 'integer',
            'catalog' => 'array',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'lock_version' => 'integer',
        ];
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

    /** @return HasMany<QuerySemanticResource, $this> */
    public function queryResources(): HasMany
    {
        return $this->hasMany(QuerySemanticResource::class);
    }

    /** @return HasMany<QueryTemplateVersionSemanticResource, $this> */
    public function queryTemplateVersionResources(): HasMany
    {
        return $this->hasMany(QueryTemplateVersionSemanticResource::class);
    }

    /** @return HasMany<QueryExecution, $this> */
    public function queryExecutions(): HasMany
    {
        return $this->hasMany(QueryExecution::class);
    }

    /** @return HasMany<OracleResourceSchemaSnapshot, $this> */
    public function oracleSchemaSnapshots(): HasMany
    {
        return $this->hasMany(OracleResourceSchemaSnapshot::class);
    }

    /** @return HasMany<OracleSchemaImpact, $this> */
    public function oracleSchemaImpacts(): HasMany
    {
        return $this->hasMany(OracleSchemaImpact::class);
    }

    /** @param array<string, mixed> $catalog */
    public static function contentHash(array $catalog): string
    {
        return hash('sha256', json_encode(
            $catalog,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
