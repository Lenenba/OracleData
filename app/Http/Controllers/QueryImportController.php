<?php

namespace App\Http\Controllers;

use App\Actions\Queries\ImportPostmanQueries;
use App\Http\Requests\ImportQueriesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class QueryImportController extends Controller
{
    public function preview(ImportQueriesRequest $request, ImportPostmanQueries $import): JsonResponse
    {
        return response()->json($import->preview(
            $request->user(),
            $request->validated('collection'),
        ));
    }

    public function store(ImportQueriesRequest $request, ImportPostmanQueries $import): RedirectResponse
    {
        $result = $import->execute(
            $request->user(),
            $request->validated('collection'),
            $request->validated('selected'),
            $request->string('tenant_key')->toString(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Import Postman terminé : :created créée(s), :skipped déjà présente(s).', [
                'created' => $result['created'],
                'skipped' => $result['skipped_existing'],
            ]),
        ]);

        return to_route('queries.index');
    }
}
