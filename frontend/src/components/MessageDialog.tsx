import { useEffect, useId, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'

type MessageDialogProps = {
  title: string
  children: ReactNode
  onClose: () => void
}

export function MessageDialog({ title, children, onClose }: MessageDialogProps) {
  const dialogRef = useRef<HTMLDialogElement>(null)
  const titleId = useId()
  const descriptionId = useId()

  useEffect(() => {
    const dialog = dialogRef.current
    const previousFocus = document.activeElement
    if (dialog?.showModal) dialog.showModal()
    else dialog?.setAttribute('open', '')
    return () => {
      if (dialog?.open && dialog.close) dialog.close()
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus()
    }
  }, [])

  return createPortal(<dialog ref={dialogRef} className="message-dialog" aria-labelledby={titleId} aria-describedby={descriptionId} onCancel={(event) => { event.preventDefault(); onClose() }}>
    <p className="section-eyebrow">Aviso</p>
    <h2 id={titleId}>{title}</h2>
    <div id={descriptionId} className="message-dialog-description">{children}</div>
    <div className="message-dialog-actions"><button className="button" type="button" autoFocus onClick={onClose}>Entendi</button></div>
  </dialog>, document.body)
}
