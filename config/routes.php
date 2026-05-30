<?php
declare(strict_types=1);

/**
 * Route table for the application.
 *
 * Each entry is a four-element array:
 *   [HTTP_METHOD, URI_PATTERN, ControllerClass, controllerMethod]
 *
 * An optional fifth element may be an array of middleware tags, e.g. ['auth'].
 *
 * URI patterns support {param} named placeholders which are compiled into
 * named regex capture groups by Router::compilePattern().
 *
 * Controller class names are relative to App\Controllers\ — the Router
 * prepends that namespace automatically.
 *
 * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4?: list<string>}>
 */
return [
    // -----------------------------------------------------------------------
    // Public routes
    // -----------------------------------------------------------------------

    ['GET',  '/',                           'HomeController',              'index'],
    ['GET',  '/products',                   'ProductController',           'index'],
    ['GET',  '/products/{slug}',            'ProductController',           'byCategory'],
    ['GET',  '/order',                      'OrderController',             'form'],
    ['POST', '/order/delivery-fee',         'OrderController',             'calculateDelivery'],
    ['POST', '/order',                      'OrderController',             'submit'],
    ['GET',  '/quote/{token}',              'QuoteController',             'show'],
    ['POST', '/quote/{token}/accept',       'QuoteController',             'accept'],
    ['POST', '/quote/{token}/deposit',      'QuoteController',             'confirmDeposit'],
    ['GET',  '/about',                      'AboutController',             'index'],
    ['GET',  '/contact',                    'ContactController',           'index'],
    ['POST', '/contact',                    'ContactController',           'submit'],
    ['POST', '/signup',                     'SignupController',            'submit'],
    ['GET',  '/lang/{code}',               'LocaleController',            'switch'],

    // -----------------------------------------------------------------------
    // Admin — unauthenticated (login form; no 'auth' middleware)
    // -----------------------------------------------------------------------

    ['GET',  '/admin/login',                'Admin\AuthController',        'loginForm'],
    ['POST', '/admin/login',                'Admin\AuthController',        'login'],

    // -----------------------------------------------------------------------
    // Admin — authenticated (all require 'auth' middleware)
    // -----------------------------------------------------------------------

    ['GET',  '/admin',                      'Admin\DashboardController',   'index',           ['auth']],
    ['GET',  '/admin/logout',               'Admin\AuthController',        'logout',          ['auth']],

    // Products
    ['GET',  '/admin/products',             'Admin\ProductsController',    'index',           ['auth']],
    ['GET',  '/admin/products/new',         'Admin\ProductsController',    'newForm',         ['auth']],
    ['POST', '/admin/products',             'Admin\ProductsController',    'create',          ['auth']],
    ['GET',  '/admin/products/{id}/edit',   'Admin\ProductsController',    'editForm',        ['auth']],
    ['POST', '/admin/products/{id}',        'Admin\ProductsController',    'update',          ['auth']],
    ['POST', '/admin/products/{id}/delete', 'Admin\ProductsController',    'delete',          ['auth']],

    // Categories
    ['GET',  '/admin/categories',           'Admin\CategoriesController',  'index',           ['auth']],
    ['POST', '/admin/categories',           'Admin\CategoriesController',  'create',          ['auth']],
    ['POST', '/admin/categories/{id}',      'Admin\CategoriesController',  'update',          ['auth']],
    ['POST', '/admin/categories/{id}/delete', 'Admin\CategoriesController', 'delete',         ['auth']],

    // Quotes
    ['GET',  '/admin/quotes',               'Admin\QuotesController',      'index',           ['auth']],
    ['GET',  '/admin/quotes/new',           'Admin\QuotesController',      'newForm',         ['auth']],
    ['POST', '/admin/quotes',               'Admin\QuotesController',      'create',          ['auth']],
    ['GET',  '/admin/quotes/{id}',          'Admin\QuotesController',      'show',            ['auth']],
    ['POST', '/admin/quotes/{id}/status',   'Admin\QuotesController',      'updateStatus',    ['auth']],

    // Customers
    ['GET',  '/admin/customers',            'Admin\CustomersController',   'index',           ['auth']],
    ['GET',  '/admin/customers/export',     'Admin\CustomersController',   'export',          ['auth']],
    ['POST', '/admin/customers',            'Admin\CustomersController',   'create',          ['auth']],
    ['GET',  '/admin/customers/{id}',       'Admin\CustomersController',   'show',            ['auth']],
    ['POST', '/admin/customers/{id}/notes', 'Admin\CustomersController',   'updateNotes',     ['auth']],

    // Campaigns
    ['GET',  '/admin/campaigns',            'Admin\CampaignsController',   'index',           ['auth']],
    ['GET',  '/admin/campaigns/new',        'Admin\CampaignsController',   'newForm',         ['auth']],
    ['POST', '/admin/campaigns',            'Admin\CampaignsController',   'create',          ['auth']],
    ['GET',  '/admin/campaigns/{id}',       'Admin\CampaignsController',   'show',            ['auth']],
    ['POST', '/admin/campaigns/{id}/pause', 'Admin\CampaignsController',   'pause',           ['auth']],

    // Analytics
    ['GET',  '/admin/analytics',            'Admin\AnalyticsController',   'index',           ['auth']],

    // SMS
    ['GET',  '/admin/sms',                  'Admin\SmsController',         'index',           ['auth']],
    ['POST', '/admin/sms/send',             'Admin\SmsController',         'send',            ['auth']],

    // Settings
    ['GET',  '/admin/settings',             'Admin\SettingsController',    'index',           ['auth']],
    ['POST', '/admin/settings',             'Admin\SettingsController',    'update',          ['auth']],

    // Admin Users
    ['GET',  '/admin/admin-users',             'Admin\AdminUsersController', 'index',   ['auth']],
    ['GET',  '/admin/admin-users/new',         'Admin\AdminUsersController', 'newForm', ['auth']],
    ['POST', '/admin/admin-users',             'Admin\AdminUsersController', 'create',  ['auth']],
    ['POST', '/admin/admin-users/{id}/delete', 'Admin\AdminUsersController', 'delete',  ['auth']],

    // Change Password
    ['GET',  '/admin/change-password', 'Admin\AuthController', 'changePasswordForm', ['auth']],
    ['POST', '/admin/change-password', 'Admin\AuthController', 'changePassword',     ['auth']],
];
