import contact from '../../../backend/resources/company-contact.json'

export function CompanyContactDetails() {
  if (!contact.email && !contact.phone && !contact.whatsapp) return null

  return <address id="enerdiag-contact" className="company-contact company-contact--prominent" aria-label="Contato da EnerDiag" tabIndex={-1}>
    <div className="company-contact-heading"><strong className="company-contact-title">EnerDiag</strong><span>Fale com nossa equipe</span></div>
    <div className="company-contact-details">
      {contact.email && <a className="company-contact-item" href={`mailto:${contact.email}`}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" /></svg><span><span className="company-contact-label">E-mail</span><strong>{contact.email}</strong></span></a>}
      {contact.phone && <a className="company-contact-item" href={`tel:+${contact.phone.replace(/\D/g, '')}`}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M7.2 3.5h2.6l1.3 4.2-1.8 1.7a15.1 15.1 0 0 0 5.3 5.3l1.7-1.8 4.2 1.3v2.6a2 2 0 0 1-2.1 2A15.9 15.9 0 0 1 5.2 5.6a2 2 0 0 1 2-2.1Z" /></svg><span><span className="company-contact-label">Telefone</span><strong>{contact.phone}</strong></span></a>}
      {contact.whatsapp && <a className="company-contact-item" href={`https://wa.me/${contact.whatsapp.replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M20.2 11.5a8.2 8.2 0 0 1-12.1 7.2L4 20l1.3-4A8.2 8.2 0 1 1 20.2 11.5Z" /><path d="M9 8.5c.4 2.7 2.1 4.4 4.8 4.8l1-1.1 2 .6v1.3a1 1 0 0 1-1.1 1 9 9 0 0 1-7.5-7.5 1 1 0 0 1 1-1.1h1.3l.6 2L9 8.5Z" /></svg><span><span className="company-contact-label">WhatsApp</span><strong>{contact.whatsapp}</strong></span></a>}
    </div>
  </address>
}
