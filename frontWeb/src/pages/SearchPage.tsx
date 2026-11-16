import { useSearchParams } from 'react-router-dom';
import { SearchBox } from '@/components/SearchBox';
import { Seo } from '@/components/ui';
import { ProductListing } from './Catalog';

export default function SearchPage() {
  const [params] = useSearchParams();
  const q = params.get('q') ?? '';
  return (
    <div className="container-page py-6">
      <Seo title={q ? `Recherche « ${q} »` : 'Recherche'} noindex />
      <h1 className="section-title mb-4 !text-3xl">{q ? `Résultats pour « ${q} »` : 'Recherche'}</h1>
      <div className="mb-6 max-w-xl md:hidden">
        <SearchBox />
      </div>
      {q.length >= 2 && <ProductListing key={q} mode="search" baseQuery={{ q }} title={`Recherche « ${q} »`} />}
    </div>
  );
}
