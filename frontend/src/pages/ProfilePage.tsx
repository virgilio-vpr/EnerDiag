import { useEffect, useState, type FormEvent } from 'react'
import { ApiError, apiRequest, getAuthToken, setAuthToken } from '../services/api/client'

type Distributor = { id: number; name: string; state: string }
type Account = {
  name: string
  email: string
  phone: string | null
  profile: {
    company_name: string
    cnpj: string | null
    distributor_id: number | null
    transformer_power_kva: string | null
    address_details: string | null
    substation_same_address: boolean | null
    substation_address: string | null
  } | null
}

function navigate(path: string) {
  window.history.pushState({}, '', path)
  window.dispatchEvent(new PopStateEvent('popstate'))
}

export function ProfilePage() {
  const [account, setAccount] = useState<Account | null>(null)
  const [distributors, setDistributors] = useState<Distributor[]>([])
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [companyName, setCompanyName] = useState('')
  const [cnpj, setCnpj] = useState('')
  const [distributorId, setDistributorId] = useState('')
  const [transformerPower, setTransformerPower] = useState('')
  const [address, setAddress] = useState('')
  const [sameSubstationAddress, setSameSubstationAddress] = useState(true)
  const [substationAddress, setSubstationAddress] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [confirmDeletion, setConfirmDeletion] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')
  const [successMessage, setSuccessMessage] = useState('')

  useEffect(() => {
    if (!getAuthToken()) {
      window.history.replaceState({}, '', '/login')
      window.dispatchEvent(new PopStateEvent('popstate'))
      return
    }
    let active = true
    Promise.all([
      apiRequest<{ data: Account }>('/me', {}, { notifyGlobalError: false }),
      apiRequest<{ data: Distributor[] }>('/distributors', {}, { notifyGlobalError: false }),
    ]).then(([userResponse, distributorResponse]) => {
      if (!active) return
      const user = userResponse.data
      const profile = user.profile
      setAccount(user)
      setName(user.name ?? '')
      setPhone(user.phone ?? '')
      setCompanyName(profile?.company_name ?? '')
      setCnpj(profile?.cnpj ?? '')
      setDistributorId(profile?.distributor_id ? String(profile.distributor_id) : '')
      setTransformerPower(profile?.transformer_power_kva ?? '')
      setAddress(profile?.address_details ?? '')
      setSameSubstationAddress(profile?.substation_same_address ?? true)
      setSubstationAddress(profile?.substation_address ?? '')
      setDistributors(distributorResponse.data)
    }).catch((error: unknown) => {
      if (active) setErrorMessage(error instanceof Error ? error.message : 'Não foi possível carregar seu perfil.')
    }).finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [])

  async function saveProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setErrorMessage('')
    setSuccessMessage('')
    setSaving(true)
    try {
      const response = await apiRequest<{ data: Account }>('/me', {
        method: 'PUT',
        body: JSON.stringify({
          name: name.trim(),
          phone: phone.trim() || null,
          company_name: companyName.trim(),
          cnpj: cnpj.trim() || null,
          distributor_id: distributorId ? Number(distributorId) : null,
          transformer_power_kva: transformerPower.trim() || null,
          address_details: address.trim() || null,
          substation_same_address: sameSubstationAddress,
          substation_address: sameSubstationAddress ? null : substationAddress.trim(),
        }),
      }, { notifyGlobalError: false })
      setAccount(response.data)
      setSuccessMessage('Seu perfil foi atualizado.')
    } catch (error) {
      if (error instanceof ApiError && error.errors) {
        setErrorMessage(Object.values(error.errors).flat()[0] ?? error.message)
      } else {
        setErrorMessage(error instanceof Error ? error.message : 'Não foi possível salvar seu perfil.')
      }
    } finally {
      setSaving(false)
    }
  }

  async function deleteAccount() {
    setErrorMessage('')
    setDeleting(true)
    try {
      await apiRequest('/me', { method: 'DELETE' }, { notifyGlobalError: false })
      setAuthToken(null)
      navigate('/login')
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Não foi possível excluir sua conta.')
      setDeleting(false)
      setConfirmDeletion(false)
    }
  }

  if (loading) return <section className="access-page" aria-live="polite"><p>Carregando seu perfil…</p></section>
  if (!account) return <section className="access-page" aria-labelledby="profile-error-title"><h1 id="profile-error-title">Perfil indisponível</h1><p className="field-error" role="alert">{errorMessage || 'Não foi possível carregar seu perfil.'}</p></section>

  return <section className="profile-page" aria-labelledby="profile-title">
    <p className="section-eyebrow">Sua conta EnerDiag</p>
    <h1 id="profile-title">Meu perfil</h1>
    <p className="access-intro">Atualize seus dados e as informações da unidade consumidora.</p>
    {errorMessage && <p className="field-error" role="alert">{errorMessage}</p>}
    {successMessage && <p className="form-success" role="status">{successMessage}</p>}

    <form className="profile-form" onSubmit={saveProfile}>
      <fieldset><legend>Dados pessoais</legend><div className="profile-grid">
        <label>Nome do responsável<input autoComplete="name" value={name} onChange={(event) => setName(event.target.value)} required maxLength={255} /></label>
        <label>E-mail<input type="email" value={account.email} readOnly aria-describedby="profile-email-note" /></label>
        <label>Telefone<input autoComplete="tel" value={phone} onChange={(event) => setPhone(event.target.value)} maxLength={30} /></label>
      </div><p id="profile-email-note" className="password-hint">O e-mail de acesso não pode ser alterado por esta tela.</p></fieldset>

      <fieldset><legend>Dados da empresa</legend><div className="profile-grid">
        <label>Nome da empresa<input autoComplete="organization" value={companyName} onChange={(event) => setCompanyName(event.target.value)} required maxLength={255} /></label>
        <label>CNPJ<input value={cnpj} onChange={(event) => setCnpj(event.target.value)} maxLength={18} /></label>
        <label>Distribuidora<select value={distributorId} onChange={(event) => setDistributorId(event.target.value)}><option value="">Não informada</option>{distributors.map((distributor) => <option key={distributor.id} value={distributor.id}>{distributor.name} · {distributor.state}</option>)}</select></label>
        <label>Potência do transformador (kVA)<input inputMode="decimal" value={transformerPower} onChange={(event) => setTransformerPower(event.target.value)} /></label>
      </div>
        <label className="profile-wide">Endereço da unidade consumidora<textarea rows={3} value={address} onChange={(event) => setAddress(event.target.value)} maxLength={5000} /></label>
        <label className="consent-control"><input type="checkbox" checked={sameSubstationAddress} onChange={(event) => setSameSubstationAddress(event.target.checked)} /><span>O endereço da subestação é o mesmo da unidade consumidora.</span></label>
        {!sameSubstationAddress && <label className="profile-wide">Endereço da subestação<textarea rows={3} value={substationAddress} onChange={(event) => setSubstationAddress(event.target.value)} maxLength={5000} required /></label>}
      </fieldset>
      <button className="button access-submit" type="submit" disabled={saving}>{saving ? 'Salvando…' : 'Salvar alterações'}</button>
    </form>

    <section className="delete-account" aria-labelledby="delete-account-title">
      <h2 id="delete-account-title">Excluir conta e dados</h2>
      <p>A exclusão remove sua conta e os dados pessoais associados. Esta ação não pode ser desfeita.</p>
      {!confirmDeletion ? <button className="delete-button" type="button" onClick={() => setConfirmDeletion(true)}>Excluir minha conta</button> : <div className="delete-confirmation" role="alertdialog" aria-labelledby="delete-confirmation-title" aria-describedby="delete-confirmation-description">
        <h3 id="delete-confirmation-title">Confirmar exclusão da conta?</h3>
        <p id="delete-confirmation-description">Sua conta e os dados relacionados serão excluídos permanentemente. Você será desconectado.</p>
        <div><button className="delete-button" type="button" disabled={deleting} onClick={deleteAccount}>{deleting ? 'Excluindo…' : 'Confirmar exclusão'}</button><button className="text-button" type="button" disabled={deleting} onClick={() => setConfirmDeletion(false)}>Manter minha conta</button></div>
      </div>}
    </section>
  </section>
}
