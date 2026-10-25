import { useEffect, useState, type ReactNode } from 'react'
import { apiRequest, ApiError } from '../services/api/client'

type PageMeta = { current_page: number; last_page: number; per_page: number; total: number }
type Paged<T> = { data: T[]; meta: PageMeta }
type Customer = {
  id: number
  name: string
  email: string
  phone: string | null
  email_verified_at: string | null
  contact_requested_at: string | null
  profile: {
    company_name: string
    cnpj: string | null
    transformer_power_kva: string | null
  } | null
}
type Simulation = {
  id: number
  status: string
  months_count: number | null
  total_monthly_savings: string | null
  total_yearly_savings: string | null
  customer: { id: number; name: string; email: string } | null
}
type InvoiceItem = {
  id: number
  item_type: string
  tariff_component: string | null
  time_slot: string | null
  quantity: string | null
  unit: string | null
  unit_price_taxed: string | null
  tariff_rate: string | null
  amount: string
  source_label: string | null
}
type Invoice = {
  id: number
  reference_month: string
  consumer_unit: string
  subgroup: string
  tariff_modality: string
  total_paid: string
  items: InvoiceItem[]
}
type SimulationDetail = Simulation & {
  user_id: number
  expires_at: string | null
  invoices: Invoice[]
  report: {
    total_monthly_savings: string | null
    total_yearly_savings: string | null
    recommended_modality: string | null
    algorithm_version: string | null
  } | null
}

function currency(value: string | null | undefined) {
  if (value === null || value === undefined) return '—'
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value))
}

function AdminPageFrame({ title, children }: { title: string; children: ReactNode }) {
  return <section className="admin-page" aria-labelledby="admin-data-title">
    <a className="admin-back-link" href="/admin">← Painel administrativo</a>
    <p className="section-eyebrow">Área administrativa</p>
    <h1 id="admin-data-title">{title}</h1>
    {children}
  </section>
}

function Pagination({ meta, onPage }: { meta: PageMeta; onPage: (page: number) => void }) {
  return <nav className="admin-pagination" aria-label="Paginação">
    <button className="button button--secondary" type="button" onClick={() => onPage(meta.current_page - 1)} disabled={meta.current_page <= 1}>Anterior</button>
    <span>Página {meta.current_page} de {meta.last_page} · {meta.total} registros</span>
    <button className="button button--secondary" type="button" onClick={() => onPage(meta.current_page + 1)} disabled={meta.current_page >= meta.last_page}>Próxima</button>
  </nav>
}

function useAdminList<T>(endpoint: string) {
  const [page, setPage] = useState(1)
  const [result, setResult] = useState<Paged<T> | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    setLoading(true)
    setError('')
    apiRequest<Paged<T>>(`${endpoint}?page=${page}`)
      .then((response) => { if (active) setResult(response) })
      .catch((reason: unknown) => {
        if (active) setError(reason instanceof ApiError ? reason.message : 'Não foi possível carregar os dados. Tente novamente.')
      })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [endpoint, page])

  return { page, setPage, result, loading, error }
}

export function AdminCustomersPage() {
  const { setPage, result, loading, error } = useAdminList<Customer>('/admin/customers')
  return <AdminPageFrame title="Clientes e contatos">
    <p className="access-intro">Dados de cadastro, empresa e solicitações de contato.</p>
    {loading && <p role="status">Carregando clientes…</p>}
    {error && <p className="field-error" role="alert">{error}</p>}
    {result && <>
      {result.data.length === 0 ? <p>Nenhum cliente encontrado.</p> : <div className="admin-table-wrap"><table className="admin-table">
        <thead><tr><th>Cliente</th><th>Empresa</th><th>Contato</th><th>Pedido de contato</th><th>Ações</th></tr></thead>
        <tbody>{result.data.map((customer) => <tr key={customer.id}>
          <td><strong>{customer.name}</strong><span>{customer.email}</span></td>
          <td>{customer.profile?.company_name ?? '—'}</td>
          <td>{customer.phone || '—'}</td>
          <td>{customer.contact_requested_at ? 'Solicitado' : 'Não solicitado'}</td>
          <td><a href={`/admin/customers/${customer.id}`}>Ver cadastro</a></td>
        </tr>)}</tbody>
      </table></div>}
      <Pagination meta={result.meta} onPage={setPage} />
    </>}
  </AdminPageFrame>
}

export function AdminCustomerDetailPage({ customerId }: { customerId: string }) {
  const [customer, setCustomer] = useState<Customer | null>(null)
  const [error, setError] = useState('')
  useEffect(() => {
    let active = true
    apiRequest<{ data: Customer }>(`/admin/customers/${customerId}`)
      .then((response) => { if (active) setCustomer(response.data) })
      .catch((reason: unknown) => { if (active) setError(reason instanceof ApiError ? reason.message : 'Não foi possível carregar o cadastro.') })
    return () => { active = false }
  }, [customerId])

  return <AdminPageFrame title="Cadastro do cliente">
    {error && <p className="field-error" role="alert">{error}</p>}
    {!customer && !error && <p role="status">Carregando cadastro…</p>}
    {customer && <dl className="admin-details">
      <div><dt>Nome</dt><dd>{customer.name}</dd></div>
      <div><dt>E-mail</dt><dd>{customer.email}</dd></div>
      <div><dt>Telefone</dt><dd>{customer.phone || '—'}</dd></div>
      <div><dt>Empresa</dt><dd>{customer.profile?.company_name ?? '—'}</dd></div>
      <div><dt>CNPJ</dt><dd>{customer.profile?.cnpj ?? '—'}</dd></div>
      <div><dt>Potência do transformador</dt><dd>{customer.profile?.transformer_power_kva ? `${customer.profile.transformer_power_kva} kVA` : '—'}</dd></div>
      <div><dt>E-mail verificado</dt><dd>{customer.email_verified_at ? 'Sim' : 'Não'}</dd></div>
      <div><dt>Pedido de contato</dt><dd>{customer.contact_requested_at ? 'Solicitado' : 'Não solicitado'}</dd></div>
    </dl>}
  </AdminPageFrame>
}

