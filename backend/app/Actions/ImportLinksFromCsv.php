<?php

namespace App\Actions;

use App\Billing\Entitlements;
use App\Billing\LimitedResource;
use App\Billing\PlanLimitReached;
use App\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class ImportLinksFromCsv
{
    /**
     * Rows past this are ignored rather than silently truncating a much
     * larger file - the caller is told how many were skipped and why.
     */
    public const MAX_ROWS = 1000;

    /**
     * `plan_limit` is true when the import stopped because the workspace's plan
     * is full - the rows after that point were not even tried.
     *
     * @return array{imported: int, skipped: array<int, array{row: int, reason: string}>, plan_limit: bool}
     */
    public function handle(Site $site, UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return ['imported' => 0, 'skipped' => [['row' => 0, 'reason' => 'Не вдалося прочитати файл.']], 'plan_limit' => false];
        }

        $header = fgetcsv($handle);
        $columns = $this->resolveColumns($header);

        if ($columns === null) {
            fclose($handle);

            return [
                'imported' => 0,
                'skipped' => [['row' => 1, 'reason' => 'У першому рядку має бути заголовок зі стовпцем target_url.']],
                'plan_limit' => false,
            ];
        }

        $entitlements = Entitlements::for($site->workspace);
        $imported = 0;
        $skipped = [];
        $planLimit = false;
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($rowNumber - 1 > self::MAX_ROWS) {
                $skipped[] = ['row' => $rowNumber, 'reason' => 'Перевищено ліміт у '.self::MAX_ROWS.' рядків.'];
                break;
            }

            if ($this->isBlank($row)) {
                continue;
            }

            $targetUrl = trim((string) ($row[$columns['target_url']] ?? ''));
            $shortCode = $columns['short_code'] !== null
                ? trim((string) ($row[$columns['short_code']] ?? ''))
                : '';

            $validator = Validator::make(
                ['target_url' => $targetUrl, 'short_code' => $shortCode ?: null],
                [
                    'target_url' => ['required', 'url', 'max:2048'],
                    // Rows are inserted as they're read, so this also catches
                    // a code repeated later in the same file.
                    'short_code' => ['nullable', 'string', 'max:64', 'alpha_dash', 'unique:links,short_code'],
                ],
                [
                    'target_url.required' => 'Порожній URL.',
                    'target_url.url' => 'Не схоже на коректний URL.',
                    'target_url.max' => 'URL задовгий.',
                    'short_code.alpha_dash' => 'У короткому коді лише латиниця, цифри, "-" та "_".',
                    'short_code.max' => 'Короткий код задовгий.',
                    'short_code.unique' => 'Такий короткий код уже зайнятий.',
                ]
            );

            if ($validator->fails()) {
                $skipped[] = ['row' => $rowNumber, 'reason' => $validator->errors()->first()];

                continue;
            }

            try {
                $entitlements->within(LimitedResource::Links, fn () => $site->links()->create([
                    'target_url' => $targetUrl,
                    'short_code' => $shortCode ?: null,
                ]));
            } catch (PlanLimitReached $full) {
                // Everything after this row would meet the same wall.
                $skipped[] = ['row' => $rowNumber, 'reason' => $full->getMessage()];
                $planLimit = true;
                break;
            }

            $imported++;
        }

        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped, 'plan_limit' => $planLimit];
    }

    /**
     * @param  array<int, string>|false  $header
     * @return array{target_url: int, short_code: int|null}|null
     */
    private function resolveColumns(array|false $header): ?array
    {
        if ($header === false) {
            return null;
        }

        $normalized = array_map(
            fn ($value) => strtolower(trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF")),
            $header
        );

        $targetIndex = array_search('target_url', $normalized, true);

        if ($targetIndex === false) {
            return null;
        }

        $codeIndex = array_search('short_code', $normalized, true);

        return [
            'target_url' => $targetIndex,
            'short_code' => $codeIndex === false ? null : $codeIndex,
        ];
    }

    /** @param array<int, string|null> $row */
    private function isBlank(array $row): bool
    {
        return count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0;
    }
}
