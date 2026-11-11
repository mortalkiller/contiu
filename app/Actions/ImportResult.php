<?php

namespace App\Actions;

/**
 * What an import created (or would create, in a dry run), plus anything worth a second look.
 */
final class ImportResult
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $warnings
     * @param  list<string>  $info
     */
    public function __construct(
        public array $counts = [],
        public array $warnings = [],
        public array $info = [],
        public int $contracted = 0, // cents
        public int $received = 0,
        public int $expenses = 0,
        public bool $dryRun = false,
    ) {}

    /** Translation keys for the counts, in display order. */
    public const LABELS = [
        'clients' => 'Clients',
        'projects' => 'Projects',
        'retainers' => 'Retainers',
        'receivables' => 'Amounts receivable',
        'payments' => 'Payments received',
        'suppliers' => 'Suppliers',
        'expenses' => 'Expenses',
        'recurring_expenses' => 'Recurring expenses',
        'categories' => 'Categories',
    ];

    /** Livewire-friendly shape (the import page keeps the preview between requests). */
    public function toArray(): array
    {
        return [
            'counts' => $this->counts,
            'warnings' => $this->warnings,
            'info' => $this->info,
            'contracted' => $this->contracted,
            'received' => $this->received,
            'expenses' => $this->expenses,
            'dryRun' => $this->dryRun,
        ];
    }
}
