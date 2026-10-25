import { useState, type FormEvent } from 'react'
import { ApiError, apiRequest, setAuthToken, setAuthenticatedUser } from '../services/api/client'

type RegisterData = {
  name: string
  company_name: string
  email: string
  phone: string
  password: string
  password_confirmation: string
  substation_same_address: true
  terms_accepted: boolean
}

type FieldErrors = Record<string, string>

function firstFieldErrors(error: ApiError): FieldErrors {
  return Object.fromEntries(Object.entries(error.errors ?? {}).map(([field, messages]) => [field, messages[0]]))
}

export function RegistrationPage() {
  const [form, setForm] = useState<RegisterData>({
    name: '', company_name: '', email: '', phone: '', password: '', password_confirmation: '',
    substation_same_address: true, terms_accepted: false,
  })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [requestError, setRequestError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const hasLength = form.password.length >= 10
  const hasLettersAndNumbers = /[a-z]/i.test(form.password) && /\d/.test(form.password)
  const passwordStrength = !form.password ? 'Informe uma senha com pelo menos 10 caracteres, letras e números.'
    : hasLength && hasLettersAndNumbers ? 'Senha atende aos requisitos básicos.'
      : 'Use pelo menos 10 caracteres, incluindo letras e números.'

  function update<K extends keyof RegisterData>(field: K, value: RegisterData[K]) {
    setForm((current) => ({ ...current, [field]: value }))
    setErrors((current) => ({ ...current, [field]: '' }))
    setRequestError('')
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setErrors({})
    setRequestError('')
    const requiredErrors: FieldErrors = {}
    if (!form.name.trim()) requiredErrors.name = 'Informe o nome do responsável.'
    if (!form.company_name.trim()) requiredErrors.company_name = 'Informe o nome da empresa.'
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) requiredErrors.email = 'Informe um endereço de e-mail válido.'
    if (Object.keys(requiredErrors).length) {
      setErrors(requiredErrors)
      return
    }
    if (!form.terms_accepted) {
      setErrors({ terms_accepted: 'É necessário aceitar os Termos de Uso e a Política de Privacidade.' })
      return
    }
    if (!hasLength || !hasLettersAndNumbers) {
      setErrors({ password: 'Use pelo menos 10 caracteres, incluindo letras e números.' })
      return
    }
    if (form.password !== form.password_confirmation) {
      setErrors({ password_confirmation: 'A confirmação da senha não corresponde.' })
      return
    }

    setSubmitting(true)
    try {
      await apiRequest('/auth/register', { method: 'POST', body: JSON.stringify(form) }, { notifyGlobalError: false })
      window.history.pushState({}, '', `/verify-email?email=${encodeURIComponent(form.email)}`)
      window.dispatchEvent(new PopStateEvent('popstate'))
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error))
        if (!error.errors || Object.keys(error.errors).length === 0) setRequestError(error.message)
      } else {
        setRequestError('Não foi possível concluir o cadastro. Verifique sua conexão e tente novamente.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="access-page" aria-labelledby="register-title">
      <p className="section-eyebrow">Sua conta EnerDiag</p>
      <h1 id="register-title">Crie sua conta</h1>
      <p className="access-intro">Cadastre-se para iniciar a análise das faturas de energia da sua empresa.</p>
      <form className="access-form" onSubmit={submit} noValidate>
        <label>Nome do responsável<input autoComplete="name" name="name" value={form.name} onChange={(event) => update('name', event.target.value)} required aria-invalid={Boolean(errors.name)} /></label>
        {errors.name && <p className="field-error">{errors.name}</p>}
        <label>Nome da empresa<input autoComplete="organization" name="company_name" value={form.company_name} onChange={(event) => update('company_name', event.target.value)} required aria-invalid={Boolean(errors.company_name)} /></label>
        {errors.company_name && <p className="field-error">{errors.company_name}</p>}
        <label>E-mail<input autoComplete="email" name="email" type="email" value={form.email} onChange={(event) => update('email', event.target.value)} required aria-invalid={Boolean(errors.email)} /></label>
        {errors.email && <p className="field-error">{errors.email}</p>}
        <label>Telefone <span className="optional-label">(opcional)</span><input autoComplete="tel" name="phone" type="tel" value={form.phone} onChange={(event) => update('phone', event.target.value)} /></label>
        <label>Senha<input autoComplete="new-password" name="password" type="password" value={form.password} onChange={(event) => update('password', event.target.value)} required aria-describedby="password-help" aria-invalid={Boolean(errors.password)} /></label>
        <p id="password-help" className={hasLength && hasLettersAndNumbers ? 'password-hint password-hint--valid' : 'password-hint'}>{passwordStrength}</p>
        {errors.password && <p className="field-error">{errors.password}</p>}
        <label>Confirme sua senha<input autoComplete="new-password" name="password_confirmation" type="password" value={form.password_confirmation} onChange={(event) => update('password_confirmation', event.target.value)} required aria-invalid={Boolean(errors.password_confirmation)} /></label>
        {errors.password_confirmation && <p className="field-error">{errors.password_confirmation}</p>}
        <label className="consent-control"><input name="terms_accepted" type="checkbox" checked={form.terms_accepted} onChange={(event) => update('terms_accepted', event.target.checked)} /> <span>Li e aceito os Termos de Uso e a Política de Privacidade.</span></label>
        {errors.terms_accepted && <p className="field-error">{errors.terms_accepted}</p>}
        <p className="security-note">Sua senha é protegida e suas faturas são usadas apenas para preparar a análise da sua empresa.</p>
        {requestError && <p className="field-error" role="alert">{requestError}</p>}
        <button className="button access-submit" type="submit" disabled={submitting}>{submitting ? 'Enviando…' : 'Criar conta'}</button>
      </form>
      <p className="access-switch">Já tem uma conta? <a href="/login">Entrar</a></p>
    </section>
  )
}

