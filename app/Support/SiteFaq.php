<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The questions and answers of the public site, page by page: shown in the page and given to
 * search engines as its FAQPage (App\Support\SiteSeo), so both always say the same.
 */
final class SiteFaq
{
    /**
     * The questions of the help page shown first, under «Mais procuradas».
     *
     * @var list<string>
     */
    private const POPULAR = [
        'O Contiu é para mim?',
        'Os valores são oficiais?',
        'Posso emitir faturas pelo Contiu?',
        'Quanto custa?',
        'Posso cancelar quando quiser?',
    ];

    /**
     * @return list<array{id: string, group: ?string, question: string, answer: string, open: bool}>
     */
    public static function for(string $page): array
    {
        $trialDays = (int) config('contiu.trial_days');
        $prices = PublicSite::planPrices();
        $company = (string) config('contiu.company.name');

        $faq = match ($page) {
            'home' => [
                [null, 'Porquê escolher o Contiu?', 'Porque junta num só sítio o que recebes, o que gastas e os impostos que daí resultam, feito à medida de quem trabalha por conta própria em Portugal.'],
                [null, 'O Contiu substitui um contabilista?', 'Não. O Contiu organiza as tuas contas e estima os teus impostos. Se quiseres alguém que entregue as declarações por ti, podes juntar um contabilista certificado à conta.'],
                [null, 'E se eu quiser só experimentar?', "Tens {$trialDays} dias grátis para ver se o Contiu é mesmo o que procuras."],
                [null, 'Posso emitir faturas pelo Contiu?', 'Sim, ligando a tua conta do InvoiceXpress. O documento é emitido e certificado lá e fica guardado no Contiu.'],
                [null, 'Posso cancelar a subscrição a qualquer momento?', 'Sim. Cancelas na tua conta e manténs o acesso até ao fim do período que já pagaste.'],
            ],
            'pricing' => [
                [null, "O que acontece no fim dos {$trialDays} dias?", 'Escolhes o plano mensal ou o anual para continuar. Se não escolheres nenhum, a conta fica em pausa e os teus dados ficam guardados.'],
                [null, 'Os preços incluem IVA?', 'Sim. Todos os preços do site já incluem o IVA à taxa legal em vigor.'],
                [null, 'Como pago?', 'Com cartão, de forma segura, através da Stripe.'],
                [null, 'Posso mudar de mensal para anual?', 'Sim, a qualquer momento, na página da subscrição da tua conta.'],
                [null, 'Posso cancelar quando quiser?', 'Sim. Manténs o acesso até ao fim do período que já pagaste.'],
            ],
            'accountant' => [
                [null, 'Sou obrigado a ter contabilista?', 'No regime simplificado, não. Com contabilidade organizada, sim.', true],
                [null, 'Posso deixar o serviço mais tarde?', 'Sim. Cancelas o serviço na tua subscrição e continuas com o teu plano do Contiu.'],
                [null, 'O contabilista vê todos os meus dados?', 'Vê os dados da tua conta de que precisa para fazer as tuas declarações.'],
            ],
            'help' => [
                ['O Contiu', 'O Contiu é para mim?', 'É para quem trabalha por conta própria em Portugal: recibos verdes, no regime simplificado ou com contabilidade organizada, com ou sem um emprego por conta de outrem ao mesmo tempo.', true],
                ['O Contiu', 'O Contiu substitui um contabilista?', 'Não. O Contiu organiza as tuas contas e estima os teus impostos. Se quiseres alguém que entregue as declarações por ti, podes juntar um contabilista certificado à conta.'],
                ['O Contiu', 'É difícil de configurar?', 'Não. No registo respondes a algumas perguntas sobre a tua atividade e o Contiu monta o teu perfil fiscal. Podes mudá-lo mais tarde nas definições.'],
                ['Impostos', 'Como chega aos valores dos impostos?', 'A partir do que registas e do teu perfil fiscal, com as regras em vigor. Cada valor mostra as contas que lhe deram origem.', true],
                ['Impostos', 'Os valores são oficiais?', 'Não, são estimativas. O valor oficial é sempre o da Autoridade Tributária e o da Segurança Social.'],
                ['Impostos', 'O Contiu entrega as minhas declarações?', 'Não. O Contiu avisa-te dos prazos e mostra-te os valores. A entrega é feita por ti ou pelo teu contabilista.'],
                ['Faturação', 'Posso emitir faturas pelo Contiu?', 'Sim, ligando a tua conta do InvoiceXpress. O documento é emitido e certificado lá e fica guardado no Contiu.', true],
                ['Faturação', 'E se eu não tiver programa de faturação?', 'Podes registar à mão os documentos que emites noutro lado, por exemplo no Portal das Finanças.'],
                ['Conta e pagamentos', 'Quanto custa?', "{$prices['monthly']['with_vat']} por mês ou {$prices['yearly']['with_vat']} por ano, IVA incluído, depois de {$trialDays} dias grátis.", true],
                ['Conta e pagamentos', 'Posso cancelar quando quiser?', 'Sim. Cancelas na tua conta e manténs o acesso até ao fim do período que já pagaste.'],
                ['Conta e pagamentos', 'Quem está por trás do Contiu?', "O Contiu é um serviço da {$company}, uma empresa portuguesa de desenvolvimento de software."],
            ],
            default => [],
        };

        return array_map(fn (array $item): array => [
            'id' => Str::slug($item[1]),
            'group' => $item[0],
            'question' => $item[1],
            'answer' => $item[2],
            'open' => $item[3] ?? false,
        ], $faq);
    }

    /**
     * The most asked questions of the help page, in the order of POPULAR.
     *
     * @return list<array{id: string, group: ?string, question: string, answer: string, open: bool}>
     */
    public static function popular(): array
    {
        $help = collect(self::for('help'))->keyBy('question');

        return array_values(array_filter(array_map(fn (string $question): ?array => $help->get($question), self::POPULAR)));
    }
}
