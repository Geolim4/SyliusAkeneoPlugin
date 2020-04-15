<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Filter;

use Akeneo\Pim\ApiClient\Search\Operator;
use Akeneo\Pim\ApiClient\Search\SearchBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Locale\Model\LocaleInterface;
use Synolia\SyliusAkeneoPlugin\Entity\ProductFiltersRules;
use Synolia\SyliusAkeneoPlugin\Form\Type\ProductFiltersRulesType;
use Synolia\SyliusAkeneoPlugin\Payload\ProductModel\ProductModelPayload;

final class ProductFilter
{
    private const AT_LEAST_COMPLETE = 'AT LEAST COMPLETE';

    private const ALL_COMPLETE = 'ALL COMPLETE';

    private const FULL_COMPLETE = '100';

    private const API_DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const AVAILABLE_PRODUCT_MODEL_QUERIES = [
        'updated' => [],
        'completeness' => [],
        'categories' => [],
        'family' => [],
        'created' => [],
    ];

    /** @var EntityRepository */
    private $productFiltersRulesRepository;

    /** @var EntityRepository */
    private $localeRepository;

    public function __construct(EntityRepository $productFiltersRulesRepository, EntityRepository $localeRepository)
    {
        $this->productFiltersRulesRepository = $productFiltersRulesRepository;
        $this->localeRepository = $localeRepository;
    }

    public function getProductModelFilters(ProductModelPayload $payload): array
    {
        /** @var ProductFiltersRules $productFilterRules */
        $productFilterRules = $this->productFiltersRulesRepository->findOneBy([]);
        if (!$productFilterRules instanceof ProductFiltersRules) {
            return [];
        }

        $queryParameters = [];
        if ($productFilterRules->getMode() === ProductFiltersRulesType::PRODUCT_FILTER_MODE_SIMPLE) {
            $queryParameters = new SearchBuilder();

            $queryParameters = $this->getUpdatedFilter($productFilterRules, $queryParameters);

            $locales = $this->getLocales($payload);
            $completeness = self::AT_LEAST_COMPLETE;
            if ($productFilterRules->getCompletenessValue() === self::FULL_COMPLETE) {
                $completeness = self::ALL_COMPLETE;
            }
            $this->getCompletenessFilter($productFilterRules, $queryParameters, $locales, $completeness);

            $queryParameters = $this->getFamiliesFilter($productFilterRules, $queryParameters);
            $queryParameters = $queryParameters->getFilters();
        }

        if ($productFilterRules->getMode() === ProductFiltersRulesType::PRODUCT_FILTER_MODE_ADVANCED && !empty($productFilterRules->getAdvancedFilter())) {
            $query = json_decode($productFilterRules->getAdvancedFilter(), true);
            $invalideArrayKey = array_diff_key($query, self::AVAILABLE_PRODUCT_MODEL_QUERIES);
            foreach (array_keys($invalideArrayKey) as $value) {
                unset($query[$value]);
            }

            if (isset($query['completeness'][0]['value'])) {
                $query['completeness'][0]['operator'] = self::AT_LEAST_COMPLETE;
                if ($query['completeness'][0]['value'] === self::FULL_COMPLETE) {
                    $query['completeness'][0]['operator'] = self::ALL_COMPLETE;
                }
                unset($query['completeness'][0]['value']);
            }

            return $query;
        }

        return $queryParameters;
    }

    public function getProductFilters(ProductModelPayload $payload): array
    {
        /** @var ProductFiltersRules $productFilterRules */
        $productFilterRules = $this->productFiltersRulesRepository->findOneBy([]);
        if (!$productFilterRules instanceof ProductFiltersRules) {
            return [];
        }

        $queryParameters = [];
        if ($productFilterRules->getMode() === ProductFiltersRulesType::PRODUCT_FILTER_MODE_SIMPLE) {
            $queryParameters = new SearchBuilder();

            $queryParameters = $this->getUpdatedFilter($productFilterRules, $queryParameters);

            $locales = $this->getLocales($payload);

            $this->getCompletenessFilter(
                $productFilterRules,
                $queryParameters,
                $locales,
                $productFilterRules->getCompletenessType(),
                $productFilterRules->getCompletenessValue()
            );
            $queryParameters = $this->getFamiliesFilter($productFilterRules, $queryParameters);
            $queryParameters = $queryParameters->getFilters();
        }

        if ($productFilterRules->getMode() === ProductFiltersRulesType::PRODUCT_FILTER_MODE_ADVANCED && !empty($productFilterRules->getAdvancedFilter())) {
            $queryParameters = json_decode($productFilterRules->getAdvancedFilter(), true);
        }

        return $queryParameters;
    }

