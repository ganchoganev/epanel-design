<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\BomService;
use App\Services\ProjectPdfService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        private readonly BomService $bomService,
        private readonly ProjectPdfService $pdfService,
    ) {
    }

    public function index()
    {
        return response()->json(
            Project::orderByDesc('updated_at')->get(['id', 'name', 'client_name', 'is_template', 'current_version', 'updated_at'])
        );
    }

    public function store(Request $request)
    {
        $data = $this->validateProject($request);

        return response()->json(Project::create($data), 201);
    }

    public function show(Project $project)
    {
        return response()->json($project->load('versions:id,project_id,version_number,note,created_at'));
    }

    public function update(Request $request, Project $project)
    {
        $data = $this->validateProject($request, false);
        $project->update($data);

        return response()->json($project);
    }

    public function destroy(Project $project)
    {
        $project->delete();

        return response()->json(null, 204);
    }

    public function duplicate(Project $project)
    {
        $copy = $project->replicate();
        $copy->name = $project->name.' (копие)';
        $copy->current_version = 1;
        $copy->save();

        return response()->json($copy, 201);
    }

    public function createVersion(Request $request, Project $project)
    {
        $version = $project->versions()->create([
            'version_number' => $project->current_version + 1,
            'design_data' => $project->design_data,
            'panel_config' => $project->panel_config,
            'note' => $request->input('note'),
        ]);

        $project->increment('current_version');

        return response()->json($version, 201);
    }

    public function bom(Project $project)
    {
        return response()->json($this->bomService->build($project));
    }

    public function exportPdf(Project $project)
    {
        return $this->pdfService->generate($project)
            ->download('project-'.$project->id.'.pdf');
    }

    public function exportCsv(Project $project)
    {
        $csv = $this->bomService->toCsv($this->bomService->build($project));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="bom-'.$project->id.'.csv"',
        ]);
    }

    public function exportExcel(Project $project)
    {
        $bom = $this->bomService->build($project);

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BomExport($bom),
            'bom-'.$project->id.'.xlsx'
        );
    }

    private function validateProject(Request $request, bool $requireName = true): array
    {
        return $request->validate([
            'name' => ($requireName ? 'required' : 'sometimes').'|string|max:255',
            'description' => 'nullable|string',
            'client_name' => 'nullable|string|max:255',
            'panel_config' => 'nullable|array',
            'design_data' => ($requireName ? 'required' : 'sometimes').'|array',
            'is_template' => 'boolean',
        ]);
    }
}
