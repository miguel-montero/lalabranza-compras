import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { SupplierPicker } from '@/features/catalog/SupplierPicker'
import { api, ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/errors'
import { formatCLP } from '@/lib/format'
import { todaySantiago } from '@/lib/santiago'
import type { ReceiptView } from '@/lib/types'
import { LineRow } from './LineRow'
import { buildPayload, useReceiptDraft, type Draft } from './useReceiptDraft'

export interface ReceiptFormProps {
  /** localStorage key of the draft. Default `kt:draft:new`. */
  storageKey?: string
  /** Starting draft when nothing is stored under `storageKey` (edit mode passes draftFromReceipt(r)). */
  initial?: Draft
  /** Persists the payload. Default: POST /receipts. */
  submit?: (payload: Record<string, unknown>) => Promise<ReceiptView>
  /** Called after a successful save, after the draft was reset. Default: navigate to /compras/:id. */
  onSaved?: (r: ReceiptView) => void
}

type Errors = Record<string, string>

const LINE_FIELD: Record<string, string> = { entered_amount: 'amount', unit_price: 'amount' }

function Section({ children }: { children: React.ReactNode }) {
  return <div className="grid gap-3 rounded-lg border bg-card p-3 shadow-xs">{children}</div>
}

function Label({ htmlFor, children }: { htmlFor?: string; children: React.ReactNode }) {
  return (
    <label htmlFor={htmlFor} className="mb-1 block text-sm text-muted-foreground">
      {children}
    </label>
  )
}

export default function ReceiptForm({ storageKey, initial, submit, onSaved }: ReceiptFormProps) {
  const navigate = useNavigate()
  const { draft, setHeader, addLine, updateLine, removeLine, computed, reset } = useReceiptDraft({ storageKey, initial })
  const [errors, setErrors] = useState<Errors>({})
  const [saving, setSaving] = useState(false)
  const savingRef = useRef(false)
  const [dupe, setDupe] = useState<number | null>(null)
  const listRef = useRef<HTMLDivElement>(null)
  const focusNew = useRef(false)
  const factura = draft.doc_type === 'factura'

  // Errors describe the last save attempt; drop them once the user edits.
  const first = useRef(true)
  useEffect(() => {
    if (first.current) {
      first.current = false
      return
    }
    setErrors((e) => (Object.keys(e).length ? {} : e))
  }, [draft])

  useEffect(() => {
    if (!focusNew.current) return
    focusNew.current = false
    const rows = listRef.current?.querySelectorAll<HTMLElement>('[data-line-product] button')
    rows?.[rows.length - 1]?.focus()
  }, [draft.lines.length])

  function addAndFocus() {
    focusNew.current = true
    addLine()
  }

  async function save(confirmDuplicate = false) {
    if (savingRef.current) return
    savingRef.current = true
    setSaving(true)
    setDupe(null)
    const { payload, indexMap } = buildPayload(draft, { confirmDuplicate })
    try {
      const r = submit ? await submit(payload) : await api<ReceiptView>('POST', '/receipts', payload)
      reset()
      toast.success('Compra guardada')
      if (onSaved) onSaved(r)
      else navigate(`/compras/${r.id}`)
    } catch (e) {
      if (e instanceof ApiError && e.status === 409 && e.code === 'duplicate_receipt_number') {
        setDupe(Number(e.data.existing_id) || 0)
      } else if (e instanceof ApiError && e.status === 422) {
        const out: Errors = {}
        for (const [k, msg] of Object.entries(e.fields)) {
          const m = /^items\.(\d+)\.(.+)$/.exec(k)
          if (m) {
            const lineIdx = indexMap[Number(m[1])]
            if (lineIdx !== undefined) out[`line.${lineIdx}.${LINE_FIELD[m[2]] ?? m[2]}`] = msg
          } else {
            out[k] = msg === 'adjustment_too_large' ? 'El descuento es mayor que el total de las líneas.' : msg
          }
        }
        setErrors(out)
        toast.error('Revisa los campos marcados.')
      } else {
        toast.error(errorMessage(e))
      }
    } finally {
      savingRef.current = false
      setSaving(false)
    }
  }

  const lineErrors = (i: number): Errors => {
    const out: Errors = {}
    const p = `line.${i}.`
    for (const [k, v] of Object.entries(errors)) if (k.startsWith(p)) out[k.slice(p.length)] = v
    return out
  }
  const fieldErr = (k: string) => (errors[k] ? <p className="mt-1 text-sm text-destructive">{errors[k]}</p> : null)
  const { totals } = computed

  return (
    <form
      className="grid gap-4"
      // Saving happens only via the save button; Enter / "Go" in inputs must never submit.
      onSubmit={(e) => e.preventDefault()}
    >
      <div className="sticky top-0 z-10 -mx-4 flex items-baseline justify-between border-b bg-background/95 px-4 py-2 backdrop-blur md:static md:mx-0 md:border-0 md:px-0">
        <h1 className="text-2xl font-semibold">Nueva compra</h1>
        <div className="text-right" aria-live="polite">
          <div className="text-xs text-muted-foreground">Total</div>
          <div className="text-xl font-semibold" data-testid="header-total">
            {formatCLP(totals.total)}
          </div>
        </div>
      </div>

      <Section>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label htmlFor="r-date">Fecha</Label>
            <Input id="r-date" type="date" max={todaySantiago()} value={draft.purchase_date} aria-invalid={!!errors.purchase_date || undefined} onChange={(e) => setHeader({ purchase_date: e.target.value })} />
            {fieldErr('purchase_date')}
          </div>
          <div>
            <Label>Documento</Label>
            <div role="group" aria-label="Tipo de documento" className="grid h-9 grid-cols-2 overflow-hidden rounded-md border border-input">
              {(['boleta', 'factura'] as const).map((t) => (
                <button
                  key={t}
                  type="button"
                  aria-pressed={draft.doc_type === t}
                  className={`text-sm font-medium capitalize ${draft.doc_type === t ? 'bg-primary text-primary-foreground' : 'bg-card hover:bg-accent'}`}
                  onClick={() => setHeader({ doc_type: t })}
                >
                  {t === 'boleta' ? 'Boleta' : 'Factura'}
                </button>
              ))}
            </div>
          </div>
        </div>
        <div>
          <Label htmlFor="r-supplier">Proveedor</Label>
          <SupplierPicker id="r-supplier" value={draft.supplier} onChange={(s) => setHeader({ supplier: s })} invalid={!!errors.supplier_id} />
          {fieldErr('supplier_id')}
        </div>
        <div>
          <Label htmlFor="r-number">N° de {factura ? 'factura' : 'boleta'} (opcional)</Label>
          <Input id="r-number" value={draft.receipt_number} maxLength={40} aria-invalid={!!errors.receipt_number || undefined} onChange={(e) => setHeader({ receipt_number: e.target.value })} />
          {fieldErr('receipt_number')}
        </div>
        <div>
          <Label htmlFor="r-notes">Notas</Label>
          <Textarea id="r-notes" rows={2} value={draft.notes} onChange={(e) => setHeader({ notes: e.target.value })} />
        </div>
      </Section>

      <div ref={listRef} className="grid gap-3">
        {draft.lines.map((l, i) => (
          <LineRow
            key={l.key}
            index={i}
            line={l}
            computed={computed.lines[i]}
            docType={draft.doc_type}
            errors={lineErrors(i)}
            onChange={(patch) => updateLine(l.key, patch)}
            onRemove={() => removeLine(l.key)}
            onEnterAmount={() => (i === draft.lines.length - 1 ? addAndFocus() : undefined)}
          />
        ))}
        {fieldErr('items')}
        <Button type="button" variant="outline" onClick={addAndFocus}>
          + Agregar línea
        </Button>
      </div>

      <Section>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label htmlFor="r-adj">Descuento / ajuste</Label>
            <Input id="r-adj" inputMode="text" placeholder="-500 = descuento" value={draft.adjustment} aria-invalid={!!errors.adjustment_amount || undefined} onChange={(e) => setHeader({ adjustment: e.target.value })} />
            {fieldErr('adjustment_amount')}
          </div>
          <div>
            <Label htmlFor="r-del">Despacho</Label>
            <Input id="r-del" inputMode="numeric" placeholder="$" value={draft.delivery} aria-invalid={!!errors.delivery_amount || undefined} onChange={(e) => setHeader({ delivery: e.target.value })} />
            {fieldErr('delivery_amount')}
          </div>
        </div>
        <div>
          <Label htmlFor="r-printed">Total impreso (opcional)</Label>
          <Input id="r-printed" inputMode="numeric" placeholder="$" value={draft.printed_total} aria-invalid={!!errors.printed_total || undefined} onChange={(e) => setHeader({ printed_total: e.target.value })} />
          {fieldErr('printed_total')}
        </div>

        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 border-t pt-3 text-sm">
          {factura && (
            <>
              <dt className="text-muted-foreground">Neto</dt>
              <dd className="text-right">{formatCLP(totals.neto)}</dd>
              {totals.exento > 0 && (
                <>
                  <dt className="text-muted-foreground">Exento</dt>
                  <dd className="text-right">{formatCLP(totals.exento)}</dd>
                </>
              )}
              <dt className="text-muted-foreground">IVA ({Math.round(computed.ivaRate * 100)}%)</dt>
              <dd className="text-right">{formatCLP(totals.iva)}</dd>
            </>
          )}
          <dt className="font-semibold">Total</dt>
          <dd className="text-right text-lg font-semibold">{formatCLP(totals.total)}</dd>
        </dl>
        {computed.mismatch && computed.printedTotal !== null && (
          <p role="status" className="rounded-md bg-[color-mix(in_oklab,var(--lana-dorada)_25%,transparent)] px-3 py-2 text-sm">
            El total calculado ({formatCLP(totals.total)}) no coincide con el total impreso ({formatCLP(computed.printedTotal)}). Puedes guardar igual.
          </p>
        )}
        {computed.adjustmentTooLarge && (
          <p role="status" className="text-sm text-destructive">
            El descuento es mayor que el monto de alguna línea.
          </p>
        )}
      </Section>

      <Button type="button" size="lg" disabled={saving} className="w-full" onClick={() => void save()}>
        {saving ? 'Guardando…' : 'Guardar compra'}
      </Button>

      <Dialog open={dupe !== null} onOpenChange={(o) => !o && setDupe(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Número de documento repetido</DialogTitle>
            <DialogDescription>
              Ya existe una compra de este proveedor con el mismo número.
              {dupe ? (
                <>
                  {' '}
                  <Link className="underline" to={`/compras/${dupe}`} target="_blank">
                    Ver la existente
                  </Link>
                  .
                </>
              ) : null}{' '}
              ¿Guardar de todos modos?
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => setDupe(null)}>
              Cancelar
            </Button>
            <Button type="button" onClick={() => void save(true)}>
              Guardar igual
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </form>
  )
}
