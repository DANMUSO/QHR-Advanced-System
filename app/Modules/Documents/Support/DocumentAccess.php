<?php

namespace App\Modules\Documents\Support;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Documents\Models\CompanyDocument;
use App\Modules\Documents\Models\EmployeeDocument;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorisation and private-file helpers for the Documents module.
 * Files live on the private `local` disk and are only ever streamed through authorised routes.
 */
class DocumentAccess
{
    public const DISK = 'local';

    public const MIMES = 'pdf,doc,docx,odt,jpg,jpeg,png';

    public const HR_MAX_KB = 51200;   // 50 MB

    public const SELF_MAX_KB = 10240; // 10 MB

    /** Content types that browsers can preview inline. */
    public const PREVIEWABLE = ['application/pdf', 'image/jpeg', 'image/png'];

    public static function manages(User $user): bool
    {
        return $user->can('documents.manage');
    }

    /** The user owns the record, or an earlier employment period of theirs (before a transfer or re-instatement). */
    public static function isOwner(User $user, Employee|int|null $employee): bool
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;

        return $id !== null && $user->employee !== null && in_array((int) $id, array_keys(static::lineage($user->employee)), true);
    }

    /**
     * The employee's own id plus every earlier employment period (previous_employee_id chain), mapped to company_id.
     * A personal file follows the person across transfers and re-instatements.
     *
     * @return array<int, int> employee id => company id
     */
    public static function lineage(Employee $employee): array
    {
        $ids = [(int) $employee->id => (int) $employee->company_id];
        $previous = $employee->previous_employee_id;
        for ($depth = 0; $previous && $depth < 15 && ! isset($ids[(int) $previous]); $depth++) {
            $row = Employee::withoutGlobalScopes()->whereKey($previous)->first(['id', 'company_id', 'previous_employee_id']);
            if (! $row) {
                break;
            }
            $ids[(int) $row->id] = (int) $row->company_id;
            $previous = $row->previous_employee_id;
        }

        return $ids;
    }

    /**
     * HR of the document's company (and super admins), HR of the company the person later moved to (non-confidential
     * files only), and the owner (unless confidential).
     */
    public static function canView(User $user, EmployeeDocument $doc): bool
    {
        if (static::manages($user) && static::inUsersCompany($doc)) {
            return true;
        }
        if (static::manages($user) && ! $doc->is_confidential && static::followedIntoUsersCompany($doc)) {
            return true;
        }

        return static::isOwner($user, $doc->employee_id) && ! $doc->is_confidential;
    }

    /** HR of the document's own company may change anything; owners only their own self-uploaded, non-confidential documents. */
    public static function canModify(User $user, EmployeeDocument $doc): bool
    {
        return (static::manages($user) && static::inUsersCompany($doc))
            || (static::isOwner($user, $doc->employee_id) && $doc->source === 'self' && ! $doc->is_confidential);
    }

    private static function inUsersCompany(EmployeeDocument $doc): bool
    {
        $companyId = Tenancy::companyId();

        return $companyId === null ? Tenancy::isGlobal() : (int) $doc->company_id === (int) $companyId;
    }

    /** The document's employee later transferred (or was re-instated) into the viewer's company. */
    private static function followedIntoUsersCompany(EmployeeDocument $doc): bool
    {
        $companyId = Tenancy::companyId();
        $id = $doc->employee_id;
        for ($depth = 0; $id && $companyId && $depth < 15; $depth++) {
            $next = Employee::withoutGlobalScopes()->where('previous_employee_id', $id)->first(['id', 'company_id']);
            if (! $next) {
                return false;
            }
            if ((int) $next->company_id === (int) $companyId) {
                return true;
            }
            $id = $next->id;
        }

        return false;
    }

    public static function canViewCompanyDocument(User $user, CompanyDocument $doc): bool
    {
        if (static::manages($user)) {
            return true;
        }

        return $user->employee !== null && $doc->isAddressedTo($user->employee);
    }

    /** Stores an upload privately and returns the file columns. */
    public static function store(UploadedFile $file, string $folder): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs($folder, Str::uuid().'.'.$ext, self::DISK);

        return [
            'file_path' => $path,
            'file_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
            'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
            'file_size' => $file->getSize() ?: 0,
        ];
    }

    /** Streams a private file as a download or inline preview. */
    public static function respond(string $path, string $name, ?string $mime, bool $inline): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($path), 404, 'The file is missing from storage.');
        $mime = $mime ?: 'application/octet-stream';
        $inline = $inline && in_array($mime, self::PREVIEWABLE, true);
        $headers = [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ];

        return $inline
            ? $disk->response($path, $name, $headers, 'inline')
            : $disk->download($path, $name, $headers);
    }

    /** Active users of a company holding a permission (for notifications). */
    public static function usersWithPermission(int $companyId, string $permission): Collection
    {
        return User::query()->where('company_id', $companyId)->where('is_active', true)->permission($permission)->get();
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, $i ? 1 : 0).' '.$units[$i];
    }
}
