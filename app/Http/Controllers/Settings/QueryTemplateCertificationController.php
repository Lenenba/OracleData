<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateCertification;
use App\Models\User;
use App\Services\QueryTemplateGovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class QueryTemplateCertificationController extends Controller
{
    public function store(
        Request $request,
        QueryTemplate $queryTemplate,
        QueryTemplateGovernanceService $governance,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('certify', $queryTemplate);
        /** @var array{template_lock_version: int, published_version_id: int, public_note?: string|null} $validated */
        $validated = $request->validate([
            'template_lock_version' => ['required', 'integer', 'min:1'],
            'published_version_id' => ['required', 'integer', 'min:1'],
            'public_note' => ['nullable', 'string', 'max:500'],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $governance->certifyPublishedVersion(
                $queryTemplate,
                $actor,
                $validated['template_lock_version'],
                $validated['published_version_id'],
                $validated['public_note'] ?? null,
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Modèle officiel certifié.')]);

        return to_route('query-template-governance.show', $queryTemplate);
    }

    public function revoke(
        Request $request,
        QueryTemplate $queryTemplate,
        QueryTemplateCertification $queryTemplateCertification,
        QueryTemplateGovernanceService $governance,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('viewGovernance', $queryTemplate);
        abort_unless($queryTemplateCertification->belongsToTemplate($queryTemplate), 404);
        Gate::authorize('revokeCertification', [$queryTemplate, $queryTemplateCertification]);
        /** @var array{template_lock_version: int, certification_lock_version: int} $validated */
        $validated = $request->validate([
            'template_lock_version' => ['required', 'integer', 'min:1'],
            'certification_lock_version' => ['required', 'integer', 'min:1'],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $governance->revokeCertification(
                $queryTemplate,
                $queryTemplateCertification,
                $actor,
                $validated['template_lock_version'],
                $validated['certification_lock_version'],
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Certification révoquée.')]);

        return to_route('query-template-governance.show', $queryTemplate);
    }

    private function conflictResponse(
        Request $request,
        ConflictHttpException $exception,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());
        }

        return back()->withErrors(['governance' => $exception->getMessage()]);
    }
}
