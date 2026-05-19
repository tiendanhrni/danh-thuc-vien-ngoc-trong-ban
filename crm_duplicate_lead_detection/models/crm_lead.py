from odoo import api, fields, models, _
from odoo.tools import email_normalize


class CrmLead(models.Model):
    _inherit = 'crm.lead'

    duplicate_lead_ids = fields.Many2many(
        'crm.lead',
        compute='_compute_duplicate_leads',
        string='Lead trùng lặp',
    )
    duplicate_lead_count = fields.Integer(
        compute='_compute_duplicate_leads',
        string='Số lead trùng',
    )
    has_duplicates = fields.Boolean(
        compute='_compute_duplicate_leads',
        string='Có lead trùng',
    )

    @api.depends('email_from', 'phone', 'mobile')
    def _compute_duplicate_leads(self):
        for lead in self:
            duplicates = lead._find_duplicate_leads()
            lead.duplicate_lead_ids = duplicates
            lead.duplicate_lead_count = len(duplicates)
            lead.has_duplicates = bool(duplicates)

    def _find_duplicate_leads(self):
        self.ensure_one()
        base_conditions = [('id', '!=', self.id or 0)]
        match_conditions = []

        email = self.email_from and email_normalize(self.email_from)
        if email:
            match_conditions.append(('email_normalized', '=', email))

        phone = self._normalize_phone(self.phone)
        if phone:
            match_conditions.append(('phone_sanitized', '=', phone))

        mobile = self._normalize_phone(self.mobile)
        if mobile and mobile != phone:
            match_conditions.append(('phone_sanitized', '=', mobile))

        if not match_conditions:
            return self.env['crm.lead']

        # Xây dựng OR domain cho các điều kiện khớp
        if len(match_conditions) == 1:
            or_part = list(match_conditions)
        else:
            or_part = ['|'] * (len(match_conditions) - 1) + list(match_conditions)

        final_domain = base_conditions + or_part

        return self.env['crm.lead'].with_context(active_test=False).search(
            final_domain,
            order='create_date desc',
            limit=20,
        )

    def _normalize_phone(self, phone):
        if not phone:
            return False
        digits = ''.join(c for c in phone if c.isdigit())
        if len(digits) >= 9:
            return digits[-9:]
        return False

    def action_open_duplicate_wizard(self):
        self.ensure_one()
        return {
            'type': 'ir.actions.act_window',
            'name': _('Lead trùng lặp được phát hiện'),
            'res_model': 'crm.lead.duplicate.wizard',
            'view_mode': 'form',
            'target': 'new',
            'context': {
                'default_lead_id': self.id,
                'default_duplicate_lead_ids': self.duplicate_lead_ids.ids,
            },
        }

    def action_view_duplicate_lead(self):
        self.ensure_one()
        duplicates = self._find_duplicate_leads()
        if not duplicates:
            return {'type': 'ir.actions.act_window_close'}
        return {
            'type': 'ir.actions.act_window',
            'name': _('Lead trùng lặp'),
            'res_model': 'crm.lead',
            'view_mode': 'list,form',
            'domain': [('id', 'in', duplicates.ids)],
            'context': {'active_test': False},
        }

    @api.model_create_multi
    def create(self, vals_list):
        leads = super().create(vals_list)
        for lead in leads:
            if lead.has_duplicates:
                lead._post_duplicate_warning_message()
        return leads

    def write(self, vals):
        result = super().write(vals)
        if 'email_from' in vals or 'phone' in vals or 'mobile' in vals:
            for lead in self:
                if lead.has_duplicates:
                    lead._post_duplicate_warning_message()
        return result

    def _post_duplicate_warning_message(self):
        self.ensure_one()
        duplicates = self.duplicate_lead_ids
        if not duplicates:
            return
        lines = []
        for dup in duplicates[:5]:
            sale_name = dup.user_id.name or _('Chưa phân công')
            stage = dup.stage_id.name or _('Chưa có giai đoạn')
            dup_type = _('Cơ hội') if dup.type == 'opportunity' else _('Lead')
            lines.append(
                f'• <a href="/odoo/crm/{dup.id}">{dup.name or "Không có tên"}</a> '
                f'— {dup_type} | Giai đoạn: {stage} | Sale: {sale_name} '
                f'| Ngày tạo: {dup.create_date.strftime("%d/%m/%Y") if dup.create_date else ""}'
            )
        body = _(
            '<p><strong>⚠️ Phát hiện %(count)d lead trùng lặp (email/SĐT)</strong></p>'
            '<p>Danh sách lead có thể trùng:</p>'
            '<ul>%(items)s</ul>'
            '<p>Vui lòng xem xét <strong>hợp nhất</strong> hoặc <strong>chuyển cho sale phụ trách</strong> trước đó.</p>',
            count=len(duplicates),
            items=''.join(f'<li>{line}</li>' for line in lines),
        )
        self.message_post(
            body=body,
            message_type='comment',
            subtype_xmlid='mail.mt_note',
        )
