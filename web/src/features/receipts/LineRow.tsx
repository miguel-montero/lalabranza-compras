import { forwardRef } from 'react'
import { ProductPicker, type PickedProduct } from '@/features/catalog/ProductPicker'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { formatCLP, formatUnitPrice } from '@/lib/format'
import { parseCLP, parseDecimal } from '@/lib/parse'
import type { DocType, Unit } from '@/lib/calc'
import { ComparisonChip } from './ComparisonChip'
import { compatibleUnits, type DraftLine, type LineComputed } from './useReceiptDraft'

export interface LineRowProps {
  index: number
  line: DraftLine
  computed: LineComputed
  docType: DocType
  /** Server errors for this line, keyed by field (product_id, quantity, entry_unit, amount, package_count, package_content, package_unit). */
  errors?: Record<string, string>
  onChange: (patch: Partial<Omit<DraftLine, 'key'>>) => void
  onRemove: () => void
  /** Called on Enter in this row's amount field. */
  onEnterAmount: () => void
}

function UnitSelect({ value, onChange, base, label, invalid }: { value: Unit; onChange: (u: Unit) => void; base: PickedProduct['base_unit'] | undefined; label: string; invalid?: boolean }) {
  return (
    <Select value={value} onValueChange={(v) => onChange(v as Unit)}>
      <SelectTrigger className="w-24 shrink-0" aria-label={label} aria-invalid={invalid || undefined}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {compatibleUnits(base).map((u) => (
          <SelectItem key={u} value={u}>
            {u}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

export const LineRow = forwardRef<HTMLDivElement, LineRowProps>(function LineRow(
  { index, line, computed, docType, errors = {}, onChange, onRemove, onEnterAmount },
  ref,
) {
  const base = line.product?.base_unit
  const factura = docType === 'factura'
  const n = index + 1
  const modeUnit = line.entry_mode === 'unit_price'
  const amountLabel = modeUnit
    ? line.packaged
      ? 'Precio por envase'
      : factura
        ? 'Precio unitario neto'
        : 'Precio unitario'
    : factura
      ? 'Total neto línea'
      : 'Total línea'
  const bad = (v: string, parse: (s: string) => number | null) => v.trim() !== '' && parse(v) === null

  function pickProduct(p: PickedProduct) {
    const units = compatibleUnits(p.base_unit)
    onChange({
      product: p,
      unit: units.includes(line.unit) ? line.unit : p.base_unit,
      package_unit: units.includes(line.package_unit) ? line.package_unit : p.base_unit,
    })
  }

  const err = (k: string) => (errors[k] ? <p className="mt-1 text-sm text-destructive">{errors[k]}</p> : null)

  return (
    <div ref={ref} data-testid="line-row" className="grid gap-3 rounded-lg border bg-card p-3 shadow-xs">
      <div className="flex items-start gap-2">
        <div className="min-w-0 flex-1" data-line-product>
          <ProductPicker id={`line-${index}-product`} value={line.product} onChange={pickProduct} invalid={!!errors.product_id} />
          {err('product_id')}
        </div>
        <Button type="button" variant="ghost" size="icon" aria-label={`Quitar línea ${n}`} onClick={onRemove}>
          ×
        </Button>
      </div>

      {line.packaged ? (
        <div>
          <div className="flex items-center gap-2">
            <Input
              aria-label={`Envases, línea ${n}`}
              inputMode="decimal"
              placeholder="N°"
              className="w-16"
              value={line.package_count}
              aria-invalid={!!errors.package_count || bad(line.package_count, parseDecimal) || undefined}
              onChange={(e) => onChange({ package_count: e.target.value })}
            />
            <span aria-hidden>×</span>
            <Input
              aria-label={`Contenido por envase, línea ${n}`}
              inputMode="decimal"
              placeholder="Contenido"
              className="min-w-0 flex-1"
              value={line.package_content}
              aria-invalid={!!errors.package_content || bad(line.package_content, parseDecimal) || undefined}
              onChange={(e) => onChange({ package_content: e.target.value })}
            />
            <UnitSelect value={line.package_unit} base={base} label={`Unidad del contenido, línea ${n}`} invalid={!!errors.package_unit} onChange={(u) => onChange({ package_unit: u })} />
          </div>
          {err('package_count')}
          {err('package_content')}
          {err('package_unit')}
        </div>
      ) : (
        <div>
          <div className="flex items-center gap-2">
            <Input
              aria-label={`Cantidad, línea ${n}`}
              inputMode="decimal"
              placeholder="Cantidad"
              className="min-w-0 flex-1"
              value={line.quantity}
              aria-invalid={!!errors.quantity || bad(line.quantity, parseDecimal) || undefined}
              onChange={(e) => onChange({ quantity: e.target.value })}
            />
            <UnitSelect value={line.unit} base={base} label={`Unidad, línea ${n}`} invalid={!!errors.entry_unit} onChange={(u) => onChange({ unit: u })} />
          </div>
          {err('quantity')}
          {err('entry_unit')}
        </div>
      )}
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="size-4 accent-[var(--cobre-viejo)]" checked={line.packaged} onChange={(e) => onChange({ packaged: e.target.checked })} />
        en envases
      </label>

      <div>
        <div className="flex items-end gap-2">
          <div className="min-w-0 flex-1">
            <label className="mb-1 block text-sm text-muted-foreground" htmlFor={`line-${index}-amount`}>
              {amountLabel}
            </label>
            <Input
              id={`line-${index}-amount`}
              data-line-amount
              inputMode="numeric"
              placeholder="$"
              value={line.amount}
              aria-invalid={!!errors.amount || bad(line.amount, parseCLP) || undefined}
              onChange={(e) => onChange({ amount: e.target.value })}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault()
                  onEnterAmount()
                }
              }}
            />
          </div>
          <Button type="button" variant="outline" size="sm" onClick={() => onChange({ entry_mode: modeUnit ? 'total' : 'unit_price' })}>
            {modeUnit ? 'Ingresar total' : 'Precio unitario'}
          </Button>
        </div>
        {err('amount')}
        {computed.enteredAmount > 0 && (
          <p className="mt-1 text-sm text-muted-foreground">
            {modeUnit
              ? `Total línea: ${formatCLP(computed.enteredAmount)} (calculado)`
              : computed.derivedUnitPrice !== null
                ? `${line.packaged ? 'Precio por envase' : 'Precio unitario'}: ${formatCLP(computed.derivedUnitPrice)} (calculado)`
                : null}
          </p>
        )}
      </div>

      {factura && (
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="size-4 accent-[var(--cobre-viejo)]" checked={line.iva_exempt} onChange={(e) => onChange({ iva_exempt: e.target.checked })} />
          exento / sin IVA
        </label>
      )}

      <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-2">
        <div className="text-sm">
          {line.product && computed.normalizedPrice !== null ? (
            <span className="text-base font-semibold">{formatUnitPrice(computed.normalizedPrice, line.product.base_unit)}</span>
          ) : (
            <span className="text-muted-foreground">—</span>
          )}
          {line.product && computed.enteredAmount > 0 && (
            <span className="ml-2 text-muted-foreground">
              costo real {formatCLP(computed.realCost)}
              {factura && !line.iva_exempt ? ` · pagado ${formatCLP(computed.amountPaid)}` : ''}
            </span>
          )}
        </div>
        {line.product && <ComparisonChip comparison={computed.comparison} reference={computed.baseline} baseUnit={line.product.base_unit} />}
      </div>
    </div>
  )
})
