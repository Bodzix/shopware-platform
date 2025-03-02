<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Elasticsearch\Framework\DataAbstractionLayer;

use OpenSearchDSL\Aggregation\Bucketing\CompositeAggregation;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Storage\AbstractKeyValueStorage;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\SuffixFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\Feature;
use Shopware\Core\System\CustomField\CustomFieldService;
use Shopware\Elasticsearch\Framework\DataAbstractionLayer\CriteriaParser;
use Shopware\Tests\Unit\Common\Stubs\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 *
 * @covers \Shopware\Elasticsearch\Framework\DataAbstractionLayer\CriteriaParser
 */
class CriteriaParserTest extends TestCase
{
    private const SECOND_LANGUAGE = 'd5da80fc94874ea988eac8abdea44e0a';

    public function testAggregationWithSorting(): void
    {
        $aggs = new TermsAggregation('foo', 'test', null, new FieldSorting('abc', FieldSorting::ASCENDING), new TermsAggregation('foo', 'foo2'));

        $definition = $this->getDefinition();

        /** @var CompositeAggregation $esAgg */
        $esAgg = (new CriteriaParser(
            new EntityDefinitionQueryHelper(),
            $this->createMock(CustomFieldService::class),
            $this->createMock(AbstractKeyValueStorage::class)
        ))->parseAggregation($aggs, $definition, Context::createDefaultContext());

        static::assertInstanceOf(CompositeAggregation::class, $esAgg);
        static::assertSame([
            'composite' => [
                'sources' => [
                    [
                        'foo.sorting' => [
                            'terms' => [
                                'field' => 'abc',
                                'order' => 'ASC',
                            ],
                        ],
                    ],
                    [
                        'foo.key' => [
                            'terms' => [
                                'field' => 'test',
                            ],
                        ],
                    ],
                ],
                'size' => 10000,
            ],
            'aggregations' => [
                'foo' => [
                    'terms' => [
                        'field' => 'foo2',
                        'size' => 10000,
                    ],
                ],
            ],
        ], $esAgg->toArray());
    }

    public function testParseAggregationWithTranslatedField(): void
    {
        $aggs = new TermsAggregation('byName', 'name');

        $definition = $this->getDefinition();

        $storage = $this->createMock(AbstractKeyValueStorage::class);
        $storage->method('get')->willReturn(true);

        $parser = new CriteriaParser(
            new EntityDefinitionQueryHelper(),
            $this->createMock(CustomFieldService::class),
            $storage
        );

        $esAgg = $parser->parseAggregation($aggs, $definition, Context::createDefaultContext());

        static::assertInstanceOf(\OpenSearchDSL\Aggregation\Bucketing\TermsAggregation::class, $esAgg);
        static::assertSame([
            'terms' => [
                'field' => 'name.' . Defaults::LANGUAGE_SYSTEM,
                'size' => 10000,
            ],
        ], $esAgg->toArray());
    }

    /**
     * @dataProvider parseFilterDataProvider
     *
     * @param array<mixed> $expectedEsFilter
     */
    public function testParseFilter(Filter $filter, array $expectedEsFilter): void
    {
        Feature::skipTestIfInActive('ES_MULTILINGUAL_INDEX', $this);

        $definition = $this->getDefinition();

        $storage = $this->createMock(AbstractKeyValueStorage::class);
        $storage->method('get')->willReturn(true);

        $parser = new CriteriaParser(
            new EntityDefinitionQueryHelper(),
            $this->createMock(CustomFieldService::class),
            $storage
        );

        $context = new Context(
            new SystemSource(),
            [],
            Defaults::CURRENCY,
            [self::SECOND_LANGUAGE, Defaults::LANGUAGE_SYSTEM]
        );

        $esFilter = $parser->parseFilter($filter, $definition, ProductDefinition::ENTITY_NAME, $context);
        static::assertSame($expectedEsFilter, $esFilter->toArray());
    }

