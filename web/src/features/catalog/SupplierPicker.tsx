import { useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { errorMessage } from '@/lib/errors'
import { useSaveSupplier, useSuppliers } from './hooks'

export interface SupplierPickerProps {
  value: { id: number; name: string } | null
  onChange: (s: { id: number; name: string }) => void
  invalid?: boolean
  id?: string
}

/** Searchable supplier select with an inline «Crear "…"» action. */
export function SupplierPicker({ value, onChange, invalid, id }: SupplierPickerProps) {
  const [open, setOpen] = useState(false)
  const [text, setText] = useState('')
  const { data } = useSuppliers('', '1')
  const save = useSaveSupplier()
  const t = text.trim()
  const filtered = (data ?? []).filter((s) => s.name.toLowerCase().includes(t.toLowerCase()))
  const exact = (data ?? []).some((s) => s.name.toLowerCase() === t.toLowerCase())

  async function create() {
    try {
      const s = await save.mutateAsync({ name: t })
      onChange({ id: s.id, name: s.name })
      setOpen(false)
      setText('')
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  return (
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
          <span className={value ? '' : 'text-muted-foreground'}>{value?.name ?? 'Elegir proveedor'}</span>
          <span aria-hidden className="text-muted-foreground">
            ▾
          </span>
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[var(--radix-popover-trigger-width)] min-w-64 p-0" align="start">
        <Command shouldFilter={false}>
          <CommandInput placeholder="Buscar proveedor…" value={text} onValueChange={setText} />
          <CommandList>
            <CommandEmpty>Sin resultados.</CommandEmpty>
            <CommandGroup>
              {filtered.map((s) => (
                <CommandItem
                  key={s.id}
                  value={String(s.id)}
                  onSelect={() => {
                    onChange({ id: s.id, name: s.name })
                    setOpen(false)
                    setText('')
                  }}
                >
                  {s.name}
                </CommandItem>
              ))}
              {t && !exact && (
                <CommandItem value="__create__" onSelect={create} disabled={save.isPending}>
                  Crear «{t}»
                </CommandItem>
              )}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
