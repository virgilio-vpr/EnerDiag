import { useEffect, useState } from 'react'
import { ApiError, getHttpErrorMessage } from '../services/api/client'

export function ApiErrorHandler() {
  const [error, setError] = useState<ApiError | null>(null)

  useEffect(() => {
    function handleApiError(event: Event) {
      const apiError = (event as CustomEvent<ApiError>).detail
      if (apiError.status === 401) {
        window.history.pushState({}, '', '/login')
      }
      setError(apiError)
    }

    window.addEventListener('enerdiag:api-error', handleApiError)
    return () => window.removeEventListener('enerdiag:api-error', handleApiError)
  }, [])

  if (!error) return null
  return <div role="alert">{getHttpErrorMessage(error.status, error.message)}</div>
}
