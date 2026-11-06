export function loadSimulationReport() {
  return import('./SimulationReportPage').then(({ SimulationReportPage }) => ({ default: SimulationReportPage }))
}
