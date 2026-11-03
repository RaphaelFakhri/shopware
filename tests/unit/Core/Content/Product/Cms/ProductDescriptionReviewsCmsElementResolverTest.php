<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Product\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\FieldConfig;
use Shopware\Core\Content\Cms\DataResolver\FieldConfigCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Cms\SalesChannel\Struct\ProductDescriptionReviewsStruct;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductDocument\ProductDocumentCollection;
use Shopware\Core\Content\Product\Aggregate\ProductDocument\ProductDocumentEntity;
use Shopware\Core\Content\Product\Cms\ProductDescriptionReviewsCmsElementResolver;
use Shopware\Core\Content\Product\SalesChannel\Review\AbstractProductReviewRoute;
use Shopware\Core\Content\Product\SalesChannel\Review\ProductReviewLoader;
use Shopware\Core\Content\Product\SalesChannel\Review\ProductReviewResult;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Script\Execution\ScriptExecutor;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[Package('discovery')]
#[CoversClass(ProductDescriptionReviewsCmsElementResolver::class)]
class ProductDescriptionReviewsCmsElementResolverTest extends TestCase
{
    private StaticSystemConfigService $systemConfigService;

    protected function setUp(): void
    {
        $this->systemConfigService = new StaticSystemConfigService([
            'core.listing.showReview' => true,
        ]);
    }

    public function testGetType(): void
    {
        $resolver = $this->getResolver();

        static::assertSame('product-description-reviews', $resolver->getType());
    }

    public function testCollect(): void
    {
        $resolverContext = new ResolverContext(static::createStub(SalesChannelContext::class), new Request());

        $slot = new CmsSlotEntity();
        $slot->setUniqueIdentifier('id');
        $slot->setType(ProductDescriptionReviewsCmsElementResolver::TYPE);

        $collection = $this->getResolver()->collect($slot, $resolverContext);

        static::assertNull($collection);
    }

    public function testEnrichSlotWithProductDescriptionReviews(): void
    {
        // global setting enabled
        $this->systemConfigService->set('core.listing.showReview', true);

        $resolver = $this->getResolver();

        $context = new ResolverContext(Generator::generateSalesChannelContext(), new Request([
            'success' => true,
        ]));

        $productId = 'product-1';
        $config = new FieldConfigCollection([
            new FieldConfig('product', FieldConfig::SOURCE_STATIC, $productId),
        ]);

        $slot = new CmsSlotEntity();
        $slot->setId('slot-1');
        $slot->setFieldConfig($config);

        $product = new SalesChannelProductEntity();
        $product->setId($productId);

        $result = static::createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new SalesChannelProductCollection([$product]));

        $data = new ElementDataCollection();
        $data->add('product_slot-1', $result);

        $resolver->enrich($slot, $context, $data);

        $data = $slot->getData();
        static::assertInstanceOf(ProductDescriptionReviewsStruct::class, $data);
        static::assertTrue($data->getRatingSuccess());

