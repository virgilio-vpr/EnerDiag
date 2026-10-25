import { useEffect, useState, type FormEvent } from 'react'
import { ApiError, apiRequest, getAuthToken } from '../services/api/client'

type Customer = { id: number; name: string; email: string; profile: { company_name: string } | null }
type QuotationBaseItem = { id: number; item_key: string; name: string; unit: string; unit_price: string }
type QuotationLine = { id: number; item_key: string; name: string; unit: string; quantity: string; unit_price: string; total: string }
type Quotation = {
  id: number
  quote_number: string
  customer_id: number
  customer_name: string
  customer_email: string
  company_name: string | null
  status: 'draft' | 'released' | 'sent'
  valid_until: string | null
  total: string
  technical_responsible_name: string | null
  crea_registration: string | null
  art_number: string | null
  items: QuotationLine[]
}
type PageMeta = { current_page: number; last_page: number; per_page: number; total: number }

const emptyTechnicalData = { technical_responsible_name: '', crea_registration: '', art_number: '' }

function currency(value: string) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value))
}

function errorMessage(reason: unknown, fallback: string) {
  return reason instanceof ApiError ? reason.message : fallback
}

export function AdminQuotationsPage() {
  const [customers, setCustomers] = useState<Customer[]>([])
  const [baseItems, setBaseItems] = useState<QuotationBaseItem[]>([])
  const [quotations, setQuotations] = useState<{ data: Quotation[]; meta: PageMeta } | null>(null)
  const [quotationPage, setQuotationPage] = useState(1)
  const [customerId, setCustomerId] = useState('')
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [reviewing, setReviewing] = useState<Quotation | null>(null)
  const [technicalData, setTechnicalData] = useState(emptyTechnicalData)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  useEffect(() => {
    let active = true
    Promise.all([
      apiRequest<{ data: Customer[] }>('/admin/customers?per_page=100'),
      apiRequest<{ data: QuotationBaseItem[] }>('/admin/quotation-items'),
    ])
      .then(([customerResponse, baseResponse]) => {
        if (!active) return
        setCustomers(customerResponse.data)
        setBaseItems(baseResponse.data)
      })
      .catch((reason: unknown) => { if (active) setError(errorMessage(reason, 'Não foi possível carregar os dados de orçamento.')) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [])

  useEffect(() => {
    let active = true
    apiRequest<{ data: Quotation[]; meta: PageMeta }>(`/admin/quotations?page=${quotationPage}`)
      .then((response) => { if (active) setQuotations(response) })
      .catch((reason: unknown) => { if (active) setError(errorMessage(reason, 'Não foi possível carregar os orçamentos.')) })
    return () => { active = false }
  }, [quotationPage])

  async function createQuotation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setNotice('')
    const items = baseItems.flatMap((item) => {
      const quantity = quantities[item.id]?.trim()
      return quantity ? [{ quotation_base_id: item.id, quantity }] : []
    })
    if (!customerId || items.length === 0) {
      setError('Selecione o cliente e informe a quantidade de pelo menos um item.')
      return
    }

    setSaving(true)
    try {
      const response = await apiRequest<{ data: Quotation }>('/admin/quotations', {
        method: 'POST',
        body: JSON.stringify({ customer_id: Number(customerId), items }),
      }, { notifyGlobalError: false })
      setQuotationPage(1)
      setQuotations((current) => current?.meta.current_page === 1
        ? { ...current, data: [response.data, ...current.data], meta: { ...current.meta, total: current.meta.total + 1 } }
        : current)
      setQuantities({})
      setNotice(`Rascunho ${response.data.quote_number} criado. Revise o PDF antes de liberar o envio.`)
    } catch (reason) {
      setError(errorMessage(reason, 'Não foi possível criar o orçamento.'))
    } finally {
      setSaving(false)
    }
  }

  async function previewPdf(quotation: Quotation) {
    setError('')
    try {
      const headers = new Headers({ Accept: 'application/pdf' })
      const token = getAuthToken()
      if (token) headers.set('Authorization', `Bearer ${token}`)
      const response = await fetch(`/api/v1/admin/quotations/${quotation.id}/pdf`, { headers })
      if (!response.ok) throw new Error('Não foi possível gerar a prévia do PDF.')
      const objectUrl = URL.createObjectURL(await response.blob())
      const link = document.createElement('a')
      link.href = objectUrl
      link.target = '_blank'
      link.rel = 'noreferrer'
      link.click()
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
    } catch (reason) {
      setError(errorMessage(reason, 'Não foi possível gerar a prévia do PDF.'))
    }
  }

  function startReview(quotation: Quotation) {
    setReviewing(quotation)
    setTechnicalData({
      technical_responsible_name: quotation.technical_responsible_name ?? '',
      crea_registration: quotation.crea_registration ?? '',
      art_number: quotation.art_number ?? '',
    })
    setError('')
    setNotice('')
  }

  async function releaseQuotation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!reviewing) return
    setSaving(true)
    setError('')
    try {
      const response = await apiRequest<{ data: Quotation }>(`/admin/quotations/${reviewing.id}/release`, {
        method: 'POST',
        body: JSON.stringify(technicalData),
      }, { notifyGlobalError: false })
      setQuotations((current) => current ? {
        ...current,
        data: current.data.map((quotation) => quotation.id === response.data.id ? response.data : quotation),
      } : current)
      setNotice(`Orçamento ${response.data.quote_number} liberado e enviado para ${response.data.customer_email}.`)
      setReviewing(null)
      setTechnicalData(emptyTechnicalData)
    } catch (reason) {
      setError(errorMessage(reason, 'Não foi possível liberar e enviar o orçamento.'))
    } finally {
      setSaving(false)
    }
  }

  return <section className="admin-page" aria-labelledby="admin-quotations-title">
    <a className="admin-back-link" href="/admin">← Painel administrativo</a>
    <p className="section-eyebrow">Área administrativa</p>
    <h1 id="admin-quotations-title">Orçamentos</h1>
    <p className="access-intro">Prepare a proposta, confira a prévia e libere o envio ao cliente após registrar a responsabilidade técnica e a ART.</p>

    {loading && <p role="status">Carregando clientes e itens de orçamento…</p>}
    {error && <p className="field-error" role="alert">{error}</p>}
    {notice && <p className="form-success" role="status">{notice}</p>}

    <form className="admin-data-form" onSubmit={createQuotation}>
      <h2>Novo orçamento em rascunho</h2>
      <div className="admin-form-grid">
        <label>Cliente<select required value={customerId} onChange={(event) => setCustomerId(event.target.value)}>
          <option value="">Selecione um cliente</option>
          {customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.profile?.company_name ?? customer.name} — {customer.email}</option>)}
        </select></label>
      </div>
      <div className="admin-table-wrap"><table className="admin-table">
        <thead><tr><th>Item</th><th>Valor unitário</th><th>Quantidade</th></tr></thead>
        <tbody>{baseItems.map((item) => <tr key={item.id}>
          <td>{item.name} <span>({item.unit})</span></td>
          <td>{currency(item.unit_price)}</td>
          <td><label className="admin-quantity-label"><span className="visually-hidden">Quantidade de {item.name}</span><input type="number" min="0.001" max="100000" step="0.001" value={quantities[item.id] ?? ''} onChange={(event) => setQuantities((current) => ({ ...current, [item.id]: event.target.value }))} /></label></td>
        </tr>)}</tbody>
      </table></div>
      <div className="admin-form-actions"><button className="button" type="submit" disabled={saving || loading}>{saving ? 'Salvando…' : 'Criar rascunho'}</button></div>
    </form>

    {reviewing && <form className="admin-data-form" onSubmit={releaseQuotation}>
      <h2>Revisar e liberar {reviewing.quote_number}</h2>
      <p>Confirme os dados do profissional e da ART emitida. Ao liberar, o PDF será enviado ao e-mail do cliente.</p>
      <div className="admin-form-grid">
        <label>Engenheiro(a) Eletricista responsável<input required maxLength={255} value={technicalData.technical_responsible_name} onChange={(event) => setTechnicalData((current) => ({ ...current, technical_responsible_name: event.target.value }))} /></label>
        <label>Registro CREA-SP<input required maxLength={100} value={technicalData.crea_registration} onChange={(event) => setTechnicalData((current) => ({ ...current, crea_registration: event.target.value }))} /></label>
        <label>Número da ART emitida<input required maxLength={100} value={technicalData.art_number} onChange={(event) => setTechnicalData((current) => ({ ...current, art_number: event.target.value }))} /></label>
      </div>
      <div className="admin-form-actions">
        <button className="button" type="submit" disabled={saving}>{saving ? 'Enviando…' : 'Liberar e enviar PDF'}</button>
        <button className="button button--secondary" type="button" onClick={() => setReviewing(null)}>Cancelar</button>
      </div>
    </form>}

    <section aria-labelledby="admin-quotation-list-title">
      <h2 id="admin-quotation-list-title">Propostas e status</h2>
      {quotations && (quotations.data.length === 0
        ? <p>Nenhum orçamento criado.</p>
        : <div className="admin-table-wrap"><table className="admin-table">
          <thead><tr><th>Orçamento</th><th>Cliente</th><th>Itens</th><th>Total</th><th>Status</th><th>Ações</th></tr></thead>
          <tbody>{quotations.data.map((quotation) => <tr key={quotation.id}>
            <td>{quotation.quote_number}</td>
            <td><strong>{quotation.company_name ?? quotation.customer_name}</strong><span>{quotation.customer_email}</span></td>
            <td>{quotation.items.length}</td>
            <td>{currency(quotation.total)}</td>
            <td>{quotation.status === 'draft' ? 'Rascunho' : quotation.status === 'released' ? 'Liberado — envio pendente' : 'Enviado'}</td>
            <td className="admin-quotation-actions">
              <button className="text-button admin-inline-button" type="button" onClick={() => previewPdf(quotation)}>Prévia PDF</button>
              {(quotation.status === 'draft' || quotation.status === 'released') && <button className="text-button admin-inline-button" type="button" onClick={() => startReview(quotation)}>{quotation.status === 'draft' ? 'Revisar e liberar' : 'Tentar envio novamente'}</button>}
            </td>
          </tr>)}</tbody>
        </table></div>)}
      {quotations && <nav className="admin-pagination" aria-label="Paginação de orçamentos">
        <button className="button button--secondary" type="button" disabled={quotations.meta.current_page <= 1} onClick={() => setQuotationPage((current) => current - 1)}>Anterior</button>
        <span>Página {quotations.meta.current_page} de {quotations.meta.last_page} · {quotations.meta.total} orçamentos</span>
        <button className="button button--secondary" type="button" disabled={quotations.meta.current_page >= quotations.meta.last_page} onClick={() => setQuotationPage((current) => current + 1)}>Próxima</button>
      </nav>}
    </section>
  </section>
}
