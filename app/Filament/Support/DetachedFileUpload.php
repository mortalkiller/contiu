<?php

namespace App\Filament\Support;

use Filament\Forms\Components\FileUpload;

/**
 * An upload that is not a column of the record: the file is handed to whoever saves the form,
 * which stores it somewhere of its own (a supporting PDF, a document's PDF versions). It is never
 * stored by the field, never previewed and never refilled from the record.
 *
 * Filament's tamper check compares what the browser sends with the record's attribute named
 * after the field. There is no such attribute here, and reading it throws while models are
 * strict (the local environment), so the record has no path the browser may send back: only a
 * file uploaded in this visit is accepted.
 */
final class DetachedFileUpload extends FileUpload
{
    /** @return array<string> */
    public function getOriginalFilePaths(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeFiles(false)
            ->preventFilePathTampering()
            ->getUploadedFileUsing(null)
            ->previewable(false);
    }
}
