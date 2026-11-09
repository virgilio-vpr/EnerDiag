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

it('protects the administrator profile and saves only personal fields', async () => {
  setAuthToken('admin-token')
  const admin = { ...account, role: 'admin', profile: null }
  const fetchMock = setupFetch({ 'GET /api/v1/me': jsonResponse(200, { data: admin }), 'PUT /api/v1/me': jsonResponse(200, { data: admin }) })
  render(<ProfilePage />)
  await screen.findByRole('heading', { name: 'Dados de acesso' })
  expect(screen.queryByRole('button', { name: 'Excluir minha conta' })).not.toBeInTheDocument()
  expect(screen.queryByLabelText('Nome da empresa')).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Salvar alterações' }))
  await screen.findByText('Seu perfil foi atualizado.')
  const call = fetchMock.mock.calls.find(([, options]) => options?.method === 'PUT')
  expect(JSON.parse(String(call?.[1]?.body))).toEqual({ name: account.name, phone: account.phone })
})

it('keeps the old administrator email until the confirmation code is accepted', async () => {
  setAuthToken('admin-token')
  const fetchMock = setupFetch({
    'GET /api/v1/me': jsonResponse(200, { data: { ...account, role: 'admin', profile: null } }),
    'POST /api/v1/me/email-change': jsonResponse(200, { message: 'Código enviado.' }),
    'POST /api/v1/me/email-change/confirm': jsonResponse(200, { email: 'novo@example.com', message: 'E-mail atualizado.' }),
  })
  render(<ProfilePage />)
  fireEvent.change(await screen.findByLabelText('Novo e-mail'), { target: { value: 'novo@example.com' } })
  fireEvent.change(screen.getByLabelText('Senha atual para alterar o e-mail'), { target: { value: 'OriginalAdminPassword2026!' } })
  fireEvent.click(screen.getByRole('button', { name: 'Enviar código de confirmação' }))
  const codeInput = await screen.findByLabelText('Código enviado para novo@example.com')
  expect(screen.getByLabelText('E-mail')).toHaveValue(account.email)
  fireEvent.change(codeInput, { target: { value: '123456' } })
  fireEvent.click(screen.getByRole('button', { name: 'Confirmar novo e-mail' }))
  await screen.findByText('E-mail atualizado.')
  expect(screen.getByLabelText('E-mail')).toHaveValue('novo@example.com')
  const call = fetchMock.mock.calls.find(([url]) => url === '/api/v1/me/email-change/confirm')
  expect(JSON.parse(String(call?.[1]?.body))).toEqual({ code: '123456' })
})

it.each(['admin', 'customer'])('submits %s password changes and clears password inputs', async (role) => {
  setAuthToken('admin-token')
  const fetchMock = setupFetch({
    'GET /api/v1/me': jsonResponse(200, { data: { ...account, role, profile: role === 'admin' ? null : account.profile } }),
    'PUT /api/v1/me/password': jsonResponse(200, { message: 'Senha atualizada.' }),
  })
  render(<ProfilePage />)
  fireEvent.change(await screen.findByLabelText('Senha atual', { exact: true }), { target: { value: 'OriginalAdminPassword2026!' } })
  fireEvent.change(screen.getByLabelText('Nova senha', { exact: true }), { target: { value: 'Abcd12!x' } })
  fireEvent.change(screen.getByLabelText('Confirmar nova senha'), { target: { value: 'Abcd12!x' } })
  fireEvent.click(screen.getByRole('button', { name: 'Salvar nova senha' }))
  await screen.findByText('Senha atualizada.')
  expect(screen.getByLabelText('Nova senha', { exact: true })).toHaveValue('')
  const call = fetchMock.mock.calls.find(([url]) => url === '/api/v1/me/password')
  expect(JSON.parse(String(call?.[1]?.body))).toEqual({ current_password: 'OriginalAdminPassword2026!', password: 'Abcd12!x', password_confirmation: 'Abcd12!x' })
})

it('shows access validation errors without changing the administrator email', async () => {
  setAuthToken('admin-token')
  setupFetch({
    'GET /api/v1/me': jsonResponse(200, { data: { ...account, role: 'admin', profile: null } }),
    'POST /api/v1/me/email-change': jsonResponse(422, { message: 'Dados inválidos.', errors: { current_password: ['A senha atual está incorreta.'] } }),
  })
  render(<ProfilePage />)
  fireEvent.change(await screen.findByLabelText('Novo e-mail'), { target: { value: 'novo@example.com' } })
  fireEvent.change(screen.getByLabelText('Senha atual para alterar o e-mail'), { target: { value: 'incorreta' } })
  fireEvent.click(screen.getByRole('button', { name: 'Enviar código de confirmação' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('A senha atual está incorreta.')
  expect(screen.getByLabelText('E-mail')).toHaveValue(account.email)
  expect(screen.queryByRole('button', { name: 'Confirmar novo e-mail' })).not.toBeInTheDocument()
})

it('toggles each customer password independently without submitting the form', async () => {
  setAuthToken('customer-token')
  const fetchMock = setupFetch()
  render(<ProfilePage />)
  const current = await screen.findByLabelText('Senha atual', { exact: true })
  const password = screen.getByLabelText('Nova senha', { exact: true })
  const confirmation = screen.getByLabelText('Confirmar nova senha')
  const buttons = screen.getAllByRole('button', { name: 'Mostrar senha' })
  for (const [index, input] of [current, password, confirmation].entries()) {
    expect(input).toHaveAttribute('type', 'password')
    fireEvent.click(buttons[index])
    expect(input).toHaveAttribute('type', 'text')
    expect(buttons[index]).toHaveAttribute('aria-pressed', 'true')
    fireEvent.click(buttons[index])
    expect(input).toHaveAttribute('type', 'password')
  }
  expect(fetchMock.mock.calls.some(([, options]) => options?.method === 'PUT')).toBe(false)
})
