import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AdminQuotationsPage } from './AdminQuotationsPage'
import { setAuthToken } from '../services/api/client'

function jsonResponse(status: number, payload: unknown) {
  return new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json' } })
}

const quotationDraft = {
  id: 10,
  quote_number: 'ORC-2026-000010',
  customer_id: 2,
  customer_name: 'Ana Souza',
  customer_email: 'ana@example.com',
  company_name: 'Empresa Ana Ltda.',
  status: 'draft' as const,
  valid_until: null,
  total: '500.00',
  technical_responsible_name: null,
  crea_registration: null,
  art_number: null,
  items: [{ id: 20, item_key: 'consulting_hour', name: 'Hora de consultoria', unit: 'hour', quantity: '2.000', unit_price: '250.00', total: '500.00' }],
}

afterEach(() => {
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.history.replaceState({}, '', '/')
})

describe('admin quotations page', () => {
  it('creates a draft, reviews the technical details, and releases it by email', async () => {
    const requests: Array<[string, RequestInit | undefined]> = []
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      requests.push([url, init])
      if (url === '/api/v1/admin/customers?per_page=100') {
        return jsonResponse(200, { data: [{ id: 2, name: 'Ana Souza', email: 'ana@example.com', profile: { company_name: 'Empresa Ana Ltda.' } }] })
      }
      if (url === '/api/v1/admin/quotation-items') {
        return jsonResponse(200, { data: [{ id: 1, item_key: 'consulting_hour', name: 'Hora de consultoria', unit: 'hour', unit_price: '250.00' }] })
      }
      if (url === '/api/v1/admin/quotations?page=1') return jsonResponse(200, { data: [], links: {}, meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } })
      if (url === '/api/v1/admin/quotations' && init?.method === 'POST') return jsonResponse(201, { data: quotationDraft })
      if (url === '/api/v1/admin/quotations/10/release') {
        return jsonResponse(200, { data: { ...quotationDraft, status: 'sent', valid_until: '2026-11-23', technical_responsible_name: 'Engenheira Exemplo' } })
      }
      throw new Error(`Unexpected request ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)
    setAuthToken('admin-token')
    render(<AdminQuotationsPage />)

    await screen.findByRole('option', { name: /Empresa Ana Ltda/ })
    fireEvent.change(screen.getByLabelText('Cliente'), { target: { value: '2' } })
    fireEvent.change(screen.getByLabelText('Quantidade de Hora de consultoria'), { target: { value: '2.000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Criar rascunho' }))
    expect(await screen.findByText(/Rascunho ORC-2026-000010 criado/)).toBeInTheDocument()
    expect(await screen.findByText('Rascunho')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Revisar e liberar' }))
    fireEvent.change(screen.getByLabelText('Engenheiro(a) Eletricista responsável'), { target: { value: 'Engenheira Exemplo' } })
    fireEvent.change(screen.getByLabelText('Registro CREA-SP'), { target: { value: 'CREA-SP 1234567890' } })
    fireEvent.change(screen.getByLabelText('Número da ART emitida'), { target: { value: 'ART 28027230261000001' } })
    fireEvent.click(screen.getByRole('button', { name: 'Liberar e enviar PDF' }))

    expect(await screen.findByText(/liberado e enviado para ana@example.com/)).toBeInTheDocument()
    expect(await screen.findByText('Enviado')).toBeInTheDocument()
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/quotations/10/release', expect.objectContaining({ method: 'POST' })))
    const creationRequest = requests.find(([url, init]) => url === '/api/v1/admin/quotations' && init?.method === 'POST')
    expect(JSON.parse(String(creationRequest?.[1]?.body))).toEqual({
      customer_id: 2,
      items: [{ quotation_base_id: 1, quantity: '2.000' }],
    })
  })
})
