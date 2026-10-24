-- Finance budget reporting menu; intentionally shown in the Reports sidebar group.
INSERT INTO menu_items (page, icon, label, category, sort_order, is_active)
SELECT 'finance-reports', 'fa-chart-pie', 'Finance Budget Reports', 'Reports', 90, 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM menu_items WHERE page IN ('finance-reports','finance-reports.php')
);

INSERT IGNORE INTO menu_role_permissions (menu_id, role)
SELECT mi.menu_id, r.role
FROM menu_items mi
CROSS JOIN (
  SELECT 'Administrator' role
  UNION ALL SELECT 'Finance'
  UNION ALL SELECT 'Accountant'
) r
WHERE mi.page IN ('finance-reports','finance-reports.php');
