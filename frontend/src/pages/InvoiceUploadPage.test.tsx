import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { InvoiceUploadPage } from './InvoiceUploadPage'
import { setAuthToken } from '../services/api/client'

const account = {
  email: 'ana@example.com',
  profile: {
    company_name: 'Empresa Exemplo',
    cnpj: '12.345.678/0001-90',
    distributor_id: 9,
    transformer_power_kva: '500.00',
    address_details: 'Rua da Energia, 10, São Paulo/SP',
    substation_same_address: true,
    substation_address: null,
  },
}
const distributors = [{ id: 9, name: 'ENEL SP', state: 'SP' }]

function jsonResponse(status: number, payload: unknown) {
  return new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json' } })
}

function invoice(name: string, pages = 1, type = 'application/pdf') {
  const pageMarkers = Array.from({ length: pages }, () => '/Type /Page').join('\n')
  return new File(['%PDF-1.4\n' + pageMarkers], name, { type })
}

function setupFetch(submitResponse = jsonResponse(202, { data: { id: 12, status: 'queued' } })) {
  const fetchMock = vi.fn()
    .mockResolvedValueOnce(jsonResponse(200, { data: account }))
    .mockResolvedValueOnce(jsonResponse(200, { data: distributors }))
    .mockResolvedValue(submitResponse)
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

afterEach(() => {
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.history.replaceState({}, '', '/')
})

describe('invoice upload', () => {
  it('blocks fewer than six invoices and non-PDF files', async () => {
    setAuthToken('customer-token')
    setupFetch()
    render(<InvoiceUploadPage />)
    const picker = await screen.findByLabelText(/Faturas em PDF/)
    fireEvent.change(picker, { target: { files: [invoice('one.pdf'), invoice('two.pdf')] } })
    expect(await screen.findByRole('alert')).toHaveTextContent(/Selecione de 6 a 12 faturas/)

    fireEvent.change(picker, { target: { files: Array.from({ length: 6 }, (_, index) => invoice('invoice-' + index + '.pdf')) } })
    await screen.findByText(/6 páginas/)
    fireEvent.change(picker, { target: { files: Array.from({ length: 6 }, (_, index) => invoice('invoice-' + index + '.txt', 1, 'text/plain')) } })
    expect(await screen.findAllByText('Selecione apenas arquivos PDF.', { selector: 'small' })).toHaveLength(6)

    fireEvent.change(picker, { target: { files: Array.from({ length: 6 }, (_, index) => new File(['not a PDF'], 'invoice-' + index + '.pdf', { type: 'application/pdf' })) } })
    expect(await screen.findAllByText('O conteúdo não é um PDF válido.', { selector: 'small' })).toHaveLength(6)

    fireEvent.change(picker, { target: { files: Array.from({ length: 13 }, (_, index) => invoice('invoice-' + index + '.pdf')) } })
    expect(await screen.findByRole('alert')).toHaveTextContent(/Selecione de 6 a 12 faturas/)
  })

  it('blocks any invoice above 20 MB', async () => {
    setAuthToken('customer-token')
    setupFetch()
    render(<InvoiceUploadPage />)
    const picker = await screen.findByLabelText(/Faturas em PDF/)
    const oversized = new File([new Uint8Array(20 * 1024 * 1024 + 1)], 'oversized.pdf', { type: 'application/pdf' })
    fireEvent.change(picker, { target: { files: [oversized, ...Array.from({ length: 5 }, (_, index) => invoice('invoice-' + index + '.pdf'))] } })
    expect(await screen.findByText(/Revise os arquivos indicados/, { selector: 'p[role="alert"]' })).toBeInTheDocument()
  })

  it('blocks a selection above the total page limit', async () => {
    setAuthToken('customer-token')
    setupFetch()
    render(<InvoiceUploadPage />)
    const picker = await screen.findByLabelText(/Faturas em PDF/)
    fireEvent.change(picker, { target: { files: Array.from({ length: 6 }, (_, index) => invoice('invoice-' + index + '.pdf', 101)) } })
    expect(await screen.findByRole('alert')).toHaveTextContent(/no máximo 600 páginas/)
  })

  it('submits company data and six PDF files as a multipart simulation', async () => {
    setAuthToken('customer-token')
    const fetchMock = setupFetch()
    render(<InvoiceUploadPage />)
    const picker = await screen.findByLabelText(/Faturas em PDF/)
    fireEvent.change(picker, { target: { files: Array.from({ length: 6 }, (_, index) => invoice('invoice-' + (index + 1) + '.pdf')) } })
    await screen.findByText(/6 páginas/)
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))

    expect(await screen.findByRole('heading', { name: 'Sua análise começou' })).toBeInTheDocument()
    const [url, options] = fetchMock.mock.calls[2]
    expect(url).toBe('/api/v1/simulations')
    expect(options?.method).toBe('POST')
    expect(new Headers(options?.headers).get('Authorization')).toBe('Bearer customer-token')
    expect(options?.body).toBeInstanceOf(FormData)
    const body = options?.body as FormData
    expect(body.get('company_name')).toBe('Empresa Exemplo')
    expect(body.get('distributor_id')).toBe('9')
    expect(body.get('address_details')).toBe('Rua da Energia, 10, São Paulo/SP')
    expect(body.get('substation_same_address')).toBe('1')
    expect(body.getAll('invoices[]')).toHaveLength(6)
  })

  it('shows an unsupported distributor notice and registers the customer interest', async () => {
    setAuthToken('customer-token')
    const fetchMock = setupFetch(jsonResponse(201, { data: { id: 3 } }))
    render(<InvoiceUploadPage />)
    fireEvent.change(await screen.findByLabelText('Distribuidora'), { target: { value: 'other' } })
    fireEvent.change(screen.getByLabelText('Nome da distribuidora'), { target: { value: 'Distribuidora Regional' } })
    fireEvent.click(screen.getByRole('button', { name: 'Registrar meu interesse' }))

    expect(await screen.findByText(/Interesse registrado/)).toBeInTheDocument()
    expect(fetchMock.mock.calls[2][0]).toBe('/api/v1/leads')
    expect(JSON.parse(String(fetchMock.mock.calls[2][1]?.body))).toEqual({
      email: 'ana@example.com',
      distributor_name: 'Distribuidora Regional',
      reason: 'unsupported_distributor',
    })
  })
})
