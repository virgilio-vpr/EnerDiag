import { NavigationLink } from '../components/NavigationControl'
import { getHttpErrorMessage } from '../services/api/client'

type ErrorPageProps = {
  status: number
  message?: string
  returnForecast?: string
}

export function ErrorPage({ status, message, returnForecast }: ErrorPageProps) {
  const title = status === 503 ? 'Manutenção programada' : `Erro ${status}`

  return (
    <main className="error-page" data-error-status={status}>
      <p className="error-page__code">{status}</p>
      <h1>{title}</h1>
      <p>{getHttpErrorMessage(status, message)}</p>
      {status === 503 && (
        <p role="status">Previsão de retorno: {returnForecast ?? 'ainda não informada.'}</p>
      )}
      <NavigationLink direction="back" href="/">Voltar ao início</NavigationLink>
    </main>
  )
}
