export type ApiErrorPayload = {
  message?: string
  code?: string
  errors?: Record<string, string[]>
}

export type AuthenticatedUser = {
  id: number
  role: 'customer' | 'admin'
}

export class ApiError extends Error {
  readonly status: number
  readonly code?: string
  readonly errors?: Record<string, string[]>

  constructor(status: number, message: string, code?: string, errors?: Record<string, string[]>) {
    super(message)
    this.status = status
    this.code = code
    this.errors = errors
    this.name = 'ApiError'
  }
}

let authToken: string | null = null
let authenticatedUser: AuthenticatedUser | null = null
const authListeners = new Set<() => void>()
let authSession: Readonly<{ token: string | null; user: AuthenticatedUser | null }> = { token: authToken, user: authenticatedUser }

function notifyAuthChange() {
  authSession = { token: authToken, user: authenticatedUser }
  for (const listener of authListeners) listener()
}

export function subscribeAuthChanges(listener: () => void) {
  authListeners.add(listener)
  return () => { authListeners.delete(listener) }
}

export function getAuthSession() {
  return authSession
}
let responseGeneration = 0
const responses = new Map<string, { value: unknown; expiresAt: number }>()
const pendingReads = new Map<string, Promise<unknown>>()

function clearResponses() {
  responseGeneration += 1
  responses.clear()
  pendingReads.clear()
}

export function getCachedApiResponse<T>(path: string): T | undefined {
  const cached = responses.get(path)
  if (!cached) return undefined
  if (cached.expiresAt <= Date.now()) {
    responses.delete(path)
    return undefined
  }
  return cached.value as T
}

const errorMessages: Record<number, string> = {
  400: 'Não foi possível entender a solicitação.',
  401: 'Sua sessão expirou. Entre novamente.',
  403: 'Você não tem permissão para acessar esta página.',
  404: 'A página ou informação solicitada não foi encontrada.',
  413: 'O arquivo enviado ultrapassa o limite permitido.',
  415: 'O tipo de arquivo enviado não é aceito.',
  422: 'Confira os dados informados e tente novamente.',
  429: 'Muitas tentativas em pouco tempo. Aguarde e tente novamente.',
  500: 'Ocorreu um erro inesperado. Tente novamente mais tarde.',
  503: 'O sistema está em manutenção. Tente novamente mais tarde.',
}

export function setAuthToken(token: string | null) {
  clearResponses()
  const changed = authToken !== token || (token === null && authenticatedUser !== null)
  authToken = token
  if (token === null) authenticatedUser = null
  if (changed) notifyAuthChange()
}

export function getAuthToken() {
  return authToken
}

export function setAuthenticatedUser(user: AuthenticatedUser | null) {
  if (authenticatedUser === user) return
  authenticatedUser = user
  notifyAuthChange()
}

export function getAuthenticatedUser() {
  return authenticatedUser
}

export async function apiRequest<T>(
  path: string,
  options: RequestInit = {},
  config: { notifyGlobalError?: boolean; cacheTtlMs?: number; forceRefresh?: boolean } = {},
): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body && !(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }
  if (authToken) {
    headers.set('Authorization', `Bearer ${authToken}`)
  }

  const isRead = (options.method ?? 'GET').toUpperCase() === 'GET'
  if (!isRead) clearResponses()
  const generation = responseGeneration
  const cacheable = isRead && !options.headers && !options.signal && !options.credentials && (config.cacheTtlMs ?? 0) > 0
  if (cacheable && !config.forceRefresh) {
    const cached = getCachedApiResponse<T>(path)
    if (cached !== undefined) return cached
  }
  async function requestData(): Promise<unknown> {
    const response = await fetch(`/api/v1${path}`, { ...options, headers })
    const payload = await response.json().catch(() => ({})) as ApiErrorPayload
    if (!response.ok) {
      throw new ApiError(response.status, payload.message || errorMessages[response.status] || errorMessages[500], payload.code, payload.errors)
    }
    return payload
  }
  try {
    let request: Promise<unknown>
    if (isRead && !options.signal) {
      const key = JSON.stringify([path, generation, [...headers], options.cache, options.credentials])
      const pending = pendingReads.get(key)
      if (pending) request = pending
      else {
        request = requestData().finally(() => { if (pendingReads.get(key) === request) pendingReads.delete(key) })
        pendingReads.set(key, request)
      }
    } else request = requestData()
    const payload = await request
    if (!isRead) clearResponses()
    if (cacheable && generation === responseGeneration) {
      if (responses.size >= 50) responses.delete(responses.keys().next().value!)
      responses.set(path, { value: payload, expiresAt: Date.now() + config.cacheTtlMs! })
    }
    return payload as T
  } catch (error) {
    if (error instanceof ApiError && config.notifyGlobalError !== false) window.dispatchEvent(new CustomEvent('enerdiag:api-error', { detail: error }))
    throw error
  }
}

export function getHttpErrorMessage(status: number, serverMessage?: string) {
  return serverMessage || errorMessages[status] || errorMessages[500]
}
