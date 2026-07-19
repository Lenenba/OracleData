<?php

namespace App\Policies;

use App\Enums\QueryTemplateGovernanceStatus;
use App\Enums\QueryTemplateRole;
use App\Enums\QueryTemplateVersionStatus;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateCertification;
use App\Models\QueryTemplateVersion;
use App\Models\User;

class QueryTemplatePolicy
{
    public function viewAnyGovernance(User $user): bool
    {
        return $user->isSuperAdmin()
            || $user->technicallyOwnedQueryTemplates()->exists()
            || $user->queryTemplateRoles()->exists();
    }

    public function viewGovernance(User $user, QueryTemplate $template): bool
    {
        return $user->isSuperAdmin()
            || $this->isTechnicalOwner($user, $template)
            || $this->hasRole($user, $template, QueryTemplateRole::Editor)
            || $this->hasRole($user, $template, QueryTemplateRole::Publisher);
    }

    public function createDraft(User $user, QueryTemplate $template): bool
    {
        return $this->canEdit($user, $template)
            && $template->governance_status === QueryTemplateGovernanceStatus::PUBLISHED
            && $template->published_version_id !== null;
    }

    public function updateDraft(User $user, QueryTemplate $template, QueryTemplateVersion $version): bool
    {
        return $this->canEdit($user, $template)
            && $version->belongsToTemplate($template)
            && $version->status === QueryTemplateVersionStatus::DRAFT;
    }

    public function submitForReview(User $user, QueryTemplate $template, QueryTemplateVersion $version): bool
    {
        return $this->updateDraft($user, $template, $version);
    }

    public function publish(User $user, QueryTemplate $template, QueryTemplateVersion $version): bool
    {
        return $this->canPublish($user, $template)
            && $version->belongsToTemplate($template)
            && $version->status === QueryTemplateVersionStatus::REVIEW;
    }

    public function compareVersions(
        User $user,
        QueryTemplate $template,
        QueryTemplateVersion $fromVersion,
        QueryTemplateVersion $toVersion,
    ): bool {
        return $this->viewGovernance($user, $template)
            && $fromVersion->belongsToTemplate($template)
            && $toVersion->belongsToTemplate($template);
    }

    public function restoreVersion(User $user, QueryTemplate $template, QueryTemplateVersion $version): bool
    {
        return $this->canEdit($user, $template)
            && $template->isPublished()
            && $version->belongsToTemplate($template)
            && $version->status === QueryTemplateVersionStatus::SUPERSEDED
            && $version->published_at !== null
            && (int) $version->getKey() !== (int) $template->published_version_id;
    }

    public function certify(User $user, QueryTemplate $template): bool
    {
        return $this->canPublish($user, $template)
            && $template->governance_status === QueryTemplateGovernanceStatus::PUBLISHED;
    }

    public function revokeCertification(
        User $user,
        QueryTemplate $template,
        QueryTemplateCertification $certification,
    ): bool {
        return $this->canPublish($user, $template)
            && $certification->belongsToTemplate($template);
    }

    public function archive(User $user, QueryTemplate $template): bool
    {
        return $this->canPublish($user, $template)
            && $template->governance_status === QueryTemplateGovernanceStatus::PUBLISHED;
    }

    public function updateTechnicalDefinition(
        User $user,
        QueryTemplate $template,
        QueryTemplateVersion $version,
    ): bool {
        return ($user->isSuperAdmin() || $this->isTechnicalOwner($user, $template))
            && $version->belongsToTemplate($template)
            && $version->status === QueryTemplateVersionStatus::DRAFT;
    }

    public function runQualityValidation(
        User $user,
        QueryTemplate $template,
        QueryTemplateVersion $version,
    ): bool {
        return $this->viewGovernance($user, $template)
            && $version->belongsToTemplate($template)
            && in_array($version->status, [
                QueryTemplateVersionStatus::DRAFT,
                QueryTemplateVersionStatus::REVIEW,
                QueryTemplateVersionStatus::PUBLISHED,
            ], true);
    }

    public function captureQualityReference(
        User $user,
        QueryTemplate $template,
        QueryTemplateVersion $version,
    ): bool {
        return $this->updateDraft($user, $template, $version);
    }

    public function assignTechnicalOwner(User $user, QueryTemplate $template): bool
    {
        return $user->isSuperAdmin()
            || $this->hasRole($user, $template, QueryTemplateRole::Publisher);
    }

    public function manageRoles(User $user, QueryTemplate $template): bool
    {
        return $user->isSuperAdmin();
    }

    private function canEdit(User $user, QueryTemplate $template): bool
    {
        return $user->isSuperAdmin()
            || $this->isTechnicalOwner($user, $template)
            || $this->hasRole($user, $template, QueryTemplateRole::Editor);
    }

    private function canPublish(User $user, QueryTemplate $template): bool
    {
        return $user->isSuperAdmin()
            || $this->hasRole($user, $template, QueryTemplateRole::Publisher);
    }

    private function isTechnicalOwner(User $user, QueryTemplate $template): bool
    {
        return $template->technical_owner_user_id !== null
            && $template->technical_owner_user_id === $user->id;
    }

    private function hasRole(User $user, QueryTemplate $template, QueryTemplateRole $role): bool
    {
        return $user->hasQueryTemplateRole($template, $role);
    }
}
