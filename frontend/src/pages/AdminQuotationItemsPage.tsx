import { useEffect, useState, type FormEvent } from 'react'
import { ApiError, apiRequest } from '../services/api/client'

type QuotationItem = {
  id: number
  item_key: string
  name: string
  description: string | null
  unit: string
  unit_price: string
}

type QuotationItemInput = Omit<QuotationItem, 'id'>

const emptyItem: QuotationItemInput = {
  item_key: '',
  name: '',
  description: '',
  unit: '',
  unit_price: '',
}

function formatPrice(value: string) {
  return Number(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

export function AdminQuotationItemsPage() {
  const [items, setItems] = useState<QuotationItem[]>([])
  const [form, setForm] = useState<QuotationItemInput>(emptyItem)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  useEffect(() => {
    let active = true
    apiRequest<{ data: QuotationItem[] }>('/admin/quotation-items', {}, { notifyGlobalError: false })
      .then((response) => { if (active) setItems(response.data) })
      .catch((reason: unknown) => {
        if (active) setError(reason instanceof ApiError ? reason.message : 'Não foi possível carregar os itens de orçamento.')
      })
      .finally(() => { if (active) setLoading(false) })

    return () => { active = false }
  }, [])

  function changeField(field: keyof QuotationItemInput, value: string) {
    setForm((current) => ({ ...current, [field]: value }))
  }

  function startEditing(item: QuotationItem) {
    setEditingId(item.id)
    setForm({
      item_key: item.item_key,
      name: item.name,
      description: item.description ?? '',
      unit: item.unit,
      unit_price: item.unit_price,
    })
    setError('')
    setNotice('')
  }

  function cancelEditing() {
    setEditingId(null)
    setForm(emptyItem)
    setError('')
    setNotice('')
  }

  async function saveItem(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setError('')
    setNotice('')
    try {
      const payload = { ...form, description: form.description || null }
      const response = await apiRequest<{ data: QuotationItem }>(
        editingId === null ? '/admin/quotation-items' : `/admin/quotation-items/${editingId}`,
        { method: editingId === null ? 'POST' : 'PUT', body: JSON.stringify(payload) },
        { notifyGlobalError: false },
      )
      setItems((current) => {
        const next = editingId === null
          ? [...current, response.data]
          : current.map((item) => item.id === response.data.id ? response.data : item)
        return next.sort((left, right) => left.name.localeCompare(right.name, 'pt-BR'))
      })
      setNotice(editingId === null ? 'Item de orçamento cadastrado.' : 'Item de orçamento atualizado.')
      setEditingId(null)
      setForm(emptyItem)
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Não foi possível salvar o item de orçamento.')
    } finally {
      setSaving(false)
    }
  }

  return <section className="admin-page" aria-labelledby="admin-quotation-title">
    <a className="admin-back-link" href="/admin">← Painel administrativo</a>
    <p className="section-eyebrow">Área administrativa</p>
    <h1 id="admin-quotation-title">Orçamento</h1>
    <p className="access-intro">Atualize os custos de referência usados na preparação de orçamentos.</p>

    {loading && <p role="status">Carregando itens de orçamento…</p>}
    {error && <p className="field-error" role="alert">{error}</p>}
    {notice && <p className="form-success" role="status">{notice}</p>}
    {!loading && !error && (items.length === 0
      ? <p>Nenhum item de orçamento cadastrado.</p>
      : <div className="admin-table-wrap"><table className="admin-table">
        <thead><tr><th>Item</th><th>Unidade</th><th>Valor unitário</th><th>Ação</th></tr></thead>
        <tbody>{items.map((item) => <tr key={item.id}>
          <td><strong>{item.name}</strong>{item.description && <span>{item.description}</span>}</td>
          <td>{item.unit}</td>
          <td>{formatPrice(item.unit_price)}</td>
          <td><button className="text-button admin-inline-button" type="button" onClick={() => startEditing(item)}>Editar</button></td>
        </tr>)}</tbody>
      </table></div>)}

    <form className="admin-data-form" onSubmit={saveItem}>
      <h2>{editingId === null ? 'Adicionar item' : 'Editar item'}</h2>
      <div className="admin-form-grid">
        <label>Identificador<input required maxLength={255} pattern="[A-Za-z0-9_-]+" value={form.item_key} onChange={(event) => changeField('item_key', event.target.value)} placeholder="consulting_hour" /></label>
        <label>Nome<input required maxLength={255} value={form.name} onChange={(event) => changeField('name', event.target.value)} /></label>
        <label>Unidade<input required maxLength={30} value={form.unit} onChange={(event) => changeField('unit', event.target.value)} placeholder="hour" /></label>
        <label>Valor unitário (R$)<input required type="number" min="0.01" step="0.01" value={form.unit_price} onChange={(event) => changeField('unit_price', event.target.value)} /></label>
        <label>Descrição<textarea value={form.description ?? ''} onChange={(event) => changeField('description', event.target.value)} rows={3} /></label>
      </div>
      <div className="admin-form-actions">
        <button className="button" type="submit" disabled={saving}>{saving ? 'Salvando…' : editingId === null ? 'Adicionar item' : 'Salvar alterações'}</button>
        {editingId !== null && <button className="button button--secondary" type="button" onClick={cancelEditing}>Cancelar</button>}
      </div>
    </form>
  </section>
}
