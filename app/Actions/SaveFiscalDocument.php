<?php

namespace App\Actions;

use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class SaveFiscalDocument
{
    public function handle(array $data, ?FiscalDocument $document = null): FiscalDocument
    {
        return DB::transaction(function () use ($data, $document): FiscalDocument {
            $document = $document === null
                ? new FiscalDocument
                : FiscalDocument::query()->lockForUpdate()->findOrFail($document->getKey());

            $document->fill(Arr::except($data, FiscalDocumentLink::TARGET_FIELDS))->save();

            $targets = array_replace(
                array_fill_keys(FiscalDocumentLink::TARGET_FIELDS, null),
                Arr::only($data, FiscalDocumentLink::TARGET_FIELDS),
            );

            if (Arr::whereNotNull($targets) !== []) {
                $document->links()->updateOrCreate([], $targets);
            } else {
                $document->links()->first()?->delete();
            }

            return $document;
        });
    }
}
