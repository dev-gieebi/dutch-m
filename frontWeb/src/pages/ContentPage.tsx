import { PageDto } from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { PageSkeleton, Seo } from '@/components/ui';
import { api } from '@/lib/api';
import NotFound from './NotFound';

export default function ContentPage({ slug: prop }: { slug?: string }) {
  const { slug: param } = useParams();
  const slug = prop ?? param ?? '';
  const { data, isLoading, isError } = useQuery({
    queryKey: ['page', slug],
    queryFn: () => api<PageDto>(`/content/pages/${slug}`),
    enabled: !!slug,
    retry: false,
  });
  if (isLoading) return <PageSkeleton />;
  if (isError || !data) return <NotFound />;
  return (
    <div className="container-page max-w-3xl py-8">
      <Seo title={data.seoTitle ?? data.title} description={data.seoDescription} />
      <h1 className="section-title mb-6 !text-3xl sm:!text-4xl">{data.title}</h1>
      <div className="prose-amoria" dangerouslySetInnerHTML={{ __html: data.content }} />
    </div>
  );
}
