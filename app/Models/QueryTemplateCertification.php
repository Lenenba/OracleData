<?php

namespace App\Models;

use App\Enums\DataQualityHealthStatus;
use App\Enums\QueryTemplateVersionStatus;
use Database\Factories\QueryTemplateCertificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Historical certification of one exact published template version.
 *
 * @property int $id
 * @property int $query_template_id
 * @property int $query_template_version_id
 * @property string $version_content_hash
 * @property int|null $active_slot
 * @property int|null $certified_by_user_id
 * @property Carbon $certified_at
 * @property string|null $public_note
 * @property int|null $revoked_by_user_id
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QueryTemplate $queryTemplate
 * @property-read QueryTemplateVersion $queryTemplateVersion
 * @property-read User|null $certifiedBy
 * @property-read User|null $revokedBy
 */
#[Fillable([
    'query_template_id',
    'query_template_version_id',
    'version_content_hash',
    'active_slot',
    'certified_by_user_id',
    'certified_at',
    'public_note',
    'revoked_by_user_id',
    'revoked_at',
    'revocation_reason',
    'lock_version',
])]
class QueryTemplateCertification extends Model
{
    /** @use HasFactory<QueryTemplateCertificationFactory> */
    use HasFactory;

    public const int ACTIVE_SLOT = 1;

    public const string REASON_MANUAL = 'manual';

    public const string REASON_NEW_PUBLICATION = 'new_publication';

    public const string REASON_TEMPLATE_ARCHIVED = 'template_archived';

    /** @var list<string> */
    public const array REVOCATION_REASONS = [
        self::REASON_MANUAL,
        self::REASON_NEW_PUBLICATION,
        self::REASON_TEMPLATE_ARCHIVED,
    ];

    protected static function booted(): void
    {
        static::updating(function (QueryTemplateCertification $certification): void {
            $immutableFields = [
                'query_template_id',
                'query_template_version_id',
                'version_content_hash',
                'certified_by_user_id',
                'certified_at',
                'public_note',
            ];

            if (collect($immutableFields)->contains(
                fn (string $field): bool => $certification->isDirty($field),
            )) {
                throw new LogicException('Une certification existante est immuable.');
            }

            if ($certification->getRawOriginal('revoked_at') !== null) {
                throw new LogicException('Une certification révoquée est immuable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Les certifications ne peuvent pas être supprimées.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active_slot' => 'integer',
            'certified_at' => 'datetime',
            'revoked_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<QueryTemplate, $this> */
    public function queryTemplate(): BelongsTo
    {
        return $this->belongsTo(QueryTemplate::class);
    }

    /** @return BelongsTo<QueryTemplateVersion, $this> */
    public function queryTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(QueryTemplateVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function certifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function belongsToTemplate(QueryTemplate $template): bool
    {
        return $this->query_template_id === $template->id;
    }

    public function isActive(): bool
    {
        return $this->active_slot === self::ACTIVE_SLOT && $this->revoked_at === null;
    }

    public function isEffectiveFor(QueryTemplate $template): bool
    {
        if (! $this->isActive()
            || ! $template->isPublished()
            || $template->published_version_id !== $this->query_template_version_id
            || $template->business_owner_user_id === null
            || $template->review_due_at === null
            || $template->review_due_at->isBefore(today())
            || $template->quality_status === DataQualityHealthStatus::Failing) {
            return false;
        }

        $version = $this->queryTemplateVersion;
        $computedHash = QueryTemplateVersion::contentHash(
            $version->definition,
            $version->translations,
            $version->quality_rules ?? [],
        );

        return $version->query_template_id === $template->id
            && $version->status === QueryTemplateVersionStatus::PUBLISHED
            && $version->published_at !== null
            && hash_equals($this->version_content_hash, $version->content_hash)
            && hash_equals($version->content_hash, $computedHash);
    }
}
