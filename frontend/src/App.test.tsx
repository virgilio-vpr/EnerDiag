import './pages/SimulationReportPage'
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import { apiRequest, getAuthToken, getAuthenticatedUser, getCachedApiResponse, setAuthToken, setAuthenticatedUser } from './services/api/client'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  setAuthToken(null)
  setAuthenticatedUser(null)
  window.history.replaceState({}, '', '/')
})

describe('application shell', () => {
  it('logs out from the landing page, revokes the session and clears cached customer data', async () => {
    setAuthToken('customer-session')
    setAuthenticatedUser({ id: 2, role: 'customer' })
    const fetchMock = vi.fn<typeof fetch>(async () => new Response(JSON.stringify({ data: [] }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    await apiRequest('/simulations?page=1', {}, { cacheTtlMs: 60_000 })
    render(<App />)
    fireEvent.click(screen.getByRole('button', { name: 'Sair' }))

    expect(await screen.findByRole('heading', { name: 'Entre na sua conta' })).toBeInTheDocument()
    expect(window.location.pathname).toBe('/login')
    expect(getAuthToken()).toBeNull()
    expect(getAuthenticatedUser()).toBeNull()
    expect(getCachedApiResponse('/simulations?page=1')).toBeUndefined()
    expect(screen.queryByRole('button', { name: 'Sair' })).not.toBeInTheDocument()
    const logoutRequest = fetchMock.mock.calls.find(([input]) => String(input).endsWith('/auth/logout'))
    expect(logoutRequest).toBeDefined()
    expect(logoutRequest?.[1]).toEqual(expect.objectContaining({ method: 'POST' }))
    expect(new Headers(logoutRequest?.[1]?.headers).get('Authorization')).toBe('Bearer customer-session')
  })

  it('updates the header immediately after login and resets the login form when signing out on that page', async () => {
    window.history.replaceState({}, '', '/login')
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => new Response(JSON.stringify(
      String(input).endsWith('/auth/login') ? { token: 'new-customer-session', user: { id: 2, role: 'customer' } } : { data: [] },
    ), { status: 200 })))
    render(<App />)
    fireEvent.change(screen.getByLabelText('E-mail', { exact: true }), { target: { value: 'customer@example.test' } })
    fireEvent.change(screen.getByLabelText('Senha', { exact: true }), { target: { value: 'CustomerPassword123!' } })
    fireEvent.click(screen.getByRole('button', { name: 'Entrar' }))
    expect(await screen.findByRole('heading', { name: 'Você entrou na sua conta' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Sair' }))

    expect(await screen.findByRole('heading', { name: 'Entre na sua conta' })).toBeInTheDocument()
    expect(screen.getByLabelText('E-mail', { exact: true })).toHaveValue('')
    expect(screen.getByLabelText('Senha', { exact: true })).toHaveValue('')
    expect(getAuthToken()).toBeNull()
  })

  it('allows an expired session to sign out without displaying an API error', async () => {
    setAuthToken('expired-session')
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 401 })))
    render(<App />)
    fireEvent.click(screen.getByRole('button', { name: 'Sair' }))

    expect(await screen.findByRole('heading', { name: 'Entre na sua conta' })).toBeInTheDocument()
    expect(getAuthToken()).toBeNull()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('keeps the session available for retry when logout cannot reach the API', async () => {
    setAuthToken('customer-session')
    const fetchMock = vi.fn().mockRejectedValueOnce(new TypeError('Network unavailable'))
      .mockResolvedValue(new Response('{}', { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    render(<App />)
    fireEvent.click(screen.getByRole('button', { name: 'Sair' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Não foi possível encerrar a sessão. Tente sair novamente.')
    expect(getAuthToken()).toBe('customer-session')
    fireEvent.click(screen.getByRole('button', { name: 'Sair' }))
    expect(await screen.findByRole('heading', { name: 'Entre na sua conta' })).toBeInTheDocument()
    expect(getAuthToken()).toBeNull()
  })

  it('disables repeated logout requests while the current one is pending', async () => {
    setAuthToken('customer-session')
    let complete!: (response: Response) => void
    const fetchMock = vi.fn(() => new Promise<Response>((resolve) => { complete = resolve }))
    vi.stubGlobal('fetch', fetchMock)
    render(<App />)
    fireEvent.click(screen.getByRole('button', { name: 'Sair' }))
    const pendingButton = screen.getByRole('button', { name: 'Saindo…' })
    expect(pendingButton).toBeDisabled()
    fireEvent.click(pendingButton)
    expect(fetchMock).toHaveBeenCalledTimes(1)
    await act(async () => { complete(new Response('{}', { status: 200 })) })
    await waitFor(() => expect(getAuthToken()).toBeNull())
  })

  it.each(['customer', 'admin'] as const)('preserves the %s session and disables navigation to the current profile', async (role) => {
    setAuthToken('customer-session')
    setAuthenticatedUser({ id: 1, role })
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => new Response(JSON.stringify({
      data: String(input).endsWith('/distributors') ? [] : { role, name: 'Cliente', email: 'customer@example.test', phone: '', profile: null },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } })))
    render(<App />)
    fireEvent.click(screen.getByRole('link', { name: 'Meu perfil' }))
    expect(window.location.pathname).toBe('/perfil')
    expect(await screen.findByRole('heading', { name: 'Meu perfil' })).toBeInTheDocument()
    expect(getAuthToken()).toBe('customer-session')
    fireEvent.change(screen.getByLabelText('Nome do responsável'), { target: { value: 'Nome em edição' } })
    const currentProfile = screen.getByRole('button', { name: 'Meu perfil' })
    expect(currentProfile).toBeDisabled()
    expect(currentProfile).toHaveAttribute('aria-current', 'page')
    expect(screen.queryByRole('link', { name: 'Meu perfil' })).not.toBeInTheDocument()
    const historyLength = window.history.length
    for (let click = 0; click < 2; click++) {
      fireEvent.click(currentProfile)
    }
    expect(window.history.length).toBe(historyLength)
    expect(window.location.pathname).toBe('/perfil')
    expect(getAuthToken()).toBe('customer-session')
    expect(screen.getByRole('heading', { name: 'Meu perfil' })).toBeInTheDocument()
    expect(screen.getByLabelText('Nome do responsável')).toHaveValue('Nome em edição')
    expect(screen.getByRole('button', { name: 'Sair' })).toBeEnabled()
    fireEvent.click(screen.getByRole('link', { name: 'EnerDiag' }))
    expect(window.location.pathname).toBe('/')
    expect(screen.getByRole('link', { name: 'Meu perfil' })).toHaveAttribute('href', '/perfil')
  })

  it('shows login and clears the header when opening a report with an expired session', async () => {
    window.history.replaceState({}, '', '/simulations/10/report')
    setAuthToken('expired-token')
    setAuthenticatedUser({ id: 1, role: 'customer' })
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'Não autenticado.' }), { status: 401 })))
    render(<App />)
    expect(await screen.findByRole('heading', { name: 'Entre na sua conta' })).toBeInTheDocument()
    expect(window.location.pathname).toBe('/login')
    expect(getAuthToken()).toBeNull()
    expect(screen.queryByRole('heading', { name: 'Relatório indisponível' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sair' })).not.toBeInTheDocument()
  })

  it('renders the landing page sections and links to registration', () => {
    render(<App />)

    expect(screen.getByRole('link', { name: 'EnerDiag' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /Sua energia pode trabalhar melhor/ })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Como funciona' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /Entenda onde estão as oportunidades/ })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Seus dados tratados com cuidado' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Tema escuro' })).toBeInTheDocument()
    expect(screen.getByRole('img', { name: /instalação industrial com transformador/ })).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: /Criar conta|Começar minha análise|Criar minha conta/ })).toHaveLength(3)
    expect(screen.getByRole('link', { name: 'Começar minha análise gratuita' })).toHaveAttribute('href', '/register')
  })

  it('protects the quotation area for admins and loads it for an admin', async () => {
    window.history.replaceState({}, '', '/admin/quotation-items')
    setAuthToken('customer-token')
    setAuthenticatedUser({ id: 4, role: 'customer' })
    const { rerender } = render(<App />)
    expect(screen.getByRole('alert')).toHaveTextContent('Você não tem permissão')

    window.history.replaceState({}, '', '/admin/quotation-items')
    act(() => { setAuthToken('admin-token'); setAuthenticatedUser({ id: 5, role: 'admin' }) })
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } })))
    rerender(<App />)

    expect(await screen.findByRole('heading', { name: 'Orçamento' })).toBeInTheDocument()
    expect(await screen.findByText('Nenhum item de orçamento cadastrado.')).toBeInTheDocument()
  })

  it('protects quotation creation for admins and loads customer and cost data', async () => {
    window.history.replaceState({}, '', '/admin/quotations')
    setAuthToken('customer-token')
    setAuthenticatedUser({ id: 6, role: 'customer' })
    const { rerender } = render(<App />)
    expect(screen.getByRole('alert')).toHaveTextContent('Você não tem permissão')

    act(() => { setAuthToken('admin-token'); setAuthenticatedUser({ id: 7, role: 'admin' }) })
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      const payload = url.includes('/admin/customers')
        ? { data: [] }
        : url.includes('/admin/quotation-items')
          ? { data: [] }
          : { data: [], links: {}, meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      return new Response(JSON.stringify(payload), { status: 200, headers: { 'Content-Type': 'application/json' } })
    }))
    rerender(<App />)

    expect(await screen.findByRole('heading', { name: 'Orçamentos' })).toBeInTheDocument()
    expect(await screen.findByText('Nenhum orçamento criado.')).toBeInTheDocument()
  })
})
