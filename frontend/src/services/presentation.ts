import reportTexts from '../../../backend/resources/report-texts.json'

const simulationStatuses: Record<string, string> = {
  draft: 'Envio de faturas', queued: 'Aguardando cálculo', processing: 'Calculando',
  completed: 'Concluída', failed: 'Precisa de atenção',
}
const modalities: Record<string, string> = { blue: 'Azul', green: 'Verde' }
const timeSlots: Record<string, string> = {
  peak: 'Ponta', off_peak: 'Fora de ponta', single: 'Único', unique: 'Único',
  late_night_weekend: 'Madrugada / fim de semana', not_applicable: 'Não se aplica',
}
const tariffComponents: Record<string, string> = {
  demand: 'Demanda', energy_te: 'Energia (TE)', energy_tusd: 'Uso da rede (TUSD)',
  TE: 'Energia (TE)', TUSD: 'Uso da rede (TUSD)', other: 'Outros encargos', not_applicable: 'Não se aplica',
}
const invoiceItems: Record<string, string> = {
  contracted_demand: 'Demanda contratada', measured_demand: 'Demanda medida', idle_demand: 'Demanda ociosa',
  demand_overrun: 'Ultrapassagem de demanda', reactive_energy_excess: 'Energia reativa excedente',
  reactive_demand_excess: 'Demanda reativa excedente', other_charge: 'Outros encargos e ajustes',
}
const units: Record<string, string> = {
  KW: 'kW', KWH: 'kWh', KVA: 'kVA', KVAR: 'kVAr', KVARH: 'kVArh',
  hour: 'hora', hours: 'horas', day: 'diária', days: 'diárias', unit: 'unidade', units: 'unidades', service: 'serviço',
}
const additionalWarnings: Record<string, string> = {
  demand_billing_parameter_unavailable: 'Não foi encontrado um parâmetro necessário para calcular a cobrança de demanda.',
  demand_optimization_series_unavailable: 'Os dados de demanda são insuficientes para encontrar a contratação mais econômica.',
  invoice_billing_total_mismatch: 'A soma dos itens extraídos difere do total da fatura. Confira a leitura dos valores.',
}

export function simulationStatusLabel(value: string) { return simulationStatuses[value] ?? 'Situação não identificada' }
export function modalityLabel(value: string | null | undefined) { return value ? modalities[value] ?? 'Modalidade não identificada' : '—' }
export function timeSlotLabel(value: string | null | undefined) { return value ? timeSlots[value] ?? 'Posto não identificado' : '—' }
export function componentLabel(value: string | null | undefined) { return value ? tariffComponents[value] ?? 'Componente não identificado' : '—' }
export function unitLabel(value: string | null | undefined) { return value ? units[value] ?? value : '—' }
export function invoiceItemLabel(type: string, component?: string | null) {
  if (type === 'active_consumption' || type === 'consumption') {
    if (component === 'energy_te' || component === 'TE') return 'Consumo ativo (TE)'
    if (component === 'energy_tusd' || component === 'TUSD') return 'Consumo ativo (TUSD)'
    return 'Consumo ativo'
  }
  return invoiceItems[type] ?? (component && tariffComponents[component]) ?? 'Item da fatura'
}
export function invoiceSourceLabel(value: string | null) {
  if (!value) return '—'
  return /\bACL\b|mercado\s+livre/i.test(value) ? 'Ajuste de energia' : value
}
export function adminWarningTexts(values: string[]) {
  const messages: Record<string, string> = { ...reportTexts.warnings, ...additionalWarnings }
  return values.filter((value) => value !== 'free_market_invoice').map((value) => {
    if (messages[value]) return messages[value]
    return /^[a-z][a-z0-9]*(?:_[a-z0-9]+)+$/.test(value) ? 'Há uma observação da análise que precisa de conferência.' : value
  })
}
export function formatDecimal(value: string | null | undefined, places = 2) {
  if (value === null || value === undefined || value === '') return '—'
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed.toLocaleString('pt-BR', { minimumFractionDigits: places, maximumFractionDigits: places }) : '—'
}
export function formatUnitPrice(value: string | null) {
  if (value === null || value === '') return '—'
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 5, maximumFractionDigits: 5 }) : '—'
}
export function formatDate(value: string | null, empty = '—') {
  if (!value) return empty
  const date = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
  return date ? `${date[3]}/${date[2]}/${date[1]}` : empty
}
export function formatReferenceMonth(value: string) {
  return /^\d{4}-\d{2}/.test(value) ? `${value.slice(5, 7)}/${value.slice(0, 4)}` : '—'
}