        $reviews = $data->getReviews();
        static::assertInstanceOf(ProductReviewResult::class, $reviews);
        static::assertSame($product, $data->getProduct());
        static::assertSame($productId, $reviews->getProductId());
    }

    public function testEnrichSlotWithProductDescriptionReviewsForCurrentSalesChannel(): void
    {
        $resolver = $this->getResolver();

        $context = Generator::generateSalesChannelContext();

        // global setting disabled
        $this->systemConfigService->set('core.listing.showReview', false);
        // but enabled for current sales channel
        $this->systemConfigService->set('core.listing.showReview', true, $context->getSalesChannelId());

        $context = new ResolverContext($context, new Request([
            'success' => true,
        ]));

        $productId = 'product-1';
        $config = new FieldConfigCollection([
            new FieldConfig('product', FieldConfig::SOURCE_STATIC, $productId),
        ]);

        $slot = new CmsSlotEntity();
        $slot->setId('slot-1');
        $slot->setFieldConfig($config);

        $product = new SalesChannelProductEntity();
        $product->setId($productId);

        $result = static::createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new SalesChannelProductCollection([$product]));

        $data = new ElementDataCollection();
        $data->add('product_slot-1', $result);

        $resolver->enrich($slot, $context, $data);

        $data = $slot->getData();
        static::assertInstanceOf(ProductDescriptionReviewsStruct::class, $data);
        static::assertTrue($data->getRatingSuccess());

        $reviews = $data->getReviews();
        static::assertInstanceOf(ProductReviewResult::class, $reviews);
        static::assertSame($product, $data->getProduct());
        static::assertSame($productId, $reviews->getProductId());
    }

    public function testEnrichSlotWithProductReviewsDisabled(): void
    {
        $resolver = $this->getResolver();

        $this->systemConfigService->set('core.listing.showReview', false);

        $context = new ResolverContext(Generator::generateSalesChannelContext(), new Request([
            'success' => true,
        ]));

        $productId = 'product-1';
        $config = new FieldConfigCollection([
            new FieldConfig('product', FieldConfig::SOURCE_STATIC, $productId),
        ]);

        $slot = new CmsSlotEntity();
        $slot->setId('slot-1');
        $slot->setFieldConfig($config);

        $product = new SalesChannelProductEntity();
        $product->setId($productId);

        $result = static::createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new SalesChannelProductCollection([$product]));

        $data = new ElementDataCollection();
        $data->add('product_slot-1', $result);

        $resolver->enrich($slot, $context, $data);

        $data = $slot->getData();
        static::assertInstanceOf(ProductDescriptionReviewsStruct::class, $data);
        static::assertTrue($data->getRatingSuccess());

        static::assertNull($data->getReviews());
        static::assertSame($product, $data->getProduct());
    }

    public function testEnrichSlotWithProductReviewsDisabledForCurrentSalesChannel(): void
    {
        $resolver = $this->getResolver();

        $salesChannelContext = Generator::generateSalesChannelContext();
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        $context = new ResolverContext($salesChannelContext, new Request([
            'success' => true,
        ]));

        // global setting enabled
        $this->systemConfigService->set('core.listing.showReview', true);
        // but disabled for current sales channel
        $this->systemConfigService->set('core.listing.showReview', false, $salesChannelId);

        $productId = 'product-1';
        $config = new FieldConfigCollection([
            new FieldConfig('product', FieldConfig::SOURCE_STATIC, $productId),
        ]);

        $slot = new CmsSlotEntity();
        $slot->setId('slot-1');
        $slot->setFieldConfig($config);

        $product = new SalesChannelProductEntity();
        $product->setId($productId);

        $result = static::createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new SalesChannelProductCollection([$product]));

        $data = new ElementDataCollection();
        $data->add('product_slot-1', $result);

        $resolver->enrich($slot, $context, $data);

        $data = $slot->getData();
        static::assertInstanceOf(ProductDescriptionReviewsStruct::class, $data);
        static::assertTrue($data->getRatingSuccess());

        static::assertNull($data->getReviews());
        static::assertSame($product, $data->getProduct());
    }

    public function testEnrichSetsEmptyDataWithoutConfig(): void
    {
        $resolver = $this->getResolver();

        $context = new ResolverContext(Generator::generateSalesChannelContext(), new Request());

        $slot = new CmsSlotEntity();
        $slot->setId('slot-1');

        $data = new ElementDataCollection();

        $resolver->enrich($slot, $context, $data);

        $data = $slot->getData();
        static::assertInstanceOf(ProductDescriptionReviewsStruct::class, $data);
        static::assertNull($data->getReviews());
        static::assertNull($data->getProduct());
    }

    public function testCollectLoadsTheDocumentsOfAStaticProduct(): void
    {
        $slot = new CmsSlotEntity();
        $slot->setUniqueIdentifier('id');
        $slot->setType(ProductDescriptionReviewsCmsElementResolver::TYPE);
        $slot->setFieldConfig(new FieldConfigCollection([
            new FieldConfig('product', FieldConfig::SOURCE_STATIC, 'product-1'),
        ]));

        $collection = $this->getResolver()->collect(
            $slot,
            new ResolverContext(static::createStub(SalesChannelContext::class), new Request())
        );

        static::assertNotNull($collection);
        $criteria = $collection->all()[SalesChannelProductDefinition::class]['product_id'];
        static::assertTrue($criteria->hasAssociation('documents'));
        static::assertEquals([new FieldSorting('position')], $criteria->getAssociation('documents')->getSorting());
    }

    public function testEnrichAddsTheDocumentsWithAPublicFile(): void
    {
        $documentWithFile = $this->createDocument('document-1', 'media-1');
        $documentWithPrivateFile = $this->createDocument('document-2', 'media-2');
        $documentWithoutFile = $this->createDocument('document-3', null);

        $product = $this->createProduct();
        $product->setDocuments(new ProductDocumentCollection([$documentWithFile, $documentWithPrivateFile, $documentWithoutFile]));

        $mediaRepository = new StaticEntityRepository([new MediaCollection([$this->createMedia('media-1')])]);

        $data = $this->enrichWithProduct($product, $mediaRepository);

        static::assertSame([$documentWithFile], array_values($data->getDocuments()?->getElements() ?? []));
        static::assertSame(['media-1'], array_values($data->getDocumentMedia()?->getIds() ?? []));

        $mediaCriteria = $mediaRepository->searchedCriteria[0];
        static::assertSame(['media-1', 'media-2'], $mediaCriteria->getIds());
        static::assertEquals([new EqualsFilter('private', false)], $mediaCriteria->getFilters());
    }

    public function testEnrichSkipsTheMediaSearchWithoutDocuments(): void
    {
        $product = $this->createProduct();
        $product->setDocuments(new ProductDocumentCollection());

        $mediaRepository = new StaticEntityRepository([]);

        $data = $this->enrichWithProduct($product, $mediaRepository);

        static::assertNull($data->getDocuments());
        static::assertNull($data->getDocumentMedia());
        static::assertSame([], $mediaRepository->searchedCriteria);
    }

    /**
     * @param StaticEntityRepository<MediaCollection> $mediaRepository
     */
    private function enrichWithProduct(SalesChannelProductEntity $product, StaticEntityRepository $mediaRepository): ProductDescriptionReviewsStruct
    {
        $this->systemConfigService->set('core.listing.showReview', false);

        $slot = new CmsSlotEntity();
        $slot->setId('slot-1');
        $slot->setFieldConfig(new FieldConfigCollection([
            new FieldConfig('product', FieldConfig::SOURCE_STATIC, $product->getId()),
        ]));

        $result = static::createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new SalesChannelProductCollection([$product]));

        $elementData = new ElementDataCollection();
        $elementData->add('product_slot-1', $result);

        $this->getResolver($mediaRepository)->enrich(
            $slot,
            new ResolverContext(Generator::generateSalesChannelContext(), new Request()),
            $elementData
        );

        $data = $slot->getData();
        static::assertInstanceOf(ProductDescriptionReviewsStruct::class, $data);

        return $data;
    }

    private function createProduct(): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setId('product-1');

        return $product;
    }

    private function createDocument(string $id, ?string $mediaId): ProductDocumentEntity
    {
        $document = new ProductDocumentEntity();
        $document->setId($id);
        $document->setTranslated(['mediaId' => $mediaId]);

        return $document;
    }

    private function createMedia(string $id): MediaEntity
    {
        $media = new MediaEntity();
        $media->setId($id);

        return $media;
    }

    /**
     * @param StaticEntityRepository<MediaCollection>|null $mediaRepository
     */
    private function getResolver(?StaticEntityRepository $mediaRepository = null): ProductDescriptionReviewsCmsElementResolver
    {
        $productReviewLoader = new ProductReviewLoader(
            static::createStub(AbstractProductReviewRoute::class),
            $this->systemConfigService,
            new EventDispatcher()
        );

        $scriptExecutor = static::createStub(ScriptExecutor::class);

        return new ProductDescriptionReviewsCmsElementResolver(
            $productReviewLoader,
            $scriptExecutor,
            $this->systemConfigService,
            $mediaRepository ?? new StaticEntityRepository([]),
        );
    }
}
