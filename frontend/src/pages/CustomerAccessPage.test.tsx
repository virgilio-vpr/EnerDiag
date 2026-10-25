import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'
import { ForgotPasswordPage, RegistrationPage, ResetPasswordPage, VerifyEmailPage } from './CustomerAccessPage'
import { getAuthToken, setAuthToken } from '../services/api/client'

function jsonResponse(status: number, payload: unknown) {
  return new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json' } })
}

afterEach(() => {
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.history.replaceState({}, '', '/')
})

describe('customer registration', () => {
  it('blocks submission until terms are accepted', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    render(<RegistrationPage />)

    fireEvent.change(screen.getByLabelText('Nome do responsável'), { target: { value: 'Ana Souza' } })
    fireEvent.change(screen.getByLabelText('Nome da empresa'), { target: { value: 'Empresa Exemplo' } })
    fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'ana@example.com' } })
    fireEvent.change(screen.getByLabelText('Senha'), { target: { value: 'EnergiaSegura2026' } })
    fireEvent.change(screen.getByLabelText('Confirme sua senha'), { target: { value: 'EnergiaSegura2026' } })
    fireEvent.click(screen.getByRole('button', { name: 'Criar conta' }))

    expect(await screen.findByText(/É necessário aceitar/)).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('submits registration and opens email verification', async () => {
    window.history.replaceState({}, '', '/register')
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(201, { message: 'Cadastro realizado.' })))
    render(<App />)

    fireEvent.change(screen.getByLabelText('Nome do responsável'), { target: { value: 'Ana Souza' } })
    fireEvent.change(screen.getByLabelText('Nome da empresa'), { target: { value: 'Empresa Exemplo' } })
    fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'ana@example.com' } })
    fireEvent.change(screen.getByLabelText('Telefone (opcional)'), { target: { value: '11999990000' } })
    fireEvent.change(screen.getByLabelText('Senha'), { target: { value: 'EnergiaSegura2026' } })
    fireEvent.change(screen.getByLabelText('Confirme sua senha'), { target: { value: 'EnergiaSegura2026' } })
    fireEvent.click(screen.getByRole('checkbox', { name: /Li e aceito/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Criar conta' }))

    expect(await screen.findByRole('heading', { name: 'Verifique seu e-mail' })).toBeInTheDocument()
    expect(window.location.pathname).toBe('/verify-email')
    expect(window.location.search).toBe('?email=ana%40example.com')
    const [, request] = vi.mocked(fetch).mock.calls[0]
    expect(JSON.parse(String(request?.body))).toMatchObject({
      name: 'Ana Souza', company_name: 'Empresa Exemplo', email: 'ana@example.com',
      phone: '11999990000', terms_accepted: true, substation_same_address: true,
    })
  })

  it('shows validation feedback for invalid passwords and server errors', async () => {
    render(<RegistrationPage />)
    fireEvent.change(screen.getByLabelText('Senha'), { target: { value: 'curta' } })
    fireEvent.change(screen.getByLabelText('Confirme sua senha'), { target: { value: 'outra' } })
    fireEvent.click(screen.getByRole('button', { name: 'Criar conta' }))
    expect(await screen.findByText('Use pelo menos 10 caracteres, incluindo letras e números.')).toBeInTheDocument()
  })
})

describe('email verification', () => {
  it('shows an invalid code, then confirms and resends through the API', async () => {
    window.history.replaceState({}, '', '/verify-email?email=ana%40example.com')
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse(200, { message: 'E-mail confirmado.' }))
      .mockResolvedValueOnce(jsonResponse(200, { message: 'Se a conta existir, enviaremos um novo código.' }))
    vi.stubGlobal('fetch', fetchMock)
    render(<App />)

    fireEvent.click(screen.getByRole('button', { name: 'Confirmar e-mail' }))
    expect(await screen.findByText('Informe o código de seis dígitos enviado para seu e-mail.')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Código de confirmação'), { target: { value: '123456' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar e-mail' }))
    expect(await screen.findByText(/E-mail confirmado/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Reenviar código' }))
    expect(await screen.findByText(/enviaremos um novo código/)).toBeInTheDocument()
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
    expect(JSON.parse(String(fetchMock.mock.calls[0][1]?.body))).toEqual({ email: 'ana@example.com', code: '123456' })
    expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body))).toEqual({ email: 'ana@example.com' })
  })

  it('shows API errors for a rejected verification code', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(422, {
      message: 'Os dados informados são inválidos.', code: 'VALIDATION_ERROR',
      errors: { code: ['Código inválido ou expirado.'] },
    })))
    render(<VerifyEmailPage email="ana@example.com" />)
    fireEvent.change(screen.getByLabelText('Código de confirmação'), { target: { value: '000000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar e-mail' }))
    expect(await screen.findByText('Código inválido ou expirado.')).toBeInTheDocument()
  })
})

