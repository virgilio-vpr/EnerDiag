import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ProfilePage } from './ProfilePage'
import { getAuthToken, setAuthToken } from '../services/api/client'

const account = {
  name: 'Ana Souza',
  email: 'ana@example.com',
  phone: '11999999999',
  profile: {
    company_name: 'Energia Exemplo Ltda.',
    cnpj: '12.345.678/0001-90',
    distributor_id: 9,
    transformer_power_kva: '500.00',
    address_details: 'Rua da Energia, 10, São Paulo/SP',
    substation_same_address: true,
    substation_address: null,
  },
}

function jsonResponse(status: number, payload: unknown) {
  return new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json' } })
}

function setupFetch(responses: Record<string, Response> = {}) {
  const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
    const key = `${init?.method ?? 'GET'} ${String(input)}`
    if (responses[key]) return Promise.resolve(responses[key])
    if (String(input) === '/api/v1/me') return Promise.resolve(jsonResponse(200, { data: account }))
    if (String(input) === '/api/v1/distributors') return Promise.resolve(jsonResponse(200, { data: [{ id: 9, name: 'ENEL SP', state: 'SP' }] }))
    if (key === 'DELETE /api/v1/me') return Promise.resolve(new Response(null, { status: 204 }))
    return Promise.resolve(jsonResponse(200, { data: account }))
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.history.replaceState({}, '', '/')
})

describe('customer profile', () => {
  it('loads and updates personal and company profile data', async () => {
    setAuthToken('customer-token')
    const fetchMock = setupFetch({ 'PUT /api/v1/me': jsonResponse(200, { data: { ...account, name: 'Ana Silva' } }) })
    render(<ProfilePage />)

    const nameInput = await screen.findByLabelText('Nome do responsável')
    expect(nameInput).toHaveValue('Ana Souza')
    expect(screen.getByLabelText('E-mail')).toHaveValue('ana@example.com')
    expect(screen.getByLabelText('Nome da empresa')).toHaveValue('Energia Exemplo Ltda.')
    fireEvent.change(nameInput, { target: { value: 'Ana Silva' } })
    fireEvent.click(screen.getByRole('button', { name: 'Salvar alterações' }))

    expect(await screen.findByRole('status')).toHaveTextContent('Seu perfil foi atualizado.')
    const updateCall = fetchMock.mock.calls.find(([url, options]) => url === '/api/v1/me' && options?.method === 'PUT')
    expect(updateCall).toBeDefined()
    expect(JSON.parse(String(updateCall?.[1]?.body))).toMatchObject({ name: 'Ana Silva', company_name: 'Energia Exemplo Ltda.' })
    expect(new Headers(updateCall?.[1]?.headers).get('Authorization')).toBe('Bearer customer-token')
  })

  it('shows API validation errors when profile data cannot be saved', async () => {
    setAuthToken('customer-token')
    setupFetch({ 'PUT /api/v1/me': jsonResponse(422, { message: 'Os dados informados são inválidos.', errors: { company_name: ['Informe o nome da empresa.'] } }) })
    render(<ProfilePage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Salvar alterações' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Informe o nome da empresa.')
  })

  it('requires confirmation and allows the user to cancel account deletion', async () => {
    setAuthToken('customer-token')
    const fetchMock = setupFetch()
    render(<ProfilePage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Excluir minha conta' }))

    expect(screen.getByRole('alertdialog')).toHaveTextContent('serão excluídos permanentemente')
    fireEvent.click(screen.getByRole('button', { name: 'Manter minha conta' }))
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    expect(fetchMock.mock.calls.some(([url, options]) => url === '/api/v1/me' && options?.method === 'DELETE')).toBe(false)
  })

  it('deletes the account only after confirmation, clears the token, and returns to login', async () => {
    setAuthToken('customer-token')
    const fetchMock = setupFetch()
    render(<ProfilePage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Excluir minha conta' }))
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar exclusão' }))

    await waitFor(() => expect(getAuthToken()).toBeNull())
    expect(window.location.pathname).toBe('/login')
    expect(fetchMock.mock.calls.some(([url, options]) => url === '/api/v1/me' && options?.method === 'DELETE')).toBe(true)
  })
})
