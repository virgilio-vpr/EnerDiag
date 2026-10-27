import { useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent } from 'react'
import { ApiError, apiRequest, getAuthToken } from '../services/api/client'

const MAX_FILE_BYTES = 20 * 1024 * 1024
const MAX_TOTAL_BYTES = 120 * 1024 * 1024
const MAX_PAGES = 600
// Temporary minimum for manual testing; restore to six after testing.
const MIN_FILES = 2
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
type SimulationStatus = 'queued' | 'processing' | 'completed' | 'failed'
type ProcessingFileError = { file: string; code: string; errors: Record<string, string | string[]> }
type QueuedSimulation = { data: { id: number; status: SimulationStatus } }
type ProcessingProgress = { stage: string; files: Array<{ file: string; status: 'pending' | 'reading' | 'read' | 'failed' }> }
type SimulationStatusResponse = { data: { id: number; status: SimulationStatus; processing_errors: ProcessingFileError[] | null; processing_progress?: ProcessingProgress | null } }
type UploadState = { status: 'pending' | 'sending' | 'sent' | 'failed'; message?: string }
const fileKey = (file: File) => `${file.name}:${file.size}:${file.lastModified}`
function errorDetails(error: unknown) {
  return error instanceof ApiError ? Object.values(error.errors ?? {}).flat()[0] ?? error.message : error instanceof Error ? error.message : 'Não foi possível enviar esta fatura.'
}
const STATUS_POLL_INTERVAL_MS = 2000

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
  const [fileValidationErrors, setFileValidationErrors] = useState<Array<{ key: string; name: string; message: string }>>([])
  const [validationMessage, setValidationMessage] = useState('')
  const [pageCount, setPageCount] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [notice, setNotice] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const [queuedSimulation, setQueuedSimulation] = useState<{ id: number; status: SimulationStatus } | null>(null)
  const [processingErrors, setProcessingErrors] = useState<ProcessingFileError[]>([])
  const [processingProgress, setProcessingProgress] = useState<ProcessingProgress | null>(null)
  const [uploadStates, setUploadStates] = useState<Record<string, UploadState>>({})
  const [checkingFiles, setCheckingFiles] = useState(false)
  const batchId = useRef<string | null>(null)
  const clientIds = useRef<Record<string, string>>({})
  const readFailures = useRef<Record<string, string>>({})
  const fileInput = useRef<HTMLInputElement>(null)
  const activeRequest = useRef<AbortController | null>(null)
  useEffect(() => () => { activeRequest.current?.abort() }, [])

  function discardBatch() {
    const id = batchId.current
    batchId.current = null
    clientIds.current = {}
    setUploadStates({})
    setErrorMessage('')
    if (id) void apiRequest(`/invoice-upload-batches/${id}`, { method: 'DELETE' }, { notifyGlobalError: false }).catch(() => {})
  }

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

  const simulationId = queuedSimulation?.id
  useEffect(() => {
    if (!simulationId) return
    let active = true
    let timer: number | undefined

    async function pollStatus() {
      try {
        const response = await apiRequest<SimulationStatusResponse>(`/simulations/${simulationId}/status`, {}, { notifyGlobalError: false })
        if (!active) return
        setQueuedSimulation({ id: response.data.id, status: response.data.status })
        setProcessingErrors(response.data.processing_errors ?? [])
        setProcessingProgress(response.data.processing_progress ?? null)
        if (response.data.status === 'queued' || response.data.status === 'processing') {
          timer = window.setTimeout(pollStatus, STATUS_POLL_INTERVAL_MS)
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
  }, [simulationId])

  const totalBytes = useMemo(() => files.reduce((total, file) => total + file.size, 0), [files])
  const selectedDistributor = distributors.find((distributor) => String(distributor.id) === distributorId)
  const hasUnsupportedDistributor = distributorId === 'other'

  function changeFiles(event: ChangeEvent<HTMLInputElement>) {
    const added = Array.from(event.target.files ?? [])
    event.target.value = ''
    discardBatch()
    setFiles((current) => {
      const keys = new Set(current.map(fileKey))
      return [...current, ...added.filter((file) => {
        if (keys.has(fileKey(file))) return false
        keys.add(fileKey(file))
        return true
      })]
    })
    setNotice('')
    setErrorMessage('')
  }

  function removeFile(key: string) {
    delete readFailures.current[key]
    discardBatch()
    setFiles((current) => current.filter((file) => fileKey(file) !== key))
  }

  useEffect(() => {
    let cancelled = false
    setCheckingFiles(true)
    setPageCount(null)
    setFileValidationErrors([])
    setValidationMessage('')
    async function validateFiles() {
      const errors: Array<{ key: string; name: string; message: string }> = []
      let pages = 0
      let allPagesKnown = true
      for (const file of files) {
        let message = readFailures.current[fileKey(file)] ?? ''
        if (!message && (!file.name.toLowerCase().endsWith('.pdf') || (file.type && file.type !== 'application/pdf'))) {
          message = 'Selecione apenas arquivos PDF.'
        } else if (!message && file.size > MAX_FILE_BYTES) {
          message = 'Cada fatura pode ter no máximo 20 MB.'
        } else if (!message) {
          try {
            if (!/%PDF-\d\.\d/.test(await file.slice(0, 1024).text())) {
              message = 'O conteúdo não é um PDF válido.'
            } else {
              const count = estimatePageCount(await file.text())
              if (count === null) allPagesKnown = false
              else pages += count
            }
          } catch { message = 'Não foi possível abrir este arquivo. Selecione outra cópia.' }
        }
        if (message) errors.push({ key: fileKey(file), name: file.name, message })
      }
      if (cancelled) return
      setFileValidationErrors(errors)
      setPageCount(allPagesKnown && !errors.length && files.length ? pages : null)
      setValidationMessage(
        files.length > MAX_FILES ? `Selecione de ${MIN_FILES} a 12 faturas. Remova os arquivos excedentes.` :
        totalBytes > MAX_TOTAL_BYTES ? 'O conjunto das faturas pode ter no máximo 120 MB.' :
        pages > MAX_PAGES ? 'O conjunto das faturas pode ter no máximo 600 páginas.' :
        errors.length ? 'Revise os arquivos indicados antes de enviar.' : '',
      )
      setCheckingFiles(false)
    }
    void validateFiles()
    return () => { cancelled = true }
  }, [files, totalBytes])

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
      setValidationMessage(`Envie de ${MIN_FILES} a 12 faturas PDF. O ideal são 12 meses.`)
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

    if (checkingFiles || fileValidationErrors.length || submitting) return
    const controller = new AbortController()
    activeRequest.current = controller
    const options = { notifyGlobalError: false }
    setSubmitting(true)
    try {
      if (!batchId.current) {
        const created = await apiRequest<{ data: { id: string } }>('/invoice-upload-batches', {
          method: 'POST', signal: controller.signal,
          body: JSON.stringify({
            company_name: companyName.trim(), cnpj: cnpj.trim() || null,
            transformer_power_kva: transformerPower.trim() || null, distributor_id: selectedDistributor.id,
            address_details: address.trim(), substation_same_address: sameSubstationAddress,
            substation_address: sameSubstationAddress ? null : substationAddress.trim(),
          }),
        }, options)
        batchId.current = created.data.id
      }
      let failed = false
      for (const file of files) {
        const key = fileKey(file)
        if (uploadStates[key]?.status === 'sent') continue
        clientIds.current[key] ??= crypto.randomUUID()
        const formData = new FormData()
        formData.set('client_id', clientIds.current[key])
        formData.set('invoice', file, file.name)
        setUploadStates((current) => ({ ...current, [key]: { status: 'sending' } }))
        try {
          await apiRequest(`/invoice-upload-batches/${batchId.current}/files`, { method: 'POST', body: formData, signal: controller.signal }, options)
          setUploadStates((current) => ({ ...current, [key]: { status: 'sent' } }))
        } catch (error) {
          if (controller.signal.aborted) return
          if (error instanceof ApiError && [404, 410].includes(error.status)) {
            discardBatch()
            setErrorMessage('O envio expirou. Clique em Simular economia para reenviar as faturas.')
            return
          }
          failed = true
          setUploadStates((current) => ({ ...current, [key]: { status: 'failed', message: errorDetails(error) } }))
        }
      }
      if (failed) {
        setErrorMessage('Algumas faturas não foram enviadas. Revise os erros e tente novamente; as já recebidas não serão reenviadas.')
        return
      }
      const response = await apiRequest<QueuedSimulation>(`/invoice-upload-batches/${batchId.current}/complete`, { method: 'POST', signal: controller.signal }, options)
      setQueuedSimulation(response.data)
    } catch (error) {
      if (!controller.signal.aborted) setErrorMessage(errorDetails(error))
    } finally {
      if (!controller.signal.aborted) setSubmitting(false)
    }
  }

  const sentCount = files.filter((file) => uploadStates[fileKey(file)]?.status === 'sent').length
  const progressFiles = processingProgress?.files ?? files.map((file) => ({ file: file.name, status: 'pending' as const }))
  const readCount = progressFiles.filter((file) => file.status === 'read').length

  if (loading) return <section className="access-page" aria-live="polite"><p>Carregando seus dados…</p></section>
  if (queuedSimulation) {
    const statusMessage: Record<SimulationStatus, string> = {
      queued: 'Suas faturas foram enviadas e estão na fila de processamento.',
      processing: 'Estamos lendo suas faturas e preparando a análise.',
      completed: 'A análise das suas faturas está concluída.',
      failed: 'Não foi possível concluir a análise de todas as faturas.',
    }
    return <section className="access-page invoice-progress-page" aria-labelledby="queued-title">
      <p className="section-eyebrow">{queuedSimulation.status === 'failed' ? 'Envio com falhas' : 'Acompanhamento da análise'}</p>
      <h1 id="queued-title">{queuedSimulation.status === 'completed' ? 'Sua análise está pronta' : queuedSimulation.status === 'failed' ? 'A análise precisa de atenção' : 'Sua análise começou'}</h1>
      <p className={queuedSimulation.status === 'failed' ? 'field-error' : 'form-success'} role={queuedSimulation.status === 'failed' ? 'alert' : 'status'}>{statusMessage[queuedSimulation.status]}</p>
      <p>Protocolo da simulação: {queuedSimulation.id}</p>
      <div className="selected-files" aria-label="Andamento por fatura">
        <p aria-live="polite">{progressFiles.length} de {files.length} faturas enviadas · {readCount} de {progressFiles.length} lidas</p>
        {processingProgress?.stage === 'retrying' && <p>Uma ou mais leituras falharam. Uma nova tentativa será feita automaticamente.</p>}
        <ol className="invoice-file-list">{progressFiles.map((file, index) => <li key={index} className={`invoice-file invoice-file--${file.status}`}>
          <strong>{index + 1}. {file.file}</strong><span>{({ pending: 'Aguardando leitura', reading: 'Lendo fatura…', read: 'Leitura concluída', failed: 'Erro nesta fatura' })[file.status]}</span>
        </li>)}</ol>
      </div>
      {queuedSimulation.status === 'completed' && <a className="button" href={`/simulations/${queuedSimulation.id}/report`}>Ver relatório completo</a>}
      {queuedSimulation.status === 'queued' || queuedSimulation.status === 'processing' ? <p className="password-hint" aria-live="polite">{queuedSimulation.status === 'queued' ? 'Aguardando início do processamento…' : 'Esta página será atualizada automaticamente.'}</p> : null}
      {processingErrors.length > 0 && <div className="processing-errors"><h2>Faturas que precisam ser corrigidas</h2><ul>{processingErrors.map((fileError, index) => {
        const details = Object.values(fileError.errors).flatMap((value) => Array.isArray(value) ? value : [value]).filter(Boolean)
        return <li key={fileError.file + index}><strong>{fileError.file}</strong><span>{details.join(' ') || 'Não foi possível ler esta fatura.'}</span></li>
      })}</ul><button className="button" type="button" onClick={() => {
        discardBatch()
        setQueuedSimulation(null)
        setProcessingProgress(null)
        setErrorMessage('')
        setFileValidationErrors(files.flatMap((file) => {
          const error = processingErrors.find((entry) => entry.file === file.name)
          if (!error) return []
          const message = Object.values(error.errors).flat().join(' ')
          readFailures.current[fileKey(file)] = message
          return [{ key: fileKey(file), name: file.name, message }]
        }))
      }}>Corrigir anexos</button></div>}
    </section>
  }

  return (
    <section className="upload-page" aria-labelledby="upload-title">
      <p className="section-eyebrow">Análise da sua empresa</p>
      <h1 id="upload-title">Envie suas faturas</h1>
      <p className="access-intro">Envie 12 meses de faturas para uma análise mais completa. O mínimo temporário para testes é de {MIN_FILES} arquivos PDF; cada arquivo pode ter até 20 MB, o conjunto até 120 MB e a análise aceita até 600 páginas.</p>
      {errorMessage && <p className="field-error" role="alert">{errorMessage}</p>}
      {notice && <p className="form-success" role="status">{notice}</p>}
      <form className="upload-form" onSubmit={submit} onChange={(event) => {
        if (!(event.target instanceof HTMLInputElement && event.target.type === 'file') && batchId.current) discardBatch()
      }}>
        <fieldset className="upload-fields" disabled={submitting}>
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
          <div className="upload-field invoice-picker">
            <label htmlFor="invoice-input">Faturas em PDF</label>
            <input id="invoice-input" ref={fileInput} className="invoice-file-input" type="file" accept="application/pdf,.pdf" multiple onChange={changeFiles} aria-describedby="invoice-help" />
            <button className="button button--secondary" type="button" onClick={() => fileInput.current?.click()}>Adicionar faturas</button>
            <span id="invoice-help" className="password-hint">Adicione os PDFs um por vez ou selecione vários. De {MIN_FILES} a 12 faturas; até 20 MB por arquivo e 120 MB no total.</span>
          </div>
          {files.length > 0 && <div className="selected-files" aria-label="Faturas selecionadas">
            <p aria-live="polite">{files.length} fatura(s) anexada(s) de 12 · {(totalBytes / (1024 * 1024)).toFixed(1)} MB{pageCount !== null ? ` · ${pageCount} páginas` : ''}</p>
            {files.length < MIN_FILES && <p>Adicione mais {MIN_FILES - files.length} fatura(s) para iniciar.</p>}
            {(submitting || sentCount > 0 || Object.keys(uploadStates).length > 0) && <p role="status">{sentCount} de {files.length} faturas enviadas</p>}
            <ol className="invoice-file-list">{files.map((file, index) => {
              const key = fileKey(file)
              const fileError = fileValidationErrors.find((error) => error.key === key)
              const state = uploadStates[key]
              return <li className="invoice-file" key={key}>
                <div><strong>{index + 1}. {file.name}</strong><span>{(file.size / (1024 * 1024)).toFixed(1)} MB · {state ? ({ pending: 'Aguardando envio', sending: 'Enviando…', sent: 'Recebida pelo servidor', failed: 'Falha no envio' })[state.status] : 'Aguardando envio'}</span></div>
                <button type="button" className="text-button" aria-label={`Remover ${file.name}`} onClick={() => removeFile(key)}>Remover</button>
                {(fileError || state?.message) && <small className="file-validation-error" role="alert">{fileError?.message ?? state?.message}</small>}
              </li>
            })}</ol>
          </div>}
          {validationMessage && <p className="field-error" role="alert">{validationMessage}</p>}
          <button className="button access-submit" type="submit" disabled={submitting || checkingFiles || !distributors.length || files.length < MIN_FILES || files.length > MAX_FILES || fileValidationErrors.length > 0 || !!validationMessage}>{submitting ? `Enviando faturas… ${sentCount}/${files.length}` : Object.keys(uploadStates).length ? 'Tentar envio novamente' : 'Simular economia'}</button>
        </>}
        </fieldset>
      </form>
    </section>
  )
}
