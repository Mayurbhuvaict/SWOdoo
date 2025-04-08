import template from './shopware-odoo-connector-configuration.html.twig';
import './shopware-odoo-connector-configuration.scss';

const {Component, Mixin} = Shopware;
const {Criteria} = Shopware.Data;


Component.register('shopware-odoo-connector-configuration', {

    template,

    inject: [
        'repositoryFactory',
        'configService',
        'acl',
        'stateMachineService'
    ],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            salesChannels: [],
            defaultShopwareStatuses: [],
            orderStatusOptions: [],
            odooOrderTransaction: [],
            orderPaymentOptions: [],
            odooOrderDelivery: [],
            orderDeliveryOptions: [],
            odooOrderOptions: [],
            domainId: null,
            config: null,
            productSalesChannel: [],
            categoryCount: 0,
            currencyCount: 0,
            orderStatusSyncCount: 0,
            customerCount: 0,
            orderCount: 0,
            isLoadingAuth: false,
            disabled: true,
            isLoading: false,
            isSaveSuccessful : false,
            isLoadingCategory: false,
            isLoadingCurrency: false,
            isLoadingSyncCustomer: false,
            isLoadingSyncOrder: false,
            isLoadingSyncOrderStatus: false,
        }
    },

    watch: {
        config: {
            handler() {
                this.domainId = this.$refs.configComponent.selectedSalesChannelId;
            },
            deep: true
        },

        shopwareOdooStatusArray: {
            handler(value) {
                if (!this.actualConfigData['ICTECHOdooShopwareConnector.settings.statusesMapping']) {
                    this.$set(this.actualConfigData, 'ICTECHOdooShopwareConnector.settings.statusesMapping', []);
                }

                this.$set(this.actualConfigData, 'ICTECHOdooShopwareConnector.settings.statusesMapping', value);
            },
            deep: true,
        }
    },

    computed: {
        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },

        StateMachineStateRepository() {
            return this.repositoryFactory.create('state_machine_state');
        },
        odooOrderStatusRepository() {
            return this.repositoryFactory.create('odoo_order_status');
        },
        categoryRepository() {
            return this.repositoryFactory.create('category');
        },
        currencyRepository() {
            return this.repositoryFactory.create('currency');
        },
        orderStatusSyncRepository() {
            return this.repositoryFactory.create('odoo_order_status');
        },
        customerRepository() {
            return this.repositoryFactory.create('customer');
        },
        orderRepository() {
            return this.repositoryFactory.create('order');
        },

        statusDataColumn() {
            return [{
                property: 'name',
                label: this.$tc('shopware-odoo-connector-configuration.general.shopwareDeliveryStatus')
            }, {
                property: 'odooDeliveryStatus',
                label: this.$tc('shopware-odoo-connector-configuration.general.odooDeliveryStatus')
            }, {
                property: 'shopwareOrderStatus',
                label: this.$tc('shopware-odoo-connector-configuration.general.shopwareOrderStatus')
            }
            ]
        },

        actualConfigData() {
            return this.$refs.configComponent?.actualConfigData || {};
        },

        shopwareOdooStatusArray: {
            get() {
                return this.actualConfigData['ICTECHOdooShopwareConnector.settings.statusesMapping'] || []
            },

            set(value) {
                const configComponent = this.$refs.configComponent;
                this.$set(configComponent, 'allConfigs', {
                    ...configComponent.allConfigs,
                    [configComponent.selectedSalesChannelId]: {
                        ...configComponent.allConfigs[configComponent.selectedSalesChannelId] || {},
                        'ICTECHOdooShopwareConnector.settings.statusesMapping': value
                    },
                });
            }
        }

    },

    created() {
        this.loadDefaultShopwareStatuses();
        this.createdComponent();
        this.orderStatusComponent();
        this.orderTransactionComponent();
        // this.orderDeliveryComponent();
        this.odooOrderStatusOptionsComponent();
        this.odooOrderStatusComponent();
        this.odooOrderDeliveryComponent();
        this.categoryCountComponent();
        this.currencyCountComponent();
        this.customerCountComponent();
        this.orderCountComponent();
        this.odooOrderStatusCountComponent();
    },

    methods: {
        async loadDefaultShopwareStatuses() {
            this.defaultShopwareStatuses = await this.fetchOrderDelieveryStatuses();
            console.log("this.defaultShopwareStatuses", this.defaultShopwareStatuses)
        },

        createdComponent() {
            const criteria = new Criteria();
            this.salesChannelRepository.search(criteria, Shopware.Context.api).then(res => {
                res.add({
                    id: null,
                    translated: {
                        name: this.$tc('sw-sales-channel-switch.labelDefaultOption')
                    }
                });
                this.salesChannels = res;
            }).finally(() => {
                this.isLoading = false;
            });
        },
        checkTextFieldInheritance(value) {
            if (typeof value !== 'string') {
                return true;
            }

            return value.length <= 0;
        },


        onSave() {
            this.isLoading = true;

            this.$refs.configComponent.save().then(() => {
                this.isSaveSuccessful = true;
                this.createNotificationSuccess({
                    title: this.$tc('shopware-odoo-connector-configuration.action.titleSaveSuccess'),
                    message: this.$tc('shopware-odoo-connector-configuration.action.messageSaveSuccess')
                });
            }).catch(error => {
                this.createNotificationError({
                    title: this.$tc('shopware-odoo-connector-configuration.action.Error'),
                    message: error.message
                });
                this.isLoadingAuth = false;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        categoryCountComponent() {
            const categoryCriteria = new Criteria();
            categoryCriteria.addFilter(Criteria.not('or', [
                Criteria.equals('customFields.odoo_category_id', null),
                Criteria.equals('customFields.odoo_category_id', 0),
            ]));
            this.categoryRepository.search(categoryCriteria, Shopware.Context.api).then(result => {
                this.categoryCount = result.total  ;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        currencyCountComponent() {
            const currencyCriteria = new Criteria();
            currencyCriteria.addFilter(Criteria.not('or', [
                Criteria.equals('customFields.odoo_currency_id', null),
            ]));
            this.currencyRepository.search(currencyCriteria, Shopware.Context.api).then(result => {
                this.currencyCount = result.total;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        odooOrderStatusCountComponent() {
            const orderStatusCriteria = new Criteria();
            this.orderStatusSyncRepository.search(orderStatusCriteria, Shopware.Context.api).then(result => {
                this.orderStatusSyncCount = result.total;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        customerCountComponent() {
            const customerCriteria = new Criteria();
            customerCriteria.addFilter(Criteria.not('or', [
                Criteria.equals('customFields.odoo_customer_id', null),
            ]));
            customerCriteria.addFilter(Criteria.not('or', [
                Criteria.equals('customFields.odoo_customer_sync_status', "false"),
            ]));
            this.customerRepository.search(customerCriteria, Shopware.Context.api).then(result => {
                this.customerCount = result.total;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        orderCountComponent() {
            const orderCriteria = new Criteria();
            orderCriteria.addFilter(Criteria.not('or', [
                Criteria.equals('customFields.odoo_order_id', null),
            ]));
            orderCriteria.addFilter(Criteria.not('or', [
                Criteria.equals('customFields.odoo_order_sync_status', "false"),
            ]));
            this.orderRepository.search(orderCriteria, Shopware.Context.api).then(result => {
                this.orderCount = result.total;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        async fetchOrderDelieveryStatuses() {
            const deliveryCriteria = new Criteria();
            deliveryCriteria.addAssociation('stateMachine');
            deliveryCriteria.addFilter(Criteria.equals('stateMachine.technicalName', 'order_delivery.state'));

            return await this.StateMachineStateRepository.search(deliveryCriteria, Shopware.Context.api);
        },
        orderStatusComponent() {
            const orderStatusCriteria = new Criteria();
            orderStatusCriteria.addAssociation('stateMachine');
            orderStatusCriteria.addFilter(Criteria.equals('stateMachine.technicalName', 'order.state'));
            this.StateMachineStateRepository.search(orderStatusCriteria, Shopware.Context.api).then(result => {
                this.orderStatusOptions = result;
            }).finally(() => {
                this.isLoading = false;
            });
        },
        orderTransactionComponent() {
            const transactionCriteria = new Criteria();
            transactionCriteria.addAssociation('stateMachine');
            transactionCriteria.addFilter(Criteria.equals('stateMachine.technicalName', 'order_transaction.state'));
            this.StateMachineStateRepository.search(transactionCriteria, Shopware.Context.api).then(result => {
                this.orderPaymentOptions = result;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        odooOrderStatusOptionsComponent() {
            const odooOrderStatusCriteria = new Criteria();
            odooOrderStatusCriteria.addFilter(Criteria.equals('odooStatusType', 'sale_states'));
            this.odooOrderStatusRepository.search(odooOrderStatusCriteria, Shopware.Context.api).then(result => {
                this.odooOrderOptions = result;

            }).finally(() => {
                this.isLoading = false;
            });
        },

        odooOrderStatusComponent() {
            const odooTransactionCriteria = new Criteria();
            odooTransactionCriteria.addFilter(Criteria.equals('odooStatusType', 'payment_states'));
            this.odooOrderStatusRepository.search(odooTransactionCriteria, Shopware.Context.api).then(result => {
                this.odooOrderTransaction = result;
            }).finally(() => {
                this.isLoading = false;
            });
        },
        odooOrderDeliveryComponent() {
            const odooTransactionCriteria = new Criteria();
            odooTransactionCriteria.addFilter(Criteria.equals('odooStatusType', 'delivery_states'));
            this.odooOrderStatusRepository.search(odooTransactionCriteria, Shopware.Context.api).then(result => {
                this.odooOrderDelivery = result;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        checkWebSiteCredential() {
            this.isLoadingAuth = true;
            let headers = this.configService.getBasicHeaders();
            return this.configService.httpClient.post('/check/web-url-credential', {}, {
                headers
            })
                .then(response => {
                    if (response.data.responseCode === 200) {
                        this.createNotificationSuccess({
                            title: "Success",
                            message: response.data.message
                        });
                    } else {
                        if (response.data.responseCode === 400) {
                            this.createNotificationError({
                                title: "Error",
                                message: this.$tc('shopware-odoo-connector-configuration.apiSection.enterUrlError'),
                            });
                        }
                    }
                    this.isLoadingAuth = false;
                })
                .catch(error => {
                    this.createNotificationError({
                        title: "Error",
                        message: this.$tc('shopware-odoo-connector-configuration.apiSection.errorAuthenticate'),
                    });
                    this.isLoadingAuth = false;
                });
        },


        fetchCategoryData() {
            this.isLoading = true;
            let headers = this.configService.getBasicHeaders();
            return this.configService.httpClient.post('/category/default/odoo', {}, {
                headers
            })
                .then(response => {
                    if (response.data.responseCode === 200) {
                        this.createNotificationSuccess({
                            title: response.data.type,
                            message: this.$tc('ictech-shopware-odoo-connector.successMessageCategory')
                        });
                    } else {
                        if (response.data.responseCode === 400) {
                            this.createNotificationError({
                                title: response.data.type,
                                message: this.$tc('shopware-odoo-connector-configuration.apiSection.enterUrlError'),
                            });
                        }
                    }
                    this.isLoading = false;
                })
                .catch(error => {
                    this.createNotificationError({
                        title: "Error",
                        message: this.$tc('shopware-odoo-connector-configuration.apiSection.errorAuthenticate'),
                    });
                    this.isLoading = false;
                });
        },

        fetchCurrencyData() {
            this.isLoadingCurrency = true;
            let headers = this.configService.getBasicHeaders();
            return this.configService.httpClient.post('/currency/default/odoo', {}, {
                headers
            })
                .then(response => {
                    if (response.data.responseCode === 200) {
                        this.createNotificationSuccess({
                            title: response.data.type,
                            message: this.$tc('ictech-shopware-odoo-connector.successMessageCurrency')
                        });
                    } else {
                        if (response.data.responseCode === 400) {
                            this.createNotificationError({
                                title: response.data.type,
                                message: this.$tc('shopware-odoo-connector-configuration.apiSection.enterUrlError'),
                            });
                        }
                    }
                    this.isLoadingCurrency = false;
                })
                .catch(error => {
                    this.createNotificationError({
                        title: "Error",
                        message: this.$tc('shopware-odoo-connector-configuration.apiSection.errorAuthenticate'),
                    });
                    this.isLoadingCurrency = false;
                });
        },

        syncCustomerData() {
            this.isLoadingSyncCustomer = true;
            let headers = this.configService.getBasicHeaders();
            return this.configService.httpClient.post('/pending/customer-sync', {}, {
                headers
            })
                .then(response => {
                    if (response.data.responseCode === 200) {
                        this.createNotificationSuccess({
                            title: "Success",
                            message: this.$tc('ictech-shopware-odoo-connector.successMessageSyncCustomer')
                        });
                    } else {
                        if (response.data.responseCode === 400) {
                            this.createNotificationError({
                                title: response.data.type,
                                message: this.$tc('shopware-odoo-connector-configuration.apiSection.enterUrlError'),
                            });
                        }
                        this.isLoadingSyncOrder = false;
                    }
                    this.isLoadingSyncCustomer = false;
                })
                .catch(error => {
                    this.createNotificationError({
                        title: "Error",
                        message: this.$tc('shopware-odoo-connector-configuration.apiSection.errorAuthenticate'),
                    });
                    this.isLoadingSyncCustomer = false;
                });
        },

        syncOrderData() {
            this.isLoadingSyncOrder = true;
            let headers = this.configService.getBasicHeaders();
            return this.configService.httpClient.post('/pending/order-sync', {}, {
                headers
            })
                .then(response => {
                    if (response.data.responseCode === 200) {
                        this.createNotificationSuccess({
                            title: "Success",
                            message: this.$tc('ictech-shopware-odoo-connector.successMessageSyncOrder')
                        });
                    }
                    else {
                        if (response.data.responseCode === 400) {
                            this.createNotificationError({
                                title: response.data.type,
                                message: this.$tc('shopware-odoo-connector-configuration.apiSection.enterUrlError'),
                            });
                        }
                        this.isLoadingSyncOrder = false;
                    }
                })
                .catch(error => {
                    this.createNotificationError({
                        title: "Error",
                        message: this.$tc('shopware-odoo-connector-configuration.apiSection.errorAuthenticate'),
                    });
                    this.isLoadingSyncOrder = false;
                });
        },

        syncOdooOrderStatus() {
            this.isLoadingSyncOrderStatus = true;
            let headers = this.configService.getBasicHeaders();
            return this.configService.httpClient.post('/odoo/order/status', {}, {
                headers
            })
                .then(response => {
                    if (response.data.responseCode === 200) {
                        this.createNotificationSuccess({
                            title: response.data.type,
                            message: this.$tc('ictech-shopware-odoo-connector.successMessageOrderStatus')
                        });
                    } else {
                        if (response.data.responseCode === 400) {
                            this.createNotificationError({
                                title: response.data.type,
                                message: this.$tc('shopware-odoo-connector-configuration.apiSection.enterUrlError'),
                            });
                        }
                    }
                    this.isLoadingSyncOrderStatus = false;
                })
                .catch(error => {
                    this.createNotificationError({
                        title: "Error",
                        message: this.$tc('shopware-odoo-connector-configuration.apiSection.errorAuthenticate'),
                    });
                    this.isLoadingSyncOrderStatus = false;
                });
        }
    }
})
  