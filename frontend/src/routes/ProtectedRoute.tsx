import { useEffect } from 'react'

export type UserRole = 'customer' | 'admin'

export type AuthenticatedUser = {
  id: number
  role: UserRole
}

type ProtectedRouteProps = {
  user: AuthenticatedUser | null
  requiredRole: UserRole
  children: React.ReactNode
}

function RedirectToLogin() {
  useEffect(() => {
    window.history.replaceState({}, '', '/login')
    window.dispatchEvent(new PopStateEvent('popstate'))
  }, [])

  return <p>Redirecionando para o login…</p>
}

export function ProtectedRoute({ user, requiredRole, children }: ProtectedRouteProps) {
  if (!user) {
    return <RedirectToLogin />
  }

  if (user.role !== requiredRole) {
    return <div role="alert" data-error-status="403">Você não tem permissão para acessar esta página.</div>
  }

  return <>{children}</>
}
