(function (blocks, element, i18n) {
    'use strict';
    if (!blocks || !element) return;
    var el = element.createElement;
    var __ = i18n.__;
    [
        ['membership-center', 'EVO Membership Center', 'admin-users', 'Products, plans and the complete member account center.'],
        ['products', 'EVO Products', 'products', 'Display active EVO products with purchase buttons.'],
        ['plans', 'EVO Membership Plans', 'groups', 'Display active membership plans with Buy / Upgrade actions.'],
        ['my-membership', 'EVO My Membership', 'id-alt', 'Display the signed-in member account, licenses, activations, orders, downloads and messages.']
    ].forEach(function (item) {
        blocks.registerBlockType('evoxup/' + item[0], {
            apiVersion: 2,
            title: item[1],
            icon: item[2],
            category: 'evoxup',
            edit: function () {
                return el('div', {className: 'components-placeholder is-medium'},
                    el('div', {className: 'components-placeholder__label'}, item[1]),
                    el('div', {className: 'components-placeholder__instructions'}, __(item[3], 'evoxup-membership'))
                );
            },
            save: function () { return null; }
        });
    });
})(window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.i18n);
