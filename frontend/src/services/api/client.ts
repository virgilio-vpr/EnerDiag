export type ApiErrorPayload = {
  message?: string
  code?: string
  errors?: Record<string, string[]>
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
  authToken = token
}

export function getAuthToken() {
  return authToken
}

export async function apiRequest<T>(
  path: string,
  options: RequestInit = {},
  config: { notifyGlobalError?: boolean } = {},
): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body && !(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }
  if (authToken) {
    headers.set('Authorization', `Bearer ${authToken}`)
  }

  const response = await fetch(`/api/v1${path}`, { ...options, headers })
  const payload = await response.json().catch(() => ({})) as ApiErrorPayload
  if (!response.ok) {
    const message = payload.message || errorMessages[response.status] || errorMessages[500]
    const error = new ApiError(response.status, message, payload.code, payload.errors)
    if (config.notifyGlobalError !== false) {
      window.dispatchEvent(new CustomEvent('enerdiag:api-error', { detail: error }))
    }
    throw error
  }

  return payload as T
}

export function getHttpErrorMessage(status: number, serverMessage?: string) {
  return serverMessage || errorMessages[status] || errorMessages[500]
}
