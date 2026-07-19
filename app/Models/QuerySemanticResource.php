<?php

namespace App\Models;

use App\Enums\SemanticLineageUsage;
use Database\Factories\QuerySemanticResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'query_id',
    'semantic_resource_id',
    'semantic_catalog_version_id',
    'resource_key',
    'child_key',
    'field_key',
    'usage',
    'definition_hash',
    'captured_at',
])]
class QuerySemanticResource extends Model
{
    /** @use HasFactory<QuerySemanticResourceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'usage' => SemanticLineageUsage::class,
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Query, $this> */
    public function executedQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    /** @return BelongsTo<SemanticResource, $this> */
    public function semanticResource(): BelongsTo
    {
        return $this->belongsTo(SemanticResource::class);
    }

    /** @return BelongsTo<SemanticCatalogVersion, $this> */
    public function semanticCatalogVersion(): BelongsTo
    {
        return $this->belongsTo(SemanticCatalogVersion::class);
    }
}
