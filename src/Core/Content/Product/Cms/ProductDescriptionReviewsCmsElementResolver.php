<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\Cms;

use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\EntityResolverContext;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Cms\SalesChannel\Struct\ProductDescriptionReviewsStruct;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Product\Aggregate\ProductDocument\ProductDocumentEntity;
use Shopware\Core\Content\Product\SalesChannel\Review\AbstractProductReviewLoader;
use Shopware\Core\Content\Product\SalesChannel\Review\ProductReviewsWidgetLoadedHook;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Adapter\Request\RequestParamHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Script\Execution\ScriptExecutor;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[Package('discovery')]
class ProductDescriptionReviewsCmsElementResolver extends AbstractProductDetailCmsElementResolver
{
    final public const TYPE = 'product-description-reviews';

    /**
     * @internal
     *
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    public function __construct(
        private readonly AbstractProductReviewLoader $productReviewLoader,
        private readonly ScriptExecutor $scriptExecutor,
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $mediaRepository,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $criteriaCollection = parent::collect($slot, $resolverContext);

        // the product page loads the documents of a product mapped from the page
        foreach ($criteriaCollection ?? [] as $criteriaList) {
            foreach ($criteriaList as $criteria) {
                $criteria->getAssociation('documents')->addSorting(new FieldSorting('position'));
            }
        }

        return $criteriaCollection;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $data = new ProductDescriptionReviewsStruct();
        $slot->setData($data);

        $productConfig = $slot->getFieldConfig()->get('product');
        if ($productConfig === null) {
            return;
        }

        $request = $resolverContext->getRequest();
        $ratingSuccess = (bool) RequestParamHelper::get($request, 'success', false);
        $data->setRatingSuccess($ratingSuccess);

        $product = null;

        if ($productConfig->isMapped() && $resolverContext instanceof EntityResolverContext) {
            $product = $this->resolveEntityValue($resolverContext->getEntity(), $productConfig->getStringValue());
        }

        if ($productConfig->isStatic()) {
            $product = $this->getSlotProduct($slot, $result, $productConfig->getStringValue());
        }

        if (!$product instanceof SalesChannelProductEntity) {
            // product can not be resolved, so we do not enrich the slot
            return;
        }

        $data->setProduct($product);
        $this->addDocuments($data, $product, $resolverContext->getSalesChannelContext()->getContext());

        if ($this->systemConfigService->getBool('core.listing.showReview', $resolverContext->getSalesChannelContext()->getSalesChannelId())) {
            $reviews = $this->productReviewLoader->load($request, $resolverContext->getSalesChannelContext(), $product->getId(), $product->getParentId());

            $this->scriptExecutor->execute(new ProductReviewsWidgetLoadedHook($reviews, $resolverContext->getSalesChannelContext()));

            $data->setReviews($reviews);
        }
    }

    /**
     * Adds the files of the product's documents in one search.
     * Each document gets the file of the first language of the context's language chain that has one (`translated.mediaId`).
     * Documents without a public file are left out.
     */
    private function addDocuments(ProductDescriptionReviewsStruct $data, SalesChannelProductEntity $product, Context $context): void
    {
        $documents = $product->getDocuments();
        if ($documents === null) {
            return;
        }

        $mediaIds = [];
        foreach ($documents as $document) {
            $mediaId = $document->getTranslation('mediaId');
            if (\is_string($mediaId)) {
                $mediaIds[] = $mediaId;
            }
        }

        if ($mediaIds === []) {
            return;
        }

        $mediaCriteria = new Criteria(array_values(array_unique($mediaIds)));
        $mediaCriteria->setTitle('cms::product-description-reviews-document-media');
        $mediaCriteria->addFilter(new EqualsFilter('private', false));

        $media = $this->mediaRepository->search($mediaCriteria, $context)->getEntities();

        $data->setDocuments($documents->filter(
            static fn (ProductDocumentEntity $document): bool => \is_string($mediaId = $document->getTranslation('mediaId')) && $media->has($mediaId)
        ));
        $data->setDocumentMedia($media);
    }
}
