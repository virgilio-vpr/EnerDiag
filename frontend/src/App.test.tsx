import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import App from './App'

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
})
