import { NavigationLink } from '../components/NavigationControl'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { apiRequest, expireAuthSession, getAuthToken, getCachedApiResponse } from '../services/api/client'
import reportTexts from '../../../backend/resources/report-texts.json'

type ChartPoint = {
  reference_month?: string
  time_slot?: string
  value?: string | null
  measured?: string | null
  contracted?: string | null
  optimal?: string | null
  tariff_reference_month?: string | null
  tariff_reference_source?: string | null
  reference_tariff?: string | null
  tariff_reference_date?: string | null
  blue?: string | null
  green?: string | null
}

export type Report = {
  id: number
  simulation_id: number
  months_count: number
  total_monthly_savings: string | null
  total_yearly_savings: string | null
  savings_percentage: string | null
  demand_savings_monthly: string | null
  demand_savings_yearly: string | null
  overrun_savings_monthly: string | null
  overrun_savings_yearly: string | null
  reactive_savings_monthly: string | null
  reactive_savings_yearly: string | null
  migration_savings_monthly: string | null
  migration_savings_yearly: string | null
  recommended_modality: string | null
  optimal_demand_peak: string | null
  optimal_demand_off_peak: string | null
  optimal_demand_single: string | null
  chart_series: Record<string, ChartPoint[]>
  warnings: string[]
  explanatory_texts: Record<string, string>
  recommendations: string[]
  r_values: Array<{ reference_month: string; time_slot: string; ratio: string | null; source: string; inherited: boolean }>
}

const warningText: Record<string, string> = reportTexts.warnings

const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })
const decimal = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 })

function amount(value: string | null | undefined) {
  if (value === null || value === undefined || value === '') return '—'
  const parsed = Number(value)
  return Number.isFinite(parsed) ? currency.format(parsed) : '—'
}

function monthLabel(value?: string) {
  if (!value) return ''
  const [year, month] = value.split('-').map(Number)
  if (!year || !month) return value
  return new Intl.DateTimeFormat('pt-BR', { month: 'short', year: '2-digit', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, 1)))
}

