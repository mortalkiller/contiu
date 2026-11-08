# TODO

## Stripe — antes de abrir o registo

A tua conta está isenta de pagamento e não usa a Stripe. Isto só é preciso quando abrires o
registo a outros freelancers (`CONTIU_REGISTRATION_OPEN=true`).

Faz tudo primeiro em **modo de teste** e depois repete em **produção**. Os IDs e as chaves são
diferentes em cada modo.

### 1. Conta

- [ ] Na Stripe: menu da conta (canto superior esquerdo) → **Criar** → **Conta**. Nome
      "Contiu", país Portugal, moeda EUR.
- [ ] Ativar a conta para produção: NIF, IBAN e identificação.
- [ ] Em **Definições → Dados públicos**: nome "Contiu", email de suporte e descritor do extrato
      bancário (ex.: `CONTIU`).

### 2. Produto e preços

- [ ] **Catálogo de produtos → Adicionar produto**: nome "Contiu".
- [ ] Preço **Mensal**: 5,00 EUR, recorrente, todos os meses.
- [ ] Preço **Anual**: 54,00 EUR, recorrente, todos os anos.
- [ ] Copiar o ID de cada preço (`price_…`).

Os valores têm de bater com `AccountSubscription::PLANS` (500 e 5400 cêntimos, sem IVA).

### 3. Faturação

- [ ] **Definições → Billing → Portal do cliente**: ativar. Permitir mudar o cartão, ver as
      faturas e cancelar.
- [ ] **Definições → Billing → Subscrições**: limitar a uma subscrição por cliente.
- [ ] Recibos por email aos clientes: ligados ou desligados, conforme preferires (as faturas
      emites tu, a partir do email "Pagamento recebido").

### 4. Webhook

- [ ] **Programadores → Webhooks → Adicionar destino**:
      `https://<o-teu-domínio>/stripe/webhook`
- [ ] Eventos:
  - `customer.subscription.created`
  - `customer.subscription.updated`
  - `customer.subscription.deleted`
  - `customer.updated`
  - `customer.deleted`
  - `invoice.paid`
  - `invoice.payment_action_required`
- [ ] Copiar o **segredo de assinatura** (`whsec_…`).

### 5. `.env` do servidor

```
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_MONTHLY=price_...
STRIPE_PRICE_YEARLY=price_...
```

Depois:

```bash
docker exec -u www-data -w /var/www/contiu php-fpm php artisan optimize
```

### 6. Testar (com as chaves de teste, na tua máquina)

- [ ] Pôr as chaves e os preços **de teste** no `.env` local e correr
      `stripe listen --forward-to localhost:8000/stripe/webhook`. Usar o `whsec_…` que ele mostra.
- [ ] Registar uma conta nova (com `CONTIU_REGISTRATION_OPEN=true` local) e ir a **Definições →
      Assinatura** → **Subscrever mensal**.
- [ ] Pagar com o cartão `4242 4242 4242 4242` (qualquer data futura e qualquer CVC).
- [ ] Confirmar: a conta passa a "Mensal", chega o email "Pagamento recebido" e o pagamento
      aparece em **Definições → Contas** → a conta.
- [ ] Cancelar no portal da Stripe e confirmar que a conta fica só de leitura quando o período
      acaba.

### 7. Abrir o registo

- [ ] Rever o texto dos Termos (`/termos`) e da Privacidade (`/privacidade`).
- [ ] `CONTIU_REGISTRATION_OPEN=true` no `.env` do servidor e correr o `optimize`.
- [ ] Na Stripe (produção), enviar um evento de teste para o webhook e confirmar que responde 200.
