import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { InvoiceImportTable, type ImportedInvoice } from './InvoiceImportTable'

describe('invoice review table', () => {
  it('preserves decimal precision, negative credits and missing fields and highlights idle demand', () => {
    const invoice: ImportedInvoice = {
      id: 1, reference_month: '2026-08', consumer_unit: '123456789', total_paid: '42446.32', warnings: ['free_market_invoice'], items: [
        { id: 1, item_type: 'idle_demand', time_slot: 'off_peak', source_label: 'DEMANDA LEI ESTADUAL 16.886/18', quantity: '67.920', unit: 'kW', unit_price_taxed: '10.05771', tariff_rate: '9.52000', amount: '683.12', pis_cofins_amount: '36.53', icms_base: '0.00', icms_rate: '0.0000', icms_amount: '0.00' },
        { id: 2, item_type: 'other_charge', time_slot: 'not_applicable', source_label: 'BENEFÍCIO TARIFÁRIO LÍQUIDO', quantity: null, unit: null, unit_price_taxed: null, tariff_rate: null, amount: '-9410.65', pis_cofins_amount: '-503.46', icms_base: '0.00', icms_rate: '0.0000', icms_amount: '0.00' },
      ],
    }
    render(<InvoiceImportTable invoices={[invoice]} />)
    expect(screen.getByText('Fatura do mercado livre (energia ACL).')).toBeInTheDocument()
    const idleRow = screen.getByText('DEMANDA LEI ESTADUAL 16.886/18').closest('tr')!
    expect(idleRow).toHaveClass('invoice-idle-row')
    expect(within(idleRow).getByText('67,920')).toBeInTheDocument()
    expect(within(idleRow).getByText('10,05771')).toBeInTheDocument()
    expect(within(idleRow).getByText('9,52000')).toBeInTheDocument()
    const creditRow = screen.getByText('BENEFÍCIO TARIFÁRIO LÍQUIDO').closest('tr')!
    expect(within(creditRow).getByText('-9.410,65')).toBeInTheDocument()
    expect(within(creditRow).getAllByText('—').length).toBeGreaterThan(0)
  })
})
