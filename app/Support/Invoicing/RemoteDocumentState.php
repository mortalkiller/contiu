<?php

namespace App\Support\Invoicing;

/** Where a document stands at the provider, whatever the provider calls it. */
enum RemoteDocumentState: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Canceled = 'canceled';
}
