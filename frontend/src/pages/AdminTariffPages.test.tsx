import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AdminParametersPage, AdminTariffsPage } from './AdminTariffPages'
import { setAuthToken } from '../services/api/client'

function jsonResponse(status: number, payload: unknown) {
  return new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json' } })
}

function paged<T>(data: T[], total = data.length) {
  return { data, links: {}, meta: { current_page: 1, last_page: 1, per_page: 15, total } }
}

const distributorResponse = { data: [{ id: 1, name: 'ENEL SP', state: 'SP' }] }
const emptyTariffs = () => jsonResponse(200, paged([]))

function prepareTariffForm() {
  fireEvent.change(screen.getByLabelText('Distribuidora'), { target: { value: '1' } })
  fireEvent.change(screen.getByLabelText('Tarifa unitária'), { target: { value: '37.80322' } })
  fireEvent.change(screen.getByLabelText('Base tributária'), { target: { value: 'false' } })
  fireEvent.change(screen.getByLabelText('Início da vigência'), { target: { value: '2027-01-01' } })
}

afterEach(() => {
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.history.replaceState({}, '', '/')
})

describe('admin tariff and regulatory parameter pages', () => {
  it('creates a tariff with its tax basis and displays its origin', async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      if (url === '/api/v1/admin/tariffs' && init?.method === 'POST') return jsonResponse(201, { data: { id: 4 } })
      if (url === '/api/v1/admin/tariffs?page=1') return emptyTariffs()
      if (url === '/api/v1/distributors') return jsonResponse(200, distributorResponse)
      throw new Error(`Unexpected request ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)
    render(<AdminTariffsPage />)
    await screen.findByRole('button', { name: 'Cadastrar tarifa' })
    prepareTariffForm()
    fireEvent.click(screen.getByRole('button', { name: 'Cadastrar tarifa' }))

    expect(await screen.findByText('Tarifa criada com sucesso.')).toBeInTheDocument()
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/tariffs', expect.objectContaining({ method: 'POST' })))
    const request = fetchMock.mock.calls.find(([url, init]) => String(url) === '/api/v1/admin/tariffs' && init?.method === 'POST')
    expect(JSON.parse(String(request?.[1]?.body))).toMatchObject({
      distributor_id: 1, rate: '37.80322', includes_taxes: false, valid_from: '2027-01-01',
    })
  })

  it('creates a new immutable version from an existing tariff', async () => {
    const tariff = {
      id: 3, distributor_id: 1, distributor: { id: 1, name: 'ENEL SP', state: 'SP' }, subgroup: 'A4',
      tariff_modality: 'blue', time_slot: 'peak', component: 'demand', rate: '37.80322', includes_taxes: true,
      source: 'manual', source_reference: 'REH anterior', valid_from: '2026-01-01', valid_to: null,
    }
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      if (url === '/api/v1/admin/tariffs/3' && init?.method === 'PUT') return jsonResponse(201, { data: { id: 4 } })
      if (url === '/api/v1/admin/tariffs?page=1') return jsonResponse(200, paged([tariff]))
      if (url === '/api/v1/distributors') return jsonResponse(200, distributorResponse)
      throw new Error(`Unexpected request ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)
    render(<AdminTariffsPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Criar nova versão' }))
    fireEvent.change(screen.getByLabelText('Tarifa unitária'), { target: { value: '40.00000' } })
    fireEvent.change(screen.getByLabelText('Início da vigência'), { target: { value: '2027-01-01' } })
    fireEvent.click(screen.getByRole('button', { name: 'Criar versão' }))

    expect(await screen.findByText('Nova versão criada; o valor anterior foi preservado.')).toBeInTheDocument()
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/tariffs/3', expect.objectContaining({ method: 'PUT' })))
    const request = fetchMock.mock.calls.find(([url, init]) => String(url) === '/api/v1/admin/tariffs/3' && init?.method === 'PUT')
    expect(JSON.parse(String(request?.[1]?.body))).toMatchObject({ rate: '40.00000', valid_from: '2027-01-01', includes_taxes: true })
  })

  it('shows the ANEEL import summary for accepted and ignored rows', async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      if (url === '/api/v1/admin/tariffs/import' && init?.method === 'POST') return jsonResponse(200, { message: 'Importação concluída.', data: { rows_accepted: 3, rows_ignored: 1, import_id: 8 } })
      if (url === '/api/v1/admin/tariffs?page=1') return emptyTariffs()
      if (url === '/api/v1/distributors') return jsonResponse(200, distributorResponse)
      throw new Error(`Unexpected request ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)
    render(<AdminTariffsPage />)
    await screen.findByText('Importar CSV da ANEEL')
    fireEvent.change(screen.getByLabelText('Arquivo CSV'), { target: { files: [new File(['csv'], 'tarifas.csv', { type: 'text/csv' })] } })
    fireEvent.click(screen.getByRole('button', { name: 'Importar tarifas' }))

    expect(await screen.findByText('Linhas aceitas: 3')).toBeInTheDocument()
    expect(screen.getByText('Linhas ignoradas: 1')).toBeInTheDocument()
  })

  it('shows API errors for overlapping validity, missing tax basis and invalid CSV', async () => {
    const overlapFetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input) === '/api/v1/admin/tariffs' && init?.method === 'POST') return jsonResponse(422, { message: 'Dados inválidos.', code: 'VALIDATION_ERROR', errors: { valid_from: ['Já existe uma tarifa com essa base tributária em período sobreposto.'] } })
      if (String(input) === '/api/v1/admin/tariffs?page=1') return emptyTariffs()
      if (String(input) === '/api/v1/distributors') return jsonResponse(200, distributorResponse)
      throw new Error(`Unexpected request ${String(input)}`)
    })
    vi.stubGlobal('fetch', overlapFetch)
    const first = render(<AdminTariffsPage />)
    await screen.findByRole('button', { name: 'Cadastrar tarifa' })
    prepareTariffForm()
    fireEvent.click(screen.getByRole('button', { name: 'Cadastrar tarifa' }))
    expect(await screen.findByText(/Já existe uma tarifa com essa base tributária/)).toBeInTheDocument()
    first.unmount()

    const taxFetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input) === '/api/v1/admin/tariffs' && init?.method === 'POST') return jsonResponse(422, { message: 'Dados inválidos.', code: 'VALIDATION_ERROR', errors: { includes_taxes: ['O campo includes taxes é obrigatório.'] } })
      if (String(input) === '/api/v1/admin/tariffs?page=1') return emptyTariffs()
      if (String(input) === '/api/v1/distributors') return jsonResponse(200, distributorResponse)
      throw new Error(`Unexpected request ${String(input)}`)
    })
    vi.stubGlobal('fetch', taxFetch)
    const { unmount: taxUnmount } = render(<AdminTariffsPage />)
    await screen.findByRole('button', { name: 'Cadastrar tarifa' })
    prepareTariffForm()
    fireEvent.change(screen.getByLabelText('Base tributária'), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: 'Cadastrar tarifa' }))
    expect(await screen.findByText('O campo includes taxes é obrigatório.')).toBeInTheDocument()
    taxUnmount()

    const invalidFileFetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input) === '/api/v1/admin/tariffs/import' && init?.method === 'POST') return jsonResponse(422, { message: 'Dados inválidos.', code: 'VALIDATION_ERROR', errors: { file: ['O formato do arquivo CSV é inválido.'] } })
      if (String(input) === '/api/v1/admin/tariffs?page=1') return emptyTariffs()
      if (String(input) === '/api/v1/distributors') return jsonResponse(200, distributorResponse)
      throw new Error(`Unexpected request ${String(input)}`)
    })
    vi.stubGlobal('fetch', invalidFileFetch)
    const { unmount } = render(<AdminTariffsPage />)
    await screen.findByText('Importar CSV da ANEEL')
    fireEvent.change(screen.getByLabelText('Arquivo CSV'), { target: { files: [new File(['invalid'], 'invalid.csv', { type: 'text/csv' })] } })
    fireEvent.click(screen.getByRole('button', { name: 'Importar tarifas' }))
    expect(await screen.findByText('O formato do arquivo CSV é inválido.')).toBeInTheDocument()
    unmount()
  })

  it('lists regulatory parameters and creates a new dated version', async () => {
    const existing = { id: 2, key: 'idle_demand_discount_fallback', value: '0.18000', state: null, valid_from: '2026-01-01', valid_to: null }
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input) === '/api/v1/admin/parameters' && init?.method === 'POST') return jsonResponse(201, { data: { ...existing, id: 3, value: '0.20000', valid_from: '2027-01-01' } })
      if (String(input) === '/api/v1/admin/parameters?page=1') return jsonResponse(200, paged([existing]))
      throw new Error(`Unexpected request ${String(input)}`)
    })
    vi.stubGlobal('fetch', fetchMock)
    render(<AdminParametersPage />)
    expect(await screen.findByText('0.18000')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Parâmetro'), { target: { value: 'idle_demand_discount_fallback' } })
    fireEvent.change(screen.getByLabelText('Valor decimal'), { target: { value: '0.20000' } })
    fireEvent.change(screen.getByLabelText('Início da vigência'), { target: { value: '2027-01-01' } })
    fireEvent.click(screen.getByRole('button', { name: 'Criar versão' }))

    expect(await screen.findByText('Nova versão do parâmetro registrada.')).toBeInTheDocument()
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/parameters', expect.objectContaining({ method: 'POST' })))
    const request = fetchMock.mock.calls.find(([url, init]) => String(url) === '/api/v1/admin/parameters' && init?.method === 'POST')
    expect(JSON.parse(String(request?.[1]?.body))).toEqual({ key: 'idle_demand_discount_fallback', value: '0.20000', valid_from: '2027-01-01', valid_to: null })
  })
})
