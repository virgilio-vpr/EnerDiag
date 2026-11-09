import { useState, type InputHTMLAttributes } from 'react'

export function PasswordInput(props: Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>) {
  const [visible, setVisible] = useState(false)
  return <span className="password-input-wrap">
    <input {...props} type={visible ? 'text' : 'password'} />
    <button className="password-visibility-toggle" type="button" aria-label={visible ? 'Ocultar senha' : 'Mostrar senha'} aria-pressed={visible} onClick={() => setVisible((current) => !current)}>
      <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">{visible ? <><path d="M3 3l18 18" /><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" /><path d="M9.9 5.2A11 11 0 0 1 12 5c5 0 9 5 9 7a9.8 9.8 0 0 1-2.1 3.1M6.2 6.2C3.6 7.8 2 10.4 2 12c0 2 4 7 10 7 1 0 2-.2 2.9-.5" /></> : <><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /></>}</svg>
    </button>
  </span>
}
