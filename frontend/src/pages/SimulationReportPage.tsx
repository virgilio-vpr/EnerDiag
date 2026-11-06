import { useEffect, useMemo, useState } from 'react'
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
import { apiRequest, getAuthToken, getCachedApiResponse } from '../services/api/client'
import reportTexts from '../../../backend/resources/report-texts.json'
import { CompanyContactDetails } from '../components/CompanyContactDetails'

type ChartPoint = {
  reference_month?: string
  time_slot?: string
  value?: string | null
  measured?: string | null
  contracted?: string | null
  optimal?: string | null
  blue?: string | null
  green?: string | null
}

type Report = {
  id: number
  simulation_id: number
  months_count: number
  total_monthly_savings: string
  total_yearly_savings: string
  savings_percentage: string | null
  demand_savings_monthly: string
  demand_savings_yearly: string
  overrun_savings_monthly: string
  overrun_savings_yearly: string
  reactive_savings_monthly: string
  reactive_savings_yearly: string
  migration_savings_monthly: string
  migration_savings_yearly: string
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
  const [pdfMessage, setPdfMessage] = useState('')
  const [downloading, setDownloading] = useState(false)

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
      const response = await fetch(`/api/v1/simulations/${simulationId}/pdf`, {
        headers: token ? { Authorization: `Bearer ${token}`, Accept: 'application/pdf' } : { Accept: 'application/pdf' },
      })
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


  if (loading) return <section className="access-page" aria-live="polite"><p>Carregando seu relatório…</p></section>
  if (errorMessage || !report) return <section className="access-page" aria-labelledby="report-error-title"><h1 id="report-error-title">Relatório indisponível</h1><p className="field-error" role="alert">{errorMessage || 'Não foi possível carregar este relatório.'}</p><a className="button" href="/upload-faturas">Voltar às faturas</a></section>

  const warnings = [...report.warnings]
  if (report.months_count > 0 && report.months_count < 12) warnings.unshift('incomplete_invoice_series')
  const uniqueWarnings = [...new Set(warnings)]
  const customerWarnings = uniqueWarnings.filter((warning) => !reportTexts.internal_warnings.includes(warning))
  const reserveValues = report.r_values.filter((value) => value.source === 'fallback' && value.ratio !== null && value.ratio !== '')
  const demandUnavailable = uniqueWarnings.includes('demand_savings_unavailable_pending_section_9_limits')
    || uniqueWarnings.includes('demand_optimization_unavailable_pending_section_9_limits')
    || uniqueWarnings.includes('demand_optimization_unavailable')
  const migrationUnavailable = uniqueWarnings.includes('migration_unavailable')
    || uniqueWarnings.includes('tariff_migration_unavailable_pending_section_9_rules')
  const percentageText = report.savings_percentage === null ? '—' : `${decimal.format(Number(report.savings_percentage))}%`
  const demandSlots = demandData.some((point) => point.timeSlot === 'peak' || point.timeSlot === 'off_peak')
    ? [{ key: 'peak', label: 'Ponta' }, { key: 'off_peak', label: 'Fora de ponta' }]
    : [{ key: 'unique', label: 'Única' }]

  return <section className="report-page" aria-labelledby="report-title">
    <div className="report-heading">
      <div><p className="section-eyebrow">Análise concluída · Protocolo {simulationId}</p><h1 id="report-title">Seu relatório de energia</h1><p className="access-intro">Resultados calculados para {report.months_count} {report.months_count === 1 ? 'fatura' : 'faturas'}.</p><CompanyContactDetails /></div>
      <div className="report-actions"><a className="button button--secondary" href="/minhas-simulacoes">Minhas simulações</a><button className="button button--secondary" type="button" disabled={downloading} onClick={downloadPdf}>{downloading ? 'Preparando PDF…' : 'Baixar relatório em PDF'}</button></div>
    </div>
    {pdfMessage && <p className="form-success" role="status">{pdfMessage}</p>}

    <section className="report-highlights" aria-label="Indicadores principais">
      <article className="report-highlight"><span>Economia mensal média</span><strong>{amount(report.total_monthly_savings)}</strong></article>
      <article className="report-highlight"><span>Projeção de economia anual</span><strong>{amount(report.total_yearly_savings)}</strong></article>
      <article className="report-highlight"><span>{report.explanatory_texts.percentage || '% do valor médio pago no período'}</span><strong>{percentageText}</strong><small>{report.explanatory_texts.percentage_observation || 'A economia usa tarifas atuais e o valor pago usa as tarifas históricas.'}</small></article>
    </section>

    <section className="report-components" aria-labelledby="components-title"><h2 id="components-title">Economia por componente</h2><dl>
      <div><dt>Demanda</dt><dd>{demandUnavailable ? 'Indisponível' : `${amount(report.demand_savings_monthly)} / mês · ${amount(report.demand_savings_yearly)} / ano`}</dd></div>
      <div><dt>Ultrapassagem</dt><dd>{amount(report.overrun_savings_monthly)} / mês · {amount(report.overrun_savings_yearly)} / ano</dd></div>
      <div><dt>Reativos</dt><dd>{amount(report.reactive_savings_monthly)} / mês · {amount(report.reactive_savings_yearly)} / ano</dd></div>
      <div><dt>Migração de modalidade</dt><dd>{migrationUnavailable ? 'Indisponível' : `${amount(report.migration_savings_monthly)} / mês · ${amount(report.migration_savings_yearly)} / ano`}</dd></div>
      {report.recommended_modality && <div><dt>Modalidade recomendada</dt><dd>{report.recommended_modality === 'blue' ? 'Azul' : report.recommended_modality === 'green' ? 'Verde' : report.recommended_modality}</dd></div>}
    </dl></section>

    {customerWarnings.length > 0 && <section className="report-warnings" aria-labelledby="warnings-title"><h2 id="warnings-title">Avisos da análise</h2><ul>{customerWarnings.map((warning) => <li key={warning}>{warningText[warning] ?? reportTexts.fallback_warning}</li>)}</ul></section>}
    {reserveValues.length > 0 && <section className="report-ratio" aria-label="Reserva da demanda ociosa"><h2>Reserva aplicada à demanda ociosa</h2><p>Foi usada a proporção r = {decimal.format(Number(reserveValues[0].ratio) * 100)}% nos períodos em que a fatura não indicou essa proporção.</p></section>}

    <section className="report-charts" aria-label="Gráficos do relatório">
      {demandSlots.map(({ key, label }) => {
        const points = demandData.filter((point) => point.timeSlot === key)
        return <article className="report-chart" key={key}><h2>Demanda {label.toLowerCase()}: registrada × contratada × ótima</h2>{points.length ? <div className="chart-container" data-testid={`demand-chart-${key}`} aria-label={`Gráfico de demanda ${label.toLowerCase()} em kW`}><ResponsiveContainer width="100%" height={280}><LineChart data={points} margin={{ top: 8, right: 12, left: 0, bottom: 8 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="month" tick={{ fill: 'var(--text)', fontSize: 11 }} /><YAxis unit=" kW" tick={{ fill: 'var(--text)', fontSize: 11 }} /><Tooltip formatter={(value) => value == null ? '—' : `${decimal.format(Number(value))} kW`} /><Legend /><Line type="monotone" dataKey="measured" name="Registrada" stroke="#0b8f6a" strokeWidth={2} connectNulls /><Line type="monotone" dataKey="contracted" name="Contratada" stroke="#2764c5" strokeWidth={2} connectNulls /><Line type="monotone" dataKey="contractedTolerance" name="Contratada + 5%" stroke="#dc2626" strokeWidth={2} strokeDasharray="6 4" dot={false} /><Line type="monotone" dataKey="optimal" name="Ótima" stroke="#b86d13" strokeWidth={2} connectNulls /><Line type="monotone" dataKey="optimalTolerance" name="Ótima + 5%" stroke="#a855f7" strokeWidth={2} strokeDasharray="6 4" dot={false} /></LineChart></ResponsiveContainer></div> : <p className="chart-empty">A série de demanda não está disponível neste relatório.</p>}{points.length > 0 && <p className="report-chart-note">As linhas tracejadas indicam +5% sobre a demanda contratada e sobre a ótima simulada, para comparar a medição com o limite de tolerância de cada cenário.</p>}</article>
      })}
      <article className="report-chart"><h2>Economia com reativos por mês</h2>{reactiveData.length ? <div className="chart-container" data-testid="reactive-chart" aria-label="Gráfico mensal da economia com reativos"><ResponsiveContainer width="100%" height={280}><BarChart data={reactiveData} margin={{ top: 8, right: 12, left: 0, bottom: 8 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="month" tick={{ fill: 'var(--text)', fontSize: 11 }} /><YAxis tickFormatter={(value) => `R$ ${decimal.format(Number(value))}`} tick={{ fill: 'var(--text)', fontSize: 11 }} /><Tooltip formatter={(value) => amount(String(value ?? ''))} /><Bar dataKey="value" name="Economia mensal" fill="#0b8f6a" radius={[4, 4, 0, 0]} /></BarChart></ResponsiveContainer></div> : <p className="chart-empty">A série de reativos não está disponível neste relatório.</p>}</article>
      <article className="report-chart"><h2>Comparação entre Azul e Verde</h2>{modalityData.length ? <div className="chart-container" data-testid="modality-chart" aria-label="Comparação dos custos da modalidade Azul e Verde"><ResponsiveContainer width="100%" height={280}><BarChart data={modalityData} margin={{ top: 8, right: 12, left: 0, bottom: 8 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="month" tick={{ fill: 'var(--text)', fontSize: 11 }} /><YAxis tickFormatter={(value) => `R$ ${decimal.format(Number(value))}`} tick={{ fill: 'var(--text)', fontSize: 11 }} /><Tooltip formatter={(value) => amount(String(value ?? ''))} /><Legend /><Bar dataKey="Azul" fill="#2764c5" /><Bar dataKey="Verde" fill="#0b8f6a" /></BarChart></ResponsiveContainer></div> : <p className="chart-empty">A comparação não está disponível sem tarifas válidas e dados suficientes para as duas modalidades.</p>}</article>
    </section>

    {report.recommendations.length > 0 && <section className="report-recommendations" aria-labelledby="recommendations-title"><h2 id="recommendations-title">Próximos passos</h2><ul>{report.recommendations.map((recommendation, index) => <li key={`${recommendation}-${index}`}>{recommendation}</li>)}</ul></section>}
  </section>
}
