import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import App from './App'

describe('application shell', () => {
  it('renders the EnerDiag shell and theme control', () => {
    render(<App />)

    expect(screen.getByRole('link', { name: 'EnerDiag' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'EnerDiag' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Tema escuro' })).toBeInTheDocument()
  })
})
