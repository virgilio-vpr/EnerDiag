import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiErrorHandler } from '../../components/ApiErrorHandler'
import { apiRequest, getAuthToken, setAuthToken } from './client'

function RequestButton({ path }: { path: string }) {
  return <button onClick={() => void apiRequest(path).catch(() => undefined)}>Enviar</button>
}

afterEach(() => {
  setAuthToken(null)
  vi.unstubAllGlobals()
  window.history.replaceState({}, '', '/')
})

describe('API client', () => {
  it('keeps the token in memory and redirects to login after a 401', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'Sessão expirada.' }), { status: 401 }))
    vi.stubGlobal('fetch', fetchMock)
    setAuthToken('memory-token')
    render(<><ApiErrorHandler /><RequestButton path="/me" /></>)

    fireEvent.click(screen.getByRole('button', { name: 'Enviar' }))

    await waitFor(() => expect(window.location.pathname).toBe('/login'))
    expect(new Headers(fetchMock.mock.calls[0]?.[1]?.headers).get('Authorization')).toBe('Bearer memory-token')
    expect(getAuthToken()).toBe('memory-token')
  })

  it.each([
    [422, 'Confira os dados informados e tente novamente.'],
    [429, 'Muitas tentativas em pouco tempo. Aguarde e tente novamente.'],
  ])('shows the friendly message for HTTP %s', async (status, message) => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status })))
    render(<><ApiErrorHandler /><RequestButton path="/me" /></>)

    fireEvent.click(screen.getByRole('button', { name: 'Enviar' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(message as string)
  })
})
