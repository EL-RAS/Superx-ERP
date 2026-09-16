<?php

return [
    // Canonical RBAC matrix. A permission key is "{module}.{action}". The
    // `permission` middleware maps HTTP method -> action when a route is
    // guarded by a bare module (GET/HEAD->view, POST->create, PUT/PATCH->edit,
    // DELETE->delete) or accepts a full "{module}.{action}" key directly.
    'actions' => ['view', 'create', 'edit', 'delete'],

    'modules' => [
        'dashboard' => 'Dashboard',
        'pos' => 'POS',
        'sales' => 'Sales',
        'inventory' => 'Inventory',
        'accounting' => 'Accounting',
        'crm' => 'CRM',
        'purchases' => 'Purchases',
        'reports' => 'Reports',
        'users_roles' => 'Users & Roles',
        'settings' => 'Settings',
    ],

    // System roles seeded for every business. `slug` is the stable key that
    // matches `users.role`. `admin` always bypasses permission checks.
    'system_roles' => [
        'admin' => 'Admin',
        'manager' => 'Manager',
        'accountant' => 'Accountant',
        'staff' => 'Staff',
        'cashier' => 'Cashier',
    ],

    // Default permission matrix per system role (module => granted actions).
    // '*' expands to every module x every action.
    'defaults' => [
        'admin' => '*',

        'manager' => [
            'dashboard' => ['view'],
            'pos' => ['view', 'create', 'edit', 'delete'],
            'sales' => ['view', 'create', 'edit'],
            'inventory' => ['view', 'create', 'edit'],
            'accounting' => ['view'],
            'crm' => ['view', 'create', 'edit'],
            'purchases' => ['view', 'create', 'edit'],
            'reports' => ['view'],
            'users_roles' => ['view'],
            'settings' => ['view'],
        ],

        'accountant' => [
            'dashboard' => ['view'],
            'pos' => ['view'],
            'sales' => ['view'],
            'inventory' => ['view'],
            'accounting' => ['view', 'create', 'edit'],
            'crm' => ['view'],
            'purchases' => ['view'],
            'reports' => ['view', 'create', 'edit'],
            'users_roles' => [],
            'settings' => [],
        ],

        'staff' => [
            'dashboard' => ['view'],
            'pos' => ['view', 'create'],
            'sales' => ['view', 'create'],
            'inventory' => ['view', 'create'],
            'accounting' => [],
            'crm' => ['view', 'create'],
            'purchases' => ['view'],
            'reports' => [],
            'users_roles' => [],
            'settings' => [],
        ],

        // Strictly POS + basic sales: can ring up invoices and view customers,
        // but has no access to Inventory, Accounting, Purchases, Reports,
        // Users & Roles, or Settings.
        'cashier' => [
            'dashboard' => ['view'],
            'pos' => ['view', 'create'],
            'sales' => ['view', 'create'],
            'inventory' => [],
            'accounting' => [],
            'crm' => ['view'],
            'purchases' => [],
            'reports' => [],
            'users_roles' => [],
            'settings' => [],
        ],
    ],
];
