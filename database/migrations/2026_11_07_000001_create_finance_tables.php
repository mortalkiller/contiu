<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('vat_number')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('vat_number')->nullable();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // CategoryType
            $table->string('name');
            $table->string('color')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['type', 'name']);
        });

        // Trabalhos: one-off jobs (orçamentos aprovados).
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('business_line')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('quoted_on')->nullable();
            $table->decimal('net_amount', 12, 2);
            $table->decimal('vat_rate', 5, 4)->default(0);
            $table->string('status'); // ProjectStatus
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Avenças: recurring charges that produce one receivable per period.
        Schema::create('retainers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('business_line')->nullable();
            $table->string('title');
            $table->decimal('net_amount', 12, 2);
            $table->decimal('vat_rate', 5, 4)->default(0);
            $table->string('frequency'); // Frequency
            $table->unsignedTinyInteger('due_day')->default(1);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Valores a receber: a project instalment or a retainer period.
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->date('due_on');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Recebimentos.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receivable_id')->constrained()->restrictOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 12, 2);
            $table->string('method'); // PaymentMethod
            $table->string('invoice_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Despesas recorrentes (templates).
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind'); // ExpenseKind
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('retainer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('net_amount', 12, 2);
            $table->decimal('vat_rate', 5, 4)->default(0);
            $table->string('frequency'); // Frequency
            $table->unsignedTinyInteger('day')->default(1);
            $table->string('method'); // PaymentMethod
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind'); // ExpenseKind
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('retainer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recurring_expense_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->date('spent_on');
            $table->decimal('net_amount', 12, 2);
            $table->decimal('vat_rate', 5, 4)->default(0);
            $table->boolean('is_paid')->default(true);
            $table->date('paid_on')->nullable();
            $table->string('method')->nullable(); // PaymentMethod
            $table->string('invoice_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('recurring_expenses');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('receivables');
        Schema::dropIfExists('retainers');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('clients');
    }
};
