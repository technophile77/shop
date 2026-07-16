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
    ['GET',  '/flowers/occasions',          'ShopController',              'occasions'],
    ['GET',  '/flowers/occasion/{slug}',    'ShopController',              'occasion'],
    ['GET',  '/flowers/{ref}',              'ShopController',              'product'],
    ['GET',  '/cart',                       'CartController',              'view'],
    ['POST', '/cart/add',                   'CartController',              'add'],
    ['POST', '/cart/update',                'CartController',              'update'],
    ['POST', '/cart/remove',                'CartController',              'remove'],
    ['GET',  '/checkout',                   'CheckoutController',          'view'],
    ['POST', '/checkout',                   'CheckoutController',          'submit'],
    ['GET',  '/checkout/success',           'CheckoutController',          'success'],
    ['GET',  '/order',                      'OrderController',             'form'],
    ['POST', '/order',                      'OrderController',             'submit'],
    ['GET',  '/quote/{token}',              'QuoteController',             'show'],
    ['POST', '/quote/{token}/accept',       'QuoteController',             'accept'],
    ['POST', '/quote/{token}/deposit',      'QuoteController',             'confirmDeposit'],
    ['POST', '/quote/{token}/stripe/checkout', 'StripeController',         'initiateQuoteCheckout'],
    ['GET',  '/quote/{token}/stripe/success',  'StripeController',         'handleQuoteSuccess'],
    ['POST', '/stripe/webhook',                'StripeController',         'webhook'],
    ['GET',  '/about',                      'AboutController',             'index'],
    ['GET',  '/contact',                    'ContactController',           'index'],
    ['POST', '/contact',                    'ContactController',           'submit'],
    ['GET',  '/returns',                    'ReturnPolicyController',      'index'],
    ['POST', '/signup',                     'SignupController',            'submit'],
    ['GET',  '/lang/{code}',               'LocaleController',            'switch'],
    ['GET',  '/sitemap.xml',               'SitemapController',           'index'],

    // Local-SEO city landing pages (driven by config/local-areas.php)
    ['GET',  '/delivery-areas',                  'LocalAreaController',    'hub'],
    ['GET',  '/flower-delivery-{city}',          'LocalAreaController',    'cityHub'],
    ['GET',  '/funeral-flowers-{city}',          'LocalAreaController',    'funeral'],
    ['GET',  '/hospital-flower-delivery-{city}', 'LocalAreaController',    'hospital'],
    ['GET',  '/birthday-delivery-{city}',        'LocalAreaController',    'birthday'],
    // Per-venue landing pages (funeral homes + hospitals), nested under the city page.
    ['GET',  '/funeral-flowers-{city}/{venue}',          'LocalAreaController', 'funeralVenue'],
    ['GET',  '/hospital-flower-delivery-{city}/{venue}', 'LocalAreaController', 'hospitalVenue'],

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

    // Occasions
    ['GET',  '/admin/occasions',             'Admin\OccasionsController', 'index',  ['auth']],
    ['POST', '/admin/occasions',             'Admin\OccasionsController', 'create', ['auth']],
    ['POST', '/admin/occasions/{id}',        'Admin\OccasionsController', 'update', ['auth']],
    ['POST', '/admin/occasions/{id}/delete', 'Admin\OccasionsController', 'delete', ['auth']],

    // Flower Types
    ['GET',  '/admin/flower-types',                   'Admin\FlowerTypesController', 'index',        ['auth']],
    ['POST', '/admin/flower-types',                   'Admin\FlowerTypesController', 'create',       ['auth']],
    ['POST', '/admin/flower-types/{id}',              'Admin\FlowerTypesController', 'update',       ['auth']],
    ['POST', '/admin/flower-types/{id}/delete',       'Admin\FlowerTypesController', 'delete',       ['auth']],
    ['POST', '/admin/flower-types/{id}/colors',       'Admin\FlowerTypesController', 'updateColors', ['auth']],

    // Flower Colors
    ['GET',  '/admin/flower-colors',             'Admin\FlowerColorsController', 'index',  ['auth']],
    ['POST', '/admin/flower-colors',             'Admin\FlowerColorsController', 'create', ['auth']],
    ['POST', '/admin/flower-colors/{id}',        'Admin\FlowerColorsController', 'update', ['auth']],
    ['POST', '/admin/flower-colors/{id}/delete', 'Admin\FlowerColorsController', 'delete', ['auth']],

    // Paper Colors
    ['GET',  '/admin/paper-colors',             'Admin\PaperColorsController', 'index',  ['auth']],
    ['POST', '/admin/paper-colors',             'Admin\PaperColorsController', 'create', ['auth']],
    ['POST', '/admin/paper-colors/{id}',        'Admin\PaperColorsController', 'update', ['auth']],
    ['POST', '/admin/paper-colors/{id}/delete', 'Admin\PaperColorsController', 'delete', ['auth']],

    // Add-Ons
    ['GET',  '/admin/addons',             'Admin\AddonsController', 'index',    ['auth']],
    ['GET',  '/admin/addons/new',         'Admin\AddonsController', 'newForm',  ['auth']],
    ['POST', '/admin/addons',             'Admin\AddonsController', 'create',   ['auth']],
    ['GET',  '/admin/addons/{id}/edit',   'Admin\AddonsController', 'editForm', ['auth']],
    ['POST', '/admin/addons/{id}',        'Admin\AddonsController', 'update',   ['auth']],
    ['POST', '/admin/addons/{id}/delete', 'Admin\AddonsController', 'delete',   ['auth']],

    // Media
    ['GET',  '/admin/media',        'Admin\MediaController', 'index',  ['auth']],
    ['GET',  '/admin/media/list',   'Admin\MediaController', 'list',   ['auth']],
    ['POST', '/admin/media/upload', 'Admin\MediaController', 'upload', ['auth']],
    ['POST', '/admin/media/delete', 'Admin\MediaController', 'delete', ['auth']],

    // Orders (shop-cart checkout purchases)
    ['GET',  '/admin/orders',               'Admin\OrdersController',      'index',           ['auth']],
    ['GET',  '/admin/orders/{id}',          'Admin\OrdersController',      'show',            ['auth']],
    ['POST', '/admin/orders/{id}/status',   'Admin\OrdersController',      'updateStatus',    ['auth']],

    // Quotes
    ['GET',  '/admin/quotes',               'Admin\QuotesController',      'index',           ['auth']],
    ['GET',  '/admin/quotes/new',           'Admin\QuotesController',      'newForm',         ['auth']],
    ['POST', '/admin/quotes',               'Admin\QuotesController',      'create',          ['auth']],
    ['GET',  '/admin/quotes/{id}',          'Admin\QuotesController',      'show',            ['auth']],
    ['GET',  '/admin/quotes/{id}/edit',     'Admin\QuotesController',      'editForm',        ['auth']],
    ['POST', '/admin/quotes/{id}/edit',     'Admin\QuotesController',      'update',          ['auth']],
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
