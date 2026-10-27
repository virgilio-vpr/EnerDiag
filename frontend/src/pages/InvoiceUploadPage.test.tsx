import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { InvoiceUploadPage } from './InvoiceUploadPage'
import { setAuthToken } from '../services/api/client'

const account = { email: 'ana@example.com', profile: { company_name: 'Empresa Exemplo', distributor_id: 9,
  address_details: 'Rua da Energia, 10', substation_same_address: true } }
const distributors = [{ id: 9, name: 'ENEL SP', state: 'SP' }]
const batch = '019c0000-0000-7000-8000-000000000001'
function json(status: number, payload: unknown) {
  return new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json' } })
}
function invoice(name: string, pages = 1, type = 'application/pdf') {
  return new File(['%PDF-1.4\n' + '/Type /Page\n'.repeat(pages)], name, { type, lastModified: 1 })
}
function setup(options: { upload?: (body: FormData) => Response | Promise<Response>; status?: Record<string, unknown>; complete?: Response } = {}) {
  setAuthToken('customer-token')
  const fetchMock = vi.fn(async (url: string, request: RequestInit = {}) => {
    if (url.endsWith('/me')) return json(200, { data: account })
    if (url.endsWith('/distributors')) return json(200, { data: distributors })
    if (url.endsWith('/invoice-upload-batches')) return json(201, { data: { id: batch } })
    if (url.endsWith('/files')) return options.upload ? options.upload(request?.body as FormData) : json(201, { data: {} })
    if (url.endsWith('/complete')) return options.complete ?? json(202, { data: { id: 12, status: 'queued' } })
    if (url.endsWith('/status')) return json(200, { data: { id: 12, status: 'queued', processing_errors: null, ...options.status } })
    return json(200, { data: {} })
  })
  vi.stubGlobal('fetch', fetchMock)
  render(<InvoiceUploadPage />)
  return fetchMock
}
async function add(files: File[]) {
  fireEvent.change(await screen.findByLabelText('Faturas em PDF'), { target: { files } })
}
async function ready(files = [invoice('Março.pdf'), invoice('Abril.pdf')]) {
  await add(files)
  await screen.findByText(new RegExp(`${files.length} páginas`))
  await waitFor(() => expect(screen.getByRole('button', { name: 'Simular economia' })).toBeEnabled())
}
afterEach(() => {
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.history.replaceState({}, '', '/')
})

