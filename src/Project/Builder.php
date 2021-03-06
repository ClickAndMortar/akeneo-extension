<?php

namespace Pim\Bundle\TextmasterBundle\Project;

use Akeneo\Pim\Structure\Bundle\Doctrine\ORM\Repository\AttributeRepository;
use Akeneo\Tool\Component\StorageUtils\Detacher\ObjectDetacherInterface;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Pim\Bundle\TextmasterBundle\Project\Exception\RuntimeException;
use Akeneo\Pim\Structure\Component\AttributeTypes;
use Akeneo\Pim\Structure\Component\Model\AttributeInterface;
use Akeneo\Pim\Enrichment\Component\Product\Model\EntityWithValuesInterface;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductInterface;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductModelInterface;
use Akeneo\Pim\Enrichment\Component\Product\Model\ValueInterface;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Textmaster\Model\DocumentInterface;
use Doctrine\Common\Util\ClassUtils;

/**
 * TextMaster builder.
 * Can build project and document payload from PIM data
 *
 * @author    Jean-Marie Leroux <jean-marie.leroux@akeneo.com>
 * @copyright 2016 TextMaster.com (https://textmaster.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Builder implements BuilderInterface
{
    /** @var array */
    protected $options = [];

    /** @var ConfigManager */
    protected $configManager;

    /** @var ObjectDetacherInterface */
    protected $objectDetacher;

    /** @var LoggerInterface */
    protected $logger;

    /**@var array */
    protected $textmasterAttributes;

    /**@var array */
    protected $validAttribute;

    /** @var array */
    protected $availableAttributes = [];

    /** @var Container */
    protected $container;

    /** @var AttributeRepository */
    protected $attributeRepository;

    /** @var array */
    protected $attributes = [];

    /**
     * Builder constructor.
     *
     * @param ConfigManager           $configManager
     * @param ObjectDetacherInterface $objectDetacher
     * @param LoggerInterface         $logger
     * @param Container               $container
     *
     * @throws \Exception
     */
    public function __construct(
        ConfigManager $configManager,
        ObjectDetacherInterface $objectDetacher,
        LoggerInterface $logger,
        Container $container
    ) {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $this->options              = $resolver->resolve([]);
        $this->configManager        = $configManager;
        $this->objectDetacher       = $objectDetacher;
        $this->logger               = $logger;
        $this->container            = $container;
        $this->attributeRepository  = $this->container->get('pim_catalog.repository.attribute');
    }

    /**
     * @inheritdoc
     */
    public function createProjectData(ProjectInterface $project)
    {
        $data = [
            'name'            => $project->getName(),
            'api_template_id' => $project->getApiTemplateId(),
        ];

        $this->logger->debug(sprintf('Create project data: %s', json_encode($data)));

        return $data;
    }

    /**
     * @inheritdoc
     */
    public function createDocumentData($product, $localeCode)
    {
        $docData = $this->getProductValuesTitle($product);

        $originalContent = [];
        $wysiwyg         = false;

        /** @var ValueInterface $productValue */
        foreach ($docData['product_values'] as $productValue) {
            $code = $productValue->getAttributeCode();
            $this->getAttributeByCode($code);
            $attribute = $this->attributes[$code];

            if (
                $this->isValidForTranslation($attribute)
                && $localeCode === $productValue->getLocaleCode()
            ) {
                $key            = $this->createProductValueKey($productValue);
                $originalPhrase = trim($productValue->getData());
                if ($attribute->isWysiwygEnabled()) {
                    $wysiwyg = true;
                }
                if (!empty($originalPhrase)) {
                    $originalContent[$key]['original_phrase'] = $originalPhrase;
                }
            }
        }

        $documentData = [
            'title'              => $docData['title'],
            'original_content'   => $originalContent,
            'perform_word_count' => true,
            'type'               => DocumentInterface::TYPE_KEY_VALUE,
            'markup_in_content'  => $wysiwyg,
        ];

        if (empty($originalContent)) {
            return null;
        }

        $this->logger->debug(sprintf('Create document data: %s', json_encode($documentData)));

        return $documentData;
    }


    /**
     * getProductValuesTitle
     *
     * @param EntityWithValuesInterface $product
     *
     * @return array
     * @throws \Exception
     */
    private function getProductValuesTitle(EntityWithValuesInterface $product): array
    {
        if ($product instanceof ProductInterface) {
            $title = $product->getIdentifier();
        } elseif ($product instanceof ProductModel) {
            $title = sprintf('product_model|%s', $product->getCode());
        } else {
            throw new \Exception(
                sprintf(
                    'Processed item must implement ProductInterface or Product Model, %s given',
                    ClassUtils::getClass($product)
                )
            );
        }

        $productValues       = [];
        $availableAttributes = $this->getAvailableAttributes($product);

        /** @var ValueInterface $productValue */
        foreach ($product->getValues() as $productValue) {
            #if (\in_array($productValue->getAttribute()->getCode(), $availableAttributes)) {
            if (\in_array($productValue->getAttributeCode(), $availableAttributes)) {
                $productValues[] = $productValue;
            }
        }

        return ['product_values' => $productValues, 'title' => $title];
    }

    /**
     * Retrieve available attribute codes.
     *
     * @param EntityWithValuesInterface $product
     *
     * @return array
     */
    protected function getAvailableAttributes(EntityWithValuesInterface $product): array
    {
        $availableAttributes = array_intersect($this->getTextmasterAttributes(), $product->getUsedAttributeCodes());

        if ($product instanceof ProductModelInterface) {
            $familyVariantCode = $product->getFamilyVariant()->getCode();

            if (0 === $product->getLevel()) {
                $this->availableAttributes[$familyVariantCode] = $product->getUsedAttributeCodes();
            }


            if (1 === $product->getLevel()) {
                if (!isset($this->availableAttributes[$familyVariantCode])) {
                    $this->availableAttributes[$familyVariantCode] = $this->getAvailableAttributes(
                        $product->getParent()
                    );

                    $this->objectDetacher->detach($product->getParent());
                }

                $availableAttributes = array_diff(
                    $availableAttributes,
                    $this->availableAttributes[$familyVariantCode]
                );
            }
        }

        return $availableAttributes;
    }

    /**
     * Retrieve available attributes from product.
     *
     * @param EntityWithValuesInterface $product
     *
     * @return array
     */
    protected function getAvailableAttributesFromProduct(EntityWithValuesInterface $product)
    {
        return array_intersect($this->getTextmasterAttributes(), $product->getUsedAttributeCodes());
    }


    /**
     * Create the document key for a product value
     *
     * @param ValueInterface $productValue
     *
     * @return string
     */
    public function createProductValueKey(ValueInterface $productValue): string
    {
        $attributeCode = $productValue->getAttributeCode();

        if ($productValue->isScopable()) {
            $attributeCode = sprintf('%s-%s', $attributeCode, $productValue->getScopeCode());
        }

        return $attributeCode;
    }


    /**
     * getTextmasterAttributes
     *
     * @return string[]
     */
    protected function getTextmasterAttributes(): array
    {
        if (null === $this->textmasterAttributes) {
            $this->textmasterAttributes = explode(',', $this->configManager->get('pim_textmaster.attributes'));

            if (empty($this->textmasterAttributes)) {
                throw new RuntimeException('No attributes configured for translation');
            }
        }

        return $this->textmasterAttributes;
    }


    /**
     * isValidForTranslation
     *
     * @param AttributeInterface $attribute
     *
     * @return bool
     */
    protected function isValidForTranslation(AttributeInterface $attribute): bool
    {
        if (!\in_array($attribute->getCode(), $this->getTextmasterAttributes())) {
            return false;
        }

        $isText = AttributeTypes::TEXT === $attribute->getType()
            || AttributeTypes::TEXTAREA === $attribute->getType();

        return $isText && $attribute->isLocalizable();
    }

    /**
     * @param OptionsResolver $resolver
     */
    protected function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            [
                'ctype' => 'translation',
            ]
        );
    }

    /**
     * getAttributeByCode
     *
     * @param string $code
     */
    protected function getAttributeByCode($code) {
        if (!array_key_exists($code, $this->attributes)) {
            $this->attributes[$code] = $this->attributeRepository->findOneByIdentifier($code);
        }
    }
}
