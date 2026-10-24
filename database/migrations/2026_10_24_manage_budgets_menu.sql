INSERT INTO menu_items (page, icon, label, category, sort_order, is_active)
SELECT 'manage-project-program-budgets', 'fa-coins', 'Manage Project/Program Budgets', 'Finance', 5, 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM menu_items WHERE page IN ('manage-project-program-budgets','manage-project-program-budgets.php')
);

INSERT IGNORE INTO menu_role_permissions (menu_id, role)
SELECT mi.menu_id, r.role
FROM menu_items mi
CROSS JOIN (
  SELECT 'Administrator' role
  UNION ALL SELECT 'Finance'
  UNION ALL SELECT 'Accountant'
) r
WHERE mi.page IN ('manage-project-program-budgets','manage-project-program-budgets.php');
