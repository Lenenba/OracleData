<?php

namespace App\Models;

use Database\Factories\QueryExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Journal produit des exécutions de requêtes.
 *
 * Le tenant et la connexion référencés sont toujours ceux de l'exécutant
 * réel : pour une requête partagée, jamais ceux de l'auteur. Les statuts
 * `queued`, `running` et `cancelled` sont réservés à l'exécution asynchrone.
 *
 * @property int $id
 * @property int|null $query_id
 * @property int|null $query_template_id
 * @property int|null $query_template_version_id
 * @property int $user_id
 * @property int|null $oracle_tenant_id
 * @property int|null $auth_connection_id
 * @property string $source_type
 * @property string $purpose
 * @property string $status
 * @property int $duration_ms
 * @property int $rows_count
 * @property string|null $error_code
 * @property Carbon $started_at
 * @property Carbon $finished_at
 * @property-read Query|null $executedQuery
 * @property-read QueryTemplate|null $queryTemplate
 * @property-read QueryTemplateVersion|null $queryTemplateVersion
 * @property-read User $user
 * @property-read OracleTenant|null $oracleTenant
 * @property-read AuthConnection|null $authConnection
 */
#[Fillable([
    'query_id',
    'query_template_id',
    'query_template_version_id',
    'user_id',
    'oracle_tenant_id',
    'auth_connection_id',
    'source_type',
    'purpose',
    'status',
    'duration_ms',
    'rows_count',
    'error_code',
    'started_at',
    'finished_at',
])]
class QueryExecution extends Model
{
    /** @use HasFactory<QueryExecutionFactory> */
    use HasFactory;

    public const string SOURCE_SAVED_QUERY = 'saved_query';

    public const string SOURCE_QUERY_TEMPLATE = 'query_template';

    public const string PURPOSE_RUN = 'run';

    public const string PURPOSE_PREVIEW = 'preview';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * `query()` étant réservé par Eloquent, la relation porte un nom explicite.
     *
     * @return BelongsTo<Query, $this>
     */
    public function executedQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    /**
     * The predefined template directly previewed or executed by the user.
     * Personal copies remain attached to executedQuery() instead.
     *
     * @return BelongsTo<QueryTemplate, $this>
     */
    public function queryTemplate(): BelongsTo
    {
        return $this->belongsTo(QueryTemplate::class);
    }

    /**
     * Exact official version used by a direct template preview or run.
     *
     * @return BelongsTo<QueryTemplateVersion, $this>
     */
    public function queryTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(QueryTemplateVersion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<OracleTenant, $this>
     */
    public function oracleTenant(): BelongsTo
    {
        return $this->belongsTo(OracleTenant::class);
    }

    /**
     * @return BelongsTo<AuthConnection, $this>
     */
    public function authConnection(): BelongsTo
    {
        return $this->belongsTo(AuthConnection::class);
    }
}
