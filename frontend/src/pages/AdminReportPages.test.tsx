import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AdminReportDetailPage, AdminReportsPage } from './AdminReportPages'
import { setAuthToken } from '../services/api/client'
import reportTexts from '../../../backend/resources/report-texts.json'

function jsonResponse(payload: unknown) {
  return new Response(JSON.stringify(payload), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

function reportData() {
  return {
    id: 13, status: 'completed', months_count: 6, customer: { id: 5, name: 'Ana Souza', email: 'ana@example.com' },
    report: {
      id: 21, total_monthly_savings: '125.50', total_yearly_savings: '1506.00', savings_percentage: '12.34',
      demand_savings_monthly: '50.00', overrun_savings_monthly: '25.00', reactive_savings_monthly: '10.00',
      migration_savings_monthly: '40.50', recommended_modality: 'green', algorithm_version: '1.0',
      explanatory_texts: ['Texto atual'], recommendations: ['Ação atual'], warnings: ['Aviso do relatório'],
      chart_series: { demand: [{ reference_month: '2026-01', time_slot: 'unique', measured: '80', contracted: '100', optimal: '77' }], reactive: [{ reference_month: '2026-01', value: '10' }], modality: [{ reference_month: 'Período', blue: '2000', green: '1800' }] },
      revisions: [{ id: 8, reason: 'manual_edit', author: 'Admin' as string | null, created_at: '2026-10-24T12:00:00Z' as string | null, explanatory_texts: ['Texto anterior'], recommendations: ['Ação anterior'] }],
    },
  }
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.history.replaceState({}, '', '/')
})

describe('admin report management', () => {
  it('lists reports and links to their detail pages', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({
      data: [{ id: 13, status: 'completed', months_count: 12, total_monthly_savings: '125.50', customer: { id: 5, name: 'Ana Souza', email: 'ana@example.com' } }],
      links: {}, meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
    })))
    render(<AdminReportsPage />)

    expect(await screen.findByRole('link', { name: 'Abrir relatório' })).toHaveAttribute('href', '/admin/reports/13')
    expect(screen.getByText('Ana Souza')).toBeInTheDocument()
  })

  it('edits text, shows immutable results and history, resends and recalculates through the API', async () => {
    const currentReport = reportData()
    let recalcCount = 0
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      const method = init?.method ?? 'GET'
      if (url === '/api/v1/admin/simulations/13' && method === 'GET') return jsonResponse({ data: currentReport })
      if (url === '/api/v1/admin/reports/21' && method === 'PUT') {
        const body = JSON.parse(String(init?.body)) as { explanatory_texts: string[]; recommendations: string[] }
        expect(body).toEqual({ explanatory_texts: ['Texto atualizado'], recommendations: ['Ação revisada'] })
        currentReport.report.explanatory_texts = body.explanatory_texts
        currentReport.report.recommendations = body.recommendations
        currentReport.report.revisions.unshift({ id: 9, reason: 'manual_edit', author: 'Admin', created_at: '2026-10-25T12:00:00Z', explanatory_texts: body.explanatory_texts, recommendations: body.recommendations })
        return jsonResponse({ data: currentReport.report })
      }
      if (url === '/api/v1/admin/reports/21/resend' && method === 'POST') return jsonResponse({ message: 'Relatório reenviado por e-mail.' })
      if (url === '/api/v1/admin/simulations/13/recalculate' && method === 'POST') {
        recalcCount += 1
        currentReport.report.total_monthly_savings = '135.50'
        currentReport.report.revisions.unshift({ id: 10, reason: 'calculation_recalculation', author: null, created_at: '2026-10-25T12:05:00Z', explanatory_texts: currentReport.report.explanatory_texts, recommendations: currentReport.report.recommendations })
        return jsonResponse({ data: { id: 21, total_monthly_savings: '135.50' } })
      }
      throw new Error(`Unexpected request ${method} ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)
    render(<AdminReportDetailPage simulationId="13" />)

    expect(await screen.findByText(/125,50/)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Histórico de versões' })).toBeInTheDocument()
    expect(screen.getByText('Texto anterior')).toBeInTheDocument()
    expect(screen.queryByRole('textbox', { name: 'Economia mensal' })).not.toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Textos explicativos'), { target: { value: 'Texto atualizado' } })
    fireEvent.change(screen.getByLabelText('Recomendações'), { target: { value: 'Ação revisada' } })
    fireEvent.click(screen.getByRole('button', { name: 'Salvar textos e criar versão' }))
    expect(await screen.findByText('Textos atualizados e nova versão registrada.')).toBeInTheDocument()
    expect(screen.getByText(/125,50/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Reenviar ao cliente' }))
    expect(await screen.findByText('Relatório reenviado por e-mail.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Recalcular resultados' }))
    expect(await screen.findByText('Relatório recalculado com sucesso. Os dados extraídos foram preservados.')).toBeInTheDocument()
    expect(screen.getByText(/135,50/)).toBeInTheDocument()
    expect(recalcCount).toBe(1)
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/reports/21/resend', expect.objectContaining({ method: 'POST' })))
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/simulations/13/recalculate', expect.objectContaining({ method: 'POST' }))
  })
})

it('shows the customer charts, PDF action and identical customer warnings above the history', async () => {
  const data = reportData()
  data.report.warnings = ['no_savings_on_migration', 'idle_demand_pis_cofins_inconsistent', 'tax_calibration_factor_divergence', 'demand_invoice_reconciliation_mismatch', 'free_market_invoice', 'unknown_analysis_warning']
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ data })))
  render(<AdminReportDetailPage simulationId="13" />)
  expect(await screen.findByRole('heading', { name: 'Seu relatório de energia' })).toBeInTheDocument()
  expect(screen.getByRole('heading', { name: 'Avisos da análise' })).toBeInTheDocument()
  expect(screen.getByText(reportTexts.warnings.incomplete_invoice_series)).toBeInTheDocument()
  expect(screen.getByText(reportTexts.warnings.demand_invoice_reconciliation_mismatch)).toBeInTheDocument()
  expect(screen.getByText(reportTexts.fallback_warning)).toBeInTheDocument()
  expect(screen.queryByText(reportTexts.warnings.idle_demand_pis_cofins_inconsistent)).not.toBeInTheDocument()
  expect(screen.getByTestId('demand-chart-unique')).toBeInTheDocument()
  expect(screen.getByTestId('reactive-chart')).toBeInTheDocument()
  expect(screen.getByTestId('modality-chart')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Baixar relatório em PDF' })).toBeInTheDocument()
  expect(screen.getByRole('heading', { name: 'Histórico de versões' }).compareDocumentPosition(screen.getByTestId('modality-chart')) & Node.DOCUMENT_POSITION_PRECEDING).toBeTruthy()
})

it('previews admin demands without saving the customer report', async () => {
  const data = { ...reportData(), invoices: [{ tariff_modality: 'blue', reference_month: '2026-01', items: [{ item_type: 'contracted_demand', time_slot: 'peak', quantity: '338.000' }, { item_type: 'contracted_demand', time_slot: 'off_peak', quantity: '372.000' }] }] }
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    if (String(input).endsWith('/preview-demand')) {
      expect(JSON.parse(String(init?.body))).toEqual({ demands: { peak: '120', off_peak: '200' } })
      return jsonResponse({ data: { ...data.report, demand_savings_monthly: '-10.00', chart_series: { ...data.report.chart_series, demand: [{ reference_month: '2026-01', time_slot: 'peak', measured: '80', contracted: '100', optimal: '120' }, { reference_month: '2026-01', time_slot: 'off_peak', measured: '180', contracted: '200', optimal: '200' }] } } })
    }
    return jsonResponse({ data })
  })
  vi.stubGlobal('fetch', fetchMock)
  render(<AdminReportDetailPage simulationId="13" />)
  expect(await screen.findByLabelText('Demanda de ponta (kW)')).toHaveDisplayValue('338')
  expect(screen.getByLabelText('Demanda fora de ponta (kW)')).toHaveDisplayValue('372')
  expect(screen.getByLabelText('Demanda de ponta (kW)')).toHaveAttribute('step', '1')
  fireEvent.change(screen.getByLabelText('Demanda de ponta (kW)'), { target: { value: '120' } })
  fireEvent.change(screen.getByLabelText('Demanda fora de ponta (kW)'), { target: { value: '200' } })
  fireEvent.click(screen.getByRole('button', { name: 'Simular demandas' }))
  expect(await screen.findByText(/Prévia das demandas aplicadas/)).toBeInTheDocument()
  expect(screen.getByText(/-.*10,00/)).toBeInTheDocument()
  expect(screen.getByTestId('demand-chart-peak')).toBeInTheDocument()
  expect(screen.getByTestId('demand-chart-off_peak')).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Restaurar relatório original' }))
  expect(screen.getByTestId('demand-chart-unique')).toBeInTheDocument()
  expect(screen.queryByText(/Prévia das demandas aplicadas/)).not.toBeInTheDocument()
  expect(fetchMock).toHaveBeenCalledTimes(2)
})

it('uses the green demand field and downloads the displayed scenario as PDF', async () => {
  setAuthToken('admin-token')
  const data = { ...reportData(), invoices: [{ tariff_modality: 'green', reference_month: '2026-01', items: [{ item_type: 'contracted_demand', time_slot: 'unique', quantity: '100.000' }] }] }
  const createUrl = vi.fn(() => 'blob:admin-report')
  const revokeUrl = vi.fn()
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: createUrl, revokeObjectURL: revokeUrl }))
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/pdf')) {
      if (init?.method === 'POST') expect(JSON.parse(String(init.body))).toEqual({ demands: { unique: '120' } })
      return new Response(new Blob(['%PDF-1.4 test'], { type: 'application/pdf' }), { status: 200 })
    }
    if (url.endsWith('/preview-demand')) return jsonResponse({ data: { ...data.report, total_monthly_savings: '80.00' } })
    return jsonResponse({ data })
  })
  vi.stubGlobal('fetch', fetchMock)
  render(<AdminReportDetailPage simulationId="13" />)
  const demand = await screen.findByLabelText('Demanda única (kW)')
  expect(demand).toHaveDisplayValue('100')
  expect(demand).toHaveAttribute('step', '1')
  expect(screen.queryByLabelText('Demanda de ponta (kW)')).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Baixar relatório em PDF' }))
  await screen.findByText('O download do relatório começou.')
  expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/simulations/13/pdf', expect.objectContaining({ method: 'GET' }))
  fireEvent.change(demand, { target: { value: '120' } })
  fireEvent.click(screen.getByRole('button', { name: 'Simular demandas' }))
  await screen.findByText(/Prévia das demandas aplicadas/)
  fireEvent.click(screen.getByRole('button', { name: 'Baixar relatório em PDF' }))
  await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/simulations/13/preview-demand/pdf', expect.objectContaining({ method: 'POST', headers: expect.objectContaining({ Authorization: 'Bearer admin-token' }) })))
  await waitFor(() => expect(click).toHaveBeenCalledTimes(2))
  expect(revokeUrl).toHaveBeenCalledTimes(2)
  fireEvent.change(demand, { target: { value: '130' } })
  expect(screen.queryByText(/Prévia das demandas aplicadas/)).not.toBeInTheDocument()
  expect(screen.getByText(/125,50/)).toBeInTheDocument()
})
