<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Backup;
use App\Modules\Organization\Models\DataImport;
use App\Modules\Organization\Services\BackupService;
use App\Modules\Organization\Services\DataImporter;
use App\Modules\Organization\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Bulk CSV import / export and company data backups (permission settings.manage). */
class DataController extends Controller
{
    // ---- Imports ---------------------------------------------------------------------------

    public function imports(): JsonResponse
    {
        $rows = DataImport::with('user:id,name', 'company:id,name')->latest()->limit(100)->get()
            ->map(fn (DataImport $i) => $i->toArray() + ['type_label' => DataImporter::TYPES[$i->type]['label'] ?? $i->type]);

        return response()->json([
            'data' => $rows,
            'types' => collect(DataImporter::TYPES)->map(fn ($t, $k) => ['value' => $k, 'label' => $t['label'], 'columns' => $t['columns']])->values(),
        ]);
    }

    public function import(Request $request, DataImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(DataImporter::TYPES))],
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'],
        ], [
            'file.mimes' => 'Upload a CSV file (save the template as "CSV UTF-8" in Excel).',
            'file.mimetypes' => 'Upload a CSV file (save the template as "CSV UTF-8" in Excel).',
            'file.max' => 'The file must not be larger than 5 MB.',
        ]);

        $import = new DataImport([
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'filename' => $request->file('file')->getClientOriginalName(),
            'status' => 'pending',
        ]);
        $import->company_id = CompanyContext::forWrite($request);
        $import->save();

        $importer->import($import, $request->file('file')->getRealPath());

        activity('organization')->causedBy($request->user())->performedOn($import)
            ->withProperties(['imported' => $import->imported, 'failed' => $import->failed])
            ->log("Bulk import of {$import->type}: {$import->imported} imported, {$import->failed} failed");

        $message = $import->status === 'failed'
            ? ($import->errors[0]['message'] ?? 'Import failed.')
            : "Imported {$import->imported} of {$import->total_rows} row(s)".($import->failed ? "; {$import->failed} row(s) need attention." : '.');

        return response()->json(['message' => $message, 'data' => $import->fresh()], $import->status === 'failed' ? 422 : 201);
    }

    public function template(string $type, DataImporter $importer): Response
    {
        abort_unless(isset(DataImporter::TYPES[$type]), 404);

        return $this->csvResponse($importer->template($type), "{$type}_template.csv");
    }

    public function export(Request $request, string $type, DataImporter $importer): Response
    {
        abort_unless(isset(DataImporter::TYPES[$type]), 404);
        $company = CompanyContext::current($request);
        activity('organization')->causedBy($request->user())->performedOn($company)->log("Exported {$type} (CSV)");

        return $this->csvResponse($importer->export($type, $company->id), $type.'-'.now()->format('Ymd').'.csv');
    }

    // ---- Backups ---------------------------------------------------------------------------

    public function backups(): JsonResponse
    {
        return response()->json(['data' => Backup::with('user:id,name', 'company:id,name')->latest()->limit(50)->get()]);
    }

    public function createBackup(Request $request, BackupService $service): JsonResponse
    {
        $company = CompanyContext::current($request);
        $backup = $service->create($company, $request->user()->id);
        activity('security')->causedBy($request->user())->performedOn($company)->log("Data backup {$backup->filename} created ({$backup->status})");

        return response()->json(
            ['message' => $backup->status === 'completed' ? "Backup created ({$backup->size_human}, {$backup->records} records)." : 'Backup failed: '.$backup->error, 'data' => $backup],
            $backup->status === 'completed' ? 201 : 500
        );
    }

    public function downloadBackup(Request $request, int $id): StreamedResponse
    {
        $backup = Backup::findOrFail($id);
        abort_unless($backup->status === 'completed' && $backup->path && Storage::disk('local')->exists($backup->path), 404, 'Backup file not found.');
        activity('security')->causedBy($request->user())->performedOn($backup)->log("Data backup {$backup->filename} downloaded");

        return Storage::disk('local')->download($backup->path, $backup->filename, ['Content-Type' => 'application/gzip']);
    }

    public function destroyBackup(Request $request, int $id): JsonResponse
    {
        $backup = Backup::findOrFail($id);
        if ($backup->path) {
            Storage::disk('local')->delete($backup->path);
        }
        $backup->delete();
        activity('security')->causedBy($request->user())->performedOn($backup)->log("Data backup {$backup->filename} deleted");

        return response()->json(['message' => 'Backup deleted.']);
    }

    private function csvResponse(string $csv, string $filename): Response
    {
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
