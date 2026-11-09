import { MessageDialog } from '../components/MessageDialog'
import { simulationStatusLabel } from '../services/presentation'
import { NavigationLink, NavigationButton } from '../components/NavigationControl'
import { useEffect, useState } from 'react'
import { apiRequest, getAuthToken, getCachedApiResponse } from '../services/api/client'
import { loadSimulationReport } from './reportLoader'

type Simulation = {
  id: number
  status: 'draft' | 'queued' | 'processing' | 'completed' | 'failed'
  months_count: number
  invoice_count: number
  total_monthly_savings: string | null
  created_at: string | null
}
type SimulationList = { data: Simulation[]; meta: { total: number; limit: number; can_create: boolean; current_page: number; last_page: number } }

function navigate(path: string) {
  window.history.pushState({}, '', path)
  window.dispatchEvent(new PopStateEvent('popstate'))
}

export function CustomerSimulationsPage() {
  const [result, setResult] = useState<SimulationList | null>(() => getCachedApiResponse<SimulationList>('/simulations?page=1') ?? null)
  const [page, setPage] = useState(1)
  const [reload, setReload] = useState(0)
  const [loading, setLoading] = useState(() => !getCachedApiResponse('/simulations?page=1'))
  const [busy, setBusy] = useState<number | null>(null)
  const [confirmation, setConfirmation] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [showLimitMessage, setShowLimitMessage] = useState(false)

  useEffect(() => {
    if (!getAuthToken()) {
      window.history.replaceState({}, '', '/login')
      window.dispatchEvent(new PopStateEvent('popstate'))
      return
    }
    let active = true
    setLoading(true)
    apiRequest<SimulationList>(`/simulations?page=${page}`, {}, { notifyGlobalError: false, cacheTtlMs: 60_000, forceRefresh: true })
      .then((response) => { if (active) setResult(response) })
      .catch((failure: unknown) => { if (active) setError(failure instanceof Error ? failure.message : 'Não foi possível carregar suas simulações.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [page, reload])

  function preloadReport(id: number) {
    void loadSimulationReport().catch(() => undefined)
    void apiRequest(`/simulations/${id}/report`, { cache: 'no-store' }, { notifyGlobalError: false, cacheTtlMs: 60_000 }).catch(() => undefined)
  }

  async function removeSimulation(id: number) {
    if (busy !== null) return
    setBusy(id)
    setError('')
    try {
      await apiRequest(`/simulations/${id}`, { method: 'DELETE' }, { notifyGlobalError: false })
      if (window.localStorage.getItem('enerdiag_invoice_import_id') === String(id)) window.localStorage.removeItem('enerdiag_invoice_import_id')
      setConfirmation(null)
      setNotice(`Simulação nº ${id} excluída.`)
      setResult((current) => current ? {
        ...current,
        data: current.data.filter((simulation) => simulation.id !== id),
        meta: { ...current.meta, total: current.meta.total - 1, can_create: current.meta.total - 1 < current.meta.limit },
      } : current)
      if (page > 1 && result?.data.length === 1) setPage(page - 1)
      else if (result && result.meta.total > result.data.length) setReload((value) => value + 1)
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Não foi possível excluir a simulação.')
    } finally {
      setBusy(null)
    }
  }

  async function calculate(id: number) {
    if (busy !== null) return
    setBusy(id)
    setError('')
    setNotice('Calculando o relatório com as faturas já salvas…')
    try {
      const response = await apiRequest<{ data: { id: number; status: string } }>(`/simulations/${id}/process`, { method: 'POST' }, { notifyGlobalError: false })
      navigate(response.data.status === 'completed' ? `/simulations/${id}/report` : `/upload-faturas?import=${id}`)
    } catch (failure) {
      setNotice('')
      setError(failure instanceof Error ? failure.message : 'Não foi possível calcular. Suas faturas continuam salvas.')
      setReload((value) => value + 1)
    } finally {
      setBusy(null)
    }
  }

  if (loading && !result) return <section className="access-page" aria-live="polite"><p>Carregando suas simulações…</p></section>
  const total = result?.meta.total ?? 0
  const limit = result?.meta.limit ?? 3
  const toRemove = Math.max(1, total - limit + 1)
  const canCreate = result?.meta.can_create === true && !loading && busy === null
  return <section className="simulations-page" aria-labelledby="simulations-title">
    <div className="simulations-heading"><div className="simulations-heading-copy"><p className="section-eyebrow">Suas análises de energia</p><h1 id="simulations-title">Minhas simulações</h1><p>Você pode manter até {limit} simulações, incluindo os envios em andamento.</p></div>
      <div className="simulation-card-actions">{canCreate ? <NavigationLink variant="primary" href="/upload-faturas?new=1">Nova simulação</NavigationLink> : <button className="button" disabled={loading || busy !== null || !result} type="button" onClick={() => setShowLimitMessage(true)}>Nova simulação</button>}</div>
    </div>
    {error && <div><p className="field-error" role="alert">{error}</p><button className="text-button" type="button" disabled={loading || busy !== null} onClick={() => { setError(''); setReload((value) => value + 1) }}>Tentar novamente</button></div>}
    {notice && <p className="form-success" role="status">{notice}</p>}
    <p aria-live="polite">{total} {total === 1 ? 'simulação salva' : 'simulações salvas'} · Limite de {limit}</p>
    {showLimitMessage && <MessageDialog title="Limite de simulações atingido" onClose={() => setShowLimitMessage(false)}>
      <p>Você já tem {total} {total === 1 ? 'simulação salva' : 'simulações salvas'}. O limite é de {limit} por conta, incluindo os envios em andamento.</p>
      <p>Para iniciar uma nova, exclua {toRemove === 1 ? 'uma das simulações' : `${toRemove} simulações`} usando a opção <strong>Excluir</strong> na lista abaixo.</p>
      <p>A exclusão remove as faturas e o relatório daquela simulação e precisa da sua confirmação.</p>
    </MessageDialog>}
    {total >= limit && <p className="simulation-limit-message">Exclua {toRemove} {toRemove === 1 ? 'simulação' : 'simulações'} para iniciar uma nova. A exclusão remove as faturas lidas e o relatório dessa análise.</p>}
    {result?.data.length === 0 && <p className="simulation-empty">Você ainda não tem simulações. Clique em Nova simulação para enviar suas faturas.</p>}
    <div className="simulation-cards">{result?.data.map((simulation) => <article className="simulation-card" key={simulation.id} aria-labelledby={`simulation-${simulation.id}`}>
      <div className="simulation-card-heading"><h2 id={`simulation-${simulation.id}`}>Simulação nº {simulation.id}</h2><span className={`simulation-status simulation-status--${simulation.status}`}>{simulationStatusLabel(simulation.status)}</span></div>
      <dl><div><dt>Faturas</dt><dd>{simulation.months_count || simulation.invoice_count}</dd></div><div><dt>Criada em</dt><dd>{simulation.created_at ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeZone: 'America/Sao_Paulo' }).format(new Date(simulation.created_at)) : '—'}</dd></div>
        {simulation.status === 'completed' && <div><dt>Economia mensal estimada</dt><dd>{simulation.total_monthly_savings === null ? '—' : Number(simulation.total_monthly_savings).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</dd></div>}
      </dl>
      <div className="simulation-card-actions">
        {simulation.status === 'completed' && <NavigationLink href={`/simulations/${simulation.id}/report`} onPointerEnter={() => preloadReport(simulation.id)} onFocus={() => preloadReport(simulation.id)}>Ver relatório</NavigationLink>}
        {simulation.status === 'draft' && <NavigationLink href={`/upload-faturas?import=${simulation.id}`}>Continuar envio</NavigationLink>}
        {(simulation.status === 'queued' || simulation.status === 'failed') && simulation.invoice_count > 0 && <button className="button button--secondary" type="button" disabled={busy !== null || loading} onClick={() => calculate(simulation.id)}>{busy === simulation.id ? 'Calculando…' : 'Calcular agora'}</button>}
        {simulation.status === 'processing' && <NavigationLink href={`/upload-faturas?import=${simulation.id}`}>Acompanhar</NavigationLink>}
        <button className="text-button" type="button" disabled={busy !== null || loading} onClick={() => setConfirmation(simulation.id)} aria-label={`Excluir simulação nº ${simulation.id}`}>Excluir</button>
      </div>
      {confirmation === simulation.id && <div className="simulation-delete-confirmation" role="group" aria-label={`Confirmar exclusão da simulação nº ${simulation.id}`}><p>Excluir a simulação nº {simulation.id}, suas faturas lidas e seu relatório?</p><button className="button button--secondary" type="button" disabled={busy !== null} onClick={() => removeSimulation(simulation.id)}>{busy === simulation.id ? 'Excluindo…' : 'Confirmar exclusão'}</button><button className="text-button" type="button" disabled={busy !== null} onClick={() => setConfirmation(null)}>Cancelar</button></div>}
    </article>)}</div>
    {result && result.meta.last_page > 1 && <nav className="simulation-pagination" aria-label="Páginas de simulações"><NavigationButton direction="back" type="button" disabled={page <= 1 || loading || busy !== null} onClick={() => setPage(page - 1)}>Anterior</NavigationButton><span>Página {page} de {result.meta.last_page}</span><NavigationButton direction="forward" type="button" disabled={page >= result.meta.last_page || loading || busy !== null} onClick={() => setPage(page + 1)}>Próxima</NavigationButton></nav>}
  </section>
}
