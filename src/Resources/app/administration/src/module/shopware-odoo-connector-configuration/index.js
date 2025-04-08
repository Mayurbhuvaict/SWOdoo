import './page/shopware-odoo-connector-configuration';
import './components/shopware-odoo-connector-configuration-icon';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Module.register(
    'shopware-odoo-connector-configuration',
    {
        type: 'plugin',
        name: 'shopware-odoo-connector-configuration.general.name',
        title: 'shopware-odoo-connector-configuration.general.mainMenuItemGeneral',
        description: 'shopware-odoo-connector-configuration.general.descriptionTextModule',
        color: '#ff3d58',
        icon: 'regular-cog',

        snippets: {
            'de-DE': deDE,
            'en-GB': enGB
        },

        routes: {
            index: {
                component: 'shopware-odoo-connector-configuration',
                path: 'index',
                meta: {
                    parentPath: 'sw.settings.index',
                }
            }
        },

        settingsItem: {
            group: 'plugins',
            iconComponent: 'shopware-odoo-connector-configuration-icon',
            to: 'shopware.odoo.connector.configuration.index',
            backgroundEnabled: true,
        }
    }
);
