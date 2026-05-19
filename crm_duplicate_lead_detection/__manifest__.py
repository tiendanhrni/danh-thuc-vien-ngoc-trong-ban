{
    'name': 'CRM - Phát hiện Lead trùng lặp',
    'version': '17.0.1.0.0',
    'category': 'Sales/CRM',
    'summary': 'Tự động cảnh báo khi tạo lead trùng email/SĐT, đề xuất hợp nhất hoặc chuyển sale',
    'description': """
Tính năng:
- Kiểm tra email và số điện thoại trùng lặp khi tạo/sửa lead
- Hiển thị cảnh báo trực tiếp trên form lead
- Gợi ý danh sách lead trùng với lịch sử và thông tin sale phụ trách
- Cho phép hợp nhất lead hoặc chuyển cho sale đã chăm sóc
    """,
    'author': 'RNI',
    'depends': ['crm'],
    'data': [
        'security/ir.model.access.csv',
        'views/crm_lead_duplicate_wizard_views.xml',
        'views/crm_lead_views.xml',
    ],
    'assets': {
        'web.assets_backend': [
            'crm_duplicate_lead_detection/static/src/css/duplicate_warning.css',
            'crm_duplicate_lead_detection/static/src/xml/duplicate_warning.xml',
            'crm_duplicate_lead_detection/static/src/js/duplicate_warning.js',
        ],
    },
    'installable': True,
    'auto_install': False,
    'license': 'LGPL-3',
}
