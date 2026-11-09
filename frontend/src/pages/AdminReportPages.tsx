import { simulationStatusLabel } from '../services/presentation'
import { NavigationLink, NavigationButton } from '../components/NavigationControl'
import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { ApiError, apiRequest } from '../services/api/client'
import { SimulationReportView, type Report as CustomerReport } from './SimulationReportPage'

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
type Report = Omit<CustomerReport, 'explanatory_texts' | 'recommendations' | 'warnings' | 'months_count' | 'simulation_id'> & {
  algorithm_version: string | null
  explanatory_texts: string[] | Record<string, string> | null
  recommendations: string[] | Record<string, string> | null
  warnings: string[] | Record<string, string> | null
  revisions: Revision[]
}
type SimulationReportData = {
  id: number
  status: string
  months_count?: number | null
  customer: { id: number; name: string; email: string } | null
  invoices?: { tariff_modality: string; reference_month: string; items?: { item_type: string; time_slot: string; quantity: string }[] }[]
  report: Report | null
}

function money(value: string | null) {
  return value === null ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value))
}

function textList(value: string[] | Record<string, string> | null | undefined) {
  if (!value) return []
  return (Array.isArray(value) ? value : Object.values(value)).filter((text): text is string => typeof text === 'string')
}

function contractedDemands(simulation: SimulationReportData) {
  const latest = simulation.invoices?.slice().sort((a, b) => a.reference_month.localeCompare(b.reference_month)).at(-1)
  return Object.fromEntries((latest?.items ?? []).filter((item) => item.item_type === 'contracted_demand').map((item) => [item.time_slot, String(Number(item.quantity))]))
}

function optimalDemands(simulation: SimulationReportData) {
  const contracted = contractedDemands(simulation)
  const modality = simulation.invoices?.slice().sort((a, b) => a.reference_month.localeCompare(b.reference_month)).at(-1)?.tariff_modality
  const recommendations = modality === 'blue'
    ? { peak: simulation.report?.optimal_demand_peak, off_peak: simulation.report?.optimal_demand_off_peak }
    : modality === 'green' ? { unique: simulation.report?.optimal_demand_single } : {}

  return Object.fromEntries(Object.entries(recommendations).map(([slot, demand]) => [slot, demand ? String(Number(demand)) : contracted[slot] ?? '']))
}

