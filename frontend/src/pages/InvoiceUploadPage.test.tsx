import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { InvoiceUploadPage } from './InvoiceUploadPage'
import { setAuthToken } from '../services/api/client'

const account = { email: 'ana@example.com', profile: { company_name: 'Empresa Exemplo', distributor_id: 9, address_details: 'Rua da Energia, 10', substation_same_address: true } }
const distributors = [{ id: 9, name: 'ENEL SP', state: 'SP' }]
const savedInvoice = (month: string, warnings: string[] = []) => ({ id: Number(month.slice(5)), reference_month: month, consumer_unit: '123456789', total_paid: '1000.00', warnings, items: [], source_filename: `${month}.pdf` })
const session = (months: string[] = []) => ({ id: 12, status: 'draft', invoice_count: months.length, invoices: months.map((month) => savedInvoice(month)) })
const json = (status: number, payload: unknown) => new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json' } })
const pdf = (name: string) => new File(['%PDF-1.4\n/Type /Page'], name, { type: 'application/pdf' })

function setupFetch(handler?: (url: string, options?: RequestInit) => Response | Promise<Response>) {
  const fetchMock = vi.fn((url: string, options?: RequestInit) => {
    if (url.endsWith('/me')) return Promise.resolve(json(200, { data: account }))
    if (url.endsWith('/distributors')) return Promise.resolve(json(200, { data: distributors }))
    if (url.endsWith('/invoice-imports')) return Promise.resolve(json(201, { data: { id: 12, status: 'draft' } }))
    return Promise.resolve(handler?.(url, options) ?? json(500, { message: 'Resposta não configurada.' }))
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

async function startImport() {
  render(<InvoiceUploadPage />)
  fireEvent.click(await screen.findByRole('button', { name: 'Começar envio' }))
  return screen.findByLabelText(/Selecionar faturas/)
}

beforeEach(() => {
  window.localStorage.clear()
  window.history.replaceState({}, '', '/upload-faturas')
  setAuthToken('customer-token')
})
afterEach(() => {
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.localStorage.clear()
})

describe('sequential invoice import', () => {
  it('keeps the import identifier when the review link requires login', async () => {
    setAuthToken(null)
    window.history.replaceState({}, '', '/upload-faturas?import=12')
    const fetchMock = setupFetch()
    render(<InvoiceUploadPage />)
    await waitFor(() => expect(window.location.pathname).toBe('/login'))
    expect(window.localStorage.getItem('enerdiag_invoice_import_id')).toBe('12')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('removes a draft invoice and updates the review table before allowing a replacement', async () => {
    window.localStorage.setItem('enerdiag_invoice_import_id', '12')
    const fetchMock = setupFetch((url, options) => json(200, { data: session(url.endsWith('/invoices/3') && options?.method === 'DELETE' ? [] : ['2026-03']) }))
    render(<InvoiceUploadPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Remover fatura de 03/2026', hidden: true }))
    await screen.findByText('0 de 12 faturas lidas e salvas')
    expect(screen.getByRole('button', { name: 'Envie ao menos uma fatura' })).toBeDisabled()
    expect(fetchMock.mock.calls.some(([url, options]) => url.endsWith('/invoices/3') && options?.method === 'DELETE')).toBe(true)
  })

  it('sends one PDF per request and waits for each invoice to be saved before sending the next', async () => {
    let finishFirst!: (response: Response) => void
    const first = new Promise<Response>((resolve) => { finishFirst = resolve })
    let requests = 0
    const fetchMock = setupFetch((url) => {
      if (url.endsWith('/invoices')) {
        requests++
        return requests === 1 ? first : json(201, { data: session(['2026-03', '2026-04']) })
      }
      return json(500, {})
    })
    const picker = await startImport()
    expect(picker).toHaveAttribute('multiple')
    fireEvent.change(picker, { target: { files: [pdf('april.pdf'), pdf('march.pdf')] } })
    await waitFor(() => expect(requests).toBe(1))
    expect(screen.getByRole('button', { name: 'Aguarde…' })).toBeDisabled()
    await act(async () => { finishFirst(json(201, { data: session(['2026-04']) })) })
    await screen.findByText('2 de 12 faturas lidas e salvas')
    const uploads = fetchMock.mock.calls.filter(([url]) => url.endsWith('/invoices'))
    expect(uploads).toHaveLength(2)
    expect(uploads.map(([, options]) => (options?.body as FormData).getAll('invoice').length)).toEqual([1, 1])
    expect(uploads.map(([, options]) => ((options?.body as FormData).get('invoice') as File).name)).toEqual(['april.pdf', 'march.pdf'])
    const rows = within(screen.getByRole('table', { name: 'Faturas em ordem do mês da medição' })).getAllByRole('row')
    expect(rows[1]).toHaveTextContent('03/2026')
    expect(rows[2]).toHaveTextContent('04/2026')
  })

  it('continues with the next file after a duplicate is rejected and keeps the saved table', async () => {
    let requests = 0
    setupFetch(() => ++requests === 1
      ? json(422, { message: 'Fatura repetida.', errors: { invoice: ['Esta fatura já foi enviada.'] } })
      : json(201, { data: session(['2026-04']) }))
    const picker = await startImport()
    fireEvent.change(picker, { target: { files: [pdf('duplicate.pdf'), pdf('april.pdf')] } })
    await screen.findByText('1 de 12 faturas lidas e salvas')
    expect(screen.getByRole('alert')).toHaveTextContent('duplicate.pdf: Esta fatura já foi enviada.')
    expect(screen.getByRole('button', { name: 'Simular relatório' })).toBeEnabled()
  })

  it('blocks calculation before the first invoice and starts it only after a saved invoice', async () => {
    const fetchMock = setupFetch((url) => url.endsWith('/invoices')
      ? json(201, { data: session(['2026-03']) })
      : json(url.endsWith('/complete') ? 202 : 200, { data: { id: 12, status: 'completed', processing_errors: null } }))
    const picker = await startImport()
    expect(screen.getByRole('button', { name: 'Envie ao menos uma fatura' })).toBeDisabled()
    fireEvent.change(picker, { target: { files: [pdf('march.pdf')] } })
    fireEvent.click(await screen.findByRole('button', { name: 'Simular relatório' }))
    expect(await screen.findByRole('link', { name: 'Ver relatório completo' })).toHaveAttribute('href', '/simulations/12/report')
    expect(fetchMock.mock.calls.filter(([url]) => url.endsWith('/complete'))).toHaveLength(1)
  })

  it('restores a saved draft from its link and blocks a table whose items do not reconcile', async () => {
    window.history.replaceState({}, '', '/upload-faturas?import=12')
    setupFetch(() => json(200, { data: { ...session(['2026-03']), invoices: [savedInvoice('2026-03', ['invoice_billing_total_mismatch'])] } }))
    render(<InvoiceUploadPage />)
    expect(await screen.findByRole('button', { name: 'Simular relatório' })).toBeDisabled()
    expect(screen.getByRole('alert')).toHaveTextContent('A leitura precisa ser revisada')
    expect(window.localStorage.getItem('enerdiag_invoice_import_id')).toBe('12')
  })

  it('limits a resumed import to twelve saved invoices', async () => {
    const months = Array.from({ length: 11 }, (_, index) => `2026-${String(index + 1).padStart(2, '0')}`)
    window.localStorage.setItem('enerdiag_invoice_import_id', '12')
    const fetchMock = setupFetch((url) => json(url.endsWith('/invoices') ? 201 : 200, { data: session(url.endsWith('/invoices') ? [...months, '2026-12'] : months) }))
    render(<InvoiceUploadPage />)
    fireEvent.change(await screen.findByLabelText(/Selecionar faturas/), { target: { files: [pdf('december.pdf'), pdf('extra.pdf')] } })
    await screen.findByText('12 de 12 faturas lidas e salvas')
    expect(fetchMock.mock.calls.filter(([url]) => url.endsWith('/invoices'))).toHaveLength(1)
    expect(screen.queryByLabelText(/Selecionar faturas/)).not.toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('O limite é de 12 faturas')
  })

  it.each([
    [new File(['text'], 'invalid.txt', { type: 'text/plain' }), 'selecione um arquivo PDF'],
    [new File(['text'], 'invalid.pdf', { type: 'application/pdf' }), 'o conteúdo não é um PDF válido'],
    [new File([new Uint8Array(20 * 1024 * 1024 + 1)], 'large.pdf', { type: 'application/pdf' }), 'cada fatura pode ter no máximo 20 MB'],
  ])('rejects invalid uploads without sending them: %s', async (file, message) => {
    const fetchMock = setupFetch()
    const picker = await startImport()
    fireEvent.change(picker, { target: { files: [file] } })
    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(fetchMock.mock.calls.filter(([url]) => url.endsWith('/invoices'))).toHaveLength(0)
  })

  it('keeps the current draft when the file picker is canceled', async () => {
    const fetchMock = setupFetch()
    const picker = await startImport()
    fireEvent.change(picker, { target: { files: [] } })
    expect(screen.getByText('0 de 12 faturas lidas e salvas')).toBeInTheDocument()
    expect(fetchMock.mock.calls.filter(([url]) => url.endsWith('/invoices'))).toHaveLength(0)
  })

  it('shows asynchronous processing failures', async () => {
    window.localStorage.setItem('enerdiag_invoice_import_id', '12')
    setupFetch((url) => json(200, { data: url.endsWith('/status')
      ? { id: 12, status: 'failed', processing_errors: [{ file: 'simulação', code: 'FAILED', errors: { message: 'Falha no cálculo.' } }] }
      : { ...session(['2026-03']), status: 'queued' } }))
    render(<InvoiceUploadPage />)
    await screen.findByRole('heading', { name: 'A análise precisa de atenção' })
    expect(await screen.findByText('Falha no cálculo.')).toBeInTheDocument()
  })

  it('registers interest in an unsupported distributor', async () => {
    const fetchMock = setupFetch(() => json(201, {}))
    render(<InvoiceUploadPage />)
    fireEvent.change(await screen.findByLabelText('Distribuidora'), { target: { value: 'other' } })
    fireEvent.change(screen.getByLabelText('Nome da distribuidora'), { target: { value: 'Nova Distribuidora' } })
    fireEvent.click(screen.getByRole('button', { name: 'Registrar meu interesse' }))
    await screen.findByText(/Interesse registrado/)
    expect(fetchMock.mock.calls.some(([url]) => url.endsWith('/leads'))).toBe(true)
  })
})
