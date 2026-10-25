import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AdminLeadsPage } from './AdminLeadsPage'
import { setAuthToken } from '../services/api/client'

function jsonResponse(payload: unknown) {
  return new Response(JSON.stringify(payload), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

function page(data: unknown[], currentPage: number, lastPage: number, total: number) {
  return { data, links: {}, meta: { current_page: currentPage, last_page: lastPage, per_page: 15, total } }
}

const contactRequest = {
  type: 'contact_request', id: 3, email: 'ana@example.com', distributor_name: null, reason: null,
  customer_name: 'Ana Souza', requested_at: '2026-10-24T12:00:00Z',
}
const distributorLead = {
  type: 'unsupported_distributor', id: 8, email: 'lead@example.com', distributor_name: 'Distribuidora Solar',
  reason: 'Fora da área atendida', customer_name: null, requested_at: '2026-10-23T12:00:00Z',
}

afterEach(() => {
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.history.replaceState({}, '', '/')
})

describe('admin leads page', () => {
  it('lists both record types, paginates and filters by type and search', async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      if (url === '/api/v1/admin/leads?page=1') return jsonResponse(page([contactRequest], 1, 2, 16))
      if (url === '/api/v1/admin/leads?page=2') return jsonResponse(page([distributorLead], 2, 2, 16))
      if (url === '/api/v1/admin/leads?page=1&type=unsupported_distributor&search=Solar') return jsonResponse(page([distributorLead], 1, 1, 1))
      throw new Error(`Unexpected request ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)
    render(<AdminLeadsPage />)

    expect(await screen.findByText('Ana Souza')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Próxima' }))
    expect(await screen.findByText('Distribuidora Solar')).toBeInTheDocument()
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/leads?page=2', expect.objectContaining({ headers: expect.any(Headers) })))

    fireEvent.change(screen.getByLabelText('Tipo de registro'), { target: { value: 'unsupported_distributor' } })
    fireEvent.change(screen.getByLabelText('Buscar'), { target: { value: 'Solar' } })
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar filtros' }))
    expect(await screen.findByText('Distribuidora Solar')).toBeInTheDocument()
    expect(screen.getByText('Fora da área atendida')).toBeInTheDocument()
    expect(screen.queryByText('Ana Souza')).not.toBeInTheDocument()
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/leads?page=1&type=unsupported_distributor&search=Solar', expect.objectContaining({ headers: expect.any(Headers) })))
  })
})
