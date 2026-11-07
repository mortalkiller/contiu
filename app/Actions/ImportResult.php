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
        public float $contracted = 0,
        public float $received = 0,
        public float $expenses = 0,
        public bool $dryRun = false,
    ) {}

    /** Portuguese labels for the counts, in display order. */
    public const LABELS = [
        'clients' => 'Clientes',
        'projects' => 'Trabalhos',
        'retainers' => 'Avenças',
        'receivables' => 'Valores a receber',
        'payments' => 'Recebimentos',
        'suppliers' => 'Fornecedores',
        'expenses' => 'Despesas',
        'recurring_expenses' => 'Despesas recorrentes',
        'categories' => 'Categorias',
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
