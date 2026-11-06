import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiErrorHandler } from '../../components/ApiErrorHandler'
import { apiRequest, getAuthToken, getCachedApiResponse, setAuthToken } from './client'

function RequestButton({ path }: { path: string }) {
  return <button onClick={() => void apiRequest(path).catch(() => undefined)}>Enviar</button>
}

afterEach(() => {
  vi.restoreAllMocks()
  setAuthToken(null)
  vi.unstubAllGlobals()
  window.history.replaceState({}, '', '/')
})

describe('API client', () => {
  it('shares simultaneous reads, including overlapping page mounts', async () => {
    let release!: (response: Response) => void
    const fetchMock = vi.fn(() => new Promise<Response>((resolve) => { release = resolve }))
    vi.stubGlobal('fetch', fetchMock)
    const first = apiRequest('/simulations')
    const second = apiRequest('/simulations')
    expect(fetchMock).toHaveBeenCalledTimes(1)
    release(new Response(JSON.stringify({ data: [{ id: 10 }] }), { status: 200 }))
    expect(await first).toEqual(await second)
  })

  it('uses a short memory cache and allows explicit refresh and expiration', async () => {
    let now = 1000
    vi.spyOn(Date, 'now').mockImplementation(() => now)
    const fetchMock = vi.fn(() => Promise.resolve(new Response(JSON.stringify({ revision: now }), { status: 200 })))
    vi.stubGlobal('fetch', fetchMock)
    const config = { cacheTtlMs: 100 }
    await apiRequest('/simulations/10/report', {}, config)
    now = 1050
    expect(await apiRequest('/simulations/10/report', {}, config)).toEqual({ revision: 1000 })
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(await apiRequest('/simulations/10/report', {}, { ...config, forceRefresh: true })).toEqual({ revision: 1050 })
    now = 1200
    expect(await apiRequest('/simulations/10/report', {}, config)).toEqual({ revision: 1200 })
    expect(fetchMock).toHaveBeenCalledTimes(3)
  })

  it('invalidates cached results after a write', async () => {
    const fetchMock = vi.fn(() => Promise.resolve(new Response(JSON.stringify({ data: [] }), { status: 200 })))
    vi.stubGlobal('fetch', fetchMock)
    await apiRequest('/simulations', {}, { cacheTtlMs: 60_000 })
    expect(getCachedApiResponse('/simulations')).toBeDefined()
    await apiRequest('/simulations/10', { method: 'DELETE' })
    expect(getCachedApiResponse('/simulations')).toBeUndefined()
    await apiRequest('/simulations', {}, { cacheTtlMs: 60_000 })
    expect(fetchMock).toHaveBeenCalledTimes(3)
  })

  it('does not reuse cached or pending results across authenticated sessions', async () => {
    let release!: (response: Response) => void
    const fetchMock = vi.fn().mockImplementationOnce(() => new Promise<Response>((resolve) => { release = resolve }))
      .mockImplementation(() => Promise.resolve(new Response(JSON.stringify({ owner: 'second' }), { status: 200 })))
    vi.stubGlobal('fetch', fetchMock)
    setAuthToken('first-customer')
    const oldRead = apiRequest('/simulations', {}, { cacheTtlMs: 60_000 })
    setAuthToken('second-customer')
    release(new Response(JSON.stringify({ owner: 'first' }), { status: 200 }))
    await oldRead
    expect(getCachedApiResponse('/simulations')).toBeUndefined()
    expect(await apiRequest('/simulations', {}, { cacheTtlMs: 60_000 })).toEqual({ owner: 'second' })
    setAuthToken(null)
    expect(getCachedApiResponse('/simulations')).toBeUndefined()
  })

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

    expect(await screen.findByRole('main')).toHaveTextContent(message as string)
  })
})
