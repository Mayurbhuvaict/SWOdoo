import template from './shopware-odoo-connector-configuration-icon.html.twig';
import './shopware-odoo-connector-configuration-icon.scss';

const {Component} = Shopware;

Component.register('shopware-odoo-connector-configuration-icon', {
    template,

    computed: {
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },
});