    public function testParseUnsupportedFilter(): void
    {
        Feature::skipTestIfInActive('ES_MULTILINGUAL_INDEX', $this);

        static::expectException(\RuntimeException::class);
        static::expectExceptionMessage(sprintf('Unsupported filter %s', CustomFilter::class));
        $definition = $this->getDefinition();

        $storage = $this->createMock(AbstractKeyValueStorage::class);
        $storage->method('get')->willReturn(true);

        $parser = new CriteriaParser(new EntityDefinitionQueryHelper(), $this->createMock(CustomFieldService::class), $storage);

        $parser->parseFilter(new CustomFilter(), $definition, ProductDefinition::ENTITY_NAME, Context::createDefaultContext());
    }

    /**
     * @dataProvider accessorContextProvider
     */
    public function testBuildAccessor(string $field, Context $context, string $expectedAccessor): void
    {
        $definition = $this->getDefinition();
        $storage = $this->createMock(AbstractKeyValueStorage::class);
        $storage->method('get')->willReturn(true);

        $accessor = (new CriteriaParser(new EntityDefinitionQueryHelper(), $this->createMock(CustomFieldService::class), $storage))->buildAccessor($definition, $field, $context);

        static::assertSame($expectedAccessor, $accessor);
    }

    /**
     * @return iterable<string, Filter|array<mixed>>
     */
    public static function parseFilterDataProvider(): iterable
    {
        $now = '2023-06-12 05:36:22.000';

        yield 'NotFilter field' => [
            new NotFilter('AND', [
                new EqualsFilter('id', 'foo'),
                new EqualsFilter('productNumber', 'bar'),
            ]),
            [
                'bool' => [
                    'must_not' => [
                        [
                            'bool' => [
                                'must' => [
                                    [
                                        'term' => [
                                            'id' => 'foo',
                                        ],
                                    ],
                                    [
                                        'term' => [
                                            'productNumber' => 'bar',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        yield 'NotFilter translated field' => [
            new NotFilter('AND', [
                new EqualsFilter('name', 'foo'),
                new EqualsFilter('description', 'bar'),
            ]),
            [
                'bool' => [
                    'must_not' => [
                        [
                            'bool' => [
                                'must' => [
                                    [
                                        'multi_match' => [
                                            'query' => 'foo',
                                            'fields' => [
                                                'name.' . self::SECOND_LANGUAGE,
                                                'name.' . Defaults::LANGUAGE_SYSTEM,
                                            ],
                                            'type' => 'best_fields',
                                        ],
                                    ],
                                    [
                                        'multi_match' => [
                                            'query' => 'bar',
                                            'fields' => [
                                                'description.' . self::SECOND_LANGUAGE,
                                                'description.' . Defaults::LANGUAGE_SYSTEM,
                                            ],
                                            'type' => 'best_fields',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        yield 'MultiFilter field' => [
            new MultiFilter('AND', [
                new EqualsFilter('id', 'foo'),
                new EqualsFilter('productNumber', 'bar'),
            ]),
            [
                'bool' => [
                    'must' => [
                        [
                            'term' => [
                                'id' => 'foo',
                            ],
                        ],
                        [
                            'term' => [
                                'productNumber' => 'bar',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        yield 'MultiFilter translated field' => [
            new MultiFilter('AND', [
                new EqualsFilter('name', 'foo'),
                new EqualsFilter('description', 'bar'),
            ]),
            [
                'bool' => [
                    'must' => [
                        [
                            'multi_match' => [
                                'query' => 'foo',
                                'fields' => [
                                    'name.' . self::SECOND_LANGUAGE,
                                    'name.' . Defaults::LANGUAGE_SYSTEM,
                                ],
                                'type' => 'best_fields',
                            ],
                        ],
                        [
                            'multi_match' => [
                                'query' => 'bar',
                                'fields' => [
                                    'description.' . self::SECOND_LANGUAGE,
                                    'description.' . Defaults::LANGUAGE_SYSTEM,
                                ],
                                'type' => 'best_fields',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        yield 'EqualsFilter field' => [
            new EqualsFilter('productNumber', 'bar'),
            [
                'term' => [
                    'productNumber' => 'bar',
                ],
            ],
        ];
        yield 'EqualsFilter translated field' => [
            new EqualsFilter('name', 'foo'),
            [
                'multi_match' => [
                    'query' => 'foo',
                    'fields' => [
                        'name.' . self::SECOND_LANGUAGE,
                        'name.' . Defaults::LANGUAGE_SYSTEM,
                    ],
                    'type' => 'best_fields',
                ],
            ],
        ];
        yield 'EqualsAnyFilter field' => [
            new EqualsAnyFilter('productNumber', ['foo', 'bar']),
            [
                'terms' => [
                    'productNumber' => ['foo', 'bar'],
                ],
            ],
        ];
        yield 'EqualsAnyFilter translated field' => [
            new EqualsAnyFilter('name', ['foo', 'bar']),
            [
                'dis_max' => [
                    'queries' => [
                        [
                            'terms' => [
                                'name.' . self::SECOND_LANGUAGE => ['foo', 'bar'],
                            ],
                        ],
                        [
                            'terms' => [
                                'name.' . Defaults::LANGUAGE_SYSTEM => ['foo', 'bar'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        yield 'ContainsFilter field' => [
            new ContainsFilter('productNumber', 'foo'),
            [
                'wildcard' => [
                    'productNumber' => [
                        'value' => '*foo*',
                    ],
                ],
            ],
        ];
        yield 'ContainsFilter translated field' => [
            new ContainsFilter('name', 'foo'),
            [
                'dis_max' => [
                    'queries' => [
                        [
                            'wildcard' => [
                                'name.' . self::SECOND_LANGUAGE => [
                                    'value' => '*foo*',
                                ],
                            ],
                        ],
                        [
                            'wildcard' => [
                                'name.' . Defaults::LANGUAGE_SYSTEM => [
                                    'value' => '*foo*',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        yield 'PrefixFilter field' => [
            new PrefixFilter('productNumber', 'foo'),
            [
                'prefix' => [
                    'productNumber' => [
                        'value' => 'foo',
                    ],
                ],
            ],
        ];
        yield 'PrefixFilter translated field' => [
            new PrefixFilter('name', 'foo'),
            [
                'multi_match' => [
                    'query' => 'foo',
                    'fields' => [
                        'name.' . self::SECOND_LANGUAGE . '.search',
                        'name.' . Defaults::LANGUAGE_SYSTEM . '.search',
                    ],
                    'type' => 'phrase_prefix',
                    'slop' => 5,
                ],
            ],
        ];
        yield 'SuffixFilter field' => [
            new SuffixFilter('productNumber', 'foo'),
            [
                'wildcard' => [
                    'productNumber' => [
                        'value' => '*foo',
                    ],
                ],
            ],
        ];
        yield 'SuffixFilter translated field' => [
            new SuffixFilter('name', 'foo'),
            [
                'dis_max' => [
                    'queries' => [
                        [
                            'wildcard' => [
                                'name.' . self::SECOND_LANGUAGE => [
                                    'value' => '*foo',
                                ],
                            ],
                        ],
                        [
                            'wildcard' => [
                                'name.' . Defaults::LANGUAGE_SYSTEM => [
                                    'value' => '*foo',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        yield 'RangeFilter field' => [
            new RangeFilter('createdAt', [
                RangeFilter::GT => $now,
            ]),
            [
                'range' => [
                    'createdAt' => [
                        RangeFilter::GT => $now,
                    ],
                ],
            ],
        ];
        yield 'RangeFilter translated field' => [
            new RangeFilter('name', [
                RangeFilter::GT => $now,
            ]),
            [
                'dis_max' => [
                    'queries' => [
                        [
                            'range' => [
                                'name.' . self::SECOND_LANGUAGE => [
                                    RangeFilter::GT => $now,
                                ],
                            ],
                        ],
                        [
                            'range' => [
                                'name.' . Defaults::LANGUAGE_SYSTEM => [
                                    RangeFilter::GT => $now,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        yield 'MultiFilter with translated custom field' => [
            new MultiFilter('AND', [
                new EqualsFilter('customFields.foo', 'fooValue'),
                new EqualsFilter('customFields.bar', 'barValue'),
            ]),
            [
                'bool' => [
                    'must' => [
                        [
                            'multi_match' => [
                                'query' => 'fooValue',
                                'fields' => [
                                    'customFields.' . self::SECOND_LANGUAGE . '.foo',
                                    'customFields.' . Defaults::LANGUAGE_SYSTEM . '.foo',
                                ],
                                'type' => 'best_fields',
                            ],
                        ],
                        [
                            'multi_match' => [
                                'query' => 'barValue',
                                'fields' => [
                                    'customFields.' . self::SECOND_LANGUAGE . '.bar',
                                    'customFields.' . Defaults::LANGUAGE_SYSTEM . '.bar',
                                ],
                                'type' => 'best_fields',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return iterable<string, array{string, Context, string}>
     */
    public static function accessorContextProvider(): iterable
    {
        yield 'normal field' => [
            'foo',
            Context::createDefaultContext(),
            'foo',
        ];

        yield 'price, state from field: gross' => [
            'price.foo.gross',
            Context::createDefaultContext(),
            'price.foo.c_b7d2554b0ce847cd82f3ac9bd1c0dfca.gross',
        ];

        yield 'price, state from field: net' => [
            'price.foo.net',
            Context::createDefaultContext(),
            'price.foo.c_b7d2554b0ce847cd82f3ac9bd1c0dfca.net',
        ];

        yield 'price, state inherited from context: gross' => [
            'price.foo',
            Context::createDefaultContext(),
            'price.foo.c_b7d2554b0ce847cd82f3ac9bd1c0dfca.gross',
        ];

        $stateNet = Context::createDefaultContext();
        $stateNet->setTaxState(CartPrice::TAX_STATE_NET);

        yield 'price, state inherited from context: net' => [
            'price.foo',
            $stateNet,
            'price.foo.c_b7d2554b0ce847cd82f3ac9bd1c0dfca.net',
        ];
    }

    /**
     * @dataProvider providerCheapestPrice
     *
     * @param array<mixed> $expectedQuery
     */
    public function testCheapestPriceSorting(FieldSorting $sorting, array $expectedQuery, Context $context): void
    {
        $definition = $this->getDefinition();

        $storage = $this->createMock(AbstractKeyValueStorage::class);
        $storage->method('get')->willReturn(true);

        $sorting = (new CriteriaParser(
            new EntityDefinitionQueryHelper(),
            $this->createMock(CustomFieldService::class),
            $storage
        ))->parseSorting($sorting, $definition, $context);

        $script = $sorting->getParameter('script');

        static::assertSame($expectedQuery, $script);
    }

    /**
     * @dataProvider providerTranslatedField
     *
     * @param array<mixed> $expectedQuery
     */
    public function testTranslatedFieldSorting(FieldSorting $sorting, array $expectedQuery, bool $scriptSorting = true, ?Field $customField = null): void
    {
        Feature::skipTestIfInActive('ES_MULTILINGUAL_INDEX', $this);

        $definition = $this->getDefinition();

        $customFieldService = $this->createMock(CustomFieldService::class);

        if ($customField instanceof Field) {
            $customFieldService->expects(static::once())->method('getCustomField')->willReturn($customField);
        }

        $storage = $this->createMock(AbstractKeyValueStorage::class);
        $storage->method('get')->willReturn(true);

        $fieldSort = (new CriteriaParser(
            new EntityDefinitionQueryHelper(),
            $customFieldService,
            $storage
        ))->parseSorting($sorting, $definition, Context::createDefaultContext());

        if ($scriptSorting) {
            static::assertTrue($fieldSort->hasParameter('script'));
            $script = $fieldSort->getParameter('script');
            static::assertSame($expectedQuery, $script);

            return;
        }

        static::assertSame($sorting->getField(), $fieldSort->getField());
        static::assertSame($sorting->getDirection(), $fieldSort->getOrder());
        static::assertSame([], $fieldSort->getParameters());
    }

    /**
     * @return iterable<string, array{FieldSorting, array<mixed>, Context}>
     */
    public static function providerCheapestPrice(): iterable
    {
        yield 'default cheapest price' => [
            new FieldSorting('cheapestPrice', FieldSorting::ASCENDING),
            [
                'id' => 'cheapest_price',
                'params' => [
                    'accessors' => [
                        [
                            'key' => 'cheapest_price_ruledefault_currencyb7d2554b0ce847cd82f3ac9bd1c0dfca_gross',
                            'factor' => 1,
                        ],
                    ],
                    'decimals' => 100,
                    'round' => true,
                    'multiplier' => 100.0,
                ],
            ],
            Context::createDefaultContext(),
        ];

        yield 'default cheapest price/list price percentage' => [
            new FieldSorting('cheapestPrice.percentage', FieldSorting::ASCENDING),
            [
                'id' => 'cheapest_price_percentage',
                'params' => [
                    'accessors' => [
                        [
                            'key' => 'cheapest_price_ruledefault_currencyb7d2554b0ce847cd82f3ac9bd1c0dfca_gross_percentage',
                            'factor' => 1,
                        ],
                    ],
                ],
            ],
            Context::createDefaultContext(),
        ];

        $context = Context::createDefaultContext();
        $context->assign(['currencyId' => 'foo']);

        yield 'different currency cheapest price' => [
            new FieldSorting('cheapestPrice', FieldSorting::ASCENDING),
            [
                'id' => 'cheapest_price',
                'params' => [
                    'accessors' => [
                        [
                            'key' => 'cheapest_price_ruledefault_currencyfoo_gross',
                            'factor' => 1,
                        ],
                        [
                            'key' => 'cheapest_price_ruledefault_currencyb7d2554b0ce847cd82f3ac9bd1c0dfca_gross',
                            'factor' => 1.0,
                        ],
                    ],
                    'decimals' => 100,
                    'round' => true,
                    'multiplier' => 100.0,
                ],
            ],
            $context,
        ];

        yield 'different currency cheapest price/list price percentage' => [
            new FieldSorting('cheapestPrice.percentage', FieldSorting::ASCENDING),
            [
                'id' => 'cheapest_price_percentage',
                'params' => [
                    'accessors' => [
                        [
                            'key' => 'cheapest_price_ruledefault_currencyfoo_gross_percentage',
                            'factor' => 1,
                        ],
                        [
                            'key' => 'cheapest_price_ruledefault_currencyb7d2554b0ce847cd82f3ac9bd1c0dfca_gross_percentage',
                            'factor' => 1.0,
                        ],
                    ],
                ],
            ],
            $context,
        ];

        $context = Context::createDefaultContext();
        $context->getRounding()->setDecimals(3);

        yield 'default cheapest price: rounding with 3 decimals' => [
            new FieldSorting('cheapestPrice', FieldSorting::ASCENDING),
            [
                'id' => 'cheapest_price',
                'params' => [
                    'accessors' => [
                        [
                            'key' => 'cheapest_price_ruledefault_currencyb7d2554b0ce847cd82f3ac9bd1c0dfca_gross',
                            'factor' => 1,
                        ],
                    ],
                    'decimals' => 1000,
                    'round' => false,
                    'multiplier' => 100.0,
                ],
            ],
            $context,
        ];

        $context = Context::createDefaultContext();
        $context->assign(['taxState' => CartPrice::TAX_STATE_NET]);
        $context->getRounding()->setRoundForNet(false);

        yield 'default cheapest price: net rounding disabled' => [
            new FieldSorting('cheapestPrice', FieldSorting::ASCENDING),
            [
                'id' => 'cheapest_price',
                'params' => [
                    'accessors' => [
                        [
                            'key' => 'cheapest_price_ruledefault_currencyb7d2554b0ce847cd82f3ac9bd1c0dfca_net',
                            'factor' => 1,
                        ],
                    ],
                    'decimals' => 100,
                    'round' => false,
                    'multiplier' => 100.0,
                ],
            ],
            $context,
        ];
    }

    /**
     * @return iterable<string, array{FieldSorting, array{id: string, params: array{field: string, languages: list<string>}}, bool, ?Field}>
     */
    public static function providerTranslatedField(): iterable
    {
        yield 'non translated field' => [
            new FieldSorting('productNumber', FieldSorting::ASCENDING),
            [
                'id' => 'translated_field_sorting',
                'params' => [
                    'field' => 'name',
                    'languages' => [
                        Defaults::LANGUAGE_SYSTEM,
                    ],
                ],
            ],
            false,
            null,
        ];

        yield 'customFields translated field' => [
            new FieldSorting('customFields.foo', FieldSorting::ASCENDING),
            [
                'id' => 'translated_field_sorting',
                'params' => [
                    'field' => 'customFields',
                    'languages' => [
                        Defaults::LANGUAGE_SYSTEM,
                    ],
                    'suffix' => 'foo',
                ],
            ],
            true,
            new StringField('foo', 'foo'),
        ];

        yield 'customFields int translated field' => [
            new FieldSorting('customFields.foo', FieldSorting::ASCENDING),
            [
                'id' => 'numeric_translated_field_sorting',
                'params' => [
                    'field' => 'customFields',
                    'languages' => [
                        Defaults::LANGUAGE_SYSTEM,
                    ],
                    'suffix' => 'foo',
                    'order' => 'asc',
                ],
            ],
            true,
            new IntField('foo', 'foo'),
        ];

        yield 'customFields float translated field' => [
            new FieldSorting('customFields.foo', FieldSorting::ASCENDING),
            [
                'id' => 'numeric_translated_field_sorting',
                'params' => [
                    'field' => 'customFields',
                    'languages' => [
                        Defaults::LANGUAGE_SYSTEM,
                    ],
                    'suffix' => 'foo',
                    'order' => 'asc',
                ],
            ],
            true,
            new FloatField('foo', 'foo'),
        ];

        yield 'non nested translated field' => [
            new FieldSorting('product.name', FieldSorting::ASCENDING),
            [
                'id' => 'translated_field_sorting',
                'params' => [
                    'field' => 'name',
                    'languages' => [
                        Defaults::LANGUAGE_SYSTEM,
                    ],
                ],
            ],
            true,
            null,
        ];

        yield 'non translated field with root prefix' => [
            new FieldSorting('product.name', FieldSorting::ASCENDING),
            [
                'id' => 'translated_field_sorting',
                'params' => [
                    'field' => 'name',
                    'languages' => [
                        Defaults::LANGUAGE_SYSTEM,
                    ],
                ],
            ],
            true,
            null,
        ];

        yield 'nested translated field' => [
            new FieldSorting('manufacturer.name', FieldSorting::ASCENDING),
            [
                'id' => 'translated_field_sorting',
                'params' => [
                    'field' => 'manufacturer.name',
                    'languages' => [
                        Defaults::LANGUAGE_SYSTEM,
                    ],
                ],
            ],
            true,
            null,
        ];

        yield 'nested translated field with root prefix' => [
            new FieldSorting('manufacturer.name', FieldSorting::ASCENDING),
            [
                'id' => 'translated_field_sorting',
                'params' => [
                    'field' => 'manufacturer.name',
                    'languages' => [
                        Defaults::LANGUAGE_SYSTEM,
                    ],
                ],
            ],
            true,
            null,
        ];
    }

    /**
     * @dataProvider providerFilter
     *
     * @param array<mixed> $expectedFilter
     */
    public function testFilterParsing(Filter $filter, array $expectedFilter): void
    {
        $context = Context::createDefaultContext();
        $definition = $this->getDefinition();

        $storage = $this->createMock(AbstractKeyValueStorage::class);
        $storage->method('get')->willReturn(true);

        $sortedFilter = (new CriteriaParser(
            new EntityDefinitionQueryHelper(),
            $this->createMock(CustomFieldService::class),
            $storage
        ))->parseFilter($filter, $definition, '', $context);

        static::assertEquals($expectedFilter, $sortedFilter->toArray());
    }

    /**
     * @return iterable<string, array{Filter, array<mixed>}>
     */
    public static function providerFilter(): iterable
    {
        yield 'not filter: and' => [
            new NotFilter(MultiFilter::CONNECTION_AND, [new EqualsFilter('test', 'value'), new EqualsFilter('test2', 'value')]),
            [
                'bool' => [
                    'must_not' => [
                        [
                            'bool' => [
                                'must' => [
                                    [
                                        'term' => [
                                            'test' => 'value',
                                        ],
                                    ],
                                    [
                                        'term' => [
                                            'test2' => 'value',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        yield 'not filter: or' => [
            new NotFilter(MultiFilter::CONNECTION_OR, [new EqualsFilter('test', 'value'), new EqualsFilter('test2', 'value')]),
            [
                'bool' => [
                    'must_not' => [
                        [
                            'bool' => [
                                'should' => [
                                    [
                                        'term' => [
                                            'test' => 'value',
                                        ],
                                    ],
                                    [
                                        'term' => [
                                            'test2' => 'value',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        yield 'not filter: xor' => [
            new NotFilter(MultiFilter::CONNECTION_XOR, [new EqualsFilter('test', 'value'), new EqualsFilter('test2', 'value')]),
            [
                'bool' => [
                    'must_not' => [
                        [
                            'bool' => [
                                'should' => [
                                    [
                                        'bool' => [
                                            'must' => [
                                                [
                                                    'term' => [
                                                        'test' => 'value',
                                                    ],
                                                ],
                                            ],
                                            'must_not' => [
                                                [
                                                    'term' => [
                                                        'test2' => 'value',
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    [
                                        'bool' => [
                                            'must_not' => [
                                                [
                                                    'term' => [
                                                        'test' => 'value',
                                                    ],
                                                ],
                                            ],
                                            'must' => [
                                                [
                                                    'term' => [
                                                        'test2' => 'value',
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        yield 'range filter: cheapestPrice' => [
            new RangeFilter('cheapestPrice', [RangeFilter::GTE => 10]),
            [
                'script' => [
                    'script' => [
                        'id' => 'cheapest_price_filter',
                        'params' => [
                            RangeFilter::GTE => 10,
                            'accessors' => [
                                [
                                    'key' => 'cheapest_price_ruledefault_currencyb7d2554b0ce847cd82f3ac9bd1c0dfca_gross',
                                    'factor' => 1,
                                ],
                            ],
                            'decimals' => 100,
                            'round' => true,
                            'multiplier' => 100.0,
                        ],
                    ],
                ],
            ],
        ];

        yield 'range filter: cheapestPrice price/list price percentage' => [
            new RangeFilter('cheapestPrice.percentage', [RangeFilter::GTE => 10]),
            [
                'script' => [
                    'script' => [
                        'id' => 'cheapest_price_percentage_filter',
                        'params' => [
                            RangeFilter::GTE => 10,
                            'accessors' => [
                                [
                                    'key' => 'cheapest_price_ruledefault_currencyb7d2554b0ce847cd82f3ac9bd1c0dfca_gross_percentage',
                                    'factor' => 1,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        yield 'range filter: datetime' => [
            new RangeFilter('createdAt', [RangeFilter::GTE => '2023-06-01', RangeFilter::LT => '2023-06-03 13:47:42.759']),
            [
                'range' => [
                    'createdAt' => [
                        'gte' => '2023-06-01 00:00:00.000',
                        'lt' => '2023-06-03 13:47:42.000',
                    ],
                ],
            ],
        ];
    }

    public function getDefinition(): EntityDefinition
    {
        $instanceRegistry = new StaticDefinitionInstanceRegistry(
            [ProductDefinition::class, ProductManufacturerDefinition::class, ProductTranslationDefinition::class],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGatewayInterface::class)
        );

        return $instanceRegistry->getByEntityName('product');
    }
}

/**
 * @internal
 */
class CustomFilter extends Filter
{
    public function getFields(): array
    {
        return [];
    }
}
