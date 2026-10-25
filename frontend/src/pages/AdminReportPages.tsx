import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { ApiError, apiRequest } from '../services/api/client'

type PageMeta = { current_page: number; last_page: number; per_page: number; total: number }
type SimulationRow = {
  id: number
  status: string
  months_count: number | null
  total_monthly_savings: string | null
  customer: { id: number; name: string; email: string } | null
}
type Revision = {
  id: number
  reason: string | null
  author: string | null
  created_at: string | null
  explanatory_texts: string[] | Record<string, string> | null
  recommendations: string[] | Record<string, string> | null
}
type Report = {
  id: number
  total_monthly_savings: string | null
  total_yearly_savings: string | null
  savings_percentage: string | null
  demand_savings_monthly: string | null
  overrun_savings_monthly: string | null
  reactive_savings_monthly: string | null
  migration_savings_monthly: string | null
  recommended_modality: string | null
  algorithm_version: string | null
  explanatory_texts: string[] | Record<string, string> | null
  recommendations: string[] | Record<string, string> | null
  warnings: string[] | Record<string, string> | null
  revisions: Revision[]
}
type SimulationReportData = {
  id: number
  status: string
  customer: { id: number; name: string; email: string } | null
  report: Report | null
}

function money(value: string | null) {
  return value === null ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value))
}

function textList(value: string[] | Record<string, string> | null | undefined) {
  if (!value) return []
  return (Array.isArray(value) ? value : Object.values(value)).filter((text): text is string => typeof text === 'string')
}

function ReportFrame({ title, children }: { title: string; children: ReactNode }) {
  return <section className="admin-page" aria-labelledby="admin-report-title">
    <a className="admin-back-link" href="/admin">← Painel administrativo</a>
    <p className="section-eyebrow">Área administrativa</p>
    <h1 id="admin-report-title">{title}</h1>
    {children}
  </section>
}

