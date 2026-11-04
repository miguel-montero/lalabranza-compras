import { PageHeader } from '@/components/PageHeader'

export default function Placeholder({ title }: { title: string }) {
  return (
    <div>
      <PageHeader title={title} />
      <p className="text-muted-foreground">Esta pantalla se habilita en una próxima etapa.</p>
    </div>
  )
}
