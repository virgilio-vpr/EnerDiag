import { useEffect, useState, type FormEvent } from 'react'
import { ApiError, apiRequest } from '../services/api/client'

type LeadType = 'unsupported_distributor' | 'contact_request'
type AdminLead = {
  type: LeadType
  id: number
  email: string
  distributor_name: string | null
  reason: string | null
  customer_name: string | null
  requested_at: string
}
type PageMeta = { current_page: number; last_page: number; per_page: number; total: number }

function requestedAt(value: string) {
  return new Date(value).toLocaleString('pt-BR')
}

export function AdminLeadsPage() {
  const [page, setPage] = useState(1)
  const [typeFilter, setTypeFilter] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [appliedType, setAppliedType] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [result, setResult] = useState<{ data: AdminLead[]; meta: PageMeta } | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    setLoading(true)
    setError('')
    const query = new URLSearchParams({ page: String(page) })
    if (appliedType) query.set('type', appliedType)
    if (appliedSearch) query.set('search', appliedSearch)
    apiRequest<{ data: AdminLead[]; meta: PageMeta }>(`/admin/leads?${query.toString()}`)
      .then((response) => { if (active) setResult(response) })
      .catch((reason: unknown) => { if (active) setError(reason instanceof ApiError ? reason.message : 'Não foi possível carregar os leads e contatos.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [page, appliedType, appliedSearch])

  function applyFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setPage(1)
    setAppliedType(typeFilter)
    setAppliedSearch(searchInput.trim())
  }

  return <section className="admin-page" aria-labelledby="admin-leads-title">
    <a className="admin-back-link" href="/admin">← Painel administrativo</a>
    <p className="section-eyebrow">Área administrativa</p>
    <h1 id="admin-leads-title">Leads e pedidos de contato</h1>
    <p className="access-intro">Acompanhe os interessados em distribuidoras não atendidas e os clientes que solicitaram contato.</p>

    <form className="admin-lead-filters" onSubmit={applyFilters}>
      <label>Tipo de registro<select value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)}>
        <option value="">Todos</option><option value="unsupported_distributor">Distribuidora não atendida</option><option value="contact_request">Pedido de contato</option>
      </select></label>
      <label>Buscar<input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="E-mail, nome, distribuidora ou motivo" /></label>
      <button className="button" type="submit">Aplicar filtros</button>
    </form>

    {loading && <p role="status">Carregando leads e contatos…</p>}
    {error && <p className="field-error" role="alert">{error}</p>}
    {result && <>
      {result.data.length === 0 ? <p>Nenhum registro encontrado com esses filtros.</p> : <div className="admin-table-wrap"><table className="admin-table">
        <thead><tr><th>Tipo</th><th>Cliente / distribuidora</th><th>E-mail</th><th>Detalhes</th><th>Solicitado em</th></tr></thead>
        <tbody>{result.data.map((lead) => <tr key={`${lead.type}-${lead.id}`}>
          <td>{lead.type === 'contact_request' ? 'Pedido de contato' : 'Distribuidora não atendida'}</td>
          <td>{lead.customer_name ?? lead.distributor_name ?? '—'}</td>
          <td>{lead.email}</td>
          <td>{lead.reason ?? '—'}</td>
          <td>{requestedAt(lead.requested_at)}</td>
        </tr>)}</tbody>
      </table></div>}
      <nav className="admin-pagination" aria-label="Paginação">
        <button className="button button--secondary" type="button" disabled={result.meta.current_page <= 1} onClick={() => setPage((current) => current - 1)}>Anterior</button>
        <span>Página {result.meta.current_page} de {result.meta.last_page} · {result.meta.total} registros</span>
        <button className="button button--secondary" type="button" disabled={result.meta.current_page >= result.meta.last_page} onClick={() => setPage((current) => current + 1)}>Próxima</button>
      </nav>
    </>}
  </section>
}