function numeric(value: string | null | undefined) {
  if (value === null || value === undefined || value === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

export function SimulationReportPage({ simulationId }: { simulationId: string }) {
  const [report, setReport] = useState<Report | null>(() => getCachedApiResponse<{ data: Report }>(`/simulations/${simulationId}/report`)?.data ?? null)
  const [loading, setLoading] = useState(() => !getCachedApiResponse(`/simulations/${simulationId}/report`))
  const [errorMessage, setErrorMessage] = useState('')

  useEffect(() => {
    if (!getAuthToken()) {
      window.history.replaceState({}, '', '/login')
      window.dispatchEvent(new PopStateEvent('popstate'))
      return
    }
    let active = true
    let inFlight = false
    let loaded = getCachedApiResponse(`/simulations/${simulationId}/report`) !== undefined
    function loadReport() {
      if (inFlight || document.visibilityState === 'hidden') return
      inFlight = true
      apiRequest<{ data: Report }>(`/simulations/${simulationId}/report`, { cache: 'no-store' }, { notifyGlobalError: false, cacheTtlMs: 60_000, forceRefresh: true })
        .then((response) => {
          if (active) {
            loaded = true
            setReport(response.data)
            setErrorMessage('')
          }
        })
        .catch((error: unknown) => {
          if (active && !loaded) setErrorMessage(error instanceof Error ? error.message : 'Não foi possível carregar o relatório.')
        })
        .finally(() => {
          inFlight = false
          if (active) setLoading(false)
        })
    }
    loadReport()
    window.addEventListener('focus', loadReport)
    window.addEventListener('pageshow', loadReport)
    document.addEventListener('visibilitychange', loadReport)
    return () => {
      active = false
      window.removeEventListener('focus', loadReport)
      window.removeEventListener('pageshow', loadReport)
      document.removeEventListener('visibilitychange', loadReport)
    }
  }, [simulationId])

  if (loading) return <section className="access-page" aria-live="polite"><p>Carregando seu relatório…</p></section>
  if (errorMessage || !report) return <section className="access-page" aria-labelledby="report-error-title"><h1 id="report-error-title">Relatório indisponível</h1><p className="field-error" role="alert">{errorMessage || 'Não foi possível carregar este relatório.'}</p><NavigationLink direction="back" href="/upload-faturas">Voltar às faturas</NavigationLink></section>
  return <SimulationReportView report={report} simulationId={simulationId} />
}

export function SimulationReportView({ report, simulationId, backHref = '/minhas-simulacoes', backLabel = 'Minhas simulações', pdfEndpoint = `/simulations/${simulationId}/pdf`, pdfDemands, children }: {
  report: Report
  simulationId: string
  backHref?: string
  backLabel?: string
  pdfEndpoint?: string
  pdfDemands?: Record<string, string>
  children?: ReactNode
}) {
  const [pdfMessage, setPdfMessage] = useState('')
  const [downloading, setDownloading] = useState(false)
  const demandData = useMemo(() => (report?.chart_series.demand ?? []).map((point) => {
    const contracted = numeric(point.contracted)
    const optimal = numeric(point.optimal) ?? numeric(
      point.time_slot === 'peak' ? report?.optimal_demand_peak : point.time_slot === 'off_peak' ? report?.optimal_demand_off_peak : report?.optimal_demand_single,
    )
    return {
      month: monthLabel(point.reference_month),
      timeSlot: point.time_slot ?? 'unique',
      measured: numeric(point.measured),
      contracted,
      contractedTolerance: contracted === null ? null : contracted * 1.05,
      optimal,
      optimalTolerance: optimal === null ? null : optimal * 1.05,
    }
  }), [report])

  const reactiveData = useMemo(() => (report?.chart_series.reactive ?? []).map((point) => ({
    month: monthLabel(point.reference_month),
    value: numeric(point.value),
  })), [report])

  const modalityData = useMemo(() => (report?.chart_series.modality ?? report?.chart_series.blue_green ?? []).map((point) => ({
    month: monthLabel(point.reference_month),
    Azul: numeric(point.blue),
    Verde: numeric(point.green),
  })), [report])

  async function downloadPdf() {
    setPdfMessage('')
    setDownloading(true)
    try {
      const token = getAuthToken()
      const response = await fetch(`/api/v1${pdfEndpoint}`, {
        method: pdfDemands ? 'POST' : 'GET',
        body: pdfDemands ? JSON.stringify({ demands: pdfDemands }) : undefined,
        headers: token ? { Authorization: `Bearer ${token}`, Accept: 'application/pdf', ...(pdfDemands ? { 'Content-Type': 'application/json' } : {}) } : { Accept: 'application/pdf', ...(pdfDemands ? { 'Content-Type': 'application/json' } : {}) },
      })
      if (response.status === 401) expireAuthSession(token)
      if (!response.ok) throw new Error('Não foi possível preparar o PDF. Tente novamente.')
      const blob = await response.blob()
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `relatorio-enerdiag-${simulationId}.pdf`
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
      setPdfMessage('O download do relatório começou.')
    } catch (error) {
      setPdfMessage(error instanceof Error ? error.message : 'Não foi possível baixar o relatório.')
    } finally {
      setDownloading(false)
    }
  }


  const warnings = [...report.warnings]
  if (report.months_count > 0 && report.months_count < 12) warnings.unshift('incomplete_invoice_series')
  const uniqueWarnings = [...new Set(warnings)]
  const consultationWarning = 'demand_invoice_reconciliation_mismatch'
  const customerWarnings = uniqueWarnings.filter((warning) => !reportTexts.internal_warnings.includes(warning) && warning !== consultationWarning)
  const reserveValues = report.r_values.filter((value) => value.source === 'fallback' && value.ratio !== null && value.ratio !== '')
  const demandUnavailable = uniqueWarnings.includes('demand_savings_unavailable_pending_section_9_limits')
    || uniqueWarnings.includes('demand_optimization_unavailable_pending_section_9_limits')
    || uniqueWarnings.includes('demand_optimization_unavailable')
  const migrationUnavailable = uniqueWarnings.includes('migration_unavailable')
    || uniqueWarnings.includes('tariff_migration_unavailable_pending_section_9_rules')
  const percentageText = report.savings_percentage === null ? '—' : `${decimal.format(Number(report.savings_percentage))}%`
  const demandReferences = [...new Map((report.chart_series.demand ?? []).filter((point) => point.tariff_reference_source).map((point) => [point.time_slot, point])).values()]
  const demandReferenceMonth = report.chart_series.demand?.find((point) => point.tariff_reference_month)?.tariff_reference_month
  const demandSlots = demandData.some((point) => point.timeSlot === 'peak' || point.timeSlot === 'off_peak')
    ? [{ key: 'peak', label: 'Ponta' }, { key: 'off_peak', label: 'Fora de ponta' }]
    : [{ key: 'unique', label: 'Única' }]

  return <section className="report-page" aria-labelledby="report-title">
    <div className="report-heading">
      <div><p className="section-eyebrow">Análise concluída · Protocolo {simulationId}</p><h1 id="report-title">Seu relatório de energia</h1><p className="access-intro">Resultados calculados para {report.months_count} {report.months_count === 1 ? 'fatura' : 'faturas'}.</p></div>
      <div className="report-actions"><NavigationLink direction="back" href={backHref}>{backLabel}</NavigationLink><button className="button button--secondary" type="button" disabled={downloading} onClick={downloadPdf}>{downloading ? 'Preparando PDF…' : 'Baixar relatório em PDF'}</button></div>
    </div>
    {pdfMessage && <p className="form-success" role="status">{pdfMessage}</p>}

    {children}

    <section className="report-highlights" aria-label="Indicadores principais">
      <article className="report-highlight"><span>Economia mensal média</span><strong>{amount(report.total_monthly_savings)}</strong></article>
      <article className="report-highlight"><span>Projeção de economia anual</span><strong>{amount(report.total_yearly_savings)}</strong></article>
      <article className="report-highlight"><span>{report.explanatory_texts.percentage || '% do valor médio pago no período'}</span><strong>{percentageText}</strong><small>{report.explanatory_texts.percentage_observation?.replace('A economia usa tarifas atuais e o valor pago usa as tarifas históricas.', 'A economia usa tarifas de referência; o valor pago usa as tarifas históricas.') || 'A economia usa tarifas de referência; o valor pago usa as tarifas históricas.'}</small></article>
    </section>

    <section className="report-components" aria-labelledby="components-title">
      <div className="report-components-heading"><div><h2 id="components-title">Economia por componente</h2><p>Veja onde estão as oportunidades de economia.</p></div>
        {report.recommended_modality && <div className="report-modality-badge" data-modality={report.recommended_modality}><span>Modalidade recomendada</span><strong>{report.recommended_modality === 'blue' ? 'Azul' : report.recommended_modality === 'green' ? 'Verde' : report.recommended_modality}</strong></div>}
      </div>
      <dl>
        {[
          { label: 'Demanda', monthly: report.demand_savings_monthly, yearly: report.demand_savings_yearly, unavailable: demandUnavailable },
          { label: 'Ultrapassagem', monthly: report.overrun_savings_monthly, yearly: report.overrun_savings_yearly, unavailable: false },
          { label: 'Reativos', monthly: report.reactive_savings_monthly, yearly: report.reactive_savings_yearly, unavailable: false },
          { label: 'Migração de modalidade', monthly: report.migration_savings_monthly, yearly: report.migration_savings_yearly, unavailable: migrationUnavailable },
        ].map((component) => <div key={component.label} className={`report-component${!component.unavailable && Number(component.monthly) > 0 ? ' report-component--positive' : ''}`}><dt>{component.label}</dt><dd>{component.unavailable ? <strong className="report-component-unavailable">Indisponível</strong> : <><div className="report-component-monthly"><strong>{amount(component.monthly)}</strong><span>/ mês</span></div><div className="report-component-yearly"><strong>{amount(component.yearly)}</strong><span>/ ano</span></div></>}</dd></div>)}
      </dl>
      {demandReferences.length > 0 ? <div className="report-tariff-note"><h3>Tarifas utilizadas na comparação</h3><p>{reportTexts.demand_reference_note}</p><div className="report-tariff-references">{demandReferences.map((point) => <p key={point.time_slot}><strong>{point.time_slot === 'peak' ? 'Ponta' : point.time_slot === 'off_peak' ? 'Fora de ponta' : 'Única'}: {point.reference_tariff?.replace('.', ',')} R$/kW</strong><span>{reportTexts.demand_reference_sources[point.tariff_reference_source as keyof typeof reportTexts.demand_reference_sources] ?? 'Tarifa de referência'} · {monthLabel(point.tariff_reference_month ?? undefined)}</span></p>)}</div></div> : demandReferenceMonth && <p className="report-chart-note">{reportTexts.demand_comparison_note.replace('{month}', monthLabel(demandReferenceMonth))}</p>}
    </section>

    {uniqueWarnings.includes(consultationWarning) && <section className="report-consultation" aria-labelledby="consultation-title">
      <div className="report-consultation-content"><p className="section-eyebrow">Consultoria especializada</p><h2 id="consultation-title">{reportTexts.consultation_title}</h2><p>{reportTexts.warnings.demand_invoice_reconciliation_mismatch}</p></div>
      <NavigationLink href="#enerdiag-contact" variant="primary">{reportTexts.consultation_contact}</NavigationLink>
    </section>}
    {customerWarnings.length > 0 && <section className="report-warnings" aria-labelledby="warnings-title"><h2 id="warnings-title">Avisos da análise</h2><ul>{customerWarnings.map((warning) => <li key={warning}>{warningText[warning] ?? reportTexts.fallback_warning}</li>)}</ul></section>}
    {reserveValues.length > 0 && <section className="report-ratio" aria-label="Reserva da demanda ociosa"><h2>Reserva aplicada à demanda ociosa</h2><p>Foi usada a proporção r = {decimal.format(Number(reserveValues[0].ratio) * 100)}% nos períodos em que a fatura não indicou essa proporção.</p></section>}

    <section className="report-charts" aria-label="Gráficos do relatório">
      {demandSlots.map(({ key, label }) => {
        const points = demandData.filter((point) => point.timeSlot === key)
        return <article className="report-chart" key={key}><h2>Demanda {label.toLowerCase()}: registrada × contratada × ótima</h2>{points.length ? <div className="chart-container" data-testid={`demand-chart-${key}`} aria-label={`Gráfico de demanda ${label.toLowerCase()} em kW`}><ResponsiveContainer width="100%" height={280}><LineChart data={points} margin={{ top: 8, right: 12, left: 0, bottom: 8 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="month" tick={{ fill: 'var(--text)', fontSize: 11 }} /><YAxis unit=" kW" tick={{ fill: 'var(--text)', fontSize: 11 }} /><Tooltip formatter={(value) => value == null ? '—' : `${decimal.format(Number(value))} kW`} /><Legend /><Line type="monotone" dataKey="measured" name="Registrada" stroke="#0b8f6a" strokeWidth={2} connectNulls /><Line type="monotone" dataKey="contracted" name="Contratada" stroke="#2764c5" strokeWidth={2} connectNulls /><Line type="monotone" dataKey="contractedTolerance" name="Contratada + 5%" stroke="#dc2626" strokeWidth={2} strokeDasharray="6 4" dot={false} /><Line type="monotone" dataKey="optimal" name="Ótima" stroke="#b86d13" strokeWidth={2} connectNulls /><Line type="monotone" dataKey="optimalTolerance" name="Ótima + 5%" stroke="#a855f7" strokeWidth={2} strokeDasharray="6 4" dot={false} /></LineChart></ResponsiveContainer></div> : <p className="chart-empty">A série de demanda não está disponível neste relatório.</p>}{points.length > 0 && <p className="report-chart-note">As linhas tracejadas indicam +5% sobre a demanda contratada e sobre a ótima simulada, para comparar a medição com o limite de tolerância de cada cenário.</p>}</article>
      })}
      <article className="report-chart"><h2>Economia com reativos por mês</h2>{reactiveData.length ? <div className="chart-container" data-testid="reactive-chart" aria-label="Gráfico mensal da economia com reativos"><ResponsiveContainer width="100%" height={280}><BarChart data={reactiveData} margin={{ top: 8, right: 12, left: 0, bottom: 8 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="month" tick={{ fill: 'var(--text)', fontSize: 11 }} /><YAxis tickFormatter={(value) => `R$ ${decimal.format(Number(value))}`} tick={{ fill: 'var(--text)', fontSize: 11 }} /><Tooltip formatter={(value) => amount(String(value ?? ''))} /><Bar dataKey="value" name="Economia mensal" fill="#0b8f6a" radius={[4, 4, 0, 0]} /></BarChart></ResponsiveContainer></div> : <p className="chart-empty">A série de reativos não está disponível neste relatório.</p>}</article>
      <article className="report-chart"><h2>Comparação entre Azul e Verde</h2>{report.chart_series.modality?.[0]?.tariff_reference_date && <p className="report-chart-note">{reportTexts.modality_reference_note}</p>}{modalityData.length ? <div className="chart-container" data-testid="modality-chart" aria-label="Comparação dos custos da modalidade Azul e Verde"><ResponsiveContainer width="100%" height={280}><BarChart data={modalityData} margin={{ top: 8, right: 12, left: 0, bottom: 8 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="month" tick={{ fill: 'var(--text)', fontSize: 11 }} /><YAxis tickFormatter={(value) => `R$ ${decimal.format(Number(value))}`} tick={{ fill: 'var(--text)', fontSize: 11 }} /><Tooltip formatter={(value) => amount(String(value ?? ''))} /><Legend /><Bar dataKey="Azul" fill="#2764c5" /><Bar dataKey="Verde" fill="#0b8f6a" /></BarChart></ResponsiveContainer></div> : <p className="chart-empty">A comparação não está disponível sem tarifas válidas e dados suficientes para as duas modalidades.</p>}</article>
    </section>

    {report.recommendations.length > 0 && <section className="report-recommendations" aria-labelledby="recommendations-title"><h2 id="recommendations-title">Próximos passos</h2><ul>{report.recommendations.map((recommendation, index) => <li key={`${recommendation}-${index}`}>{recommendation}</li>)}</ul></section>}
  </section>
}
