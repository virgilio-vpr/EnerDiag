import { useEffect, useMemo, useState, type ChangeEvent, type FormEvent } from 'react'
import { ApiError, apiRequest, getAuthToken } from '../services/api/client'

const MAX_FILE_BYTES = 20 * 1024 * 1024
const MAX_TOTAL_BYTES = 120 * 1024 * 1024
const MAX_PAGES = 600
const MIN_FILES = 6
const MAX_FILES = 12

type Distributor = { id: number; name: string; state: string }
type Account = {
  email: string
  profile?: {
    company_name?: string | null
    cnpj?: string | null
    distributor_id?: number | null
    transformer_power_kva?: string | null
    address_details?: string | null
    substation_same_address?: boolean | null
    substation_address?: string | null
  } | null
}
type QueuedSimulation = { data: { id: number; status: string } }

function estimatePageCount(contents: string): number | null {
  const pageObjects = [...contents.matchAll(/\/Type\s*\/Page\b/g)].length
  if (pageObjects > 0) return pageObjects
  const pageTreeCounts = [...contents.matchAll(/\/Count\s+(\d+)/g)].map((match) => Number(match[1]))
  return pageTreeCounts.length ? Math.max(...pageTreeCounts) : null
}

export function InvoiceUploadPage() {
  const [account, setAccount] = useState<Account | null>(null)
  const [distributors, setDistributors] = useState<Distributor[]>([])
  const [companyName, setCompanyName] = useState('')
  const [cnpj, setCnpj] = useState('')
  const [transformerPower, setTransformerPower] = useState('')
  const [address, setAddress] = useState('')
  const [sameSubstationAddress, setSameSubstationAddress] = useState(true)
  const [substationAddress, setSubstationAddress] = useState('')
  const [distributorId, setDistributorId] = useState('')
  const [unsupportedName, setUnsupportedName] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const [fileValidationErrors, setFileValidationErrors] = useState<Array<{ name: string; message: string }>>([])
  const [validationMessage, setValidationMessage] = useState('')
  const [pageCount, setPageCount] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [notice, setNotice] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const [queuedSimulation, setQueuedSimulation] = useState<{ id: number; status: string } | null>(null)

  useEffect(() => {
    if (!getAuthToken()) {
      window.history.replaceState({}, '', '/login')
      window.dispatchEvent(new PopStateEvent('popstate'))
      return
    }
    let mounted = true
    Promise.all([
      apiRequest<{ data: Account }>('/me', {}, { notifyGlobalError: false }),
      apiRequest<{ data: Distributor[] }>('/distributors', {}, { notifyGlobalError: false }),
    ]).then(([userResponse, distributorResponse]) => {
      if (!mounted) return
      const profile = userResponse.data.profile
      setAccount(userResponse.data)
      setCompanyName(profile?.company_name ?? '')
      setCnpj(profile?.cnpj ?? '')
      setTransformerPower(profile?.transformer_power_kva ?? '')
      setAddress(profile?.address_details ?? '')
      setSameSubstationAddress(profile?.substation_same_address ?? true)
      setSubstationAddress(profile?.substation_address ?? '')
      setDistributors(distributorResponse.data)
      setDistributorId(profile?.distributor_id ? String(profile.distributor_id) : String(distributorResponse.data[0]?.id ?? ''))
    }).catch((error: unknown) => {
      if (mounted) setErrorMessage(error instanceof Error ? error.message : 'Não foi possível carregar os dados da conta.')
    }).finally(() => {
      if (mounted) setLoading(false)
    })
    return () => { mounted = false }
  }, [])

  const totalBytes = useMemo(() => files.reduce((total, file) => total + file.size, 0), [files])
  const selectedDistributor = distributors.find((distributor) => String(distributor.id) === distributorId)
  const hasUnsupportedDistributor = distributorId === 'other'

  async function changeFiles(event: ChangeEvent<HTMLInputElement>) {
    const selectedFiles = Array.from(event.target.files ?? [])
    setFiles(selectedFiles)
    setFileValidationErrors([])
    setPageCount(null)
    setQueuedSimulation(null)
    setValidationMessage('')
    setNotice('')
    setErrorMessage('')

    if (selectedFiles.length < MIN_FILES || selectedFiles.length > MAX_FILES) {
      setValidationMessage('Selecione de 6 a 12 faturas. O ideal é enviar 12 meses.')
      return
    }
    const invalidFiles = selectedFiles.filter((file) => !file.name.toLowerCase().endsWith('.pdf') || (file.type && file.type !== 'application/pdf'))
    if (invalidFiles.length) {
      setFileValidationErrors(invalidFiles.map((file) => ({ name: file.name, message: 'Selecione apenas arquivos PDF.' })))
      setValidationMessage('Revise os arquivos indicados: apenas PDFs são aceitos.')
      return
    }
    const oversizedFiles = selectedFiles.filter((file) => file.size > MAX_FILE_BYTES)
    if (oversizedFiles.length) {
      setFileValidationErrors(oversizedFiles.map((file) => ({ name: file.name, message: 'Cada fatura pode ter no máximo 20 MB.' })))
      setValidationMessage('Revise os arquivos indicados. Cada fatura pode ter no máximo 20 MB.')
      return
    }
    if (selectedFiles.reduce((total, file) => total + file.size, 0) > MAX_TOTAL_BYTES) {
      setValidationMessage('O conjunto das faturas pode ter no máximo 120 MB.')
      return
    }

    try {
      const signatures = await Promise.all(selectedFiles.map(async (file) => file.slice(0, 1024).text()))
      const invalidSignatureIndices = signatures.flatMap((signature, index) => !/%PDF-\d\.\d/.test(signature) ? [index] : [])
      if (invalidSignatureIndices.length) {
        setFileValidationErrors(invalidSignatureIndices.map((index) => ({ name: selectedFiles[index].name, message: 'O conteúdo não é um PDF válido.' })))
        setValidationMessage('Revise o conteúdo dos arquivos indicados.')
        return
      }
      const estimates = await Promise.all(selectedFiles.map(async (file) => estimatePageCount(await file.text())))
      const knownPages = estimates.filter((count): count is number => count !== null)
      if (knownPages.length === selectedFiles.length) {
        const count = knownPages.reduce((sum, value) => sum + value, 0)
        setPageCount(count)
        if (count > MAX_PAGES) {
          setValidationMessage('O conjunto das faturas pode ter no máximo 600 páginas.')
        }
      } else {
        setPageCount(null)
      }
    } catch {
      setPageCount(null)
    }
  }

  async function requestDistributorInterest() {
    setErrorMessage('')
    setNotice('')
    if (!account?.email || !unsupportedName.trim()) {
      setErrorMessage('Informe o nome da distribuidora para registrar seu interesse.')
      return
    }
    setSubmitting(true)
    try {
      await apiRequest('/leads', {
        method: 'POST',
        body: JSON.stringify({ email: account.email, distributor_name: unsupportedName.trim(), reason: 'unsupported_distributor' }),
      }, { notifyGlobalError: false })
      setNotice('Interesse registrado. Avisaremos quando houver um leitor disponível para essa distribuidora.')
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Não foi possível registrar seu interesse.')
    } finally {
      setSubmitting(false)
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setErrorMessage('')
    setNotice('')
    setValidationMessage('')
    if (files.length < MIN_FILES || files.length > MAX_FILES) {
      setValidationMessage('Envie de 6 a 12 faturas PDF. O ideal são 12 meses.')
      return
    }
    if (!selectedDistributor) {
      setValidationMessage('Selecione uma distribuidora atendida.')
      return
    }
    if (files.some((file) => !file.name.toLowerCase().endsWith('.pdf') || (file.type && file.type !== 'application/pdf'))) {
      setValidationMessage('Cada arquivo precisa estar no formato PDF.')
      return
    }
    if (files.some((file) => file.size > MAX_FILE_BYTES) || totalBytes > MAX_TOTAL_BYTES) {
      setValidationMessage('Cada arquivo pode ter até 20 MB e o conjunto até 120 MB.')
      return
    }
    if (pageCount !== null && pageCount > MAX_PAGES) {
      setValidationMessage('O conjunto das faturas pode ter no máximo 600 páginas.')
      return
    }
    if (!companyName.trim() || !address.trim() || (!sameSubstationAddress && !substationAddress.trim())) {
      setValidationMessage('Preencha o nome da empresa e os endereços solicitados.')
      return
    }

    const formData = new FormData()
    formData.set('company_name', companyName.trim())
    if (cnpj.trim()) formData.set('cnpj', cnpj.trim())
    if (transformerPower.trim()) formData.set('transformer_power_kva', transformerPower.trim())
    formData.set('distributor_id', String(selectedDistributor.id))
    formData.set('address_details', address.trim())
    formData.set('substation_same_address', sameSubstationAddress ? '1' : '0')
    if (!sameSubstationAddress) formData.set('substation_address', substationAddress.trim())
    files.forEach((file) => formData.append('invoices[]', file, file.name))

    setSubmitting(true)
    try {
      const response = await apiRequest<QueuedSimulation>('/simulations', { method: 'POST', body: formData }, { notifyGlobalError: false })
      setQueuedSimulation(response.data)
    } catch (error) {
      if (error instanceof ApiError && error.errors) {
        setValidationMessage(Object.values(error.errors).flat()[0] ?? error.message)
      } else {
        setErrorMessage(error instanceof Error ? error.message : 'Não foi possível enviar as faturas.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <section className="access-page" aria-live="polite"><p>Carregando seus dados…</p></section>
  if (queuedSimulation) return <section className="access-page" aria-labelledby="queued-title"><p className="section-eyebrow">Envio concluído</p><h1 id="queued-title">Sua análise começou</h1><p className="form-success" role="status">As faturas foram enviadas e estão na fila de processamento.</p><p>Protocolo da simulação: {queuedSimulation.id}</p></section>

  return (
    <section className="upload-page" aria-labelledby="upload-title">
      <p className="section-eyebrow">Análise da sua empresa</p>
      <h1 id="upload-title">Envie suas faturas</h1>
      <p className="access-intro">Envie 12 meses de faturas para uma análise mais completa. O mínimo é de 6 arquivos PDF; cada arquivo pode ter até 20 MB, o conjunto até 120 MB e a análise aceita até 600 páginas.</p>
      {errorMessage && <p className="field-error" role="alert">{errorMessage}</p>}
      {notice && <p className="form-success" role="status">{notice}</p>}
      <form className="upload-form" onSubmit={submit}>
        <div className="upload-grid">
          <label>Nome da empresa<input autoComplete="organization" value={companyName} onChange={(event) => setCompanyName(event.target.value)} required /></label>
          <label>CNPJ <span className="optional-label">(opcional)</span><input value={cnpj} onChange={(event) => setCnpj(event.target.value)} /></label>
          <label>Potência do transformador (kVA) <span className="optional-label">(opcional)</span><input inputMode="decimal" value={transformerPower} onChange={(event) => setTransformerPower(event.target.value)} /></label>
          <label>Distribuidora<select value={distributorId} onChange={(event) => { setDistributorId(event.target.value); setNotice(''); setErrorMessage('') }} required><option value="" disabled>Selecione</option>{distributors.map((distributor) => <option key={distributor.id} value={distributor.id}>{distributor.name}</option>)}<option value="other">Outra distribuidora</option></select></label>
        </div>
        {hasUnsupportedDistributor && <div className="unsupported-notice"><p>Esta distribuidora ainda não possui leitor disponível. Registre seu interesse e avisaremos quando a análise puder ser realizada.</p><label>Nome da distribuidora<input value={unsupportedName} onChange={(event) => setUnsupportedName(event.target.value)} required /></label><button className="text-button" type="button" disabled={submitting} onClick={requestDistributorInterest}>{submitting ? 'Enviando…' : 'Registrar meu interesse'}</button></div>}
        {!hasUnsupportedDistributor && <>
          <label className="upload-field">Endereço da unidade consumidora<textarea rows={3} value={address} onChange={(event) => setAddress(event.target.value)} required /></label>
          <label className="consent-control"><input type="checkbox" checked={sameSubstationAddress} onChange={(event) => setSameSubstationAddress(event.target.checked)} /><span>O endereço da subestação é o mesmo da unidade consumidora.</span></label>
          {!sameSubstationAddress && <label className="upload-field">Endereço da subestação<textarea rows={3} value={substationAddress} onChange={(event) => setSubstationAddress(event.target.value)} required /></label>}
          <label className="upload-field invoice-picker">Faturas em PDF<input type="file" accept="application/pdf,.pdf" multiple onChange={changeFiles} aria-describedby="invoice-help" /><span id="invoice-help" className="password-hint">Selecione de 6 a 12 faturas; o ideal são 12. Até 20 MB por arquivo e 120 MB no total.</span></label>
          {files.length > 0 && <div className="selected-files" aria-label="Faturas selecionadas"><p>{files.length} arquivo(s) selecionado(s) · {(totalBytes / (1024 * 1024)).toFixed(1)} MB{pageCount !== null ? ` · ${pageCount} páginas` : ''}</p><ul>{files.map((file) => {
            const fileError = fileValidationErrors.find((error) => error.name === file.name)
            return <li key={`${file.name}-${file.lastModified}`}>{file.name} <span>{(file.size / (1024 * 1024)).toFixed(1)} MB</span>{fileError && <small className="file-validation-error" role="alert">{fileError.message}</small>}</li>
          })}</ul></div>}
          {validationMessage && <p className="field-error" role="alert">{validationMessage}</p>}
          <button className="button access-submit" type="submit" disabled={submitting || !distributors.length}>{submitting ? 'Enviando faturas…' : 'Simular economia'}</button>
        </>}
      </form>
    </section>
  )
}
