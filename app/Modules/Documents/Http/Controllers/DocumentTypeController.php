<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\DocumentType;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentTypeController extends Controller
{
    /** Everyone can read the catalogue (needed for the upload form). */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->integer('company_id') ?: null;
        $types = DocumentType::query()
            ->when($companyId && Tenancy::isGlobal(), fn ($q) => $q->withoutGlobalScope('company')->where('company_id', $companyId))
            ->when(! $request->boolean('all') || ! $request->user()->can('documents.manage'), fn ($q) => $q->where('is_active', true))
            ->withCount('documents')
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return response()->json(['data' => $types, 'groups' => DocumentType::GROUPS]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = Tenancy::companyIdForWrite($request->integer('company_id') ?: null);
        abort_unless($companyId, 422, 'Choose a company first.');
        $data = $this->validated($request, $companyId);
        $type = new DocumentType($data);
        $type->company_id = $companyId;
        $type->sort_order = $data['sort_order'] ?? (DocumentType::withoutGlobalScope('company')->where('company_id', $companyId)->max('sort_order') + 1);
        $type->save();

        return response()->json($type, 201);
    }

    public function update(Request $request, DocumentType $documentType): JsonResponse
    {
        $documentType->update($this->validated($request, $documentType->company_id, $documentType->id));

        return response()->json($documentType);
    }

    /** Deletes an unused type; types already holding documents are deactivated instead. */
    public function destroy(DocumentType $documentType): JsonResponse
    {
        if ($documentType->documents()->withTrashed()->exists()) {
            $documentType->update(['is_active' => false]);

            return response()->json(['message' => 'This type is in use, so it was deactivated instead of deleted.']);
        }
        $documentType->delete();

        return response()->json(['message' => 'Document type deleted.']);
    }

    private function validated(Request $request, int $companyId, ?int $ignore = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('document_types')->where('company_id', $companyId)->ignore($ignore)],
            'group' => ['required', Rule::in(DocumentType::GROUPS)],
            'requires_expiry' => ['boolean'],
            'is_required' => ['boolean'],
            'allow_self_upload' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
