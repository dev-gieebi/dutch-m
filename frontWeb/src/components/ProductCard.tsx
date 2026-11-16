import { AvailabilityMode, ItemType, ProductCardDto } from '@/shared';
import { ShoppingBag } from 'lucide-react';
import { Link } from 'react-router-dom';
import { assetUrl } from '@/lib/api';
import { useCartStore } from '@/store/cart';
import { useUiStore } from '@/store/ui';
import { AvailabilityBadge, Price, Skeleton } from './ui';

export function ProductCard({ product }: { product: ProductCardDto }) {
  const add = useCartStore((s) => s.add);
  const notify = useUiStore((s) => s.notify);
  const img = product.image;
  const canQuickAdd = product.defaultVariantId !== null && product.availability !== AvailabilityMode.UNAVAILABLE;

  return (
    <article className="group card flex flex-col overflow-hidden transition duration-300 hover:-translate-y-1">
      <Link to={`/produit/${product.slug}`} className="relative block aspect-[3/4] overflow-hidden bg-sand">
        {img ? (
          <img
            src={assetUrl(img.mediumUrl)}
            srcSet={`${assetUrl(img.thumbUrl)} 300w, ${assetUrl(img.mediumUrl)} 800w`}
            sizes="(min-width: 768px) 25vw, 50vw"
            alt={img.alt || product.name}
            loading="lazy"
            className="h-full w-full object-cover transition duration-500 group-hover:scale-105"
          />
        ) : (
          <div className="flex h-full items-center justify-center font-serif text-gold">DutCh’M</div>
        )}
        <div className="absolute left-2 top-2 flex flex-col gap-1">
          {product.isNew && <span className="badge bg-blush text-rose-dark">Nouveau</span>}
          {product.onSale && <span className="badge bg-rose text-white">Promo</span>}
        </div>
      </Link>
      <div className="flex flex-1 flex-col gap-2 p-3">
        <Link to={`/produit/${product.slug}`} className="line-clamp-2 text-sm font-medium leading-snug hover:text-gold-dark">
          {product.name}
        </Link>
        <Price price={product.price} compareAt={product.compareAtPrice} className="text-sm" />
        <div className="mt-auto flex items-center justify-between gap-2">
          <AvailabilityBadge availability={product.availability} leadTimeDays={product.leadTimeDays} />
          {canQuickAdd && (
            <button
              className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gold-gradient text-white shadow-soft transition hover:brightness-95"
              aria-label={`Ajouter ${product.name} au panier`}
              onClick={() => {
                add({
                  variantId: product.defaultVariantId!,
                  productId: product.id,
                  slug: product.slug,
                  name: product.name,
                  variantName: null,
                  imageUrl: img?.thumbUrl ?? null,
                  unitPrice: product.price,
                  quantity: 1,
                  itemType: product.availability === AvailabilityMode.IN_STOCK ? ItemType.LOCAL : ItemType.PREORDER,
                  leadTimeDays: product.leadTimeDays,
                  maxQuantity: null,
                });
                notify('Ajouté au panier');
              }}
            >
              <ShoppingBag className="h-4 w-4" />
            </button>
          )}
        </div>
      </div>
    </article>
  );
}

export function ProductGridSkeleton({ count = 8 }: { count?: number }) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4">
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="card overflow-hidden">
          <Skeleton className="aspect-[3/4] rounded-none" />
          <div className="space-y-2 p-3">
            <Skeleton className="h-4 w-3/4" />
            <Skeleton className="h-4 w-1/3" />
          </div>
        </div>
      ))}
    </div>
  );
}

export function ProductGrid({ products }: { products: ProductCardDto[] }) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4">
      {products.map((p) => (
        <ProductCard key={p.id} product={p} />
      ))}
    </div>
  );
}
