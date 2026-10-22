<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Modules\Documents\Models\CompanyDocument;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Models\EmployeeDocument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DocumentsDataSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $this->seedDocumentTypes($company);
            $this->seedCompanyDocuments($company);
            $this->seedEmployeeDocuments($company);
        });
    }

    private function seedDocumentTypes(Company $company): void
    {
        $types = [
            ['National ID',              'personal',     true,  true],
            ['KRA PIN Certificate',      'personal',     false, true],
            ['NSSF Card',                'personal',     false, true],
            ['SHIF Card',                'personal',     false, true],
            ['Academic Certificate',     'academic',     false, false],
            ['Professional Certificate', 'professional', true,  false],
            ['Driving Licence',          'personal',     true,  false],
            ['Passport',                 'personal',     true,  false],
            ['Contract Letter',          'office',       false, true],
            ['Appointment Letter',       'office',       false, true],
            ['Good Conduct Certificate', 'personal',     true,  false],
        ];

        foreach ($types as [$name, $group, $requiresExpiry, $isRequired]) {
            DocumentType::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'group'            => $group,
                    'requires_expiry'  => $requiresExpiry,
                    'is_required'      => $isRequired,
                    'allow_self_upload'=> true,
                    'is_active'        => true,
                ]
            );
        }
    }

    private function seedCompanyDocuments(Company $company): void
    {
        $docs = [
            ['policy',     'HR Policy Manual 2024',              'Comprehensive HR policies and procedures for all staff.'],
            ['policy',     'Code of Conduct',                    'Expected standards of behaviour for all employees.'],
            ['policy',     'Leave Policy',                       'Guidelines on leave entitlements and application procedures.'],
            ['policy',     'IT Acceptable Use Policy',           'Rules governing use of company IT resources.'],
            ['memo',       'Q4 Performance Review Notice',       'All staff are required to complete self-assessments by end of month.'],
            ['memo',       'Public Holiday Notice – Jamhuri Day','The office will be closed on 12th December.'],
            ['newsletter', 'Staff Newsletter – November 2024',   'Monthly updates, achievements, and upcoming events.'],
            ['newsletter', 'Staff Newsletter – October 2024',    'Monthly updates, achievements, and upcoming events.'],
        ];

        foreach ($docs as [$category, $title, $description]) {
            if (CompanyDocument::withoutGlobalScopes()->where('company_id', $company->id)->where('title', $title)->exists()) {
                continue;
            }

            // Create a placeholder file path (no actual file needed for demo)
            $path = "company_documents/{$company->id}/" . str()->slug($title) . '.pdf';

            CompanyDocument::create([
                'company_id'              => $company->id,
                'category'                => $category,
                'title'                   => $title,
                'description'             => $description,
                'effective_date'          => now()->subDays(rand(10, 180))->toDateString(),
                'audience'                => 'all',
                'requires_acknowledgement'=> $category === 'policy',
                'file_path'               => $path,
                'file_name'               => str()->slug($title) . '.pdf',
                'mime_type'               => 'application/pdf',
                'file_size'               => rand(50000, 500000),
                'version'                 => 1,
            ]);
        }
    }

    private function seedEmployeeDocuments(Company $company): void
    {
        $types = DocumentType::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->get();

        if ($types->isEmpty()) return;

        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->get();

        foreach ($employees as $employee) {
            // Each employee gets 2-5 documents
            $selectedTypes = $types->random(min($types->count(), rand(2, 5)));

            foreach ($selectedTypes as $type) {
                if (EmployeeDocument::withoutGlobalScopes()
                    ->where('employee_id', $employee->id)
                    ->where('document_type_id', $type->id)
                    ->exists()) {
                    continue;
                }

                $issueDate  = now()->subDays(rand(30, 1000))->toDateString();
                $expiryDate = $type->requires_expiry
                    ? now()->addDays(rand(-30, 730))->toDateString() // some expired, some expiring soon, some valid
                    : null;

                $path = "employee_documents/{$company->id}/{$employee->id}/" . str()->slug($type->name) . '.pdf';

                EmployeeDocument::create([
                    'company_id'       => $company->id,
                    'employee_id'      => $employee->id,
                    'document_type_id' => $type->id,
                    'title'            => $type->name,
                    'source'           => collect(['hr', 'self'])->random(),
                    'issue_date'       => $issueDate,
                    'expiry_date'      => $expiryDate,
                    'is_confidential'  => false,
                    'file_path'        => $path,
                    'file_name'        => str()->slug($type->name) . '.pdf',
                    'mime_type'        => 'application/pdf',
                    'file_size'        => rand(50000, 300000),
                    'version'          => 1,
                ]);
            }
        }
    }
}
