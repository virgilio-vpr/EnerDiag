import { useEffect, useState } from 'react'
import { ApiError } from '../services/api/client'
import { ErrorPage } from '../pages/ErrorPage'

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
  return <ErrorPage status={error.status} message={error.message} />
}
