import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AdminCustomerDetailPage, AdminCustomersPage, AdminSimulationDetailPage, AdminSimulationsPage } from './AdminDataPages'
import { setAuthToken } from '../services/api/client'

function jsonResponse(payload: unknown) {
  return new Response(JSON.stringify(payload), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

function paged<T>(data: T[], currentPage = 1, lastPage = 1, total = data.length) {
  return { data, links: {}, meta: { current_page: currentPage, last_page: lastPage, per_page: 15, total } }
}

afterEach(() => {
  vi.unstubAllGlobals()
  setAuthToken(null)
  window.history.replaceState({}, '', '/')
})

describe('admin customer and simulation pages', () => {
  it('lists customers with contact requests and paginates through the API', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse(paged([{
        id: 4, name: 'Ana Souza', email: 'ana@example.com', phone: '11999990000', email_verified_at: null,
        contact_requested_at: '2026-10-24T12:00:00Z', profile: { company_name: 'Empresa Solar', cnpj: null, transformer_power_kva: '500.00' },
      }], 1, 2, 16)))
      .mockResolvedValueOnce(jsonResponse(paged([{
        id: 8, name: 'Bruno Lima', email: 'bruno@example.com', phone: null, email_verified_at: null,
        contact_requested_at: null, profile: { company_name: 'Indústria Alfa', cnpj: null, transformer_power_kva: null },
      }], 2, 2, 16)))
    vi.stubGlobal('fetch', fetchMock)
    render(<AdminCustomersPage />)

    expect(await screen.findByText('Empresa Solar')).toBeInTheDocument()
    expect(screen.getByText('Solicitado')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ver cadastro' })).toHaveAttribute('href', '/admin/customers/4')
    fireEvent.click(screen.getByRole('button', { name: 'Próxima' }))
    expect(await screen.findByText('Indústria Alfa')).toBeInTheDocument()
    await waitFor(() => expect(fetchMock).toHaveBeenLastCalledWith('/api/v1/admin/customers?page=2', expect.objectContaining({ headers: expect.any(Headers) })))
  })

  it('shows the customer contact and company profile details', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ data: {
      id: 4, name: 'Ana Souza', email: 'ana@example.com', phone: '11999990000', email_verified_at: '2026-10-24T12:00:00Z',
      contact_requested_at: null, profile: { company_name: 'Empresa Solar', cnpj: '12345678000199', transformer_power_kva: '500.00' },
    } })))
    render(<AdminCustomerDetailPage customerId="4" />)

    expect(await screen.findByText('ana@example.com')).toBeInTheDocument()
    expect(screen.getByText('12345678000199')).toBeInTheDocument()
    expect(screen.getByText('500.00 kVA')).toBeInTheDocument()
  })

  it('lists simulations with pagination and opens the extracted-data detail', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(paged([{
      id: 7, status: 'completed', months_count: 12, total_monthly_savings: '250.00', total_yearly_savings: '3000.00',
      customer: { id: 4, name: 'Ana Souza', email: 'ana@example.com' },
    }], 1, 1, 1))))
    render(<AdminSimulationsPage />)

    expect(await screen.findByText('Ana Souza')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ver dados' })).toHaveAttribute('href', '/admin/simulations/7')
    expect(screen.getByText('R$ 250,00')).toBeInTheDocument()
  })

  it('renders extracted invoice items and excludes PDF references', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ data: {
      id: 7, user_id: 4, status: 'completed', months_count: 1, total_monthly_savings: '250.00', total_yearly_savings: '3000.00',
      expires_at: null, customer: { id: 4, name: 'Ana Souza', email: 'ana@example.com' },
      invoices: [{ id: 19, reference_month: '2026-09-01', consumer_unit: '123456', subgroup: 'A4', tariff_modality: 'green', total_paid: '14500.00',
        pdf_path: 'private/secret-invoice.pdf', items: [{ id: 11, item_type: 'consumption', tariff_component: 'TUSD', time_slot: 'off_peak', quantity: '1000.000', unit: 'kWh', unit_price_taxed: '0.50000', tariff_rate: '0.40000', amount: '500.00', source_label: 'ENERGIA ATIVA FORA PONTA' }] }],
      report: { total_monthly_savings: '250.00', total_yearly_savings: '3000.00', recommended_modality: 'green', algorithm_version: '1.0' },
    } })))
    render(<AdminSimulationDetailPage simulationId="7" />)

    expect(await screen.findByText('Faturas e itens extraídos')).toBeInTheDocument()
    expect(screen.getByText('ENERGIA ATIVA FORA PONTA')).toBeInTheDocument()
    expect(screen.getByText(/14\.500,00/)).toBeInTheDocument()
    expect(screen.queryByText(/secret-invoice\.pdf/i)).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /pdf|download/i })).not.toBeInTheDocument()
  })
})
