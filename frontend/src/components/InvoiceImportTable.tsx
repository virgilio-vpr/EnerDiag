import reportTexts from '../../../backend/resources/report-texts.json'

export type ImportedInvoiceItem = {
  id: number
  item_type: string
  time_slot: string
  source_label: string | null
  quantity: string | null
  unit: string | null
  unit_price_taxed: string | null
  tariff_rate: string | null
  amount: string
  pis_cofins_amount: string
  icms_base: string
  icms_rate: string
  icms_amount: string
}

export type ImportedInvoice = {
  id: number
  reference_month: string
  consumer_unit: string
  source_filename?: string | null
  total_paid: string
  warnings: string[]
  items: ImportedInvoiceItem[]
}

const warningMessages: Record<string, string> = {
  idle_demand_post_matching_unresolved: 'Há demanda ociosa cujo posto precisa ser conferido.',
  invoice_billing_total_mismatch: 'A soma dos itens lidos difere do total. Revise esta fatura antes de simular.',
}

function decimal(value: string | null, places = 2) {
  return value === null ? '—' : Number(value).toLocaleString('pt-BR', { minimumFractionDigits: places, maximumFractionDigits: places })
}

function monthLabel(value: string) {
  return `${value.slice(5, 7)}/${value.slice(0, 4)}`
}

function slotLabel(slot: string) {
  return ({ peak: 'Ponta', off_peak: 'Fora de ponta', unique: 'Único', not_applicable: '—', late_night_weekend: 'Madrugada / fim de semana' } as Record<string, string>)[slot] ?? 'A conferir'
}

export function InvoiceImportTable({ invoices, onRemove, disabled = false }: { invoices: ImportedInvoice[]; onRemove?: (id: number) => void; disabled?: boolean }) {
  const ordered = [...invoices].sort((left, right) => left.reference_month.localeCompare(right.reference_month)).map((invoice) => ({ ...invoice, warnings: invoice.warnings.filter((warning) => !reportTexts.internal_warnings.includes(warning)) }))
  if (ordered.length === 0) return <p>Nenhuma fatura salva. Selecione os PDFs para começar a leitura.</p>

  return <section className="invoice-review" aria-labelledby="invoice-review-title">
    <h2 id="invoice-review-title">Confira as faturas lidas</h2>
    <p>Os dados abaixo já estão salvos. Abra os itens de cada mês para conferir quantidades, demanda ociosa, tarifas e impostos antes de simular. O símbolo — indica um campo que não consta nessa linha do documento.</p>
    {onRemove && <p>Enviou uma fatura fora do período desejado? Use “Remover fatura” na linha correspondente e envie o arquivo correto.</p>}
    <div className="invoice-table-wrap" tabIndex={0} role="region" aria-label="Resumo das faturas salvas">
      <table className="invoice-review-table">
        <caption>Faturas em ordem do mês da medição</caption>
        <thead><tr><th scope="col">Mês da medição</th><th scope="col">Unidade</th><th scope="col">Total da conta (R$)</th><th scope="col">Observações</th>{onRemove && <th scope="col">Ações</th>}</tr></thead>
        <tbody>{ordered.map((invoice) => <tr key={invoice.reference_month}>
          <th scope="row">{monthLabel(invoice.reference_month)}</th>
          <td>{invoice.consumer_unit}</td>
          <td>{decimal(invoice.total_paid)}</td>
          <td>{invoice.warnings.length ? invoice.warnings.map((warning) => <p key={warning}>{warningMessages[warning] ?? 'Esta fatura precisa de conferência.'}</p>) : 'Sem alertas de leitura.'}</td>
          {onRemove && <td><button className="invoice-remove-button" type="button" disabled={disabled} onClick={() => onRemove(invoice.id)} aria-label={`Remover fatura de ${monthLabel(invoice.reference_month)}`}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6" /></svg>Remover fatura</button></td>}
        </tr>)}</tbody>
      </table>
    </div>
    {ordered.map((invoice) => <details className="invoice-review-details" key={invoice.reference_month}>
      <summary>Ver itens de {monthLabel(invoice.reference_month)}{invoice.source_filename ? ` · ${invoice.source_filename}` : ''}</summary>
      <div className="invoice-table-wrap" tabIndex={0} role="region" aria-label={`Itens de ${monthLabel(invoice.reference_month)}`}>
        <table className="invoice-review-table invoice-items-table">
          <caption>Dados extraídos de {monthLabel(invoice.reference_month)}</caption>
          <thead><tr><th scope="col">Descrição</th><th scope="col">Posto</th><th scope="col">Quantidade</th><th scope="col">Unidade</th><th scope="col">Preço com tributos (R$)</th><th scope="col">Tarifa sem tributos (R$)</th><th scope="col">Valor (R$)</th><th scope="col">PIS/COFINS (R$)</th><th scope="col">Base ICMS (R$)</th><th scope="col">ICMS (%)</th><th scope="col">ICMS (R$)</th></tr></thead>
          <tbody>{invoice.items.map((item) => <tr key={item.id} className={item.item_type === 'idle_demand' ? 'invoice-idle-row' : undefined}>
            <th scope="row">{item.source_label && /\bACL\b|mercado\s+livre/i.test(item.source_label) ? 'Ajuste de energia' : item.source_label ?? 'Item da fatura'}</th><td>{slotLabel(item.time_slot)}</td><td>{decimal(item.quantity, 3)}</td><td>{item.unit ?? '—'}</td><td>{decimal(item.unit_price_taxed, 5)}</td><td>{decimal(item.tariff_rate, 5)}</td><td>{decimal(item.amount)}</td><td>{decimal(item.pis_cofins_amount)}</td><td>{decimal(item.icms_base)}</td><td>{decimal(String(Number(item.icms_rate) * 100))}</td><td>{decimal(item.icms_amount)}</td>
          </tr>)}</tbody>
        </table>
      </div>
    </details>)}
  </section>
}
