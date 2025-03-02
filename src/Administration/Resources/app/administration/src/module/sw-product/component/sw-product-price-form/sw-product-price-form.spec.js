/*
 * @package inventory
 */

import { shallowMount } from '@vue/test-utils';
import Vuex from 'vuex';
import swProductPriceForm from 'src/module/sw-product/component/sw-product-price-form';
import 'src/app/component/utils/sw-inherit-wrapper';
import 'src/app/component/form/sw-list-price-field';
import productStore from 'src/module/sw-product/page/sw-product-detail/state';

Shopware.Component.register('sw-product-price-form', swProductPriceForm);

const { Utils } = Shopware;

const parentProductData = {
    id: 'productId',
    purchasePrices: [{
        currencyId: '1',
        linked: true,
        gross: 0,
        net: 0,
    }],
    price: [{
        currencyId: '1',
        linked: true,
        gross: 100,
        net: 84.034,
        listPrice: {
            currencyId: '1',
            linked: true,
            gross: 200,
            net: 168.07,
        },
        regulationPrice: {
            currencyId: '1',
            linked: true,
            gross: 100,
            net: 93.45,
        },
    }],
};


describe('module/sw-product/component/sw-product-price-form', () => {
    async function createWrapper(productEntityOverride, parentProductOverride) {
        const productEntity =
            {
                metaTitle: 'Product1',
                id: 'productId1',
                taxId: 'taxId',
                purchasePrices: null,
                price: null,
                ...productEntityOverride,
            };

        const parentProduct = {
            ...parentProductData,
            ...parentProductOverride,
        };

        return shallowMount(await Shopware.Component.build('sw-product-price-form'), {
            mocks: {
                $route: {
                    name: 'sw.product.detail.base',
                    params: {
                        id: 1,
                    },
                },
                $store: new Vuex.Store({
                    modules: {
                        swProductDetail: {
                            ...productStore,
                            state: {
                                ...productStore.state,
                                product: productEntity,
                                parentProduct,
                                advancedModeSetting: {
                                    value: {
                                        settings: [
                                            {
                                                key: 'prices',
                                                label: 'sw-product.detailBase.cardTitlePrices',
                                                enabled: true,
                                                name: 'general',
                                            },
                                        ],
                                        advancedMode: {
                                            enabled: true,
                                            label: 'sw-product.general.textAdvancedMode',
                                        },
                                    },
                                },
                                creationStates: 'is-physical',
                            },
                            getters: {
                                ...productStore.getters,
                                isLoading: () => false,
                                defaultCurrency: () => {
                                    return {
                                        id: '1',
                                        name: 'Euro',
                                        isoCode: 'EUR',
                                    };
                                },
                                productTaxRate: () => {
                                },
                            },
                        },
                    },
                }),
            },
            // eslint-disable max-len
            stubs: {
                'sw-container': {
                    template: '<div><slot></slot></div>',
                },
                'sw-inherit-wrapper': await Shopware.Component.build('sw-inherit-wrapper'),
                'sw-list-price-field': await Shopware.Component.build('sw-list-price-field'),
                'sw-inheritance-switch': {
                    props: ['isInherited', 'disabled'],
                    template: `
                      <div class="sw-inheritance-switch">
                      <div v-if="isInherited"
                           class="sw-inheritance-switch--is-inherited"
                           @click="onClickRemoveInheritance">
                      </div>
                      <div v-else
                           class="sw-inheritance-switch--is-not-inherited"
                           @click="onClickRestoreInheritance">
                      </div>
                      </div>`,
                    methods: {
                        onClickRestoreInheritance() {
                            this.$emit('inheritance-restore');
                        },
                        onClickRemoveInheritance() {
                            this.$emit('inheritance-remove');
                        },
                    },
                },
                'sw-price-field': true,
                'sw-help-text': true,
                'sw-select-field': true,
                'sw-internal-link': true,
                'router-link': true,
                'sw-icon': true,
                'sw-maintain-currencies-modal': true,
            },
            // eslint-enable max-len
        });
    }

    /** @type Wrapper */
    let wrapper;

    afterEach(() => {
        if (wrapper) {
            wrapper.destroy();
        }
    });

    // eslint-disable-next-line max-len
    it('should disable all price fields and toggle inheritance switch on if product price and purchase price are null', async () => {
        wrapper = await createWrapper();

        const priceInheritance = wrapper.find('.sw-product-price-form__price-list');
        const priceSwitchInheritance = priceInheritance.find('.sw-inheritance-switch');
        const priceFields = priceInheritance.findAll('sw-price-field-stub');

        expect(priceSwitchInheritance.find('.sw-inheritance-switch--is-inherited').exists()).toBeTruthy();

        priceFields.wrappers.forEach(priceField => {
            expect(priceField.attributes().disabled).toBeTruthy();
        });

        expect(wrapper.vm.prices).toEqual({ price: [], purchasePrices: [] });
    });

    it('should enable all price fields and toggle inheritance switch off if product variant price exists', async () => {
        wrapper = await createWrapper({
            price: [{
                currencyId: '1',
                linked: true,
                gross: 80,
                net: 67.27,
            }],
        });

        const priceInheritance = wrapper.find('.sw-product-price-form__price-list');
        const priceSwitchInheritance = priceInheritance.find('.sw-inheritance-switch');

        expect(priceSwitchInheritance.find('.sw-inheritance-switch--is-not-inherited').exists()).toBeTruthy();

        const priceFields = priceInheritance.findAll('sw-price-field-stub');
        priceFields.wrappers.forEach(priceField => {
            expect(priceField.attributes().disabled).toBeFalsy();
        });

        expect(wrapper.vm.prices).toEqual({
            price: [{
                currencyId: '1',
                linked: true,
                gross: 80,
                net: 67.27,
            }],
            purchasePrices: [],
        });
    });

    // eslint-disable-next-line max-len
    it('should enable all price fields and toggle inheritance switch off when user clicks on remove inheritance button', async () => {
        wrapper = await createWrapper();
        const priceInheritance = wrapper.find('.sw-product-price-form__price-list');
        const priceSwitchInheritance = priceInheritance.find('.sw-inheritance-switch');

        await priceSwitchInheritance.find('.sw-inheritance-switch--is-inherited').trigger('click');

        expect(priceSwitchInheritance.find('.sw-inheritance-switch--is-inherited').exists()).toBeFalsy();
        expect(priceSwitchInheritance.find('.sw-inheritance-switch--is-not-inherited').exists()).toBeTruthy();

        const priceFields = priceInheritance.findAll('sw-price-field-stub');
        priceFields.wrappers.forEach(priceField => {
            expect(priceField.attributes().disabled).toBeFalsy();
        });

        expect(wrapper.vm.prices).toEqual({
            price: parentProductData.price,
            purchasePrices: parentProductData.purchasePrices,
        });
        expect(wrapper.vm.prices.price[0].listPrice.gross).toBe(200);
        expect(wrapper.vm.prices.price[0].regulationPrice.gross).toBe(100);
    });

    // eslint-disable-next-line max-len
    it('should enable all price fields and toggle inheritance switch off when user clicks on remove inheritance button (using empty purchasePrices)', async () => {
        wrapper = await createWrapper();

        // remove purchasePrices of parent
        wrapper.vm.parentProduct.purchasePrices = undefined;
        await wrapper.vm.$nextTick();

        const priceInheritance = wrapper.find('.sw-product-price-form__price-list');
        const priceSwitchInheritance = priceInheritance.find('.sw-inheritance-switch');

        await priceSwitchInheritance.find('.sw-inheritance-switch--is-inherited').trigger('click');

        expect(priceSwitchInheritance.find('.sw-inheritance-switch--is-inherited').exists()).toBeFalsy();
        expect(priceSwitchInheritance.find('.sw-inheritance-switch--is-not-inherited').exists()).toBeTruthy();

        const priceFields = priceInheritance.findAll('sw-price-field-stub');
        priceFields.wrappers.forEach(priceField => {
            expect(priceField.attributes().disabled).toBeFalsy();
        });

        expect(wrapper.vm.prices).toEqual({
            price: parentProductData.price,
            purchasePrices: [],
        });
    });

    // eslint-disable-next-line max-len
    it('should disable all price fields and toggle inheritance switch on when user clicks on restore inheritance button', async () => {
        wrapper = await createWrapper({
            price: [{
                currencyId: '1',
                linked: true,
                gross: 80,
                net: 67.27,
            }],
        });

        const priceInheritance = wrapper.find('.sw-product-price-form__price-list');
        const priceSwitchInheritance = priceInheritance.find('.sw-inheritance-switch');

        await priceSwitchInheritance.find('.sw-inheritance-switch--is-not-inherited').trigger('click');

        expect(priceSwitchInheritance.find('.sw-inheritance-switch--is-not-inherited').exists()).toBeFalsy();
        expect(priceSwitchInheritance.find('.sw-inheritance-switch--is-inherited').exists()).toBeTruthy();

        const priceFields = priceInheritance.findAll('sw-price-field-stub');
        priceFields.wrappers.forEach(priceField => {
            expect(priceField.attributes().disabled).toBeTruthy();
        });

        expect(wrapper.vm.prices).toEqual({ price: [], purchasePrices: [] });
    });

    it('should show price item fields when advanced mode is on', async () => {
        wrapper = await createWrapper();

        const priceFieldsClassName = [
            '.sw-purchase-price-field',
            '.sw-list-price-field__list-price sw-price-field-stub',
        ];

        priceFieldsClassName.forEach(item => {
            expect(wrapper.find(item).exists()).toBeTruthy();
        });
    });

    it('should hide price item fields when advanced mode is off', async () => {
        wrapper = await createWrapper();
        const advancedModeSetting = Utils.get(wrapper, 'vm.$store.state.swProductDetail.advancedModeSetting');

        await wrapper.vm.$store.commit('swProductDetail/setAdvancedModeSetting', {
            value: {
                ...advancedModeSetting.value,
                advancedMode: {
                    enabled: false,
                    label: 'sw-product.general.textAdvancedMode',
                },
            },
        });

        const priceFieldsClassName = [
            '.sw-purchase-price-field',
            '.sw-list-price-field__list-price sw-price-field-stub',
        ];

        priceFieldsClassName.forEach(item => {
            expect(wrapper.find(item).exists()).toBeFalsy();
        });
    });
});
