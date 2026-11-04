import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import type { BaseUnit } from '@/lib/calc'
import { useProducts } from './hooks'
import { ProductFormDialog } from './ProductFormDialog'

export interface PickedProduct {
  id: number
  name: string
  base_unit: BaseUnit
}

export interface ProductPickerProps {
  value: PickedProduct | null
  onChange: (p: PickedProduct) => void
  invalid?: boolean
  id?: string
}

function useDebounced<T>(v: T, ms = 150): T {
  const [d, setD] = useState(v)
  useEffect(() => {
    const t = setTimeout(() => setD(v), ms)
    return () => clearTimeout(t)
  }, [v, ms])
  return d
}

/** Product search by name or alias (server side), with «Crear "…"» opening a small dialog. */
export function ProductPicker({ value, onChange, invalid, id }: ProductPickerProps) {
  const [open, setOpen] = useState(false)
  const [creating, setCreating] = useState(false)
  const [text, setText] = useState('')
  const q = useDebounced(text.trim())
  const { data } = useProducts(q, '1', 20)
  const t = text.trim()
  const exact = (data ?? []).some((p) => p.name.toLowerCase() === t.toLowerCase())

  return (
    <>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            id={id}
            type="button"
            variant="outline"
            role="combobox"
            aria-expanded={open}
            aria-invalid={invalid || undefined}
            className="h-9 w-full justify-between font-normal aria-invalid:border-destructive"
          >
            <span className={'truncate ' + (value ? '' : 'text-muted-foreground')}>{value?.name ?? 'Elegir producto'}</span>
            <span aria-hidden className="text-muted-foreground">
              ▾
            </span>
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-[var(--radix-popover-trigger-width)] min-w-64 p-0" align="start">
          <Command shouldFilter={false}>
            <CommandInput placeholder="Buscar producto o alias…" value={text} onValueChange={setText} />
            <CommandList>
              <CommandEmpty>Sin resultados.</CommandEmpty>
              <CommandGroup>
                {(data ?? []).map((p) => (
                  <CommandItem
                    key={p.id}
                    value={String(p.id)}
                    onSelect={() => {
                      onChange({ id: p.id, name: p.name, base_unit: p.base_unit })
                      setOpen(false)
                      setText('')
                    }}
                  >
                    <span>{p.name}</span>
                    {p.matched_alias && <span className="ml-2 text-xs text-muted-foreground">({p.matched_alias})</span>}
                    <span className="ml-auto text-xs text-muted-foreground">{p.base_unit}</span>
                  </CommandItem>
                ))}
                {t && !exact && (
                  <CommandItem
                    value="__create__"
                    onSelect={() => {
                      setOpen(false)
                      setCreating(true)
                    }}
                  >
                    Crear «{t}»
                  </CommandItem>
                )}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
      <ProductFormDialog
        open={creating}
        onOpenChange={setCreating}
        initialName={t}
        onSaved={(p) => {
          onChange({ id: p.id, name: p.name, base_unit: p.base_unit })
          setText('')
        }}
      />
    </>
  )
}
