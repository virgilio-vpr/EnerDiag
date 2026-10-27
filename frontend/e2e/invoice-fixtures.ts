import { readFileSync } from 'node:fs'

function pdfFromText(text: string) {
  const lines = text.trim().split('\n').map((line) => `(${line.replaceAll('\\', '\\\\').replaceAll('(', '\\(').replaceAll(')', '\\)')}) Tj\nT*`).join('\n')
  const stream = `BT\n/F1 7 Tf\n10 TL\n20 820 Td\n${lines}\nET\n`
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    `<< /Length ${Buffer.byteLength(stream, 'latin1')} >>\nstream\n${stream}endstream`,
  ]
  let pdf = '%PDF-1.4\n'
  const offsets: number[] = []
  objects.forEach((object, index) => {
    offsets.push(Buffer.byteLength(pdf, 'latin1'))
    pdf += `${index + 1} 0 obj\n${object}\nendobj\n`
  })
  const xref = Buffer.byteLength(pdf, 'latin1')
  pdf += `xref\n0 6\n0000000000 65535 f \n${offsets.map((offset) => `${String(offset).padStart(10, '0')} 00000 n \n`).join('')}`
  pdf += `trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`
  return Buffer.from(pdf, 'latin1')
}

export function invoiceFixtures() {
  const reference = readFileSync(new URL('../../backend/tests/Fixtures/enel-reference-blue.txt', import.meta.url), 'utf8')
  return ['03', '04', '05', '06', '07', '08'].map((month) => ({
    name: `invoice-2025-${month}.pdf`,
    mimeType: 'application/pdf',
    buffer: pdfFromText(reference.replace('08/2025', `${month}/2025`)),
  }))
}
