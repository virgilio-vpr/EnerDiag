import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { ApiError, apiRequest } from '../services/api/client'

type PageMeta = { current_page: number; last_page: number; per_page: number; total: number }
type Distributor = { id: number; name: string; state: string }
type Tariff = {
  id: number
  distributor_id: number
  distributor?: { id: number; name: string; state: string }
  subgroup: string
  tariff_modality: string
  time_slot: string
  component: string
  rate: string
  includes_taxes: boolean
  source: string
  source_reference: string | null
  valid_from: string
  valid_to: string | null
}
type Parameter = { id: number; key: string; value: string; state: string | null; valid_from: string; valid_to: string | null }
type TariffFormState = {
  distributor_id: string
  subgroup: string
  tariff_modality: string
  time_slot: string
  component: string
  rate: string
  includes_taxes: '' | 'true' | 'false'
  valid_from: string
  valid_to: string
  source_reference: string
}
type ParameterFormState = { key: string; value: string; valid_from: string; valid_to: string }

const emptyTariff: TariffFormState = {
  distributor_id: '', subgroup: 'A4', tariff_modality: 'blue', time_slot: 'peak', component: 'demand',
  rate: '', includes_taxes: '', valid_from: '', valid_to: '', source_reference: '',
}
const emptyParameter: ParameterFormState = { key: 'overrun_tolerance', value: '', valid_from: '', valid_to: '' }
const parameterLabels: Record<string, string> = {
  overrun_tolerance: 'Tolerância de ultrapassagem',
  overrun_multiplier: 'Multiplicador de ultrapassagem',
  reference_power_factor: 'Fator de potência de referência',
  idle_demand_discount_fallback: 'Reserva da demanda ociosa',
}

function formError(error: unknown, fallback: string) {
  if (!(error instanceof ApiError)) return fallback
  const messages = Object.values(error.errors ?? {}).flat()
  return messages.length ? messages.join(' ') : error.message
}

function AdminToolFrame({ title, children }: { title: string; children: ReactNode }) {
  return <section className="admin-page" aria-labelledby="admin-tool-title">
    <a className="admin-back-link" href="/admin">← Painel administrativo</a>
    <p className="section-eyebrow">Área administrativa</p>
    <h1 id="admin-tool-title">{title}</h1>
    <nav className="admin-subnav" aria-label="Tarifas e parâmetros"><a href="/admin/tariffs">Tarifas e importação ANEEL</a><a href="/admin/parameters">Parâmetros regulatórios</a></nav>
    {children}
  </section>
}

function Pagination({ meta, onPage }: { meta: PageMeta; onPage: (page: number) => void }) {
  return <nav className="admin-pagination" aria-label="Paginação">
    <button className="button button--secondary" type="button" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Anterior</button>
    <span>Página {meta.current_page} de {meta.last_page} · {meta.total} registros</span>
    <button className="button button--secondary" type="button" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Próxima</button>
  </nav>
}

function TariffFields({ form, setForm, distributors }: { form: TariffFormState; setForm: (value: TariffFormState) => void; distributors: Distributor[] }) {
  function update<K extends keyof TariffFormState>(key: K, value: TariffFormState[K]) {
    setForm({ ...form, [key]: value })
  }
  return <div className="admin-form-grid">
    <label>Distribuidora<select value={form.distributor_id} onChange={(event) => update('distributor_id', event.target.value)}><option value="">Selecione</option>{distributors.map((item) => <option key={item.id} value={item.id}>{item.name} · {item.state}</option>)}</select></label>
    <label>Subgrupo<select value={form.subgroup} onChange={(event) => update('subgroup', event.target.value)}><option value="A4">A4</option><option value="AS">AS</option></select></label>
    <label>Modalidade<select value={form.tariff_modality} onChange={(event) => update('tariff_modality', event.target.value)}><option value="blue">Azul</option><option value="green">Verde</option></select></label>
    <label>Posto tarifário<select value={form.time_slot} onChange={(event) => update('time_slot', event.target.value)}><option value="peak">Ponta</option><option value="off_peak">Fora de ponta</option><option value="single">Único</option></select></label>
    <label>Componente<select value={form.component} onChange={(event) => update('component', event.target.value)}><option value="demand">Demanda</option><option value="energy_te">Energia TE</option><option value="energy_tusd">Energia TUSD</option></select></label>
    <label>Tarifa unitária<input inputMode="decimal" value={form.rate} onChange={(event) => update('rate', event.target.value)} placeholder="0,00000" /></label>
    <label>Base tributária<select aria-label="Base tributária" value={form.includes_taxes} onChange={(event) => update('includes_taxes', event.target.value as TariffFormState['includes_taxes'])}><option value="">Selecione a base</option><option value="true">Com tributos</option><option value="false">Sem tributos</option></select></label>
    <label>Início da vigência<input type="date" value={form.valid_from} onChange={(event) => update('valid_from', event.target.value)} /></label>
    <label>Fim da vigência (opcional)<input type="date" value={form.valid_to} onChange={(event) => update('valid_to', event.target.value)} /></label>
    <label>Origem / resolução<input value={form.source_reference} onChange={(event) => update('source_reference', event.target.value)} placeholder="Ex.: REH 1234/2026" /></label>
  </div>
}

