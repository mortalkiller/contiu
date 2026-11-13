<?php

namespace App\Jobs;

use App\Modules\InvoiceReader\Benchmark\Jobs\ProcessInvoiceBenchmark as InvoiceReaderJob;

/**
 * Allows jobs queued before the InvoiceReader module move to finish.
 */
class ProcessInvoiceBenchmark extends InvoiceReaderJob {}
