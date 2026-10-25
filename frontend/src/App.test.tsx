import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import { setAuthToken, setAuthenticatedUser } from './services/api/client'

afterEach(() => {
  vi.unstubAllGlobals()
  setAuthToken(null)
  setAuthenticatedUser(null)
  window.history.replaceState({}, '', '/')
})

describe('application shell', () => {
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
    expect(screen.getByRole('link', { name: 'Começar minha análise' })).toHaveAttribute('href', '/register')
  })

  it('protects the quotation area for admins and loads it for an admin', async () => {
    window.history.replaceState({}, '', '/admin/quotation-items')
    setAuthToken('customer-token')
    setAuthenticatedUser({ id: 4, role: 'customer' })
    const { rerender } = render(<App />)
    expect(screen.getByRole('alert')).toHaveTextContent('Você não tem permissão')

    window.history.replaceState({}, '', '/admin/quotation-items')
    setAuthToken('admin-token')
    setAuthenticatedUser({ id: 5, role: 'admin' })
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

    setAuthToken('admin-token')
    setAuthenticatedUser({ id: 7, role: 'admin' })
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
