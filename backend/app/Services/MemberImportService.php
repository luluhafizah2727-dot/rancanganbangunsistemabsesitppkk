<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\Member;
use App\Models\MemberImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class MemberImportService
{
    public function preview(UploadedFile $file, User $actor): array
    {
        $path = $file->store('member-imports');
        $rows = $this->read(Storage::path($path));
        [$valid, $errors] = $this->validateRows($rows);

        $import = MemberImport::query()->create([
            'created_by' => $actor->id,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'total_rows' => count($rows),
            'valid_rows' => count($valid),
            'failed_rows' => count($errors),
            'errors' => $errors,
        ]);

        return [
            'import' => $import,
            'preview' => array_slice($valid, 0, 20),
            'errors' => array_slice($errors, 0, 50),
        ];
    }

    public function confirm(MemberImport $import): array
    {
        abort_if($import->status !== 'previewed', 409, 'Import sudah diproses.');

        $rows = $this->read(Storage::path($import->path));
        [$valid, $errors] = $this->validateRows($rows);
        $created = 0;

        foreach (array_chunk($valid, 100) as $chunk) {
            DB::transaction(function () use ($chunk, &$created): void {
                foreach ($chunk as $row) {
                    if (User::query()->where('login_id', $row['member_number'])->exists()) {
                        continue;
                    }

                    $temporaryPassword = Str::password(14);
                    $user = User::query()->create([
                        'login_id' => $row['member_number'],
                        'name' => $row['name'],
                        'phone' => $row['phone'] ?: null,
                        'status' => UserStatus::Active,
                        'registration_source' => 'import',
                        'must_change_password' => true,
                        'approved_at' => now(),
                        'approved_by' => auth()->id(),
                        'password' => Hash::make($temporaryPassword),
                    ]);
                    $user->assignRole('member');
                    Member::query()->create([
                        'user_id' => $user->id,
                        'member_number' => $row['member_number'],
                        'position' => $row['position'] ?: null,
                        'department' => $row['department'] ?: null,
                    ]);
                    $created++;
                }
            });
        }

        $import->update([
            'status' => 'completed',
            'valid_rows' => $created,
            'failed_rows' => count($errors) + (count($valid) - $created),
            'confirmed_at' => now(),
        ]);

        Storage::delete($import->path);

        return ['created' => $created, 'failed' => $import->failed_rows];
    }

    private function read(string $path): array
    {
        $worksheet = IOFactory::load($path)->getActiveSheet();
        $raw = $worksheet->toArray(null, true, true, false);
        $rows = [];

        foreach (array_slice($raw, 1, 10000) as $index => $row) {
            if (collect($row)->filter(fn ($value) => $value !== null && $value !== '')->isEmpty()) {
                continue;
            }

            $rows[] = [
                'row' => $index + 2,
                'member_number' => $this->clean($row[0] ?? ''),
                'name' => $this->clean($row[1] ?? ''),
                'position' => $this->clean($row[2] ?? ''),
                'department' => $this->clean($row[3] ?? ''),
                'phone' => $this->clean($row[4] ?? ''),
            ];
        }

        return $rows;
    }

    private function validateRows(array $rows): array
    {
        $valid = [];
        $errors = [];
        $seen = [];

        foreach ($rows as $row) {
            $rowErrors = [];
            if ($row['member_number'] === '' || mb_strlen($row['member_number']) > 50) {
                $rowErrors[] = 'Nomor anggota wajib diisi dan maksimal 50 karakter.';
            }
            if ($row['name'] === '' || mb_strlen($row['name']) > 255) {
                $rowErrors[] = 'Nama wajib diisi dan maksimal 255 karakter.';
            }
            if (isset($seen[$row['member_number']]) || User::query()->where('login_id', $row['member_number'])->exists()) {
                $rowErrors[] = 'Nomor anggota duplikat.';
            }

            $seen[$row['member_number']] = true;
            if ($rowErrors === []) {
                $valid[] = $row;
            } else {
                $errors[] = ['row' => $row['row'], 'member_number' => $row['member_number'], 'errors' => $rowErrors];
            }
        }

        return [$valid, $errors];
    }

    private function clean(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^[=+\-@]/', $value) ? "'{$value}" : $value;
    }
}
