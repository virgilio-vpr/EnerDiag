import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { CustomerSimulationsPage } from './CustomerSimulationsPage'
import { setAuthToken } from '../services/api/client'

function simulation(id: number, status: 'draft' | 'completed' | 'queued' | 'failed' = 'completed') {
  return { id, status, months_count: status === 'completed' ? 5 : 0, invoice_count: 5, total_monthly_savings: '100.00', created_at: '2026-11-04T12:00:00Z' }
}
const response = (data: unknown, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } })
const payload = (rows: ReturnType<typeof simulation>[]) => ({ data: rows, meta: { total: rows.length, limit: 3, can_create: rows.length < 3, current_page: 1, last_page: 1 } })

beforeEach(() => {
  setAuthToken('customer-token')
  window.history.replaceState({}, '', '/minhas-simulacoes')
  window.localStorage.clear()
})
afterEach(() => {
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.localStorage.clear()
})

describe('customer simulation management', () => {
  it('lists completed and unfinished simulations and blocks a fourth one', async () => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(response(payload([simulation(1), simulation(2, 'draft'), simulation(3, 'failed')])))))
    render(<CustomerSimulationsPage />)
    expect(await screen.findByRole('heading', { name: 'Minhas simulações' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Nova simulação' })).toBeDisabled()
    expect(screen.getByText(/Exclua 1 simulação para iniciar uma nova/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ver relatório' })).toHaveAttribute('href', '/simulations/1/report')
    expect(screen.getByRole('link', { name: 'Continuar envio' })).toHaveAttribute('href', '/upload-faturas?import=2')
    expect(screen.getAllByRole('article')).toHaveLength(3)
  })

  it('requires confirmation to delete and enables a new simulation after a slot is freed', async () => {
    let rows = [simulation(1), simulation(2), simulation(3)]
    const fetchMock = vi.fn((url: string, options?: RequestInit) => {
      if (options?.method === 'DELETE') {
        expect(url).toBe('/api/v1/simulations/1')
        rows = rows.filter((row) => row.id !== 1)
        return Promise.resolve(new Response(null, { status: 204 }))
      }
      return Promise.resolve(response(payload(rows)))
    })
    vi.stubGlobal('fetch', fetchMock)
    window.localStorage.setItem('enerdiag_invoice_import_id', '1')
    render(<CustomerSimulationsPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Excluir simulação nº 1' }))
    expect(fetchMock.mock.calls.filter(([, options]) => options?.method === 'DELETE')).toHaveLength(0)
    const confirmation = screen.getByRole('group', { name: 'Confirmar exclusão da simulação nº 1' })
    fireEvent.click(within(confirmation).getByRole('button', { name: 'Confirmar exclusão' }))
    expect(await screen.findByRole('link', { name: 'Nova simulação' })).toHaveAttribute('href', '/upload-faturas?new=1')
    expect(screen.queryByRole('heading', { name: 'Simulação nº 1' })).not.toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Simulação nº 1 excluída.')
    expect(window.localStorage.getItem('enerdiag_invoice_import_id')).toBeNull()
  })

  it('resumes a queued calculation directly from saved invoices', async () => {
    const fetchMock = vi.fn((url: string, options?: RequestInit) => Promise.resolve(options?.method === 'POST' && url.endsWith('/process')
      ? response({ data: { id: 8, status: 'completed' } })
      : response(payload([simulation(8, 'queued')]))))
    vi.stubGlobal('fetch', fetchMock)
    render(<CustomerSimulationsPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Calcular agora' }))
    await waitFor(() => expect(window.location.pathname).toBe('/simulations/8/report'))
    expect(fetchMock.mock.calls.some(([url, options]) => url === '/api/v1/simulations/8/process' && options?.method === 'POST')).toBe(true)
  })

  it('preserves legacy simulations above the limit and does not delete automatically', async () => {
    const fetchMock = vi.fn(() => Promise.resolve(response(payload(Array.from({ length: 5 }, (_, index) => simulation(index + 1))))))
    vi.stubGlobal('fetch', fetchMock)
    render(<CustomerSimulationsPage />)
    expect(await screen.findByText(/Exclua 3 simulações para iniciar uma nova/)).toBeInTheDocument()
    expect(screen.getAllByRole('article')).toHaveLength(5)
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('keeps the simulation when deletion is refused by the server', async () => {
    vi.stubGlobal('fetch', vi.fn((url: string, options?: RequestInit) => Promise.resolve(options?.method === 'DELETE' && url.endsWith('/simulations/1')
      ? response({ message: 'A simulação está sendo calculada.' }, 409)
      : response(payload([simulation(1)])))))
    render(<CustomerSimulationsPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Excluir simulação nº 1' }))
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar exclusão' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('A simulação está sendo calculada.')
    expect(screen.getByRole('heading', { name: 'Simulação nº 1' })).toBeInTheDocument()
  })

  it('requires login before loading simulations', async () => {
    setAuthToken(null)
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    render(<CustomerSimulationsPage />)
    await waitFor(() => expect(window.location.pathname).toBe('/login'))
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
