import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { ProtectedRoute } from './ProtectedRoute'

afterEach(() => window.history.replaceState({}, '', '/'))

describe('ProtectedRoute', () => {
  it('redirects unauthenticated visitors to login', async () => {
    window.history.replaceState({}, '', '/admin')
    render(<ProtectedRoute user={null} requiredRole="admin"><h1>Painel</h1></ProtectedRoute>)

    await waitFor(() => expect(window.location.pathname).toBe('/login'))
    expect(screen.queryByRole('heading', { name: 'Painel' })).not.toBeInTheDocument()
  })

  it('shows forbidden access when a customer visits an admin route', () => {
    render(<ProtectedRoute user={{ id: 1, role: 'customer' }} requiredRole="admin"><h1>Painel</h1></ProtectedRoute>)

    expect(screen.getByRole('alert')).toHaveAttribute('data-error-status', '403')
    expect(screen.queryByRole('heading', { name: 'Painel' })).not.toBeInTheDocument()
  })
})
