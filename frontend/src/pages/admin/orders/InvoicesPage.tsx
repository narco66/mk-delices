import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Download, FileSpreadsheet, ReceiptText } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import { Badge, Card, PageHeader } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Notice, Skeleton } from '@/components/ui/Feedback'
import { Field, Input, Select } from '@/components/ui/Form'
import { downloadFile, api, toQuery } from '@/lib/api'
import { formatDocumentDate, formatXof } from '@/lib/format'
import type { InvoiceRow } from '@/types/api'
import { Pagination, type PaginatedResponse } from './shared'

/** Registre des factures et avoirs (documents immuables) + export CSV pour la comptabilité. */
export function InvoicesPage() {
  const [type, setType] = useState('')
  const [q, setQ] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState<string | null>(null)
  const filters = { type, q: q.trim(), from, to }

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['admin', 'invoices', filters, page],
    queryFn: () => api.get<PaginatedResponse<InvoiceRow>>(`/admin/invoices${toQuery({ ...filters, page })}`),
    placeholderData: keepPreviousData,
  })

  const download = async (key: string, path: string, filename: string) => {
    setBusy(key)
    try {
      await downloadFile(path, filename)
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setBusy(null)
    }
  }

  const update = (setter: (v: string) => void) => (e: { target: { value: string } }) => {
    setter(e.target.value)
    setPage(1)
  }

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Finances"
        title="Factures et avoirs"
        actions={
          <Button
            variant="secondary"
            icon={<FileSpreadsheet className="size-4" />}
            loading={busy === 'csv'}
            onClick={() =>
              download(
                'csv',
                `/admin/invoices/export${toQuery({ type, q: q.trim(), from, to })}`,
                'factures-mk-delices.csv',
              )
            }
          >
            Export comptable (CSV)
          </Button>
        }
      />
      <Notice tone="info" title="Documents définitifs">
        Une facture émise ne peut être ni modifiée ni supprimée, et la numérotation est continue. Une erreur ou un
        remboursement se corrige par un avoir, émis automatiquement quand un remboursement est effectué.
      </Notice>

      <Card className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Type">
          {(p) => (
            <Select {...p} value={type} onChange={update(setType)}>
              <option value="">Factures et avoirs</option>
              <option value="invoice">Factures</option>
              <option value="credit_note">Avoirs</option>
            </Select>
          )}
        </Field>
        <Field label="Numéro ou commande">
          {(p) => <Input {...p} value={q} onChange={update(setQ)} placeholder="FA-2026-… ou MKD-…" />}
        </Field>
        <Field label="Du">{(p) => <Input {...p} type="date" value={from} onChange={update(setFrom)} />}</Field>
        <Field label="Au">{(p) => <Input {...p} type="date" value={to} onChange={update(setTo)} />}</Field>
      </Card>

      {isLoading ? (
        <Skeleton className="h-48" />
      ) : isError || !data ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<ReceiptText className="size-6" />}
          title="Aucun document"
          description="Les factures sont émises automatiquement une fois la commande payée et livrée."
        />
      ) : (
        <>
          <Card className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead className="bg-cream-100 text-cocoa-500 text-left text-xs tracking-wide uppercase">
                <tr>
                  <th className="px-4 py-3">Numéro</th>
                  <th className="px-4 py-3">Date</th>
                  <th className="px-4 py-3">Commande</th>
                  <th className="px-4 py-3">Client</th>
                  <th className="px-4 py-3 text-right">HT</th>
                  <th className="px-4 py-3 text-right">TVA</th>
                  <th className="px-4 py-3 text-right">TTC</th>
                  <th className="px-4 py-3">
                    <span className="sr-only">PDF</span>
                  </th>
                </tr>
              </thead>
              <tbody className="divide-cocoa-100/70 divide-y">
                {data.data.map((i) => {
                  const sign = i.type === 'credit_note' ? '− ' : ''
                  return (
                    <tr key={i.id}>
                      <td className="px-4 py-3">
                        <span className="font-medium">{i.number}</span>
                        <div className="mt-0.5 flex flex-wrap gap-1">
                          <Badge tone={i.type === 'credit_note' ? 'warning' : undefined}>{i.type_label}</Badge>
                          {i.is_test && <Badge>Test</Badge>}
                        </div>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap">{formatDocumentDate(i.issued_at)}</td>
                      <td className="px-4 py-3">
                        {i.order_reference ? (
                          <Link to={`/admin/commandes/${i.order_id}`} className="font-medium hover:underline">
                            {i.order_reference}
                          </Link>
                        ) : (
                          '—'
                        )}
                      </td>
                      <td className="px-4 py-3">{i.buyer_name || '—'}</td>
                      <td className="px-4 py-3 text-right tabular-nums">
                        {sign}
                        {formatXof(i.total_ht_xof)}
                      </td>
                      <td className="px-4 py-3 text-right tabular-nums">
                        {i.vat_applicable ? `${sign}${formatXof(i.total_vat_xof)}` : 'n/a'}
                      </td>
                      <td className="px-4 py-3 text-right font-semibold tabular-nums">
                        {sign}
                        {formatXof(i.total_ttc_xof)}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <Button
                          size="sm"
                          variant="ghost"
                          icon={<Download className="size-4" />}
                          loading={busy === i.id}
                          onClick={() => download(i.id, `/admin/invoices/${i.id}/pdf`, `${i.number}.pdf`)}
                          aria-label={`Télécharger ${i.number} (PDF)`}
                        >
                          PDF
                        </Button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </Card>
          <Pagination meta={data.meta} onPage={setPage} />
        </>
      )}
    </div>
  )
}