function useAdminReports(page: number) {
  const [result, setResult] = useState<{ data: SimulationRow[]; meta: PageMeta } | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  useEffect(() => {
    let active = true
    setLoading(true)
    apiRequest<{ data: SimulationRow[]; meta: PageMeta }>(`/admin/simulations?page=${page}`)
      .then((response) => { if (active) setResult(response) })
      .catch((reason: unknown) => { if (active) setError(reason instanceof ApiError ? reason.message : 'Não foi possível carregar os relatórios.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [page])
  return { result, error, loading }
}

export function AdminReportsPage() {
  const [page, setPage] = useState(1)
  const { result, error, loading } = useAdminReports(page)
  return <ReportFrame title="Relatórios">
    <p className="access-intro">Consulte relatórios e abra uma simulação para revisar textos, versões e resultados.</p>
    {loading && <p role="status">Carregando relatórios…</p>}
    {error && <p className="field-error" role="alert">{error}</p>}
    {result && <>
      {result.data.length === 0 ? <p>Nenhum relatório encontrado.</p> : <div className="admin-table-wrap"><table className="admin-table">
        <thead><tr><th>Simulação</th><th>Cliente</th><th>Status</th><th>Meses</th><th>Economia mensal</th><th>Ações</th></tr></thead>
        <tbody>{result.data.map((simulation) => <tr key={simulation.id}>
          <td>#{simulation.id}</td>
          <td>{simulation.customer ? <><strong>{simulation.customer.name}</strong><span>{simulation.customer.email}</span></> : '—'}</td>
          <td>{simulation.status}</td><td>{simulation.months_count ?? '—'}</td><td>{money(simulation.total_monthly_savings)}</td>
          <td><a href={`/admin/reports/${simulation.id}`}>Abrir relatório</a></td>
        </tr>)}</tbody>
      </table></div>}
      <nav className="admin-pagination" aria-label="Paginação">
        <button className="button button--secondary" type="button" disabled={page <= 1} onClick={() => setPage(page - 1)}>Anterior</button>
        <span>Página {result.meta.current_page} de {result.meta.last_page} · {result.meta.total} registros</span>
        <button className="button button--secondary" type="button" disabled={page >= result.meta.last_page} onClick={() => setPage(page + 1)}>Próxima</button>
      </nav>
    </>}
  </ReportFrame>
}

export function AdminReportDetailPage({ simulationId }: { simulationId: string }) {
  const [simulation, setSimulation] = useState<SimulationReportData | null>(null)
  const [explanatoryText, setExplanatoryText] = useState('')
  const [recommendations, setRecommendations] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState<'save' | 'resend' | 'recalculate' | null>(null)

  async function loadSimulation() {
    const response = await apiRequest<{ data: SimulationReportData }>(`/admin/simulations/${simulationId}`)
    setSimulation(response.data)
    setExplanatoryText(textList(response.data.report?.explanatory_texts).join('\n'))
    setRecommendations(textList(response.data.report?.recommendations).join('\n'))
  }

  useEffect(() => {
    let active = true
    apiRequest<{ data: SimulationReportData }>(`/admin/simulations/${simulationId}`)
      .then((response) => {
        if (!active) return
        setSimulation(response.data)
        setExplanatoryText(textList(response.data.report?.explanatory_texts).join('\n'))
        setRecommendations(textList(response.data.report?.recommendations).join('\n'))
      })
      .catch((reason: unknown) => { if (active) setError(reason instanceof ApiError ? reason.message : 'Não foi possível carregar o relatório.') })
    return () => { active = false }
  }, [simulationId])

  async function saveText(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!simulation?.report) return
    setBusy('save')
    setError('')
    setNotice('')
    try {
      await apiRequest(`/admin/reports/${simulation.report.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          explanatory_texts: explanatoryText.split('\n').map((text) => text.trim()).filter(Boolean),
          recommendations: recommendations.split('\n').map((text) => text.trim()).filter(Boolean),
        }),
      })
      await loadSimulation()
      setNotice('Textos atualizados e nova versão registrada.')
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Não foi possível salvar os textos do relatório.')
    } finally { setBusy(null) }
  }

  async function resendReport() {
    if (!simulation?.report) return
    setBusy('resend')
    setError('')
    setNotice('')
    try {
      const response = await apiRequest<{ message: string }>(`/admin/reports/${simulation.report.id}/resend`, { method: 'POST' })
      setNotice(response.message)
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Não foi possível reenviar o relatório.')
    } finally { setBusy(null) }
  }

  async function recalculate() {
    setBusy('recalculate')
    setError('')
    setNotice('')
    try {
      await apiRequest(`/admin/simulations/${simulationId}/recalculate`, { method: 'POST' })
      await loadSimulation()
      setNotice('Relatório recalculado com sucesso. Os dados extraídos foram preservados.')
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Não foi possível recalcular o relatório.')
    } finally { setBusy(null) }
  }

  const report = simulation?.report
  return <ReportFrame title="Relatório da simulação">
    {error && <p className="field-error" role="alert">{error}</p>}
    {notice && <p className="form-success" role="status">{notice}</p>}
    {!simulation && !error && <p role="status">Carregando relatório…</p>}
    {simulation && !report && <p>Esta simulação ainda não possui relatório.</p>}
    {simulation && report && <>
      <p className="access-intro">Simulação #{simulation.id} · {simulation.customer?.name ?? 'Cliente não identificado'} · Status: {simulation.status}</p>
      <section className="admin-report-results" aria-labelledby="admin-report-results-title">
        <h2 id="admin-report-results-title">Resultados calculados (somente leitura)</h2>
        <dl className="admin-details">
          <div><dt>Economia mensal</dt><dd>{money(report.total_monthly_savings)}</dd></div>
          <div><dt>Economia anual</dt><dd>{money(report.total_yearly_savings)}</dd></div>
          <div><dt>Percentual do valor médio pago</dt><dd>{report.savings_percentage === null ? '—' : `${report.savings_percentage}%`}</dd></div>
          <div><dt>Modalidade recomendada</dt><dd>{report.recommended_modality ?? '—'}</dd></div>
          <div><dt>Economia mensal por demanda</dt><dd>{money(report.demand_savings_monthly)}</dd></div>
          <div><dt>Economia mensal por ultrapassagem</dt><dd>{money(report.overrun_savings_monthly)}</dd></div>
          <div><dt>Economia mensal por reativos</dt><dd>{money(report.reactive_savings_monthly)}</dd></div>
          <div><dt>Economia mensal por migração</dt><dd>{money(report.migration_savings_monthly)}</dd></div>
          <div><dt>Versão do algoritmo</dt><dd>{report.algorithm_version ?? '—'}</dd></div>
        </dl>
        {textList(report.warnings).length > 0 && <div className="admin-report-warnings"><h3>Avisos</h3><ul>{textList(report.warnings).map((warning, index) => <li key={`${index}-${warning}`}>{warning}</li>)}</ul></div>}
      </section>

      <form className="admin-report-form" onSubmit={saveText}>
        <h2>Textos explicativos e recomendações</h2>
        <label>Textos explicativos<textarea value={explanatoryText} onChange={(event) => setExplanatoryText(event.target.value)} rows={6} aria-describedby="admin-report-lines-help" /></label>
        <label>Recomendações<textarea value={recommendations} onChange={(event) => setRecommendations(event.target.value)} rows={6} aria-describedby="admin-report-lines-help" /></label>
        <p id="admin-report-lines-help" className="password-hint">Use uma linha para cada texto ou recomendação.</p>
        <button className="button" type="submit" disabled={busy !== null}>{busy === 'save' ? 'Salvando…' : 'Salvar textos e criar versão'}</button>
      </form>

      <section className="admin-report-actions" aria-label="Ações do relatório">
        <button className="button button--secondary" type="button" onClick={resendReport} disabled={busy !== null}>{busy === 'resend' ? 'Reenviando…' : 'Reenviar ao cliente'}</button>
        <button className="button button--secondary" type="button" onClick={recalculate} disabled={busy !== null}>{busy === 'recalculate' ? 'Recalculando…' : 'Recalcular resultados'}</button>
      </section>

      <section className="admin-revision-history" aria-labelledby="admin-revision-title">
        <h2 id="admin-revision-title">Histórico de versões</h2>
        {report.revisions.length === 0 ? <p>Nenhuma versão anterior registrada.</p> : <ol>
          {report.revisions.map((revision) => <li key={revision.id}>
            <article>
              <h3>Versão {revision.id} · {revision.reason === 'calculation_recalculation' ? 'Recálculo' : 'Edição de textos'}</h3>
              <p>{revision.author ?? 'Sistema'} · {revision.created_at ? new Date(revision.created_at).toLocaleString('pt-BR') : 'Data indisponível'}</p>
              <h4>Textos explicativos</h4><ul>{textList(revision.explanatory_texts).map((text, index) => <li key={`${index}-${text}`}>{text}</li>)}</ul>
              <h4>Recomendações</h4><ul>{textList(revision.recommendations).map((text, index) => <li key={`${index}-${text}`}>{text}</li>)}</ul>
            </article>
          </li>)}
        </ol>}
      </section>
    </>}
  </ReportFrame>
}
