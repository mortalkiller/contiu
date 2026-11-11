<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array ADDED = [
        'client_id', 'series', 'due_date', 'discount_amount', 'withholding_basis_points', 'withholding_amount',
        'vat_exemption_reason', 'client_reference', 'notes', 'reason', 'receivable_effect', 'payment', 'transport', 'issued_at', 'accepted_at',
    ];

    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            // A draft has no number until the billing provider issues it.
            $table->string('number')->nullable()->change();
            $table->foreignId('client_id')->nullable()->after('party_tax_id')->constrained()->nullOnDelete();
            $table->string('series', 32)->nullable()->after('number');
            $table->date('due_date')->nullable()->after('operation_date');
            $table->bigInteger('discount_amount')->default(0)->after('currency');
            $table->unsignedSmallInteger('withholding_basis_points')->default(0)->after('total_amount');
            $table->bigInteger('withholding_amount')->default(0)->after('withholding_basis_points');
            $table->string('vat_exemption_reason', 8)->nullable()->after('withholding_amount');
            $table->string('client_reference', 64)->nullable();
            $table->text('notes')->nullable();
            $table->string('reason')->nullable();
            // What issuing a credit or debit note does to what the client owes.
            $table->string('receivable_effect', 16)->nullable();
            $table->json('payment')->nullable();
            $table->json('transport')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('accepted_at')->nullable();

            $table->index(['account_id', 'status', 'document_date'], 'fiscal_documents_status_date_index');
        });

        Schema::create('fiscal_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('fiscal_document_id');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedBigInteger('article_id')->nullable();
            $table->string('description');
            // Thousandths, so 1.5 hours is 1500.
            $table->unsignedBigInteger('quantity');
            $table->string('unit', 16)->default('un');
            $table->bigInteger('unit_price');
            $table->unsignedSmallInteger('discount_basis_points')->default(0);
            $table->unsignedSmallInteger('vat_basis_points')->default(0);
            $table->bigInteger('net_amount');
            $table->bigInteger('vat_amount');
            $table->bigInteger('total_amount');
            $table->timestamps();

            $table->index(['account_id', 'fiscal_document_id', 'sort'], 'fiscal_lines_document_index');
            $table->foreign(['account_id', 'fiscal_document_id'], 'fiscal_lines_document_foreign')
                ->references(['account_id', 'id'])->on('fiscal_documents')->cascadeOnDelete();
            $table->foreign(['account_id', 'article_id'], 'fiscal_lines_article_foreign')
                ->references(['account_id', 'id'])->on('articles')->nullOnDelete();
        });

        // Where a stored PDF came from: uploaded by hand or fetched from the invoicing provider.
        Schema::table('fiscal_document_pdfs', fn (Blueprint $table) => $table->string('source', 16)->default('upload')->after('user_id'));

        // A sale invoiced without a charge to collect is tied to its invoice, whoever issued it.
        Schema::table('direct_invoice_sales', function (Blueprint $table): void {
            $table->unsignedBigInteger('issue_attempt_id')->nullable()->change();
            $table->unsignedBigInteger('fiscal_document_id')->nullable()->after('issue_attempt_id');
            $table->unique(['account_id', 'fiscal_document_id'], 'direct_invoice_sales_document_unique');
        });
    }

    public function down(): void
    {
        Schema::table('direct_invoice_sales', function (Blueprint $table): void {
            $table->dropUnique('direct_invoice_sales_document_unique');
            $table->dropColumn('fiscal_document_id');
        });

        Schema::table('fiscal_document_pdfs', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::dropIfExists('fiscal_document_lines');

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropIndex('fiscal_documents_status_date_index');
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(array_values(array_diff(self::ADDED, ['client_id'])));
        });
    }
};
