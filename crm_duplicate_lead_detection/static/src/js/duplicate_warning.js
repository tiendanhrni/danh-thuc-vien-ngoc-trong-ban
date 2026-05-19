/** @odoo-module **/

import { patch } from "@web/core/utils/patch";
import { FormController } from "@web/views/form/form_controller";
import { useService } from "@web/core/utils/hooks";
import { onWillUpdateProps } from "@odoo/owl";

/**
 * Patch FormController để tự động reload computed field duplicate khi email/phone thay đổi.
 * Computed fields đã được khai báo store=False nên sẽ được recompute tự động khi save.
 * File này chỉ giữ placeholder — logic chính xử lý qua server-side computed fields.
 */
patch(FormController.prototype, {
    setup() {
        super.setup(...arguments);
    },
});