type PendingImport = { id: number; source_reference: string | null; source_url: string; imported_at: string; status: string; pending_tariffs?: Array<Omit<Tariff, 'id' | 'distributor'>> }

function PendingTariffImports({ onApproved, distributors }: { onApproved: () => Promise<void>; distributors: Distributor[] }) {
  const [imports, setImports] = useState<PendingImport[]>([])
  const [review, setReview] = useState<PendingImport | null>(null)
  const [confirmed, setConfirmed] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)

  async function load(nextPage = 1) {
    setBusy(true)
    setError('')
    try {
      const response = await apiRequest<{ data: PendingImport[]; meta: { current_page: number; last_page: number } }>(`/admin/tariff-imports?page=${nextPage}`)
      setImports(response.data)
      setPage(response.meta.current_page)
      setLastPage(response.meta.last_page)
      if (response.data.length === 0) setNotice('Nenhuma importação pendente de revisão.')
    } catch (reason) { setError(formError(reason, 'Não foi possível consultar as importações.')) }
    finally { setBusy(false) }
  }

  async function openReview(id: number) {
    setBusy(true)
    setError('')
    setNotice('')
    setConfirmed(false)
    try {
      const response = await apiRequest<{ data: PendingImport }>(`/admin/tariff-imports/${id}`)
      setReview(response.data)
    } catch (reason) { setError(formError(reason, 'Não foi possível abrir a revisão.')) }
    finally { setBusy(false) }
  }

  async function approve() {
    if (!review || !confirmed) return
    setBusy(true)
    setError('')
    try {
      await apiRequest(`/admin/tariff-imports/${review.id}/approve`, { method: 'POST', body: JSON.stringify({ confirmed: true }) })
      setReview(null)
      setConfirmed(false)
      await onApproved()
      setImports((current) => current.filter((item) => item.id !== review.id))
      setNotice('Importação aprovada. Tarifas ativadas.')
    } catch (reason) { setError(formError(reason, 'Não foi possível aprovar a importação.')) }
    finally { setBusy(false) }
  }

  return <section className="admin-data-form" aria-labelledby="pending-imports-title">
    <h2 id="pending-imports-title">Importações automáticas para revisão</h2>
    <p>Os valores baixados da ANEEL só entram em vigor no sistema após sua revisão e aprovação.</p>
    <button className="button button--secondary" type="button" disabled={busy} onClick={() => void load()}>Consultar importações pendentes</button>
    {error && <p className="field-error" role="alert">{error}</p>}
    {notice && <p className="form-success" role="status">{notice}</p>}
    {imports.map((item) => <div key={item.id}><p>Importação #{item.id} · {item.source_reference}</p><button className="text-button" type="button" disabled={busy} onClick={() => void openReview(item.id)}>Revisar importação #{item.id}</button></div>)}
    {lastPage > 1 && <nav aria-label="Páginas de importações pendentes"><button type="button" disabled={busy || page <= 1} onClick={() => void load(page - 1)}>Anterior</button><span>{page} de {lastPage}</span><button type="button" disabled={busy || page >= lastPage} onClick={() => void load(page + 1)}>Próxima</button></nav>}
    {review && <div>
      <h3>Revisão da importação #{review.id}</h3>
      <p>{review.source_reference} · Valores sem tributos</p>
      <a href={review.source_url} target="_blank" rel="noreferrer">Consultar fonte oficial</a>
      <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Distribuidora</th><th>Subgrupo / modalidade</th><th>Posto / componente</th><th>Valor sem tributos</th><th>Vigência</th></tr></thead><tbody>
        {review.pending_tariffs?.map((tariff, index) => <tr key={index}><td>{distributors.find((item) => item.id === tariff.distributor_id)?.name ?? `Distribuidora #${tariff.distributor_id}`}</td><td>{tariff.subgroup} · {tariff.tariff_modality === 'blue' ? 'Azul' : 'Verde'}</td><td>{({ peak: 'Ponta', off_peak: 'Fora de ponta', single: 'Única' } as Record<string, string>)[tariff.time_slot]} · {({ demand: 'Demanda', energy_te: 'Energia TE', energy_tusd: 'Energia TUSD' } as Record<string, string>)[tariff.component]}</td><td>{tariff.rate}</td><td>{tariff.valid_from} – {tariff.valid_to ?? 'sem término'}</td></tr>)}
      </tbody></table></div>
      {review.status === 'pending' && <><label><input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />Conferi os valores, as vigências e a origem das tarifas.</label><button className="button" type="button" disabled={busy || !confirmed} onClick={() => void approve()}>Aprovar e ativar tarifas</button></>}
    </div>}
  </section>
}

export function AdminTariffsPage() {
  const [page, setPage] = useState(1)
  const [tariffs, setTariffs] = useState<{ data: Tariff[]; meta: PageMeta } | null>(null)
  const [distributors, setDistributors] = useState<Distributor[]>([])
  const [form, setForm] = useState<TariffFormState>(emptyTariff)
  const [editing, setEditing] = useState<Tariff | null>(null)
  const [file, setFile] = useState<File | null>(null)
  const [importResult, setImportResult] = useState<{ rows_accepted: number; rows_ignored: number } | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState<'save' | 'import' | null>(null)

  useEffect(() => {
    let active = true
    setLoading(true)
    Promise.all([
      apiRequest<{ data: Tariff[]; meta: PageMeta }>(`/admin/tariffs?page=${page}`),
      apiRequest<{ data: Distributor[] }>('/distributors'),
    ]).then(([tariffResponse, distributorResponse]) => {
      if (!active) return
      setTariffs(tariffResponse)
      setDistributors(distributorResponse.data)
    }).catch((reason: unknown) => { if (active) setError(formError(reason, 'Não foi possível carregar tarifas e distribuidoras.')) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [page])

  function startNewVersion(tariff: Tariff) {
    setEditing(tariff)
    setForm({
      distributor_id: String(tariff.distributor_id), subgroup: tariff.subgroup, tariff_modality: tariff.tariff_modality,
      time_slot: tariff.time_slot, component: tariff.component, rate: tariff.rate,
      includes_taxes: tariff.includes_taxes ? 'true' : 'false', valid_from: '', valid_to: '', source_reference: tariff.source_reference ?? '',
    })
    setError('')
    setNotice('')
  }

  async function reloadTariffs() {
    const response = await apiRequest<{ data: Tariff[]; meta: PageMeta }>(`/admin/tariffs?page=${page}`)
    setTariffs(response)
  }

  async function saveTariff(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy('save')
    setError('')
    setNotice('')
    const payload = {
      distributor_id: Number(form.distributor_id), subgroup: form.subgroup, tariff_modality: form.tariff_modality,
      time_slot: form.time_slot, component: form.component, rate: form.rate,
      ...(form.includes_taxes !== '' ? { includes_taxes: form.includes_taxes === 'true' } : {}),
      valid_from: form.valid_from, valid_to: form.valid_to || null, source_reference: form.source_reference || null,
    }
    try {
      await apiRequest(editing ? `/admin/tariffs/${editing.id}` : '/admin/tariffs', {
        method: editing ? 'PUT' : 'POST', body: JSON.stringify(payload),
      })
      await reloadTariffs()
      setEditing(null)
      setForm(emptyTariff)
      setNotice(editing ? 'Nova versão criada; o valor anterior foi preservado.' : 'Tarifa criada com sucesso.')
    } catch (reason) {
      setError(formError(reason, 'Não foi possível salvar a tarifa.'))
    } finally { setBusy(null) }
  }

  async function importCsv(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!file) {
      setError('Selecione o arquivo CSV da ANEEL.')
      return
    }
    setBusy('import')
    setError('')
    setNotice('')
    setImportResult(null)
    const body = new FormData()
    body.append('file', file)
    try {
      const response = await apiRequest<{ message: string; data: { rows_accepted: number; rows_ignored: number } }>('/admin/tariffs/import', { method: 'POST', body })
      setImportResult(response.data)
      setNotice(response.message)
      setFile(null)
      await reloadTariffs()
    } catch (reason) {
      setError(formError(reason, 'Não foi possível importar o CSV.'))
    } finally { setBusy(null) }
  }

  return <AdminToolFrame title="Tarifas e importação ANEEL">
    <p className="access-intro">Consulte a base tributária e a origem de cada tarifa. A importação da ANEEL grava valores sem tributos.</p>
    {loading && <p role="status">Carregando tarifas…</p>}
    {error && <p className="field-error" role="alert">{error}</p>}
    {notice && <p className="form-success" role="status">{notice}</p>}
    {importResult && <div className="admin-import-summary" role="status"><strong>Resumo da importação</strong><span>Linhas aceitas: {importResult.rows_accepted}</span><span>Linhas ignoradas: {importResult.rows_ignored}</span></div>}
    {tariffs && <>
      <div className="admin-table-wrap"><table className="admin-table">
        <thead><tr><th>Distribuidora</th><th>Subgrupo / modalidade</th><th>Posto / componente</th><th>Tarifa</th><th>Base tributária</th><th>Origem</th><th>Vigência</th><th>Ações</th></tr></thead>
        <tbody>{tariffs.data.map((tariff) => <tr key={tariff.id}>
          <td>{tariff.distributor?.name ?? distributors.find((item) => item.id === tariff.distributor_id)?.name ?? `#${tariff.distributor_id}`}</td>
          <td>{tariff.subgroup} · {tariff.tariff_modality === 'blue' ? 'Azul' : 'Verde'}</td>
          <td>{({ peak: 'Ponta', off_peak: 'Fora de ponta', single: 'Única' } as Record<string, string>)[tariff.time_slot]} · {({ demand: 'Demanda', energy_te: 'Energia TE', energy_tusd: 'Energia TUSD' } as Record<string, string>)[tariff.component]}</td><td>{tariff.rate}</td>
          <td>{tariff.includes_taxes ? 'Com tributos' : 'Sem tributos'}</td>
          <td>{tariff.source === 'aneel_import' ? 'ANEEL' : 'Manual'}{tariff.source_reference ? <span>{tariff.source_reference}</span> : null}</td>
          <td>{tariff.valid_from} – {tariff.valid_to ?? 'atual'}</td>
          <td><button className="text-button admin-inline-button" type="button" onClick={() => startNewVersion(tariff)}>Criar nova versão</button></td>
        </tr>)}</tbody>
      </table></div>
      <Pagination meta={tariffs.meta} onPage={setPage} />
    </>}

    <form className="admin-data-form" onSubmit={saveTariff} noValidate>
      <h2>{editing ? `Nova versão da tarifa #${editing.id}` : 'Cadastrar tarifa'}</h2>
      <TariffFields form={form} setForm={setForm} distributors={distributors} />
      <p className="password-hint">A base tributária é obrigatória. Tarifas importadas da ANEEL são sem tributos; editar uma tarifa cria outra versão e preserva a anterior.</p>
      <div className="admin-form-actions">
        <button className="button" type="submit" disabled={busy !== null}>{busy === 'save' ? 'Salvando…' : editing ? 'Criar versão' : 'Cadastrar tarifa'}</button>
        {editing && <button className="button button--secondary" type="button" onClick={() => { setEditing(null); setForm(emptyTariff) }}>Cancelar</button>}
      </div>
    </form>

    <PendingTariffImports onApproved={reloadTariffs} distributors={distributors} />

    <form className="admin-data-form" onSubmit={importCsv} noValidate>
      <h2>Importar CSV da ANEEL</h2>
      <label>Arquivo CSV<input type="file" accept=".csv,text/csv" onChange={(event) => setFile(event.target.files?.[0] ?? null)} /></label>
      <p className="password-hint">O resumo informa quantas linhas entraram no escopo e quantas foram ignoradas.</p>
      <button className="button" type="submit" disabled={busy !== null}>{busy === 'import' ? 'Importando…' : 'Importar tarifas'}</button>
    </form>
  </AdminToolFrame>
}

export function AdminParametersPage() {
  const [page, setPage] = useState(1)
  const [parameters, setParameters] = useState<{ data: Parameter[]; meta: PageMeta } | null>(null)
  const [form, setForm] = useState<ParameterFormState>(emptyParameter)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    let active = true
    setLoading(true)
    apiRequest<{ data: Parameter[]; meta: PageMeta }>(`/admin/parameters?page=${page}`)
      .then((response) => { if (active) setParameters(response) })
      .catch((reason: unknown) => { if (active) setError(formError(reason, 'Não foi possível carregar os parâmetros.')) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [page])

  async function reloadParameters() {
    const response = await apiRequest<{ data: Parameter[]; meta: PageMeta }>(`/admin/parameters?page=${page}`)
    setParameters(response)
  }

  async function saveParameter(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setError('')
    setNotice('')
    try {
      await apiRequest('/admin/parameters', {
        method: 'POST', body: JSON.stringify({ key: form.key, value: form.value, valid_from: form.valid_from, valid_to: form.valid_to || null }),
      })
      await reloadParameters()
      setNotice('Nova versão do parâmetro registrada.')
      setForm(emptyParameter)
    } catch (reason) {
      setError(formError(reason, 'Não foi possível salvar o parâmetro.'))
    } finally { setSaving(false) }
  }

  return <AdminToolFrame title="Parâmetros regulatórios">
    <p className="access-intro">Consulte parâmetros nacionais e registre novos valores por vigência. As versões anteriores permanecem no histórico.</p>
    {loading && <p role="status">Carregando parâmetros…</p>}
    {error && <p className="field-error" role="alert">{error}</p>}
    {notice && <p className="form-success" role="status">{notice}</p>}
    {parameters && <>
      <div className="admin-table-wrap"><table className="admin-table">
        <thead><tr><th>Parâmetro</th><th>Valor</th><th>Vigência</th></tr></thead>
        <tbody>{parameters.data.map((parameter) => <tr key={parameter.id}>
          <td>{parameterLabels[parameter.key] ?? parameter.key}<span>{parameter.key}</span></td>
          <td>{parameter.value}</td><td>{parameter.valid_from} – {parameter.valid_to ?? 'atual'}</td>
        </tr>)}</tbody>
      </table></div>
      <Pagination meta={parameters.meta} onPage={setPage} />
    </>}
    <form className="admin-data-form" onSubmit={saveParameter} noValidate>
      <h2>Criar nova versão de parâmetro</h2>
      <div className="admin-form-grid">
        <label>Parâmetro<select value={form.key} onChange={(event) => setForm({ ...form, key: event.target.value })}>{Object.entries(parameterLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
        <label>Valor decimal<input inputMode="decimal" value={form.value} onChange={(event) => setForm({ ...form, value: event.target.value })} placeholder="0,00000" /></label>
        <label>Início da vigência<input type="date" value={form.valid_from} onChange={(event) => setForm({ ...form, valid_from: event.target.value })} /></label>
        <label>Fim da vigência (opcional)<input type="date" value={form.valid_to} onChange={(event) => setForm({ ...form, valid_to: event.target.value })} /></label>
      </div>
      <button className="button" type="submit" disabled={saving}>{saving ? 'Salvando…' : 'Criar versão'}</button>
    </form>
  </AdminToolFrame>
}
