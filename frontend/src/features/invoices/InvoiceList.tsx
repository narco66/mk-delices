import { Download, FileText } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Display'
import { errorMessage } from '@/components/ui/Feedback'
import { downloadFile } from '@/lib/api'
import { formatDocumentDate, formatXof } from '@/lib/format'
import type { InvoiceSummary } from '@/types/api'

interface Props {
  invoices: InvoiceSummary[]
  /** Chemin API du PDF (sans /api/v1). */
  pathFor: (invoice: InvoiceSummary) => string
  /** En-têtes d'accès (ex. jeton de suivi invité). */
  headers?: Record<string, string>
  showVat?: boolean
}

/** Liste des factures et avoirs d'une commande, avec téléchargement du PDF. */
export function InvoiceList({ invoices, pathFor, headers, showVat = false }: Props) {
  const [loading, setLoading] = useState<string | null>(null)

  const download = async (invoice: InvoiceSummary) => {
    setLoading(invoice.id)
    try {
      await downloadFile(pathFor(invoice), `${invoice.number}.pdf`, headers)
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setLoading(null)
    }
  }

  return (
    <ul className="space-y-2">
      {invoices.map((invoice) => (
        <li
          key={invoice.id}
          className="border-cocoa-100 flex flex-col gap-2 rounded-[var(--radius-control)] border p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
        >
          <div className="flex min-w-0 items-start gap-2.5">
            <FileText className="text-caramel-600 mt-0.5 size-4 shrink-0" aria-hidden />
            <div className="min-w-0">
              <p className="flex flex-wrap items-center gap-2 font-medium">
                {invoice.type_label} {invoice.number}
                {invoice.is_test && <Badge>Test · sans valeur fiscale</Badge>}
              </p>
              <p className="text-cocoa-500 text-xs">
                Émis le {formatDocumentDate(invoice.issued_at)} · {invoice.type === 'credit_note' ? '− ' : ''}
                {formatXof(invoice.total_ttc_xof)}
                {showVat &&
                  (invoice.vat_applicable
                    ? ` TTC (dont TVA ${formatXof(invoice.total_vat_xof)})`
                    : ' · TVA non applicable')}
              </p>
            </div>
          </div>
          <Button
            size="sm"
            variant="secondary"
            icon={<Download className="size-4" />}
            loading={loading === invoice.id}
            onClick={() => download(invoice)}
            aria-label={`Télécharger ${invoice.type_label.toLowerCase()} ${invoice.number} (PDF)`}
          >
            PDF
          </Button>
        </li>
      ))}
    </ul>
  )
}
