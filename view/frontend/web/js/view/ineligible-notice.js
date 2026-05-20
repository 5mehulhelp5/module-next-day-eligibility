define([
    'uiComponent',
    'ko'
], function (Component, ko) {
    'use strict';

    return Component.extend({

        defaults: {
            template: 'ETechFlow_NextDayEligibility/ineligible-notice',
            isVisible: false,
            noticeStyle: 'warning',
            noticeMessage: '',
            noticeTitle: ''
        },

        initObservable: function () {
            this._super().observe(['isVisible']);
            return this;
        },

        initialize: function () {
            this._super();

            var config = window.checkoutConfig
                && window.checkoutConfig.nextDayEligibility
                ? window.checkoutConfig.nextDayEligibility
                : {};

            if (config.isRestricted) {
                this.isVisible(true);
                this.noticeStyle   = config.noticeStyle   || 'warning';
                this.noticeMessage = config.noticeMessage || '';
                this.noticeTitle   = config.noticeTitle   || 'Next day delivery unavailable';
            }

            return this;
        },

        dismiss: function () {
            this.isVisible(false);
        }
    });
});
