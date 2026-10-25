import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AdminQuotationItemsPage } from './AdminQuotationItemsPage'
import { setAuthToken } from '../services/api/client'

function jsonResponse(status: number, payload: unknown) {
  return new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json' } })
}

const consultingItem = {
  id: 1,
  item_key: 'consulting_hour',
  name: 'Hora técnica de consultoria',
  description: 'Consultoria energética',
  unit: 'hour',
  unit_price: '250.00',
}

afterEach(() => {
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.history.replaceState({}, '', '/')
})

describe('admin quotation items page', () => {
  it('lists items and saves an edited price', async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input) === '/api/v1/admin/quotation-items' && !init?.method) return jsonResponse(200, { data: [consultingItem] })
      if (String(input) === '/api/v1/admin/quotation-items/1' && init?.method === 'PUT') {
        return jsonResponse(200, { data: { ...consultingItem, unit_price: '275.00' } })
      }
      throw new Error(`Unexpected request ${String(input)}`)
    })
    vi.stubGlobal('fetch', fetchMock)
    setAuthToken('admin-token')
    render(<AdminQuotationItemsPage />)

    expect(await screen.findByText(/250,00/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Editar' }))
    fireEvent.change(screen.getByLabelText('Valor unitário (R$)'), { target: { value: '275.00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Salvar alterações' }))

    expect(await screen.findByText('Item de orçamento atualizado.')).toBeInTheDocument()
    expect(await screen.findByText(/275,00/)).toBeInTheDocument()
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/quotation-items/1', expect.objectContaining({ method: 'PUT' })))
  })

  it('creates a new cost item', async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input) === '/api/v1/admin/quotation-items' && init?.method === 'POST') {
        return jsonResponse(201, { data: { id: 2, item_key: 'analyzer_day', name: 'Diária do analisador', description: null, unit: 'day', unit_price: '450.00' } })
      }
      if (String(input) === '/api/v1/admin/quotation-items') return jsonResponse(200, { data: [] })
      throw new Error(`Unexpected request ${String(input)}`)
    })
    vi.stubGlobal('fetch', fetchMock)
    setAuthToken('admin-token')
    render(<AdminQuotationItemsPage />)
    await screen.findByText('Nenhum item de orçamento cadastrado.')

    fireEvent.change(screen.getByLabelText('Identificador'), { target: { value: 'analyzer_day' } })
    fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Diária do analisador' } })
    fireEvent.change(screen.getByLabelText('Unidade'), { target: { value: 'day' } })
    fireEvent.change(screen.getByLabelText('Valor unitário (R$)'), { target: { value: '450.00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Adicionar item' }))

    expect(await screen.findByText('Item de orçamento cadastrado.')).toBeInTheDocument()
    expect(await screen.findByRole('cell', { name: /450,00/ })).toBeInTheDocument()
  })
})
