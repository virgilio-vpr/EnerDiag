import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
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

function setupFetch(
  submitResponse = jsonResponse(202, { data: { id: 12, status: 'queued' } }),
  statusResponses: Response[] = [],
) {
  const fetchMock = vi.fn()
    .mockResolvedValueOnce(jsonResponse(200, { data: account }))
    .mockResolvedValueOnce(jsonResponse(200, { data: distributors }))
    .mockResolvedValueOnce(submitResponse)
  statusResponses.forEach((response) => fetchMock.mockResolvedValueOnce(response))
  fetchMock.mockResolvedValue(jsonResponse(500, { message: 'Erro inesperado.' }))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

afterEach(() => {
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.history.replaceState({}, '', '/')
})

describe('invoice upload', () => {
  it('removes only the chosen invoice, updates totals and submits the remaining file', async () => {
    setAuthToken('customer-token')
    const fetchMock = setupFetch()
    render(<InvoiceUploadPage />)
    const keep = new File(['%PDF-1.4\n/Type /Page\n', new Uint8Array(1024 * 1024)], 'keep.pdf', { type: 'application/pdf' })
    const wrong = new File(['%PDF-1.4\n/Type /Page\n/Type /Page\n', new Uint8Array(2 * 1024 * 1024)], 'wrong.pdf', { type: 'application/pdf' })
    fireEvent.change(await screen.findByLabelText(/Faturas em PDF/), { target: { files: [keep, wrong] } })
    await screen.findByText(/2 arquivos selecionados · 3.0 MB · 3 páginas/)
    fireEvent.click(screen.getByRole('button', { name: 'Remover wrong.pdf' }))
    expect(await screen.findByText(/1 arquivo selecionado · 1.0 MB · 1 página/)).toBeInTheDocument()
    expect(screen.queryByText('wrong.pdf')).not.toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([url]) => url === '/api/v1/simulations')).toBe(false)
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))
    await screen.findByRole('heading', { name: 'Sua análise começou' })
    const uploaded = (fetchMock.mock.calls[2][1]?.body as FormData).getAll('invoices[]') as File[]
    expect(uploaded.map((file) => file.name)).toEqual(['keep.pdf'])
  })

  it('allows removing the last file, blocks an empty submission and allows selecting it again', async () => {
    setAuthToken('customer-token')
    const fetchMock = setupFetch()
    render(<InvoiceUploadPage />)
    const picker = await screen.findByLabelText(/Faturas em PDF/)
    const file = invoice('invoice.pdf')
    fireEvent.change(picker, { target: { files: [file] } })
    await screen.findByText(/1 página/)
    fireEvent.click(screen.getByRole('button', { name: 'Remover invoice.pdf' }))
    expect(screen.queryByLabelText('Faturas selecionadas')).not.toBeInTheDocument()
    expect(picker).toHaveValue('')
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/Envie de 1 a 12/)
    expect(fetchMock.mock.calls.some(([url]) => url === '/api/v1/simulations')).toBe(false)
    fireEvent.change(picker, { target: { files: [file] } })
    expect(await screen.findByText(/1 página/)).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('revalidates the selection after removing an invalid file', async () => {
    setAuthToken('customer-token')
    setupFetch()
    render(<InvoiceUploadPage />)
    fireEvent.change(await screen.findByLabelText(/Faturas em PDF/), {
      target: { files: [invoice('good.pdf'), invoice('wrong.txt', 1, 'text/plain')] },
    })
    await screen.findByText('Selecione apenas arquivos PDF.', { selector: 'small' })
    fireEvent.click(screen.getByRole('button', { name: 'Remover wrong.txt' }))
    await screen.findByText(/1 página/)
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Simular economia' })).toBeEnabled()
  })

  it('ignores a pending page count from an invoice removed during validation', async () => {
    setAuthToken('customer-token')
    setupFetch()
    render(<InvoiceUploadPage />)
    let finishReading!: (text: string) => void
    const removed = invoice('slow.pdf')
    const read = vi.spyOn(removed, 'text').mockImplementationOnce(() => new Promise<string>((resolve) => { finishReading = resolve }))
    fireEvent.change(await screen.findByLabelText(/Faturas em PDF/), { target: { files: [removed, invoice('keep.pdf')] } })
    await waitFor(() => expect(read).toHaveBeenCalled())
    expect(screen.getByRole('button', { name: 'Simular economia' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Remover slow.pdf' }))
    await screen.findByText(/1 página/)
    await act(async () => { finishReading('/Type /Page\n'.repeat(601)) })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.getByText(/1 página/)).toBeInTheDocument()
  })

  it('keeps the selection fixed while the upload is in progress', async () => {
    setAuthToken('customer-token')
    let finishUpload!: (response: Response) => void
    const pending = new Promise<Response>((resolve) => { finishUpload = resolve })
    vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/me')) return Promise.resolve(jsonResponse(200, { data: account }))
      if (url.endsWith('/distributors')) return Promise.resolve(jsonResponse(200, { data: distributors }))
      if (url.endsWith('/simulations')) return pending
      return Promise.resolve(jsonResponse(200, { data: { id: 12, status: 'queued' } }))
    }))
    render(<InvoiceUploadPage />)
    const picker = await screen.findByLabelText(/Faturas em PDF/)
    fireEvent.change(picker, { target: { files: [invoice('invoice.pdf')] } })
    await screen.findByText(/1 página/)
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))
    expect(screen.getByRole('button', { name: 'Remover invoice.pdf' })).toBeDisabled()
    expect(picker).toBeDisabled()
    await act(async () => { finishUpload(jsonResponse(202, { data: { id: 12, status: 'queued' } })) })
    expect(await screen.findByRole('heading', { name: 'Sua análise começou' })).toBeInTheDocument()
  })

  it('blocks empty selections, more than twelve invoices and non-PDF files', async () => {
    setAuthToken('customer-token')
    setupFetch()
    render(<InvoiceUploadPage />)
    const picker = await screen.findByLabelText(/Faturas em PDF/)
    fireEvent.change(picker, { target: { files: [] } })
    expect(await screen.findByRole('alert')).toHaveTextContent(/Selecione de 1 a 12 faturas/)

    fireEvent.change(picker, { target: { files: Array.from({ length: 6 }, (_, index) => invoice('invoice-' + index + '.pdf')) } })
    await screen.findByText(/6 páginas/)
    fireEvent.change(picker, { target: { files: Array.from({ length: 6 }, (_, index) => invoice('invoice-' + index + '.txt', 1, 'text/plain')) } })
    expect(await screen.findAllByText('Selecione apenas arquivos PDF.', { selector: 'small' })).toHaveLength(6)

    fireEvent.change(picker, { target: { files: Array.from({ length: 6 }, (_, index) => new File(['not a PDF'], 'invoice-' + index + '.pdf', { type: 'application/pdf' })) } })
    expect(await screen.findAllByText('O conteúdo não é um PDF válido.', { selector: 'small' })).toHaveLength(6)

    fireEvent.change(picker, { target: { files: Array.from({ length: 13 }, (_, index) => invoice('invoice-' + index + '.pdf')) } })
    expect(await screen.findByRole('alert')).toHaveTextContent(/Selecione de 1 a 12 faturas/)
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

  it.each([1, 2, 5, 6, 12])('submits company data and %i PDF files as a multipart simulation', async (count) => {
    setAuthToken('customer-token')
    const fetchMock = setupFetch()
    render(<InvoiceUploadPage />)
    const picker = await screen.findByLabelText(/Faturas em PDF/)
    fireEvent.change(picker, { target: { files: Array.from({ length: count }, (_, index) => invoice('invoice-' + (index + 1) + '.pdf')) } })
    await screen.findByText(new RegExp(`${count} páginas?`))
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
    expect(body.getAll('invoices[]')).toHaveLength(count)
  })

  it('explains the annual history recommendation and prevents submitting without invoices', async () => {
    setAuthToken('customer-token')
    const fetchMock = setupFetch()
    render(<InvoiceUploadPage />)
    await screen.findByLabelText(/Faturas em PDF/)
    expect(screen.getByText(/recomendamos 12 faturas mensais, cobrindo um ciclo anual/)).toBeInTheDocument()
    expect(screen.getByText(/apenas 1 fatura para uma avaliação inicial/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/Envie de 1 a 12 faturas PDF/)
    expect(fetchMock.mock.calls.some(([url]) => url === '/api/v1/simulations')).toBe(false)
  })

  it.each([
    ['queued', 'Aguardando início do processamento…'],
    ['processing', 'Estamos lendo suas faturas e preparando a análise.'],
    ['completed', 'A análise das suas faturas está concluída.'],
  ] as const)('shows the %s simulation status returned by the API', async (status, message) => {
    setAuthToken('customer-token')
    const fetchMock = setupFetch(
      jsonResponse(202, { data: { id: 12, status: 'queued' } }),
      [jsonResponse(200, { data: { id: 12, status, processing_errors: null } })],
    )
    render(<InvoiceUploadPage />)
    fireEvent.change(await screen.findByLabelText(/Faturas em PDF/), {
      target: { files: Array.from({ length: 6 }, (_, index) => invoice(`invoice-${index + 1}.pdf`)) },
    })
    await screen.findByText(/6 páginas/)
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))

    expect(await screen.findByText(message)).toBeInTheDocument()
    await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => url === '/api/v1/simulations/12/status')).toBe(true))
  })

  it('shows asynchronous file processing failures by file', async () => {
    setAuthToken('customer-token')
    setupFetch(undefined, [jsonResponse(200, { data: {
      id: 12,
      status: 'failed',
      processing_errors: [{ file: 'invoice-2.pdf', code: 'INVOICE_READ_FAILED', errors: { message: 'Não foi possível ler este arquivo.' } }],
    } })])
    render(<InvoiceUploadPage />)
    fireEvent.change(await screen.findByLabelText(/Faturas em PDF/), {
      target: { files: Array.from({ length: 6 }, (_, index) => invoice(`invoice-${index + 1}.pdf`)) },
    })
    await screen.findByText(/6 páginas/)
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))

    expect(await screen.findByRole('heading', { name: 'A análise precisa de atenção' })).toBeInTheDocument()
    expect(screen.getByText('invoice-2.pdf')).toBeInTheDocument()
    expect(screen.getByText('Não foi possível ler este arquivo.')).toBeInTheDocument()
  })

  it('maps synchronous invoice validation errors to the matching file', async () => {
    setAuthToken('customer-token')
    setupFetch(jsonResponse(422, { message: 'Confira os dados informados.', errors: { 'invoices.1': ['O arquivo está ilegível.'] } }))
    render(<InvoiceUploadPage />)
    fireEvent.change(await screen.findByLabelText(/Faturas em PDF/), {
      target: { files: Array.from({ length: 6 }, (_, index) => invoice(`invoice-${index + 1}.pdf`)) },
    })
    await screen.findByText(/6 páginas/)
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))

    expect(await screen.findByText('O arquivo está ilegível.', { selector: 'small' })).toBeInTheDocument()
    expect(screen.getByText('Revise os erros associados às faturas indicadas.')).toBeInTheDocument()
  })

  it('shows the API message when the synchronous upload is too large', async () => {
    setAuthToken('customer-token')
    setupFetch(jsonResponse(413, { message: 'O conjunto enviado ultrapassa o limite.' }))
    render(<InvoiceUploadPage />)
    fireEvent.change(await screen.findByLabelText(/Faturas em PDF/), {
      target: { files: Array.from({ length: 6 }, (_, index) => invoice(`invoice-${index + 1}.pdf`)) },
    })
    await screen.findByText(/6 páginas/)
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('O conjunto enviado ultrapassa o limite.')
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