export function AdminSimulationsPage() {
  const { setPage, result, loading, error } = useAdminList<Simulation>('/admin/simulations')
  return <AdminPageFrame title="Simulações">
    <p className="access-intro">Consulte o processamento e os resultados das simulações.</p>
    {loading && <p role="status">Carregando simulações…</p>}
    {error && <p className="field-error" role="alert">{error}</p>}
    {result && <>
      {result.data.length === 0 ? <p>Nenhuma simulação encontrada.</p> : <div className="admin-table-wrap"><table className="admin-table">
        <thead><tr><th>Simulação</th><th>Cliente</th><th>Status</th><th>Meses</th><th>Economia mensal</th><th>Ações</th></tr></thead>
        <tbody>{result.data.map((simulation) => <tr key={simulation.id}>
          <td>#{simulation.id}</td>
          <td>{simulation.customer ? <><strong>{simulation.customer.name}</strong><span>{simulation.customer.email}</span></> : '—'}</td>
          <td>{simulation.status}</td>
          <td>{simulation.months_count ?? '—'}</td>
          <td>{currency(simulation.total_monthly_savings)}</td>
          <td><a href={`/admin/simulations/${simulation.id}`}>Ver dados</a></td>
        </tr>)}</tbody>
      </table></div>}
      <Pagination meta={result.meta} onPage={setPage} />
    </>}
  </AdminPageFrame>
}

export function AdminSimulationDetailPage({ simulationId }: { simulationId: string }) {
  const [simulation, setSimulation] = useState<SimulationDetail | null>(null)
  const [error, setError] = useState('')
  useEffect(() => {
    let active = true
    apiRequest<{ data: SimulationDetail }>(`/admin/simulations/${simulationId}`)
      .then((response) => { if (active) setSimulation(response.data) })
      .catch((reason: unknown) => { if (active) setError(reason instanceof ApiError ? reason.message : 'Não foi possível carregar a simulação.') })
    return () => { active = false }
  }, [simulationId])

  return <AdminPageFrame title="Dados extraídos da simulação">
    {error && <p className="field-error" role="alert">{error}</p>}
    {!simulation && !error && <p role="status">Carregando simulação…</p>}
    {simulation && <>
      <dl className="admin-details">
        <div><dt>Simulação</dt><dd>#{simulation.id}</dd></div>
        <div><dt>Cliente</dt><dd>{simulation.customer?.name ?? '—'}{simulation.customer?.email ? ` · ${simulation.customer.email}` : ''}</dd></div>
        <div><dt>Status</dt><dd>{simulation.status}</dd></div>
        <div><dt>Faturas</dt><dd>{simulation.invoices.length}</dd></div>
        <div><dt>Economia mensal</dt><dd>{currency(simulation.report?.total_monthly_savings ?? simulation.total_monthly_savings)}</dd></div>
        <div><dt>Economia anual</dt><dd>{currency(simulation.report?.total_yearly_savings ?? simulation.total_yearly_savings)}</dd></div>
        <div><dt>Modalidade recomendada</dt><dd>{simulation.report?.recommended_modality ?? '—'}</dd></div>
      </dl>
      {simulation.report && <a className="admin-back-link" href={`/admin/reports/${simulation.id}`}>Abrir e gerenciar relatório →</a>}
      <h2 className="admin-section-title">Faturas e itens extraídos</h2>
      {simulation.invoices.length === 0 ? <p>Esta simulação ainda não tem faturas extraídas.</p> : <div className="admin-invoices">
        {simulation.invoices.map((invoice) => <article className="admin-invoice" key={invoice.id}>
          <h3>{invoice.reference_month} · Unidade {invoice.consumer_unit}</h3>
          <p>{invoice.subgroup} · Modalidade {invoice.tariff_modality} · Total pago {currency(invoice.total_paid)}</p>
          {invoice.items.length === 0 ? <p>Nenhum item extraído.</p> : <div className="admin-table-wrap"><table className="admin-table">
            <thead><tr><th>Item</th><th>Posto</th><th>Quantidade</th><th>Preço unitário</th><th>Valor</th><th>Rótulo da fatura</th></tr></thead>
            <tbody>{invoice.items.map((item) => <tr key={item.id}>
              <td>{item.tariff_component ?? item.item_type}</td><td>{item.time_slot ?? '—'}</td>
              <td>{item.quantity === null ? '—' : `${item.quantity}${item.unit ? ` ${item.unit}` : ''}`}</td>
              <td>{currency(item.unit_price_taxed)}</td><td>{currency(item.amount)}</td><td>{item.source_label ?? '—'}</td>
            </tr>)}</tbody>
          </table></div>}
        </article>)}
      </div>}
    </>}
  </AdminPageFrame>
}
