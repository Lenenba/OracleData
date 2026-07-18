<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $query_template_id
 * @property string $locale
 * @property string $name
 * @property string|null $description
 * @property array<string, string>|null $parameter_labels
 * @property array<string, string>|null $parameter_descriptions
 * @property array<string, array<string, string>>|null $parameter_options
 * @property-read QueryTemplate $queryTemplate
 */
#[Fillable([
    'query_template_id',
    'locale',
    'name',
    'description',
    'parameter_labels',
    'parameter_descriptions',
    'parameter_options',
])]
class QueryTemplateTranslation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameter_labels' => 'array',
            'parameter_descriptions' => 'array',
            'parameter_options' => 'array',
        ];
    }

    /** @return BelongsTo<QueryTemplate, $this> */
    public function queryTemplate(): BelongsTo
    {
        return $this->belongsTo(QueryTemplate::class);
    }
}
