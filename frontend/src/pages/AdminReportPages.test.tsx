import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AdminReportDetailPage, AdminReportsPage } from './AdminReportPages'
import { setAuthToken } from '../services/api/client'

function jsonResponse(payload: unknown) {
  return new Response(JSON.stringify(payload), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

function reportData() {
  return {
    id: 13, status: 'completed', customer: { id: 5, name: 'Ana Souza', email: 'ana@example.com' },
    report: {
      id: 21, total_monthly_savings: '125.50', total_yearly_savings: '1506.00', savings_percentage: '12.34',
      demand_savings_monthly: '50.00', overrun_savings_monthly: '25.00', reactive_savings_monthly: '10.00',
      migration_savings_monthly: '40.50', recommended_modality: 'green', algorithm_version: '1.0',
      explanatory_texts: ['Texto atual'], recommendations: ['Ação atual'], warnings: ['Aviso do relatório'],
      revisions: [{ id: 8, reason: 'manual_edit', author: 'Admin' as string | null, created_at: '2026-10-24T12:00:00Z' as string | null, explanatory_texts: ['Texto anterior'], recommendations: ['Ação anterior'] }],
    },
  }
}

afterEach(() => {
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
    let currentReport = reportData()
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
