import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { getHttpErrorMessage } from '../services/api/client'
import { ErrorPage } from './ErrorPage'

const errorCodes = [400, 401, 403, 404, 413, 415, 422, 429, 500]

describe('ErrorPage', () => {
  it.each(errorCodes)('renders a friendly message for HTTP %s', (status) => {
    render(<ErrorPage status={status} />)

    expect(screen.getByRole('heading', { name: `Erro ${status}` })).toBeInTheDocument()
    expect(screen.getByRole('main')).toHaveTextContent(getHttpErrorMessage(status))
  })

  it('shows the maintenance return forecast', () => {
    render(<ErrorPage status={503} returnForecast="25/10/2026 às 02h00" />)

    expect(screen.getByRole('heading', { name: 'Manutenção programada' })).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Previsão de retorno: 25/10/2026 às 02h00')
  })
})
