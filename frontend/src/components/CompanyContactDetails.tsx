import contact from '../../../backend/resources/company-contact.json'

export function CompanyContactDetails() {
  if (!contact.email && !contact.phone && !contact.whatsapp) return null

  return <address className="company-contact" aria-label="Contato da EnerDiag">
    <div className="company-contact-details">
      <strong>EnerDiag</strong>
      {contact.email && <span>E-mail: <a href={`mailto:${contact.email}`}>{contact.email}</a></span>}
      {contact.phone && <span>Telefone: <a href={`tel:+${contact.phone.replace(/\D/g, '')}`}>{contact.phone}</a></span>}
      {contact.whatsapp && <span>WhatsApp: <a href={`https://wa.me/${contact.whatsapp.replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer">{contact.whatsapp}</a></span>}
    </div>
  </address>
}
