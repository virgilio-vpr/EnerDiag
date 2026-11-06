import { useEffect, useState, type ChangeEvent, type FormEvent } from 'react'
import { ApiError, apiRequest, getAuthToken } from '../services/api/client'
import { InvoiceImportTable, type ImportedInvoice } from '../components/InvoiceImportTable'
import { CompanyContactDetails } from '../components/CompanyContactDetails'

const MAX_FILE_BYTES = 20 * 1024 * 1024
const MAX_FILES = 12
const IMPORT_SESSION_KEY = 'enerdiag_invoice_import_id'

function readPdfSignature(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(String(reader.result ?? ''))
    reader.onerror = () => reject(new Error('Não foi possível abrir este arquivo. Selecione o PDF novamente.'))
    reader.readAsText(file.slice(0, 1024))
  })
}

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
type ImportSession = { reference_month?: string; id: number; status: string; invoice_count: number; invoices: ImportedInvoice[] }
type SimulationStatus = 'queued' | 'processing' | 'completed' | 'failed'
type ProcessingFileError = { file: string; code: string; errors: Record<string, string | string[]> }
type SimulationStatusResponse = { data: { id: number; status: SimulationStatus; processing_errors: ProcessingFileError[] | null } }
const STATUS_POLL_INTERVAL_MS = 2000

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
  const [importSession, setImportSession] = useState<ImportSession | null>(null)
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [notice, setNotice] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const [queuedSimulation, setQueuedSimulation] = useState<{ id: number; status: SimulationStatus } | null>(null)
  const [processingErrors, setProcessingErrors] = useState<ProcessingFileError[]>([])

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    if (params.get('new') === '1') window.localStorage.removeItem(IMPORT_SESSION_KEY)
    const importFromUrl = params.get('import')
    const requestedImport = importFromUrl && /^[1-9]\d*$/.test(importFromUrl) ? importFromUrl : null
    if (requestedImport) window.localStorage.setItem(IMPORT_SESSION_KEY, requestedImport)
    if (!getAuthToken()) {
      window.history.replaceState({}, '', '/login')
      window.dispatchEvent(new PopStateEvent('popstate'))
      return
    }
    let mounted = true
    Promise.all([
      apiRequest<{ data: Account }>('/me', {}, { notifyGlobalError: false }),
      apiRequest<{ data: Distributor[] }>('/distributors', {}, { notifyGlobalError: false, cacheTtlMs: 300_000 }),
    ]).then(async ([userResponse, distributorResponse]) => {
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
      const savedId = requestedImport ?? window.localStorage.getItem(IMPORT_SESSION_KEY)
      if (savedId) {
        try {
          const response = await apiRequest<{ data: ImportSession & { status: string } }>(`/invoice-imports/${savedId}`, {}, { notifyGlobalError: false })
          if (mounted && response.data.status === 'draft') {
            setImportSession(response.data)
            window.localStorage.setItem(IMPORT_SESSION_KEY, String(response.data.id))
          } else if (mounted && ['queued', 'processing', 'completed', 'failed'].includes(response.data.status)) {
            setQueuedSimulation({ id: response.data.id, status: response.data.status as SimulationStatus })
          } else {
            window.localStorage.removeItem(IMPORT_SESSION_KEY)
          }
        } catch {
          window.localStorage.removeItem(IMPORT_SESSION_KEY)
        }
      }
    }).catch((error: unknown) => {
      if (mounted) setErrorMessage(error instanceof Error ? error.message : 'Não foi possível carregar os dados da conta.')
    }).finally(() => {
      if (mounted) setLoading(false)
    })
    return () => { mounted = false }
  }, [])

  const simulationId = queuedSimulation?.id
  const shouldPoll = !submitting && (queuedSimulation?.status === 'queued' || queuedSimulation?.status === 'processing')
  useEffect(() => {
    if (!simulationId || !shouldPoll) return
    let active = true
    let timer: number | undefined

    async function pollStatus() {
      try {
        const response = await apiRequest<SimulationStatusResponse>(`/simulations/${simulationId}/status`, {}, { notifyGlobalError: false })
        if (!active) return
        setQueuedSimulation({ id: response.data.id, status: response.data.status })
        setProcessingErrors(response.data.processing_errors ?? [])
        if (response.data.status === 'queued' || response.data.status === 'processing') {
          timer = window.setTimeout(pollStatus, STATUS_POLL_INTERVAL_MS)
        } else {
          window.localStorage.removeItem(IMPORT_SESSION_KEY)
        }
      } catch (error) {
        if (!active) return
        setErrorMessage(error instanceof Error ? error.message : 'Não foi possível consultar o andamento da análise.')
        timer = window.setTimeout(pollStatus, STATUS_POLL_INTERVAL_MS)
      }
    }

    void pollStatus()
    return () => {
      active = false
      if (timer !== undefined) window.clearTimeout(timer)
    }
  }, [simulationId, shouldPoll])

  const selectedDistributor = distributors.find((distributor) => String(distributor.id) === distributorId)
  const hasUnsupportedDistributor = distributorId === 'other'
  const needsInvoiceReview = importSession?.invoices.some((invoice) => invoice.warnings.includes('invoice_billing_total_mismatch')) ?? false

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

  async function startImport(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitting || importSession) return
    setErrorMessage('')
    setNotice('')
    if (!selectedDistributor) {
      setErrorMessage('Selecione uma distribuidora atendida.')
      return
    }
    if (!companyName.trim() || !address.trim() || (!sameSubstationAddress && !substationAddress.trim())) {
      setErrorMessage('Preencha o nome da empresa e os endereços solicitados.')
      return
    }

    setSubmitting(true)
    try {
      const response = await apiRequest<{ data: { id: number; status: string } }>('/invoice-imports', {
        method: 'POST',
        body: JSON.stringify({
          company_name: companyName.trim(),
          ...(cnpj.trim() ? { cnpj: cnpj.trim() } : {}),
          ...(transformerPower.trim() ? { transformer_power_kva: transformerPower.trim() } : {}),
          distributor_id: selectedDistributor.id,
          address_details: address.trim(),
          substation_same_address: sameSubstationAddress,
          ...(!sameSubstationAddress ? { substation_address: substationAddress.trim() } : {}),
        }),
      }, { notifyGlobalError: false })
      window.localStorage.setItem(IMPORT_SESSION_KEY, String(response.data.id))
      setImportSession({ id: response.data.id, status: 'draft', invoice_count: 0, invoices: [] })
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Não foi possível iniciar o envio das faturas.')
    } finally {
      setSubmitting(false)
    }
  }

  async function uploadInvoice(event: ChangeEvent<HTMLInputElement>) {
    const selectedFiles = Array.from(event.target.files ?? [])
    event.target.value = ''
    if (selectedFiles.length === 0 || !importSession || submitting) return

    const remainingSlots = MAX_FILES - importSession.invoice_count
    const filesToUpload = selectedFiles.slice(0, remainingSlots)
    const failures = selectedFiles.length > remainingSlots
      ? [`O limite é de ${MAX_FILES} faturas; somente as primeiras ${remainingSlots} serão enviadas.`]
      : []

    setSubmitting(true)
    setErrorMessage('')
    setNotice('Preparando o envio das faturas…')
    let activeSession = importSession
    let savedCount = 0
    try {
      for (const [index, file] of filesToUpload.entries()) {
        setNotice(`Lendo e salvando a fatura ${index + 1} de ${filesToUpload.length}…`)

        if (!file.name.toLowerCase().endsWith('.pdf') || (file.type && file.type !== 'application/pdf')) {
          failures.push(`${file.name}: selecione um arquivo PDF.`)
          continue
        }
        if (file.size > MAX_FILE_BYTES) {
          failures.push(`${file.name}: cada fatura pode ter no máximo 20 MB.`)
          continue
        }
        try {
          const signature = await readPdfSignature(file)
          if (!/%PDF-\d\.\d/.test(signature)) {
            failures.push(`${file.name}: o conteúdo não é um PDF válido.`)
            continue
          }
          const formData = new FormData()
          formData.set('invoice', file, file.name)
          const response = await apiRequest<{ data: ImportSession }>(`/invoice-imports/${activeSession.id}/invoices`, {
            method: 'POST',
            body: formData,
          }, { notifyGlobalError: false })
          activeSession = { ...activeSession, ...response.data }
          setImportSession(activeSession)
          savedCount++
        } catch (error) {
          const validationMessage = error instanceof ApiError && error.errors ? Object.values(error.errors).flat()[0] : undefined
          failures.push(`${file.name}: ${validationMessage ?? (error instanceof Error ? error.message : 'não foi possível ler a fatura.')}`)
        }
      }

      setNotice(savedCount > 0
        ? `${savedCount} ${savedCount === 1 ? 'fatura foi lida e salva' : 'faturas foram lidas e salvas'}; a lista está em ordem do mês de medição.`
        : 'Nenhuma fatura desta seleção foi salva.')
      setErrorMessage(failures.join(' '))
    } finally {
      setSubmitting(false)
    }
  }

  async function removeInvoice(invoiceId: number) {
    if (!importSession || submitting) return
    setSubmitting(true)
    setErrorMessage('')
    try {
      const response = await apiRequest<{ data: ImportSession }>(`/invoice-imports/${importSession.id}/invoices/${invoiceId}`, { method: 'DELETE' }, { notifyGlobalError: false })
      setImportSession(response.data)
      setNotice('Fatura removida. Você pode enviar outra cópia antes de simular.')
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Não foi possível remover a fatura.')
    } finally {
      setSubmitting(false)
    }
  }

  async function completeImport() {
    if (!importSession || submitting || importSession.invoice_count < 1) return
    await calculateSimulation(importSession.id, true)
  }

  async function calculateSimulation(id: number, fromDraft = false) {
    if (submitting) return
    setErrorMessage('')
    setSubmitting(true)
    setQueuedSimulation({ id, status: 'processing' })
    try {
      const response = await apiRequest<{ data: { id: number; status: SimulationStatus } }>(fromDraft ? `/invoice-imports/${id}/complete` : `/simulations/${id}/process`, {
        method: 'POST',
      }, { notifyGlobalError: false })
      setQueuedSimulation(response.data)
      if (response.data.status === 'completed') window.localStorage.removeItem(IMPORT_SESSION_KEY)
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Não foi possível iniciar a simulação.')
      setQueuedSimulation(fromDraft ? null : { id, status: 'failed' })
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <section className="access-page" aria-live="polite"><p>Carregando seus dados…</p></section>
  if (queuedSimulation) {
    const statusMessage: Record<SimulationStatus, string> = {
      queued: 'As faturas estão salvas. Clique em Calcular agora para concluir esta análise.',
      processing: 'Estamos calculando o relatório com as faturas salvas.',
      completed: 'A análise das suas faturas está concluída.',
      failed: 'Não foi possível concluir a análise.',
    }
    return <section className="access-page" aria-labelledby="queued-title">
      <p className="section-eyebrow">Acompanhamento da análise</p>
      <h1 id="queued-title">{queuedSimulation.status === 'completed' ? 'Sua análise está pronta' : queuedSimulation.status === 'failed' ? 'A análise precisa de atenção' : queuedSimulation.status === 'queued' ? 'Aguardando cálculo' : 'Calculando seu relatório'}</h1>
      <CompanyContactDetails />
      <p className={queuedSimulation.status === 'failed' ? 'field-error' : 'form-success'} role={queuedSimulation.status === 'failed' ? 'alert' : 'status'}>{statusMessage[queuedSimulation.status]}</p>
      {errorMessage && <p className="field-error" role="alert">{errorMessage}</p>}
      {queuedSimulation.status === 'completed' && <a className="button" href={`/simulations/${queuedSimulation.id}/report`}>Ver relatório completo</a>}
      {(queuedSimulation.status === 'queued' || queuedSimulation.status === 'failed') && <button className="button" type="button" disabled={submitting} onClick={() => calculateSimulation(queuedSimulation.id)}>{queuedSimulation.status === 'failed' ? 'Tentar novamente' : 'Calcular agora'}</button>}
      {queuedSimulation.status === 'processing' && !submitting && <button className="button button--secondary" type="button" onClick={() => calculateSimulation(queuedSimulation.id)}>Retomar cálculo</button>}
      <p><a className="text-button" href="/minhas-simulacoes">Voltar para minhas simulações</a></p>
      {processingErrors.length > 0 && <div className="processing-errors"><h2>Faturas que precisam de atenção</h2><ul>{processingErrors.map((fileError, index) => {
        const details = Object.values(fileError.errors).flatMap((value) => Array.isArray(value) ? value : [value]).filter(Boolean)
        return <li key={fileError.file + index}><strong>{fileError.file}</strong><span>{details.join(' ') || 'Não foi possível concluir o processamento.'}</span></li>
      })}</ul></div>}
    </section>
  }

  return (
    <section className="upload-page" aria-labelledby="upload-title">
      <p className="section-eyebrow">Análise da sua empresa</p>
      <h1 id="upload-title">Envie suas faturas</h1>
      <CompanyContactDetails />
      <p><a className="text-button" href="/minhas-simulacoes">Voltar para minhas simulações</a></p>
      <p className="access-intro">Envie de 1 a {MAX_FILES} faturas. Você pode selecionar várias de uma vez; cada conta será lida e salva individualmente, em sequência. Organizaremos da mais antiga para a mais recente usando o mês da LEITURA ATUAL, não o mês de emissão. Avisaremos se uma conta ou mês de medição já tiver sido enviado. Você pode simular quando quiser, depois de salvar pelo menos uma fatura.</p>
      {errorMessage && <p className="field-error" role="alert">{errorMessage}</p>}
      {notice && <p className="form-success" role="status">{notice}</p>}
      <form className="upload-form" onSubmit={startImport}>
        <div className="upload-grid">
          <label>Nome da empresa<input autoComplete="organization" value={companyName} onChange={(event) => setCompanyName(event.target.value)} required disabled={!!importSession} /></label>
          <label>CNPJ <span className="optional-label">(opcional)</span><input value={cnpj} onChange={(event) => setCnpj(event.target.value)} disabled={!!importSession} /></label>
          <label>Potência do transformador (kVA) <span className="optional-label">(opcional)</span><input inputMode="decimal" value={transformerPower} onChange={(event) => setTransformerPower(event.target.value)} disabled={!!importSession} /></label>
          <label>Distribuidora<select value={distributorId} onChange={(event) => setDistributorId(event.target.value)} required disabled={!!importSession}><option value="" disabled>Selecione</option>{distributors.map((distributor) => <option key={distributor.id} value={distributor.id}>{distributor.name}</option>)}<option value="other">Outra distribuidora</option></select></label>
        </div>
        {hasUnsupportedDistributor && !importSession && <div className="unsupported-notice"><p>Esta distribuidora ainda não possui leitor disponível. Registre seu interesse e avisaremos quando a análise puder ser realizada.</p><label>Nome da distribuidora<input value={unsupportedName} onChange={(event) => setUnsupportedName(event.target.value)} required /></label><button className="text-button" type="button" disabled={submitting} onClick={requestDistributorInterest}>{submitting ? 'Enviando…' : 'Registrar meu interesse'}</button></div>}
        {!hasUnsupportedDistributor && <>
          <label className="upload-field">Endereço da unidade consumidora<textarea rows={3} value={address} onChange={(event) => setAddress(event.target.value)} required disabled={!!importSession} /></label>
          <label className="consent-control"><input type="checkbox" checked={sameSubstationAddress} onChange={(event) => setSameSubstationAddress(event.target.checked)} disabled={!!importSession} /><span>O endereço da subestação é o mesmo da unidade consumidora.</span></label>
          {!sameSubstationAddress && <label className="upload-field">Endereço da subestação<textarea rows={3} value={substationAddress} onChange={(event) => setSubstationAddress(event.target.value)} required disabled={!!importSession} /></label>}
          {!importSession && <>
            <p className="password-hint">Envie no máximo {MAX_FILES} faturas. Você pode iniciar a simulação depois de salvar a primeira.</p>
            <button className="button access-submit" type="submit" disabled={submitting || !distributors.length}>{submitting ? 'Iniciando…' : 'Começar envio'}</button>
          </>}
          {importSession && <>
            <p aria-live="polite">{importSession.invoice_count} de {MAX_FILES} faturas lidas e salvas</p>
            <InvoiceImportTable invoices={importSession.invoices} onRemove={removeInvoice} disabled={submitting} />
            {importSession.invoice_count < MAX_FILES && <label className="upload-field invoice-picker">Selecionar faturas <input type="file" accept="application/pdf,.pdf" multiple disabled={submitting} onChange={uploadInvoice} aria-describedby="invoice-help" /><span id="invoice-help" className="password-hint">Você pode selecionar várias faturas. Cada PDF será enviado, lido e salvo individualmente, em sequência. Até 20 MB por arquivo.</span></label>}
            {needsInvoiceReview && <p className="field-error" role="alert">Uma fatura tem diferença entre a soma dos itens e o total. A leitura precisa ser revisada antes da simulação.</p>}
            <button className="button access-submit" type="button" disabled={submitting || importSession.invoice_count < 1 || needsInvoiceReview} onClick={completeImport}>{submitting ? 'Aguarde…' : importSession.invoice_count < 1 ? 'Envie ao menos uma fatura' : 'Simular relatório'}</button>
          </>}
        </>}
      </form>
    </section>
  )
}
