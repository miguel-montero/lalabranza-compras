import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { api, ApiError } from '@/lib/api'
import type { BaseUnit, Unit } from '@/lib/calc'
import { errorMessage } from '@/lib/errors'
import { formatCLP } from '@/lib/format'
import { ProductPicker } from '@/features/catalog/ProductPicker'
import { SupplierPicker } from '@/features/catalog/SupplierPicker'
import {
  activeFlags, effectiveAmount, mapHeaders, normalizeUnit, readSheet, type EditRow, type ImportRow,
} from './importRows'

const FLAG_LABELS: Record<string, string> = {
  missing_date: 'Falta fecha',
  missing_unit: 'Falta unidad',
  missing_total: 'Falta total',
  missing_receipt_number: 'Sin N° boleta',
  unknown_supplier: 'Proveedor nuevo',
  unknown_product: 'Producto nuevo',
  possible_swap: '¿Columnas invertidas?',
  ambiguous_price: '¿Precio unitario o total?',
  invalid_quantity: 'Cantidad inválida',
  duplicate_receipt_number: 'N° boleta ya existe',
}
const UNITS: Unit[] = ['kg', 'g', 'L', 'mL', 'unidad']

interface Suggestions {
  supplier_id?: number
  supplier_name?: string
  product_id?: number
  product_name?: string
  product_base_unit?: BaseUnit
  swapped?: boolean
}
interface Validated extends ImportRow {
  index: number
  flags: string[]
  suggestions: Suggestions
}

function toEdit(v: Validated, i: number): EditRow {
  const { flags, suggestions: s, ...row } = v
  return {
    ...row,
    index: i,
    serverFlags: flags,
    supplierId: s.supplier_id ?? null,
    supplierName: s.supplier_name ?? null,
    productId: s.product_id ?? null,
    productName: s.product_name ?? null,
    productBaseUnit: s.product_base_unit ?? null,
    docType: 'boleta',
    priceMode: null,
    dateUnknown: false,
    acceptNoNumber: false,
    acceptDuplicate: false,
    swapReviewed: false,
    selected: false,
  }
}

function plain(r: EditRow): ImportRow {
  return {
    date: r.date, supplier: r.supplier, product: r.product, category: r.category, quantity: r.quantity,
    unit: r.unit, unit_price: r.unit_price, total: r.total, receipt_number: r.receipt_number,
  }
}

function numOrNull(s: string): number | null {
  const t = s.replace(/[$\s]/g, '')
  if (t === '') return null
  if (/^\d{1,3}(\.\d{3})+$/.test(t)) return Number(t.replace(/\./g, ''))
  const n = Number(t.replace(',', '.'))
  return isFinite(n) ? n : null
}

