import { PasswordInput } from './PasswordInput'
import { isValidPassword, passwordHint } from '../services/password'
import { useState, type FormEvent } from 'react'
import { ApiError, apiRequest } from '../services/api/client'

function message(error: unknown) {
  return error instanceof ApiError ? Object.values(error.errors ?? {}).flat()[0] ?? error.message : 'Não foi possível atualizar o acesso. Tente novamente.'
}

export function AccountAccess({ onEmailChanged }: { onEmailChanged?: (email: string) => void }) {
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [emailPassword, setEmailPassword] = useState('')
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>, action: 'password' | 'email') {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    setSuccess('')
    try {
      if (action === 'password') {
        if (!isValidPassword(password)) {
          setError(passwordHint)
          return
        }
        if (password !== confirmation) {
          setError('A confirmação da nova senha não corresponde.')
          return
        }
        const response = await apiRequest<{ message: string }>('/me/password', { method: 'PUT', body: JSON.stringify({ current_password: currentPassword, password, password_confirmation: confirmation }) }, { notifyGlobalError: false })
        setCurrentPassword('')
        setPassword('')
        setConfirmation('')
        setSuccess(response.message)
      } else if (confirming) {
        const response = await apiRequest<{ email: string; message: string }>('/me/email-change/confirm', { method: 'POST', body: JSON.stringify({ code }) }, { notifyGlobalError: false })
        onEmailChanged?.(response.email)
        setConfirming(false)
        setEmail('')
        setCode('')
        setSuccess(response.message)
      } else {
        const response = await apiRequest<{ message: string }>('/me/email-change', { method: 'POST', body: JSON.stringify({ email: email.trim(), current_password: emailPassword }) }, { notifyGlobalError: false })
        setEmailPassword('')
        setConfirming(true)
        setSuccess(response.message)
      }
    } catch (failure) {
      setError(message(failure))
    } finally {
      setBusy(false)
    }
  }

  return <section className="admin-account-access" aria-labelledby="admin-access-title">
    <h2 id="admin-access-title">Dados de acesso</h2>
    {onEmailChanged && <p>A conta administrativa não pode ser excluída pelo perfil.</p>}
    {error && <p className="field-error" role="alert">{error}</p>}
    {success && <p className="form-success" role="status">{success}</p>}
    <form className="profile-form" onSubmit={(event) => submit(event, 'password')}>
      <fieldset><legend>Alterar senha</legend><div className="profile-grid">
        <label>Senha atual<PasswordInput autoComplete="current-password" required value={currentPassword} onChange={(event) => setCurrentPassword(event.target.value)} /></label>
        <label>Nova senha<PasswordInput autoComplete="new-password" minLength={8} required value={password} onChange={(event) => setPassword(event.target.value)} /></label>
        <label>Confirmar nova senha<PasswordInput autoComplete="new-password" minLength={8} required value={confirmation} onChange={(event) => setConfirmation(event.target.value)} /></label>
      </div><p className="password-hint">{passwordHint} As outras sessões serão encerradas ao salvar.</p></fieldset>
      <button className="button access-submit" disabled={busy}>Salvar nova senha</button>
    </form>
    {onEmailChanged && <form className="profile-form" onSubmit={(event) => submit(event, 'email')}>
      <fieldset><legend>Alterar e-mail de acesso</legend>
        <p>O e-mail atual permanece válido até confirmar o novo endereço.</p>
        {confirming ? <label>Código enviado para {email}<input inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} required value={code} onChange={(event) => setCode(event.target.value)} /></label> : <div className="profile-grid">
          <label>Novo e-mail<input type="email" autoComplete="email" maxLength={254} required value={email} onChange={(event) => setEmail(event.target.value)} /></label>
          <label>Senha atual para alterar o e-mail<PasswordInput autoComplete="current-password" required value={emailPassword} onChange={(event) => setEmailPassword(event.target.value)} /></label>
        </div>}
      </fieldset>
      <button className="button access-submit" disabled={busy}>{confirming ? 'Confirmar novo e-mail' : 'Enviar código de confirmação'}</button>
      {confirming && <button className="button button--secondary" type="button" disabled={busy} onClick={() => { setConfirming(false); setCode(''); setError(''); setSuccess('') }}>Alterar e-mail informado</button>}
    </form>}
  </section>
}