function ReportFrame({ title, children }: { title: string; children: ReactNode }) {
  return <section className="admin-page" aria-labelledby="admin-report-title">
    <NavigationLink direction="back" href="/admin" aria-label="Voltar ao painel administrativo">Painel administrativo</NavigationLink>
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
        <thead><tr><th>Simulação</th><th>Cliente</th><th>Situação</th><th>Meses</th><th>Economia mensal</th><th>Ações</th></tr></thead>
        <tbody>{result.data.map((simulation) => <tr key={simulation.id}>
          <td>#{simulation.id}</td>
          <td>{simulation.customer ? <><strong>{simulation.customer.name}</strong><span>{simulation.customer.email}</span></> : '—'}</td>
          <td>{simulationStatusLabel(simulation.status)}</td><td>{simulation.months_count ?? '—'}</td><td>{money(simulation.total_monthly_savings)}</td>
          <td><NavigationLink href={`/admin/reports/${simulation.id}`}>Abrir relatório</NavigationLink></td>
        </tr>)}</tbody>
      </table></div>}
      <nav className="admin-pagination" aria-label="Paginação">
        <NavigationButton direction="back" type="button" disabled={page <= 1} onClick={() => setPage(page - 1)}>Anterior</NavigationButton>
        <span>Página {result.meta.current_page} de {result.meta.last_page} · {result.meta.total} registros</span>
        <NavigationButton direction="forward" type="button" disabled={page >= result.meta.last_page} onClick={() => setPage(page + 1)}>Próxima</NavigationButton>
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
  const [busy, setBusy] = useState<'save' | 'resend' | 'recalculate' | 'preview' | null>(null)

  const [demands, setDemands] = useState<Record<string, string>>({})
  const [previewDemands, setPreviewDemands] = useState<Record<string, string> | undefined>()
  const [preview, setPreview] = useState<Partial<CustomerReport> | null>(null)

  async function loadSimulation() {
    const response = await apiRequest<{ data: SimulationReportData }>(`/admin/simulations/${simulationId}`)
    setSimulation(response.data)
    setDemands(optimalDemands(response.data))
    setPreview(null)
    setPreviewDemands(undefined)
    setExplanatoryText(textList(response.data.report?.explanatory_texts).join('\n'))
    setRecommendations(textList(response.data.report?.recommendations).join('\n'))
  }

  useEffect(() => {
    let active = true
    apiRequest<{ data: SimulationReportData }>(`/admin/simulations/${simulationId}`)
      .then((response) => {
        if (!active) return
        setSimulation(response.data)
        setDemands(optimalDemands(response.data))
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

  async function previewDemand(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy('preview')
    setError('')
    setPreview(null)
    try {
      const response = await apiRequest<{ data: Partial<CustomerReport> }>(`/admin/simulations/${simulationId}/preview-demand`, {
        method: 'POST', body: JSON.stringify({ demands }),
      })
      setPreview(response.data)
      setPreviewDemands({ ...demands })
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Não foi possível simular as demandas.')
    } finally { setBusy(null) }
  }

  const modality = simulation?.invoices?.slice().sort((a, b) => a.reference_month.localeCompare(b.reference_month)).at(-1)?.tariff_modality
  const demandSlots = modality === 'blue'
    ? [{ key: 'peak', label: 'Demanda de ponta (kW)' }, { key: 'off_peak', label: 'Demanda fora de ponta (kW)' }]
    : modality === 'green' ? [{ key: 'unique', label: 'Demanda única (kW)' }] : []
  const report = simulation?.report
  return <ReportFrame title="Relatório da simulação">
    {error && <p className="field-error" role="alert">{error}</p>}
    {notice && <p className="form-success" role="status">{notice}</p>}
    {!simulation && !error && <p role="status">Carregando relatório…</p>}
    {simulation && !report && <p>Esta simulação ainda não possui relatório.</p>}
    {simulation && report && <>
      <p className="access-intro">Simulação #{simulation.id} · {simulation.customer?.name ?? 'Cliente não identificado'} · Situação: {simulationStatusLabel(simulation.status)}</p>
      <SimulationReportView
        report={{
          ...report, ...preview,
          simulation_id: simulation.id,
          months_count: simulation.months_count ?? 0,
          chart_series: preview?.chart_series ?? report.chart_series ?? {},
          warnings: textList(preview?.warnings ?? report.warnings),
          explanatory_texts: !Array.isArray(report.explanatory_texts) ? report.explanatory_texts ?? {} : {},
          recommendations: textList(report.recommendations),
          r_values: preview?.r_values ?? report.r_values ?? [],
        }}
        simulationId={simulationId}
        backHref="/admin/simulations"
        backLabel="Simulações"
        pdfEndpoint={preview ? `/admin/simulations/${simulationId}/preview-demand/pdf` : `/admin/simulations/${simulationId}/pdf`}
        pdfDemands={preview ? previewDemands : undefined}
      >
        {simulation.status === 'completed' && demandSlots.length > 0 && <form className="admin-report-form" onSubmit={previewDemand}>
          <h2>Simular demandas online</h2>
          <p>Os campos começam com as demandas ótimas indicadas pelo motor. Ajuste os valores para testar outros cenários. A prévia é exclusiva do admin e não altera o relatório do cliente.</p>
          {demandSlots.map((slot) => <label key={slot.key}>{slot.label}
            <input type="number" min="0" max="1000000" step="1" required disabled={busy !== null} value={demands[slot.key] ?? ''} onChange={(event) => { setDemands({ ...demands, [slot.key]: event.target.value }); setPreview(null); setPreviewDemands(undefined) }} />
          </label>)}
          <div className="admin-demand-actions">
            <button className="button" type="submit" disabled={busy !== null}>{busy === 'preview' ? 'Simulando…' : 'Simular demandas'}</button>
            <button className="button button--secondary" type="button" disabled={busy !== null || !preview} onClick={() => { setPreview(null); setPreviewDemands(undefined); setDemands(optimalDemands(simulation)) }}>Restaurar relatório original</button>
          </div>
          {preview && <p role="status">Prévia das demandas aplicadas ao relatório e aos gráficos. Valores negativos indicam aumento de custo.</p>}
        </form>}

      </SimulationReportView>

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