export default function ImportPage() {
  const [rows, setRows] = useState<EditRow[]>([])
  const [busy, setBusy] = useState(false)
  const [bulkDate, setBulkDate] = useState('')
  const [result, setResult] = useState<{ receipts_created: number; lines_created: number } | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  const patch = (i: number, p: Partial<EditRow>) => setRows((rs) => rs.map((r) => (r.index === i ? { ...r, ...p } : r)))

  async function onFile(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return
    setBusy(true)
    setResult(null)
    try {
      const parsed = mapHeaders(await readSheet(file))
      if (!parsed.length) {
        toast.error('No se encontraron filas en la planilla.')
        setRows([])
        return
      }
      const res = await api<{ rows: Validated[] }>('POST', '/import/validate', { rows: parsed })
      setRows(res.rows.map(toEdit))
    } catch (err) {
      toast.error(err instanceof ApiError ? errorMessage(err) : 'No se pudo leer el archivo.')
    } finally {
      setBusy(false)
      if (fileRef.current) fileRef.current.value = ''
    }
  }

  async function swap(r: EditRow) {
    const next: ImportRow = { ...plain(r), supplier: r.product, product: r.supplier }
    try {
      const res = await api<{ rows: Validated[] }>('POST', '/import/validate', { rows: [next] })
      const v = toEdit(res.rows[0], r.index)
      patch(r.index, {
        supplier: next.supplier, product: next.product, serverFlags: v.serverFlags,
        supplierId: v.supplierId, supplierName: v.supplierName,
        productId: v.productId, productName: v.productName, productBaseUnit: v.productBaseUnit,
        swapReviewed: true,
      })
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  async function addAlias(r: EditRow) {
    if (!r.productId || !r.product) return
    try {
      await api('POST', `/products/${r.productId}/aliases`, { alias: r.product })
      toast.success(`«${r.product}» guardado como alias`)
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  const blocking = rows.reduce((n, r) => n + activeFlags(r).length, 0)
  const selected = rows.filter((r) => r.selected)

  async function commit() {
    setBusy(true)
    try {
      const res = await api<{ receipts_created: number; lines_created: number }>('POST', '/import/commit', {
        rows: rows.map((r) => ({
          supplier_id: r.supplierId,
          product_id: r.productId,
          purchase_date: r.dateUnknown ? null : r.date,
          date_unknown: r.dateUnknown,
          doc_type: r.docType,
          receipt_number: r.receipt_number,
          quantity: r.quantity,
          entry_unit: r.unit,
          entered_amount: effectiveAmount(r),
          accepted_flags: [
            ...(r.acceptNoNumber ? ['missing_receipt_number'] : []),
            ...(r.acceptDuplicate ? ['duplicate_receipt_number'] : []),
          ],
        })),
      })
      setResult(res)
      setRows([])
      toast.success('Importación completa')
    } catch (err) {
      if (err instanceof ApiError && err.code === 'unresolved_flags') {
        const bad = (err.data.rows as { index: number; flags: string[] }[]) ?? []
        setRows((rs) =>
          rs.map((r) => {
            const b = bad.find((x) => x.index === r.index)
            return b ? { ...r, serverFlags: [...new Set([...r.serverFlags, ...b.flags])] } : r
          }),
        )
        toast.error('Quedan filas por resolver.')
      } else toast.error(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="grid gap-4">
      <PageHeader title="Importar planilla" />
      <div className="flex flex-wrap items-center gap-3">
        <Input
          ref={fileRef}
          type="file"
          accept=".xlsx,.csv,text/csv"
          onChange={onFile}
          disabled={busy}
          className="max-w-sm"
          aria-label="Archivo de planilla"
        />
        <span className="text-sm text-muted-foreground">Excel (.xlsx) o CSV. Los montos se toman como pagados (IVA incluido).</span>
      </div>

      {result && (
        <p className="rounded-md border bg-muted p-3">
          Se crearon {result.receipts_created} compras con {result.lines_created} líneas.{' '}
          <Link to="/historial?include_undated=1" className="underline">
            Ver en Historial
          </Link>
        </p>
      )}

      {rows.length > 0 && (
        <>
          <div className="sticky top-0 z-10 flex flex-wrap items-center gap-2 rounded-md border bg-background p-2">
            <span className="text-sm">
              {rows.length} filas, {blocking} por resolver
            </span>
            <Input type="date" value={bulkDate} onChange={(e) => setBulkDate(e.target.value)} className="w-auto" aria-label="Fecha para asignar" />
            <Button
              type="button"
              variant="outline"
              disabled={!bulkDate || selected.length === 0}
              onClick={() => setRows((rs) => rs.map((r) => (r.selected ? { ...r, date: bulkDate, dateUnknown: false } : r)))}
            >
              Asignar fecha
            </Button>
            <Button type="button" variant="ghost" onClick={() => setRows((rs) => rs.map((r) => ({ ...r, selected: true })))}>
              Seleccionar todas
            </Button>
            <Button className="ml-auto" disabled={busy || blocking > 0} onClick={commit}>
              Importar
            </Button>
          </div>

          <ul className="grid gap-3">
            {rows.map((r) => (
              <RowCard key={r.index} r={r} patch={(p) => patch(r.index, p)} swap={() => swap(r)} alias={() => addAlias(r)} />
            ))}
          </ul>
        </>
      )}
    </div>
  )
}

function RowCard({ r, patch, swap, alias }: { r: EditRow; patch: (p: Partial<EditRow>) => void; swap: () => void; alias: () => void }) {
  const flags = activeFlags(r)
  const amount = effectiveAmount(r)
  const showSwap = r.serverFlags.includes('possible_swap')
  const canAlias = r.serverFlags.includes('unknown_product') && r.productId && r.product && r.product !== r.productName
  return (
    <li className={`grid gap-2 rounded-md border p-3 ${flags.length ? 'border-amber-500' : ''}`} data-testid="import-row">
      <div className="flex flex-wrap items-center gap-2">
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={r.selected} onChange={(e) => patch({ selected: e.target.checked })} aria-label={`Seleccionar fila ${r.index + 1}`} />
          Fila {r.index + 1}
        </label>
        {flags.map((f) => (
          <Badge key={f} variant="outline" className="border-amber-500 text-amber-700">
            {FLAG_LABELS[f] ?? f}
          </Badge>
        ))}
        {flags.includes('duplicate_receipt_number') && (
          <Button type="button" size="sm" variant="outline" onClick={() => patch({ acceptDuplicate: true })}>
            Aceptar
          </Button>
        )}
        {flags.length === 0 && <Badge variant="secondary">Lista</Badge>}
        {amount !== null && <span className="ml-auto text-sm font-medium">{formatCLP(amount)}</span>}
      </div>

      <div className="grid gap-2 sm:grid-cols-2">
        <div className="grid gap-1">
          <span className="text-xs text-muted-foreground">Proveedor en planilla: {r.supplier ?? '—'}</span>
          <SupplierPicker
            value={r.supplierId ? { id: r.supplierId, name: r.supplierName ?? `#${r.supplierId}` } : null}
            onChange={(s) => patch({ supplierId: s.id, supplierName: s.name })}
          />
        </div>
        <div className="grid gap-1">
          <span className="text-xs text-muted-foreground">Producto en planilla: {r.product ?? '—'}</span>
          <ProductPicker
            value={r.productId ? { id: r.productId, name: r.productName ?? `#${r.productId}`, base_unit: r.productBaseUnit ?? 'kg' } : null}
            onChange={(p) => patch({ productId: p.id, productName: p.name, productBaseUnit: p.base_unit })}
          />
          {canAlias && (
            <Button type="button" variant="link" size="sm" className="h-auto justify-start p-0" onClick={alias}>
              Guardar «{r.product}» como alias de {r.productName}
            </Button>
          )}
        </div>
      </div>
      {showSwap && !r.swapReviewed && (
        <div className="flex flex-wrap gap-2">
          <Button type="button" size="sm" variant="outline" onClick={swap}>
            Invertir proveedor y producto
          </Button>
          <Button type="button" size="sm" variant="ghost" onClick={() => patch({ swapReviewed: true })}>
            Está bien así
          </Button>
        </div>
      )}

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <label className="grid gap-1 text-xs text-muted-foreground">
          Fecha
          <Input type="date" value={r.date ?? ''} disabled={r.dateUnknown} onChange={(e) => patch({ date: e.target.value || null })} />
          <span className="flex items-center gap-1">
            <input type="checkbox" checked={r.dateUnknown} onChange={(e) => patch({ dateUnknown: e.target.checked })} />
            Sin fecha
          </span>
        </label>
        <label className="grid gap-1 text-xs text-muted-foreground">
          Cantidad
          <Input defaultValue={r.quantity ?? ''} inputMode="decimal" onBlur={(e) => patch({ quantity: numOrNull(e.target.value) })} />
        </label>
        <label className="grid gap-1 text-xs text-muted-foreground">
          Unidad
          <select
            className="h-9 rounded-md border bg-background px-2 text-sm text-foreground"
            value={r.unit ?? ''}
            onChange={(e) => patch({ unit: normalizeUnit(e.target.value) })}
          >
            <option value="">—</option>
            {UNITS.map((u) => (
              <option key={u} value={u}>{u}</option>
            ))}
          </select>
        </label>
        <label className="grid gap-1 text-xs text-muted-foreground">
          N° boleta
          <Input defaultValue={r.receipt_number ?? ''} onBlur={(e) =>
              patch({
                receipt_number: e.target.value.trim() || null,
                serverFlags: r.serverFlags.filter((f) => f !== 'duplicate_receipt_number'),
                acceptDuplicate: false,
              })
            } />
          {!r.receipt_number && (
            <span className="flex items-center gap-1">
              <input type="checkbox" checked={r.acceptNoNumber} onChange={(e) => patch({ acceptNoNumber: e.target.checked })} />
              Sin número
            </span>
          )}
        </label>
        <label className="grid gap-1 text-xs text-muted-foreground">
          Precio unitario
          <Input defaultValue={r.unit_price ?? ''} inputMode="numeric" onBlur={(e) => patch({ unit_price: numOrNull(e.target.value) })} />
        </label>
        <label className="grid gap-1 text-xs text-muted-foreground">
          Total
          <Input defaultValue={r.total ?? ''} inputMode="numeric" onBlur={(e) => patch({ total: numOrNull(e.target.value) })} />
        </label>
        <label className="grid gap-1 text-xs text-muted-foreground">
          Documento
          <select
            className="h-9 rounded-md border bg-background px-2 text-sm text-foreground"
            value={r.docType}
            onChange={(e) => patch({ docType: e.target.value as 'boleta' | 'factura' })}
          >
            <option value="boleta">Boleta</option>
            <option value="factura">Factura</option>
          </select>
        </label>
        {r.unit_price !== null && r.total === null && r.quantity !== 1 && (
          <label className="grid gap-1 text-xs text-muted-foreground">
            El precio es
            <select
              className="h-9 rounded-md border bg-background px-2 text-sm text-foreground"
              value={r.priceMode ?? ''}
              onChange={(e) => patch({ priceMode: (e.target.value || null) as 'unit' | 'total' | null })}
            >
              <option value="">Elegir…</option>
              <option value="unit">Precio unitario</option>
              <option value="total">Total de la línea</option>
            </select>
          </label>
        )}
      </div>
    </li>
  )
}
