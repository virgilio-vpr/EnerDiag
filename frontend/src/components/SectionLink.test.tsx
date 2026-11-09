import { act, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, expect, it } from 'vitest'
import { SectionLink } from './SectionLink'

afterEach(() => window.history.replaceState({}, '', '/'))

it.each([
  ['/perfil', 'Meu perfil'],
  ['/minhas-simulacoes', 'Minhas simulações'],
  ['/admin', 'Painel administrativo'],
  ['/login', 'Login'],
  ['/register', 'Criar conta'],
  ['/admin/tariffs', 'Tarifas e importação ANEEL'],
  ['/admin/parameters', 'Parâmetros regulatórios'],
])('disables the current section %s and restores its link after leaving', (path, label) => {
  window.history.replaceState({}, '', path)
  render(<SectionLink href={path}>{label}</SectionLink>)
  const current = screen.getByRole('button', { name: label })
  expect(current).toBeDisabled()
  expect(current).toHaveAttribute('aria-current', 'page')
  const historyLength = window.history.length
  fireEvent.click(current)
  fireEvent.click(current)
  expect(window.history.length).toBe(historyLength)
  expect(window.location.pathname).toBe(path)

  act(() => {
    window.history.pushState({}, '', '/')
    window.dispatchEvent(new PopStateEvent('popstate'))
  })
  const link = screen.getByRole('link', { name: label })
  expect(link).toHaveAttribute('href', path)
  expect(fireEvent.click(link)).toBe(false)
  expect(window.location.pathname).toBe(path)
  expect(screen.getByRole('button', { name: label })).toBeDisabled()
})

it('keeps the dashboard accessible from a nested administrative section', () => {
  window.history.replaceState({}, '', '/admin/tariffs')
  render(<SectionLink href="/admin">Painel administrativo</SectionLink>)
  expect(screen.getByRole('link', { name: 'Painel administrativo' })).toHaveAttribute('href', '/admin')
})
