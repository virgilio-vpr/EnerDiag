import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { SimulationReportPage } from './SimulationReportPage'
import { setAuthToken } from '../services/api/client'

const report = {
  id: 8,
  simulation_id: 12,
  months_count: 6,
  total_monthly_savings: '1250.40',
  total_yearly_savings: '15004.80',
  savings_percentage: '12.50',
  demand_savings_monthly: '0.00',
  demand_savings_yearly: '0.00',
  overrun_savings_monthly: '0.00',
  overrun_savings_yearly: '0.00',
  reactive_savings_monthly: '1250.40',
  reactive_savings_yearly: '15004.80',
  migration_savings_monthly: '0.00',
  migration_savings_yearly: '0.00',
  recommended_modality: null,
  optimal_demand_peak: null,
  optimal_demand_off_peak: null,
  optimal_demand_single: null,
  chart_series: {
    demand: [{ reference_month: '2026-01', time_slot: 'peak', measured: '450.000', contracted: '500.000' }],
    reactive: [{ reference_month: '2026-01', value: '1250.40' }],
    modality: [] as Array<{ reference_month: string; blue: string; green: string }>,
  },
  warnings: [
    'tariff_unavailable_using_historical_value',
    'idle_demand_discount_fallback',
    'demand_invoice_reconciliation_mismatch',
    'tariff_migration_unavailable_pending_section_9_rules',
  ],
  explanatory_texts: {},
  recommendations: ['Confira as condições antes de alterar a demanda contratada.'],
  r_values: [{ reference_month: '2026-01', time_slot: 'peak', ratio: '0.820000', source: 'fallback', inherited: false }],
}

function setupFetch(responses: Record<string, Response | Blob> = {}, reportData = report) {
  const reportResponse = new Response(JSON.stringify({ data: reportData }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  const fetchMock = vi.fn((input: RequestInfo | URL) => Promise.resolve(responses[String(input)] ?? reportResponse))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.history.replaceState({}, '', '/')
})

describe('simulation report', () => {
  it('renders indicators, charts, D14 explanation, and relevant warnings', async () => {
    setAuthToken('customer-token')
    setupFetch()
    render(<SimulationReportPage simulationId="12" />)

    expect(await screen.findByRole('heading', { name: 'Seu relatório de energia' })).toBeInTheDocument()
    expect(screen.getByText((_, element) => element?.tagName === 'STRONG' && Boolean(element.textContent?.includes('1.250,40')))).toBeInTheDocument()
    expect(screen.getByText('12,5%')).toBeInTheDocument()
    expect(screen.getByText('A economia usa tarifas atuais e o valor pago usa as tarifas históricas.')).toBeInTheDocument()
    expect(screen.getByText(/menos de 12 faturas/)).toBeInTheDocument()
    expect(screen.getByText(/tarifa vigente para todos os períodos/)).toBeInTheDocument()
    expect(screen.getByText(/r = 82%/)).toBeInTheDocument()
    expect(screen.getByText(/diverge do valor registrado/)).toBeInTheDocument()
    expect(screen.getByTestId('demand-chart')).toBeInTheDocument()
    expect(screen.getByTestId('reactive-chart')).toBeInTheDocument()
    expect(screen.getByText(/Comparação entre Azul e Verde/)).toBeInTheDocument()
    expect(screen.getByText(/critérios regulatórios pendentes forem definidos/)).toBeInTheDocument()
    expect(screen.getByText('Confira as condições antes de alterar a demanda contratada.')).toBeInTheDocument()
  })

  it('escapes untrusted recommendations instead of creating executable markup', async () => {
    const payload = '<img src=x onerror="alert(document.cookie)">'
    setAuthToken('customer-token')
    setupFetch({}, { ...report, recommendations: [payload] })
    const { container } = render(<SimulationReportPage simulationId="12" />)

    expect(await screen.findByText(payload)).toBeInTheDocument()
    expect(container.querySelector('img[src="x"]')).toBeNull()
    expect(container.querySelector('[onerror]')).toBeNull()
  })

  it('downloads the PDF from the simulation endpoint', async () => {
    setAuthToken('customer-token')
    const fetchMock = setupFetch({ '/api/v1/simulations/12/pdf': new Response('%PDF-1.4', { status: 200, headers: { 'Content-Type': 'application/pdf' } }) })
    vi.stubGlobal('URL', { createObjectURL: vi.fn(() => 'blob:report'), revokeObjectURL: vi.fn() })
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    render(<SimulationReportPage simulationId="12" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Baixar relatório em PDF' }))

    expect(await screen.findByRole('status')).toHaveTextContent('O download do relatório começou.')
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/simulations/12/pdf', expect.objectContaining({
      headers: expect.objectContaining({ Authorization: 'Bearer customer-token' }),
    })))
  })

  it('registers a commercial contact request', async () => {
    setAuthToken('customer-token')
    const fetchMock = setupFetch({ '/api/v1/me/contact-request': new Response(JSON.stringify({ message: 'Solicitação registrada.' }), { status: 200, headers: { 'Content-Type': 'application/json' } }) })
    render(<SimulationReportPage simulationId="12" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Solicitar contato comercial' }))

    expect(await screen.findByRole('status')).toHaveTextContent('Nossa equipe entrará em contato')
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/me/contact-request', expect.objectContaining({ method: 'POST' }))
    expect(screen.getByRole('button', { name: 'Contato solicitado' })).toBeDisabled()
  })

  it('renders the Azul and Verde comparison when the report has both series', async () => {
    setAuthToken('customer-token')
    setupFetch({}, {
      ...report,
      months_count: 12,
      warnings: [],
      chart_series: {
        ...report.chart_series,
        modality: [{ reference_month: '2026-01', blue: '12000.00', green: '10500.00' }],
      },
    })
    render(<SimulationReportPage simulationId="12" />)

    expect(await screen.findByTestId('modality-chart')).toBeInTheDocument()
  })
})
