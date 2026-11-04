import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { LookupItem } from '@/lib/types'
import { ProductLink, StatsBlock } from './StatsBlock'

export default function LookupPage() {
  const [text, setText] = useState('')
  const [q, setQ] = useState('')
  useEffect(() => {
    const t = setTimeout(() => setQ(text.trim()), 250)
    return () => clearTimeout(t)
  }, [text])
  const { data, isFetching } = useQuery({
    queryKey: ['lookup', q],
    queryFn: () => api<{ items: LookupItem[] }>('GET', `/lookup?q=${encodeURIComponent(q)}`),
    enabled: q !== '',
    placeholderData: (prev) => prev,
  })
  const items = q === '' ? [] : (data?.items ?? [])
  return (
    <div>
      <PageHeader title="Consultar precio" />
      <Input
        autoFocus
        type="search"
        aria-label="Buscar producto"
        placeholder="Buscar producto…"
        className="mb-4 h-14 text-lg"
        value={text}
        onChange={(e) => setText(e.target.value)}
      />
      <div className="grid gap-3">
        {items.map((p) => (
          <Card key={p.id}>
            <CardHeader>
              <CardTitle className="text-lg">
                <ProductLink id={p.id}>{p.name}</ProductLink>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <StatsBlock stats={p.stats} bySupplier={p.by_supplier} baseUnit={p.base_unit} />
            </CardContent>
          </Card>
        ))}
        {q !== '' && !isFetching && items.length === 0 && <p className="text-muted-foreground">No se encontró ningún producto.</p>}
      </div>
    </div>
  )
}
