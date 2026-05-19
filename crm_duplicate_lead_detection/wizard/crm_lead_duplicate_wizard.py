from odoo import api, fields, models, _
from odoo.exceptions import UserError


class CrmLeadDuplicateWizard(models.TransientModel):
    _name = 'crm.lead.duplicate.wizard'
    _description = 'Wizard xử lý lead trùng lặp'

    lead_id = fields.Many2one(
        'crm.lead', string='Lead hiện tại', required=True, ondelete='cascade'
    )
    duplicate_lead_ids = fields.Many2many(
        'crm.lead',
        'crm_lead_dup_wizard_rel',
        'wizard_id',
        'lead_id',
        string='Lead trùng lặp',
    )
    selected_lead_id = fields.Many2one(
        'crm.lead',
        string='Lead để hợp nhất/chuyển',
        domain="[('id', 'in', duplicate_lead_ids)]",
    )
    action = fields.Selection(
        [
            ('merge', 'Hợp nhất vào lead đã có'),
            ('reassign', 'Chuyển cho sale phụ trách lead cũ'),
            ('ignore', 'Bỏ qua, tiếp tục với lead này'),
        ],
        string='Hành động',
        default='ignore',
        required=True,
    )
    note = fields.Text(string='Ghi chú thêm')

    # Thống kê lead được chọn
    selected_lead_stage = fields.Char(
        compute='_compute_selected_lead_info', string='Giai đoạn'
    )
    selected_lead_user = fields.Char(
        compute='_compute_selected_lead_info', string='Sale phụ trách'
    )
    selected_lead_activities = fields.Integer(
        compute='_compute_selected_lead_info', string='Số hoạt động'
    )

    @api.depends('selected_lead_id')
    def _compute_selected_lead_info(self):
        for wizard in self:
            lead = wizard.selected_lead_id
            if lead:
                wizard.selected_lead_stage = lead.stage_id.name or ''
                wizard.selected_lead_user = lead.user_id.name or _('Chưa phân công')
                wizard.selected_lead_activities = len(lead.activity_ids)
            else:
                wizard.selected_lead_stage = ''
                wizard.selected_lead_user = ''
                wizard.selected_lead_activities = 0

    def action_confirm(self):
        self.ensure_one()
        if self.action == 'merge':
            return self._do_merge()
        elif self.action == 'reassign':
            return self._do_reassign()
        else:
            return self._do_ignore()

    def _do_merge(self):
        if not self.selected_lead_id:
            raise UserError(_('Vui lòng chọn lead để hợp nhất.'))
        # Hợp nhất: dùng wizard merge sẵn có của Odoo CRM
        merge_wizard = self.env['crm.merge.opportunity'].create({
            'opportunity_ids': (self.lead_id | self.selected_lead_id).ids,
            'user_id': self.selected_lead_id.user_id.id or self.env.uid,
            'team_id': self.selected_lead_id.team_id.id,
        })
        return merge_wizard.action_merge()

    def _do_reassign(self):
        if not self.selected_lead_id or not self.selected_lead_id.user_id:
            raise UserError(
                _('Lead được chọn chưa có sale phụ trách. Không thể chuyển.')
            )
        new_user = self.selected_lead_id.user_id
        self.lead_id.write({
            'user_id': new_user.id,
            'team_id': self.selected_lead_id.team_id.id or self.lead_id.team_id.id,
        })
        body = _(
            '<p>Lead được chuyển cho <strong>%(user)s</strong> '
            'vì trùng với lead <a href="/odoo/crm/%(dup_id)d">%(dup_name)s</a>.</p>'
            '%(note)s',
            user=new_user.name,
            dup_id=self.selected_lead_id.id,
            dup_name=self.selected_lead_id.name or 'Lead cũ',
            note=f'<p><em>{self.note}</em></p>' if self.note else '',
        )
        self.lead_id.message_post(
            body=body,
            message_type='comment',
            subtype_xmlid='mail.mt_note',
        )
        return {
            'type': 'ir.actions.client',
            'tag': 'display_notification',
            'params': {
                'title': _('Đã chuyển sale'),
                'message': _(
                    'Lead đã được chuyển cho %(user)s', user=new_user.name
                ),
                'type': 'success',
                'sticky': False,
            },
        }

    def _do_ignore(self):
        if self.note:
            self.lead_id.message_post(
                body=_('<p><em>Bỏ qua cảnh báo trùng lặp. Ghi chú: %(note)s</em></p>', note=self.note),
                message_type='comment',
                subtype_xmlid='mail.mt_note',
            )
        return {'type': 'ir.actions.act_window_close'}

    def action_open_selected_lead(self):
        self.ensure_one()
        if not self.selected_lead_id:
            raise UserError(_('Chưa chọn lead.'))
        return {
            'type': 'ir.actions.act_window',
            'name': _('Lead trùng'),
            'res_model': 'crm.lead',
            'res_id': self.selected_lead_id.id,
            'view_mode': 'form',
            'target': 'new',
            'context': {'active_test': False},
        }