    private function getLocales(ProductModelPayload $payload): array
    {
        $apiLocales = $payload->getAkeneoPimClient()->getLocaleApi()->all();
        $resultLocales = $this->localeRepository->findAll();

        if (empty($apiLocales) || empty($resultLocales)) {
            return [];
        }

        $locales = [];
        /** @var LocaleInterface $locale */
        foreach ($resultLocales as $locale) {
            $locales[] = $locale->getCode();
        }

        $localesCode = [];
        foreach ($apiLocales as $apiLocale) {
            if (!in_array($apiLocale['code'], $locales) || $apiLocale['enabled'] === false) {
                continue;
            }
            $localesCode[] = $apiLocale['code'];
        }

        return $localesCode;
    }

    private function getUpdatedFilter(ProductFiltersRules $productFilterRules, SearchBuilder $queryParameters): SearchBuilder
    {
        $updatedMode = $productFilterRules->getUpdatedMode();
        if ($updatedMode === Operator::GREATER_THAN) {
            $queryParameters->addFilter(
                'updated',
                $updatedMode,
                $productFilterRules->getUpdatedAfter()->format(self::API_DATETIME_FORMAT)
            );
        }
        if ($updatedMode === Operator::LOWER_THAN) {
            $queryParameters->addFilter(
                'updated',
                $updatedMode,
                $productFilterRules->getUpdatedBefore()->format(self::API_DATETIME_FORMAT)
            );
        }
        if ($updatedMode === Operator::BETWEEN) {
            $queryParameters->addFilter(
                'updated',
                $updatedMode,
                [
                    $productFilterRules->getUpdatedBefore()->format(self::API_DATETIME_FORMAT),
                    $productFilterRules->getUpdatedAfter()->format(self::API_DATETIME_FORMAT),
                ]
            );
        }
        if ($updatedMode === Operator::SINCE_LAST_N_DAYS) {
            $queryParameters->addFilter(
                'updated',
                $updatedMode,
                $productFilterRules->getUpdated()
            );
        }

        return $queryParameters;
    }

    private function getFamiliesFilter(ProductFiltersRules $productFilterRules, SearchBuilder $queryParameters): SearchBuilder
    {
        if (empty($productFilterRules->getFamilies())) {
            return $queryParameters;
        }

        return $queryParameters->addFilter(
            'family',
            Operator::IN,
            $productFilterRules->getFamilies()
        );
    }

    private function getCompletenessFilter(
        ProductFiltersRules $productFilterRules,
        SearchBuilder $queryParameters,
        array $locales,
        string $completeness = null,
        string $completenessValue = null
    ): SearchBuilder {
        $completenessType = $productFilterRules->getCompletenessType();

        if ($completenessType !== null && !empty($locales)) {
            if ($completeness !== null) {
                $queryParameters->addFilter(
                    'completeness',
                    $completeness,
                    $completenessValue,
                    [
                        'locales' => $locales,
                        //TODO dynamic scope
                        'scope' => 'ecommerce',
                    ]
                );
            }
            if (in_array($completenessType, [
                Operator::LOWER_THAN_ON_ALL_LOCALES,
                Operator::GREATER_THAN_ON_ALL_LOCALES,
                Operator::LOWER_OR_EQUALS_THAN_ON_ALL_LOCALES,
                Operator::GREATER_OR_EQUALS_THAN_ON_ALL_LOCALES,
            ])) {
                $queryParameters->addFilter(
                    'completeness',
                    $completenessType,
                    $completenessValue,
                    [
                        'locales' => $productFilterRules->getLocales(),
                        //TODO dynamic scope
                        'scope' => 'ecommerce',
                    ]
                );
            }
        }

        return $queryParameters;
    }
}