describe('sequential invoice upload', () => {
  it('accumulates attachments one by one, ignores exact duplicates and lets the user remove and reattach a file', async () => {
    setup()
    const first = invoice('Março.pdf')
    await add([first])
    expect(await screen.findByText(/1 fatura\(s\) anexada/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Simular economia' })).toBeDisabled()
    await add([first, invoice('Abril.pdf')])
    expect(await screen.findByText(/2 fatura\(s\) anexada/)).toBeInTheDocument()
    const list = screen.getByLabelText('Faturas selecionadas')
    expect(within(list).getAllByRole('listitem')).toHaveLength(2)
    fireEvent.click(screen.getByRole('button', { name: 'Remover Março.pdf' }))
    expect(within(list).queryByText(/1\. Março.pdf/)).not.toBeInTheDocument()
    await add([first])
    expect(await screen.findByText(/2 páginas/)).toBeInTheDocument()
    expect(within(list).getAllByRole('listitem')).toHaveLength(2)
  })

  it('accepts two invoices temporarily and rejects selections above twelve', async () => {
    setup()
    await ready()
    await add(Array.from({ length: 11 }, (_, index) => invoice(`extra-${index}.pdf`)))
    expect(await screen.findByRole('alert')).toHaveTextContent(/Remova os arquivos excedentes/)
    expect(screen.getByRole('button', { name: 'Simular economia' })).toBeDisabled()
  })

  it('identifies invalid extensions and signatures by file even when only one file is selected', async () => {
    setup()
    await add([invoice('errada.txt', 1, 'text/plain')])
    expect(await screen.findByText('Selecione apenas arquivos PDF.', { selector: 'small' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Remover errada.txt' }))
    await add([new File(['not a PDF'], 'ilegível.pdf', { type: 'application/pdf' })])
    expect(await screen.findByText('O conteúdo não é um PDF válido.', { selector: 'small' })).toBeInTheDocument()
  })

  it('rejects an invoice above 20 MB and a batch above 600 pages', async () => {
    setup()
    await add([new File([new Uint8Array(20 * 1024 * 1024 + 1)], 'grande.pdf', { type: 'application/pdf' })])
    expect(await screen.findByText('Cada fatura pode ter no máximo 20 MB.', { selector: 'small' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Remover grande.pdf' }))
    await add([invoice('one.pdf', 301), invoice('two.pdf', 301)])
    expect(await screen.findByRole('alert')).toHaveTextContent('no máximo 600 páginas')
  })

  it('sends exactly one PDF per request, waits before sending the next and updates the sent counter', async () => {
    const releases: Array<(response: Response) => void> = []
    const fetchMock = setup({ upload: () => new Promise<Response>((resolve) => { releases.push(resolve) }) })
    await ready()
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))
    await waitFor(() => expect(releases).toHaveLength(1))
    expect(screen.getByText('0 de 2 faturas enviadas')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Remover Março.pdf' })).toBeDisabled()
    releases[0](json(201, { data: {} }))
    await waitFor(() => expect(releases).toHaveLength(2))
    expect(screen.getByText('1 de 2 faturas enviadas')).toBeInTheDocument()
    expect(fetchMock.mock.calls.filter(([url]) => url.endsWith('/complete'))).toHaveLength(0)
    releases[1](json(201, { data: {} }))
    expect(await screen.findByRole('heading', { name: 'Sua análise começou' })).toBeInTheDocument()
    const uploads = fetchMock.mock.calls.filter(([url]) => url.endsWith('/files'))
    expect(uploads).toHaveLength(2)
    expect(uploads.map(([, request]) => ((request?.body as FormData).get('invoice') as File).name)).toEqual(['Março.pdf', 'Abril.pdf'])
    for (const [, request] of uploads) {
      expect((request?.body as FormData).getAll('invoice')).toHaveLength(1)
      expect(new Headers(request?.headers).get('Authorization')).toBe('Bearer customer-token')
    }
    expect(JSON.parse(String(fetchMock.mock.calls[2][1]?.body))).toMatchObject({ company_name: 'Empresa Exemplo', distributor_id: 9 })
  })

  it('keeps successful uploads, shows the failed filename and retries only that file with the same identifier', async () => {
    let failures = 0
    const fetchMock = setup({ upload: (body) => {
      if ((body.get('invoice') as File).name === 'Abril.pdf' && failures++ === 0) return json(413, { message: 'Arquivo recusado pelo servidor.' })
      return json(201, { data: {} })
    } })
    await ready()
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))
    expect(await screen.findByText('Arquivo recusado pelo servidor.', { selector: 'small' })).toBeInTheDocument()
    expect(screen.getByText('1 de 2 faturas enviadas')).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([url]) => url.endsWith('/complete'))).toBe(false)
    fireEvent.click(screen.getByRole('button', { name: 'Tentar envio novamente' }))
    expect(await screen.findByRole('heading', { name: 'Sua análise começou' })).toBeInTheDocument()
    const uploads = fetchMock.mock.calls.filter(([url]) => url.endsWith('/files')).map(([, options]) => options?.body as FormData)
    expect(uploads.map((body) => (body.get('invoice') as File).name)).toEqual(['Março.pdf', 'Abril.pdf', 'Abril.pdf'])
    expect(uploads[1].get('client_id')).toBe(uploads[2].get('client_id'))
  })

  it('discards a partial server batch if an attachment is removed after a failed upload', async () => {
    const fetchMock = setup({ upload: () => json(415, { message: 'PDF inválido.' }) })
    await ready()
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))
    await screen.findByRole('button', { name: 'Tentar envio novamente' })
    fireEvent.click(screen.getByRole('button', { name: 'Remover Abril.pdf' }))
    await waitFor(() => expect(fetchMock.mock.calls.some(([url, options]) => url.endsWith(batch) && options?.method === 'DELETE')).toBe(true))
    expect(screen.getByText(/1 fatura\(s\) anexada/)).toBeInTheDocument()
  })

  it.each([
    ['queued', 'Aguardando início do processamento…'],
    ['processing', 'Estamos lendo suas faturas e preparando a análise.'],
    ['completed', 'A análise das suas faturas está concluída.'],
  ])('shows the %s processing status', async (status, message) => {
    setup({ status: { status } })
    await ready()
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))
    expect(await screen.findByText(message)).toBeInTheDocument()
  })

  it('shows read progress and identifies every failed invoice by its original name', async () => {
    setup({ status: { status: 'failed', processing_progress: { stage: 'failed', files: [
      { file: 'Março.pdf', status: 'read' }, { file: 'Abril.pdf', status: 'failed' },
    ] }, processing_errors: [{ file: 'Abril.pdf', code: 'INVOICE_READ_FAILED', errors: { message: 'Não foi possível ler este arquivo.' } }] } })
    await ready()
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))
    expect(await screen.findByRole('heading', { name: 'A análise precisa de atenção' })).toBeInTheDocument()
    expect(screen.getByText('2 de 2 faturas enviadas · 1 de 2 lidas')).toBeInTheDocument()
    expect(screen.getByText('Abril.pdf', { selector: 'strong' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Corrigir anexos' }))
    expect(screen.getByRole('button', { name: 'Remover Abril.pdf' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Remover Março.pdf' })).toBeInTheDocument()
  })

  it('preserves the batch when finalization fails so that retry does not resend files', async () => {
    setup({ complete: json(422, { message: 'Envio inválido.', errors: { invoices: ['Confira o conjunto enviado.'] } }) })
    await ready()
    fireEvent.click(screen.getByRole('button', { name: 'Simular economia' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Confira o conjunto enviado.')
    expect(screen.getByText('2 de 2 faturas enviadas')).toBeInTheDocument()
  })

  it('registers interest in an unsupported distributor', async () => {
    const fetchMock = setup()
    fireEvent.change(await screen.findByLabelText('Distribuidora'), { target: { value: 'other' } })
    fireEvent.change(screen.getByLabelText('Nome da distribuidora'), { target: { value: 'Distribuidora Regional' } })
    fireEvent.click(screen.getByRole('button', { name: 'Registrar meu interesse' }))
    expect(await screen.findByText(/Interesse registrado/)).toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([url]) => url.endsWith('/leads'))).toBe(true)
  })
})