export function VerifyEmailPage({ email: initialEmail }: { email: string }) {
  const [email, setEmail] = useState(initialEmail)
  const [code, setCode] = useState('')
  const [message, setMessage] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const [busyAction, setBusyAction] = useState<'verify' | 'resend' | null>(null)

  async function verify(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setMessage('')
    setErrorMessage('')
    if (!/^\d{6}$/.test(code)) {
      setErrorMessage('Informe o código de seis dígitos enviado para seu e-mail.')
      return
    }
    setBusyAction('verify')
    try {
      const response = await apiRequest<{ message: string }>('/auth/verify-email', { method: 'POST', body: JSON.stringify({ email, code }) }, { notifyGlobalError: false })
      setMessage(`${response.message} Agora você pode entrar na sua conta.`)
    } catch (error) {
      setErrorMessage(error instanceof ApiError ? error.errors?.code?.[0] ?? error.message : 'Não foi possível confirmar o e-mail. Tente novamente.')
    } finally {
      setBusyAction(null)
    }
  }

  async function resend() {
    setMessage('')
    setErrorMessage('')
    setBusyAction('resend')
    try {
      const response = await apiRequest<{ message: string }>('/auth/resend-code', { method: 'POST', body: JSON.stringify({ email }) }, { notifyGlobalError: false })
      setMessage(response.message)
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Não foi possível reenviar o código. Tente novamente.')
    } finally {
      setBusyAction(null)
    }
  }

  return (
    <section className="access-page" aria-labelledby="verify-title">
      <p className="section-eyebrow">Só falta confirmar</p>
      <h1 id="verify-title">Verifique seu e-mail</h1>
      <p className="access-intro">Enviamos um código de seis dígitos para o endereço informado. Digite-o abaixo para confirmar sua conta.</p>
      <form className="access-form" onSubmit={verify} noValidate>
        <label>E-mail<input autoComplete="email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} required /></label>
        <label>Código de confirmação<input autoComplete="one-time-code" inputMode="numeric" name="code" value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))} required aria-describedby="code-help" /></label>
        <p id="code-help" className="password-hint">O código tem seis números e pode ser usado uma única vez.</p>
        {message && <p className="form-success" role="status">{message}</p>}
        {errorMessage && <p className="field-error" role="alert">{errorMessage}</p>}
        <button className="button access-submit" type="submit" disabled={busyAction !== null}>{busyAction === 'verify' ? 'Confirmando…' : 'Confirmar e-mail'}</button>
      </form>
      <button className="text-button" type="button" onClick={resend} disabled={busyAction !== null}>{busyAction === 'resend' ? 'Enviando…' : 'Reenviar código'}</button>
      <p className="access-switch"><a href="/login">Voltar para entrar</a></p>
    </section>
  )
}

