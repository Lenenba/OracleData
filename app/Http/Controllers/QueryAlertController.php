<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQueryAlertRequest;
use App\Models\QueryAlert;
use App\Models\QuerySchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Governs the alerts attached to a user's own schedule. Cross-user access to a
 * schedule or an alert is refused with 404.
 */
class QueryAlertController extends Controller
{
    public function store(
        StoreQueryAlertRequest $request,
        QuerySchedule $querySchedule,
    ): RedirectResponse {
        abort_unless(
            $querySchedule->user_id === (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        $data = $request->validated();
        QueryAlert::create([
            'query_schedule_id' => $querySchedule->id,
            'user_id' => $querySchedule->user_id,
            'name' => $data['name'],
            'condition' => $data['condition'],
            'threshold' => $data['threshold'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return to_route('automation.index');
    }

    public function update(
        StoreQueryAlertRequest $request,
        QueryAlert $queryAlert,
    ): RedirectResponse {
        abort_unless(
            $queryAlert->user_id === (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        $data = $request->validated();
        $queryAlert->update([
            'name' => $data['name'],
            'condition' => $data['condition'],
            'threshold' => $data['threshold'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return to_route('automation.index');
    }

    public function destroy(Request $request, QueryAlert $queryAlert): RedirectResponse
    {
        abort_unless(
            $queryAlert->user_id === (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        $queryAlert->delete();

        return to_route('automation.index');
    }
}
