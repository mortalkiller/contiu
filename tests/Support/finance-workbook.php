<?php

use App\Actions\ImportFinanceWorkbook;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes a small workbook with the same sheets and columns as Pedro's
 * controlo_financeiro_receitas_despesas.xlsx (synthetic data, not his file).
 */
function financeWorkbook(): string
{
    $date = fn (?string $value) => $value === null ? null : ExcelDate::PHPToExcel(new DateTime($value));

    $sheets = [
        'Receitas' => [
            ['ID Receita/Projeto', 'Cliente', 'Descrição', 'Categoria', 'Data orçamento', 'Valor contratado sem IVA', 'IVA %', 'Total a receber', 'Data limite pagamento', 'Status', 'Valor recebido', 'Valor em aberto', '% recebido', 'Estado pagamento', 'Observações'],
            ['PRINTEE OC-1', 'Cliente A', 'Plataforma', 'Desenvolvimento', $date('2026-03-04'), 2400, 0, 2400, $date('2026-08-15'), 'Entregue'],
            ['PRESSIU OC-2', 'Cliente A', "Catálogo\nIntegrações com Moloni", 'Desenvolvimento', $date('2026-11-01'), 1965, 0, 1965, $date('2027-01-31'), 'Em execução'],
            ['PRINTEE OC-3', 'Cliente B', 'Proposta recusada', 'Desenvolvimento', $date('2026-02-01'), 500, 0.23, 615, $date('2026-03-01'), 'Cancelado'],
            ['SITE1', 'Cliente B', 'Alojamento', 'Manutenção', $date('2026-01-01'), 20, 0, 20, $date('2026-01-01'), 'Entregue'],
            ['SITE2', 'Cliente B', 'Alojamento', 'Manutenção', $date('2026-02-01'), 20, 0, 20, $date('2026-02-01'), 'Entregue'],
            ['SITE3', 'Cliente B', 'Alojamento', 'Manutenção', $date('2026-03-01'), 20, 0, 20, $date('2026-02-28'), 'Entregue'],
            ['INFRA2026', 'Cliente A', 'Infraestrutura anual', 'Manutenção', $date('2026-06-01'), 255.5, 0, 255.5, $date('2026-08-28'), 'Faturado'],
            [null, null, null, null, null, null, null, null, null, null, 0, 0],
        ],
        'Pagamentos' => [
            ['Data pagamento', 'ID Receita/Projeto', 'Cliente', 'Descrição', 'Método', 'Valor recebido', 'Referência/Fatura', 'Observações'],
            [$date('2026-03-31'), 'PRINTEE OC-1', 'Cliente A', 'Plataforma', 'MB Way', 750, null, 'Pagamento parcial.'],
            [$date('2026-07-20'), 'PRINTEE OC-1', 'Cliente A', 'Plataforma', 'Dinheiro', 1650, null, 'Pagamento final'],
            [$date('2026-01-01'), 'SITE1', 'Cliente B', 'Alojamento', 'MB Way', 20],
            [$date('2026-02-03'), 'SITE2', 'Cliente B', 'Alojamento', 'MB Way', 20],
            [$date('2026-08-03'), 'INFRA2026', 'Cliente A', 'Infraestrutura anual', 'Transferência', 255.5, 'FT 1'],
            [$date('2026-05-01'), 'NAO-EXISTE', null, null, 'MB Way', 10],
        ],
        'Despesas' => [
            ['Data despesa', 'Fornecedor', 'Categoria', 'Descrição', 'Valor sem IVA', 'IVA %', 'Total despesa', 'Pago?', 'Método', 'ID Receita/Projeto', 'Observações'],
            [$date('2026-07-01'), 'OVH', 'Infraestrutura', 'Servidor', 99.2, 0.23, 122.016, 'Sim', 'Cartão', 'INFRA2026'],
            [$date('2026-04-03'), 'OpenAI', 'ChatGPT', 'ChatGPT Plus', 18.7, 0.23, 23.001, 'Sim', 'Cartão', 'PRINTEE OC-1'],
            [$date('2026-10-06'), 'INPI', 'Outro', 'Registo de marca', 151.98, 0, 151.98, 'Não', 'Transferência'],
        ],
        'Listas' => [
            ['Clientes', 'Categorias Receita', 'Categorias Despesa', 'Métodos Pagamento', 'Status Receita', 'Pago?'],
            ['Cliente A', 'Desenvolvimento', 'Infraestrutura', 'Transferência', 'Orçamentado', 'Sim'],
            ['Cliente B', 'Manutenção', 'ChatGPT', 'MB Way', 'Aprovado', 'Não'],
            [null, 'Consultoria', 'Outro', 'Cartão', 'Em execução'],
        ],
    ];

    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);

    foreach ($sheets as $name => $rows) {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($name);
        $sheet->fromArray($rows, null, 'A1', true);
    }

    $path = tempnam(sys_get_temp_dir(), 'finance').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function importFixture(bool $replace = false, bool $dryRun = false)
{
    return app(ImportFinanceWorkbook::class)->run(financeWorkbook(), replace: $replace, dryRun: $dryRun);
}
