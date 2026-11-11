<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops what was specific to one invoicing provider. Connections, remote ids and the log of what
 * was asked of a provider now live in the generic integration tables, and drafts are fiscal
 * documents like any other.
 */
return new class extends Migration
{
    private const array TABLES = [
        'invoice_xpress_receipt_attempts', 'invoice_xpress_note_attempts', 'invoice_xpress_issue_attempts',
        'invoice_xpress_snapshots', 'invoice_xpress_configs', 'fiscal_document_drafts',
    ];

    public function up(): void
    {
        Schema::table('fiscal_document_pdfs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_xpress_snapshot_id');
        });

        Schema::table('direct_invoice_sales', function (Blueprint $table): void {
            $table->dropUnique(['account_id', 'issue_attempt_id']);
            $table->dropForeign(['issue_attempt_id']);
            $table->dropColumn('issue_attempt_id');
        });

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** The dropped tables held nothing the generic ones do not: there is nothing to restore. */
    public function down(): void {}
};