export function LoginPage() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [totpCode, setTotpCode] = useState('')
  const [requiresTotp, setRequiresTotp] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [signedIn, setSignedIn] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setErrorMessage('')
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || !password) {
      setErrorMessage('Informe um e-mail válido e sua senha.')
      return
    }
    setSubmitting(true)
    try {
      const response = await apiRequest<{ token: string; user: { id: number; role: 'customer' | 'admin' } }>(
        '/auth/login', { method: 'POST', body: JSON.stringify({ email, password, ...(requiresTotp ? { totp_code: totpCode } : {}) }) }, { notifyGlobalError: false },
      )
      setAuthToken(response.token)
      setAuthenticatedUser(response.user)
      if (response.user.role === 'admin') {
        window.history.pushState({}, '', '/admin')
        window.dispatchEvent(new PopStateEvent('popstate'))
        return
      }
      setSignedIn(true)
    } catch (error) {
      if (error instanceof ApiError && error.errors?.totp_code) {
        setRequiresTotp(true)
        setTotpCode('')
        setErrorMessage(error.errors.totp_code[0])
        return
      }
      if (error instanceof ApiError && error.status === 403) {
        window.history.pushState({}, '', `/verify-email?email=${encodeURIComponent(email)}`)
        window.dispatchEvent(new PopStateEvent('popstate'))
        return
      }
      setErrorMessage(error instanceof Error ? error.message : 'Não foi possível entrar. Tente novamente.')
    } finally {
      setSubmitting(false)
    }
  }

  if (signedIn) {
    return <section className="access-page" aria-labelledby="login-success-title"><p className="section-eyebrow">Acesso confirmado</p><h1 id="login-success-title">Você entrou na sua conta</h1><p className="form-success" role="status">Sua sessão está ativa neste dispositivo.</p><button className="button access-submit" type="button" onClick={() => { window.history.pushState({}, '', '/upload-faturas'); window.dispatchEvent(new PopStateEvent('popstate')) }}>Continuar para minhas faturas</button></section>
  }

  return (
    <section className="access-page" aria-labelledby="login-title">
      <p className="section-eyebrow">Bem-vindo de volta</p>
      <h1 id="login-title">Entre na sua conta</h1>
      <p className="access-intro">Acesse suas análises e acompanhe suas oportunidades de economia.</p>
      <form className="access-form" onSubmit={submit} noValidate>
        <label>E-mail<input autoComplete="email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} required /></label>
        <label>Senha<input autoComplete="current-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} required /></label>
        {requiresTotp && <label>Código do autenticador<input autoComplete="one-time-code" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} value={totpCode} onChange={(event) => setTotpCode(event.target.value.replace(/[^0-9]/g, ''))} required /></label>}
        {errorMessage && <p className="field-error" role="alert">{errorMessage}</p>}
        <button className="button access-submit" type="submit" disabled={submitting}>{submitting ? 'Entrando…' : 'Entrar'}</button>
      </form>
      <p className="access-switch"><a href="/forgot-password">Esqueci minha senha</a></p>
      <p className="access-switch">Ainda não tem uma conta? <a href="/register">Criar conta</a></p>
    </section>
  )
}

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [message, setMessage] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setMessage('')
    setErrorMessage('')
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setErrorMessage('Informe um endereço de e-mail válido.')
      return
    }
    setSubmitting(true)
    try {
      const response = await apiRequest<{ message: string }>('/auth/forgot-password', { method: 'POST', body: JSON.stringify({ email }) }, { notifyGlobalError: false })
      setMessage(response.message)
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Não foi possível enviar a solicitação. Tente novamente.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="access-page" aria-labelledby="forgot-title">
      <p className="section-eyebrow">Recuperação de acesso</p>
      <h1 id="forgot-title">Esqueceu sua senha?</h1>
      <p className="access-intro">Informe seu e-mail e enviaremos as instruções para redefinir sua senha, caso exista uma conta associada.</p>
      <form className="access-form" onSubmit={submit} noValidate>
        <label>E-mail<input autoComplete="email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} required /></label>
        {message && <p className="form-success" role="status">{message}</p>}
        {errorMessage && <p className="field-error" role="alert">{errorMessage}</p>}
        <button className="button access-submit" type="submit" disabled={submitting}>{submitting ? 'Enviando…' : 'Enviar instruções'}</button>
      </form>
      <p className="access-switch"><a href="/login">Voltar para entrar</a></p>
    </section>
  )
}

export function ResetPasswordPage({ email: initialEmail, token }: { email: string; token: string }) {
  const [email, setEmail] = useState(initialEmail)
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [message, setMessage] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setMessage('')
    setErrorMessage('')
    if (!token) {
      setErrorMessage('O link está incompleto. Solicite uma nova redefinição de senha.')
      return
    }
    if (password.length < 10 || !/[a-z]/i.test(password) || !/\d/.test(password)) {
      setErrorMessage('Use pelo menos 10 caracteres, incluindo letras e números.')
      return
    }
    if (password !== confirmation) {
      setErrorMessage('A confirmação da senha não corresponde.')
      return
    }
    setSubmitting(true)
    try {
      const response = await apiRequest<{ message: string }>('/auth/reset-password', {
        method: 'POST', body: JSON.stringify({ email, token, password, password_confirmation: confirmation }),
      }, { notifyGlobalError: false })
      setMessage(`${response.message} Você já pode entrar com sua nova senha.`)
    } catch (error) {
      setErrorMessage(error instanceof ApiError ? error.errors?.token?.[0] ?? error.message : 'Não foi possível redefinir a senha. Tente novamente.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="access-page" aria-labelledby="reset-title">
      <p className="section-eyebrow">Atualização segura</p>
      <h1 id="reset-title">Defina uma nova senha</h1>
      <form className="access-form" onSubmit={submit} noValidate>
        <label>E-mail<input autoComplete="email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} required /></label>
        <label>Nova senha<input autoComplete="new-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} required /></label>
        <p className="password-hint">Use pelo menos 10 caracteres, incluindo letras e números.</p>
        <label>Confirme a nova senha<input autoComplete="new-password" type="password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} required /></label>
        {message && <p className="form-success" role="status">{message}</p>}
        {errorMessage && <p className="field-error" role="alert">{errorMessage}</p>}
        <button className="button access-submit" type="submit" disabled={submitting}>{submitting ? 'Salvando…' : 'Redefinir senha'}</button>
      </form>
      <p className="access-switch"><a href="/login">Voltar para entrar</a></p>
    </section>
  )
}