describe('login and password recovery', () => {
  it('logs in and keeps the token in memory', async () => {
    window.history.replaceState({}, '', '/login')
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(200, {
      token: 'memory-only-token', user: { id: 3, name: 'Ana', email: 'ana@example.com', role: 'customer' },
    })))
    render(<App />)
    fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'ana@example.com' } })
    fireEvent.change(screen.getByLabelText('Senha'), { target: { value: 'SenhaValida123' } })
    fireEvent.click(screen.getByRole('button', { name: 'Entrar' }))
    expect(await screen.findByRole('heading', { name: 'Você entrou na sua conta' })).toBeInTheDocument()
    expect(getAuthToken()).toBe('memory-only-token')
  })

  it('routes an admin login to the protected administrative dashboard', async () => {
    window.history.replaceState({}, '', '/login')
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(200, {
      token: 'admin-memory-token', user: { id: 9, name: 'Admin', email: 'admin@example.com', role: 'admin' },
    })))
    render(<App />)
    fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'admin@example.com' } })
    fireEvent.change(screen.getByLabelText('Senha'), { target: { value: 'SenhaValida123' } })
    fireEvent.click(screen.getByRole('button', { name: 'Entrar' }))

    expect(await screen.findByRole('heading', { name: 'Painel administrativo' })).toBeInTheDocument()
    expect(window.location.pathname).toBe('/admin')
    expect(screen.getByRole('link', { name: /Clientes e contatos/ })).toHaveAttribute('href', '/admin/customers')
    expect(getAuthToken()).toBe('admin-memory-token')
  })

  it('shows invalid credentials and routes unverified accounts to code verification', async () => {
    window.history.replaceState({}, '', '/login')
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse(401, { message: 'E-mail ou senha inválidos.', code: 'UNAUTHENTICATED' }))
      .mockResolvedValueOnce(jsonResponse(403, { message: 'Confirme seu e-mail antes de entrar.', code: 'FORBIDDEN' }))
    vi.stubGlobal('fetch', fetchMock)
    render(<App />)
    fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'ana@example.com' } })
    fireEvent.change(screen.getByLabelText('Senha'), { target: { value: 'SenhaValida123' } })
    fireEvent.click(screen.getByRole('button', { name: 'Entrar' }))
    expect(await screen.findByText('E-mail ou senha inválidos.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Entrar' }))
    expect(await screen.findByRole('heading', { name: 'Verifique seu e-mail' })).toBeInTheDocument()
    expect(window.location.search).toBe('?email=ana%40example.com')
  })

  it('shows the neutral recovery response and resets a password with the link token', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(jsonResponse(200, { message: 'Se o e-mail estiver cadastrado, enviaremos as instruções para redefinição.' }))
      .mockResolvedValueOnce(jsonResponse(200, { message: 'Senha redefinida com sucesso.' })))
    const { unmount } = render(<ForgotPasswordPage />)
    fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'ana@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Enviar instruções' }))
    expect(await screen.findByText(/Se o e-mail estiver cadastrado/)).toBeInTheDocument()
    unmount()

    window.history.replaceState({}, '', '/reset-password?email=ana%40example.com&token=reset-123')
    render(<App />)
    fireEvent.change(screen.getByLabelText('Nova senha'), { target: { value: 'NovaSenhaSegura123' } })
    fireEvent.change(screen.getByLabelText('Confirme a nova senha'), { target: { value: 'NovaSenhaSegura123' } })
    fireEvent.click(screen.getByRole('button', { name: 'Redefinir senha' }))
    expect(await screen.findByText(/Você já pode entrar com sua nova senha/)).toBeInTheDocument()
  })

  it('blocks a mismatched password confirmation', async () => {
    render(<ResetPasswordPage email="ana@example.com" token="reset-123" />)
    fireEvent.change(screen.getByLabelText('Nova senha'), { target: { value: 'NovaSenhaSegura123' } })
    fireEvent.change(screen.getByLabelText('Confirme a nova senha'), { target: { value: 'SenhaDiferente123' } })
    fireEvent.click(screen.getByRole('button', { name: 'Redefinir senha' }))
    expect(await screen.findByText('A confirmação da senha não corresponde.')).toBeInTheDocument()
  })
})
