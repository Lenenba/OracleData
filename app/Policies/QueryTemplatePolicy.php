<?php

namespace App\Policies;

use App\Enums\QueryTemplateGovernanceStatus;
use App\Enums\QueryTemplateVersionStatus;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateCertification;
use App\Models\QueryTemplateVersion;
use App\Models\User;

class QueryTemplatePolicy
{
    public function viewAnyGovernance(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function viewGovernance(User $user, QueryTemplate $template): bool
    {
        return $user->isSuperAdmin();
    }

    public function createDraft(User $user, QueryTemplate $template): bool
    {
        return $user->isSuperAdmin()
            && $template->governance_status === QueryTemplateGovernanceStatus::PUBLISHED
            && $template->published_version_id !== null;
    }

    public function updateDraft(User $user, QueryTemplate $template, QueryTemplateVersion $version): bool
    {
        return $user->isSuperAdmin()
            && $version->belongsToTemplate($template)
            && $version->status === QueryTemplateVersionStatus::DRAFT;
    }

    public function submitForReview(User $user, QueryTemplate $template, QueryTemplateVersion $version): bool
    {
        return $this->updateDraft($user, $template, $version);
    }

    public function publish(User $user, QueryTemplate $template, QueryTemplateVersion $version): bool
    {
        return $user->isSuperAdmin()
            && $version->belongsToTemplate($template)
            && $version->status === QueryTemplateVersionStatus::REVIEW;
    }

    public function compareVersions(
        User $user,
        QueryTemplate $template,
        QueryTemplateVersion $fromVersion,
        QueryTemplateVersion $toVersion,
    ): bool {
        return $user->isSuperAdmin()
            && $fromVersion->belongsToTemplate($template)
            && $toVersion->belongsToTemplate($template);
    }

    public function restoreVersion(User $user, QueryTemplate $template, QueryTemplateVersion $version): bool
    {
        return $user->isSuperAdmin()
            && $template->isPublished()
            && $version->belongsToTemplate($template)
            && $version->status === QueryTemplateVersionStatus::SUPERSEDED
            && $version->published_at !== null
            && (int) $version->getKey() !== (int) $template->published_version_id;
    }

    public function certify(User $user, QueryTemplate $template): bool
    {
        return $user->isSuperAdmin()
            && $template->governance_status === QueryTemplateGovernanceStatus::PUBLISHED;
    }

    public function revokeCertification(
        User $user,
        QueryTemplate $template,
        QueryTemplateCertification $certification,
    ): bool {
        return $user->isSuperAdmin()
            && $certification->belongsToTemplate($template);
    }

    public function archive(User $user, QueryTemplate $template): bool
    {
        return $user->isSuperAdmin()
            && $template->governance_status === QueryTemplateGovernanceStatus::PUBLISHED;
    }
}
