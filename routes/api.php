<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/



// Countries & States
Route::apiResource('state', 'App\Http\Controllers\StateController');
Route::apiResource('country', 'App\Http\Controllers\CountryController');

// Settings & Options
Route::get('settings', 'App\Http\Controllers\SettingController@frontSettings');
Route::get('themeOptions', 'App\Http\Controllers\ThemeOptionController@index');

// App Settings (Mobile App Home Configuration)
Route::get('app/settings', 'App\Http\Controllers\HomePageController@appSettings');

// Webhooks
Route::post('/paypal/webhook', 'App\Http\Controllers\WebhookController@paypal')->name('paypal.webhook');
Route::post('/razorpay/webhook', 'App\Http\Controllers\WebhookController@razorpay')->name('razorpay.webhook');
Route::post('/stripe/webhook', 'App\Http\Controllers\WebhookController@stripe')->name('stripe.webhook');
Route::post('/mollie/webhook', 'App\Http\Controllers\WebhookController@mollie')->name('mollie.webhook');
Route::post('/instamojo/webhook', 'App\Http\Controllers\WebhookController@instamojo')->name('instamojo.webhook');
Route::post('/ccavenue/webhook', 'App\Http\Controllers\WebhookController@ccavenue')->name('ccavenue.webhook');
Route::post('/phonepe/webhook', 'App\Http\Controllers\WebhookController@phonepe')->name('phonepe.webhook');

// Authentication
Route::post('login', [AuthController::class, 'login']);
Route::post('backend/login', [AuthController::class, 'backendLogin']);
Route::post('/register', 'App\Http\Controllers\AuthController@register');
Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify-token', 'App\Http\Controllers\AuthController@verifyToken');
Route::post('/update-password', 'App\Http\Controllers\AuthController@updatePassword');

// OTP Authentication
Route::post('send-otp', [AuthController::class, 'sendOtp']);
Route::post('verify-otp', [AuthController::class, 'verifyOtp']);

Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');

// Products
Route::get('product/slug/{slug}', 'App\Http\Controllers\ProductController@getProductBySlug');
Route::get('product/barcode/{barcode}', 'App\Http\Controllers\ProductController@showByBarcode');
Route::apiResource('product', 'App\Http\Controllers\ProductController',[
  'only' => ['index', 'show'],
]);
Route::apiResource('featured-product', 'App\Http\Controllers\FeaturedProductController', [
  'only' => ['index'],
]);
Route::apiResource('trending-product', 'App\Http\Controllers\TrendingProductController', [
  'only' => ['index'],
]);
Route::apiResource('bestselling-product', 'App\Http\Controllers\BestsellingProductController', [
  'only' => ['index'],
]);

// Attributes
Route::apiResource('attribute', 'App\Http\Controllers\AttributeController',[
  'only' => ['index', 'show'],
]);

// Attribute Values
Route::apiResource('attribute-value', 'App\Http\Controllers\AttributeValueController',[
  'only' => ['index', 'show'],
]);

// Categories
Route::apiResource('category', 'App\Http\Controllers\CategoryController',[
  'only' => ['index', 'show'],
]);

// Tags
Route::apiResource('tag', 'App\Http\Controllers\TagController', [
  'only' => ['index', 'show'],
]);

// Stores
Route::apiResource('store', 'App\Http\Controllers\StoreController',[
  'only' => ['index', 'show'],
]);
Route::post('store', 'App\Http\Controllers\StoreController@store');
Route::get('store/slug/{slug}', 'App\Http\Controllers\StoreController@getStoreBySlug');

// Order Status
Route::apiResource('orderStatus', 'App\Http\Controllers\OrderStatusController',[
  'only' => ['index', 'show'],
]);

// Blogs
Route::apiResource('blog', 'App\Http\Controllers\BlogController', [
  'only' => ['index', 'show'],
]);
Route::get('blog/slug/{slug}', 'App\Http\Controllers\BlogController@getBlogsBySlug');

// Pages
Route::apiResource('page', 'App\Http\Controllers\PageController', [
  'only' => ['index', 'show'],
]);
Route::get('page/slug/{slug}', 'App\Http\Controllers\PageController@getPagesBySlug');

// Taxes
Route::apiResource('tax', 'App\Http\Controllers\TaxController', [
  'only' => ['index', 'show'],
]);

// Offer Banners
Route::apiResource('offer-banner', 'App\Http\Controllers\OfferBannerController', [
  'only' => ['index', 'show'],
]);

// Coupons
Route::apiResource('coupon', 'App\Http\Controllers\CouponController', [
  'only' => ['index', 'show'],
]);

// Currencies
Route::apiResource('currency', 'App\Http\Controllers\CurrencyController', [
  'only' => ['index', 'show'],
]);

// Faqs
Route::apiResource('faq', 'App\Http\Controllers\FaqController', [
  'only' => ['index', 'show'],
]);

// Home
Route::apiResource('home', 'App\Http\Controllers\HomePageController', [
  'only' => ['index', 'show'],
]);

// Theme
Route::apiResource('theme', 'App\Http\Controllers\ThemeController',[
  'only' => ['index', 'show'],
]);

// Products
Route::apiResource('question-and-answer', 'App\Http\Controllers\QuestionAndAnswerController',[
  'only' => ['index', 'show'],
]);

// Reviews
Route::get('front/review', 'App\Http\Controllers\ReviewController@frontIndex');

// ContactUs
Route::post('/contact-us', 'App\Http\Controllers\ContactUsController@contactUs');

Route::post('pearl-xp/product-updates', 'App\Http\Controllers\PearlXpController@store')->middleware('auth:sanctum');

Route::group(['middleware' => ['localization','auth:sanctum']], function () {

  // Authentication
  Route::match(['get', 'post'], 'logout', 'App\Http\Controllers\AuthController@logout');

  // Account
  Route::get('self', 'App\Http\Controllers\AccountController@self');
  Route::put('updateProfile', 'App\Http\Controllers\AccountController@updateProfile');
  Route::put('updatePassword', 'App\Http\Controllers\AccountController@updatePassword');
  Route::put('updateProfile', 'App\Http\Controllers\AccountController@updateProfile');
  Route::put('updatePassword', 'App\Http\Controllers\AccountController@updatePassword');
  Route::put('updateStoreProfile', 'App\Http\Controllers\AccountController@updateStoreProfile');

  // Address
  Route::apiResource('address', 'App\Http\Controllers\AddressController');

  // Payment Account
  Route::apiResource('paymentAccount', 'App\Http\Controllers\PaymentAccountController');

  // Badge
  Route::get('badge','App\Http\Controllers\BadgeController@index');

  // Notifications
  Route::get('notifications', 'App\Http\Controllers\NotificationController@index');
  Route::put('notifications/markAsRead', 'App\Http\Controllers\NotificationController@markAsRead');
  Route::delete('notifications/{id}', 'App\Http\Controllers\NotificationController@destroy');

  // ***********  Frontend   ***********
  Route::apiResource('cart', 'App\Http\Controllers\CartController');
  Route::apiResource('refund', 'App\Http\Controllers\RefundController');
  Route::apiResource('compare', 'App\Http\Controllers\CompareController');
  Route::apiResource('wishlist', 'App\Http\Controllers\WishlistController');

  Route::post('rePayment', 'App\Http\Controllers\OrderController@rePayment');
  Route::get('trackOrder/{order_number}', 'App\Http\Controllers\OrderController@trackOrder');
  Route::get('verifyPayment/{order_number}', 'App\Http\Controllers\OrderController@verifyPayment');

  Route::put('cart', 'App\Http\Controllers\CartController@update');
  Route::post('sync/cart', 'App\Http\Controllers\CartController@sync');
  Route::put('replace/cart', 'App\Http\Controllers\CartController@replace');

  // **********************  Backend   *******************************

  // Dashboard
  Route::get('statistics/count', 'App\Http\Controllers\DashboardController@index');
  Route::get('dashboard/chart', 'App\Http\Controllers\DashboardController@chart');

  // Users
  Route::apiResource('user', 'App\Http\Controllers\UserController');
  Route::put('user/{id}/{status}', 'App\Http\Controllers\UserController@status')->middleware('can:user.edit');
  Route::post('user/csv/import', 'App\Http\Controllers\UserController@import')->middleware('can:user.create');
  Route::post('user/csv/export', 'App\Http\Controllers\UserController@export')->name('users.export')->middleware('can:user.index');
  Route::post('user/deleteAll', 'App\Http\Controllers\UserController@deleteAll')->middleware('can:user.destroy');
  Route::delete('user/address/{id}', 'App\Http\Controllers\UserController@deleteAddress')->middleware('can:user.edit');

  //Roles
  Route::apiResource('role', 'App\Http\Controllers\RoleController');
  Route::get('module', 'App\Http\Controllers\RoleController@modules');
  Route::post('role/deleteAll', 'App\Http\Controllers\RoleController@deleteAll')->middleware('can:role.destroy');

  // Products
  // Products (Barcode-based operations)
  Route::put('product/barcode/{barcode}', 'App\Http\Controllers\ProductController@updateByBarcode')->middleware('can:product.edit');
  Route::delete('product/barcode/{barcode}', 'App\Http\Controllers\ProductController@destroyByBarcode')->middleware('can:product.destroy');
  Route::put('product/barcode/{barcode}/{status}', 'App\Http\Controllers\ProductController@statusByBarcode')->middleware('can:product.edit');
  Route::put('product/approve/barcode/{barcode}/{status}', 'App\Http\Controllers\ProductController@approveByBarcode')->middleware('can:product.edit');
  // These remain inside the auth:sanctum group with can:product.edit middleware
  Route::put('product/{id}/{status}', 'App\Http\Controllers\ProductController@status')->middleware('can:product.edit');
  Route::post('product/csv/export', 'App\Http\Controllers\ProductController@export')->name('products.export')->middleware('can:product.index');
  Route::post('product/csv/import', 'App\Http\Controllers\ProductController@import')->middleware('can:product.create');
  Route::put('product/approve/{id}/{status}', 'App\Http\Controllers\ProductController@approve')->middleware('can:product.edit');
  Route::post('product/deleteAll', 'App\Http\Controllers\ProductController@deleteAll')->middleware('can:product.destroy');

  // Pearl XP staged product updates (admin review & accept flow)
  // Intake (POST without id) stays public with master-token auth above;
  // these admin routes require sanctum + product permissions.
  Route::get('pearl-xp/product-updates', 'App\Http\Controllers\PearlXpProductUpdateController@index')->middleware('can:product.index');
  Route::get('pearl-xp/product-updates/{id}', 'App\Http\Controllers\PearlXpProductUpdateController@show')->middleware('can:product.index');
  Route::put('pearl-xp/product-updates/{id}', 'App\Http\Controllers\PearlXpProductUpdateController@update')->middleware('can:product.edit');
  Route::post('pearl-xp/product-updates/{id}/approve', 'App\Http\Controllers\PearlXpProductUpdateController@approve')->middleware('can:product.edit');
  Route::post('pearl-xp/product-updates/{id}/reject', 'App\Http\Controllers\PearlXpProductUpdateController@reject')->middleware('can:product.edit');

  // Featured Products
  Route::apiResource('featured-product', 'App\Http\Controllers\FeaturedProductController', [
    'only' => ['store', 'destroy'],
  ])->middleware('can:product.edit');
  Route::patch('featured-product/reorder', 'App\Http\Controllers\FeaturedProductController@reorder')->middleware('can:product.edit');

  // Trending Products
  Route::apiResource('trending-product', 'App\Http\Controllers\TrendingProductController', [
    'only' => ['store', 'destroy'],
  ])->middleware('can:product.edit');
  Route::patch('trending-product/reorder', 'App\Http\Controllers\TrendingProductController@reorder')->middleware('can:product.edit');

  // Bestselling Products
  Route::apiResource('bestselling-product', 'App\Http\Controllers\BestsellingProductController', [
    'only' => ['store', 'destroy'],
  ])->middleware('can:product.edit');
  Route::patch('bestselling-product/reorder', 'App\Http\Controllers\BestsellingProductController@reorder')->middleware('can:product.edit');

  // Attributes & Attribute Values
  Route::apiResource('attribute', 'App\Http\Controllers\AttributeController',[
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::apiResource('attribute-value', 'App\Http\Controllers\AttributeValueController',[
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::put('attribute/{id}/{status}', 'App\Http\Controllers\AttributeController@status')->middleware('can:attribute.edit');
  Route::post('attribute/csv/import', 'App\Http\Controllers\AttributeController@import')->middleware('can:attribute.create');
  Route::post('attribute/csv/export', 'App\Http\Controllers\AttributeController@export')->name('attributes.export')->middleware('can:attribute.index');
  Route::post('attribute/deleteAll', 'App\Http\Controllers\AttributeController@deleteAll')->middleware('can:attribute.destroy');

  // Categories
  Route::apiResource('category', 'App\Http\Controllers\CategoryController',[
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::post('category/csv/import', 'App\Http\Controllers\CategoryController@import')->middleware('can:category.create');
  Route::post('category/csv/export', 'App\Http\Controllers\CategoryController@export')->name('categories.export')->middleware('can:category.index');
  Route::put('category/{id}/{status}', 'App\Http\Controllers\CategoryController@status')->middleware('can:category.edit');

  // Tags
  Route::apiResource('tag', 'App\Http\Controllers\TagController',[
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::post('tag/csv/import', 'App\Http\Controllers\TagController@import')->middleware('can:tag.create');
  Route::post('tag/csv/export', 'App\Http\Controllers\TagController@export')->name('tags.export')->middleware('can:tag.index');
  Route::post('tag/deleteAll', 'App\Http\Controllers\TagController@deleteAll')->middleware('can:tag.destroy');
  Route::put('tag/{id}/{status}', 'App\Http\Controllers\TagController@status')->middleware('can:tag.edit');

  // Stores
  Route::apiResource('store', 'App\Http\Controllers\StoreController',[
    'only' => ['update', 'destroy'],
  ]);
  Route::post('store/deleteAll', 'App\Http\Controllers\StoreController@deleteAll')->middleware('can:store.destroy');
  Route::put('store/approve/{id}/{status}', 'App\Http\Controllers\StoreController@approve')->middleware('can:store.edit');
  Route::put('store/{id}/{status}', 'App\Http\Controllers\StoreController@status')->middleware('can:store.edit');

  // Vendor Wallets
  Route::get('wallet/vendor', 'App\Http\Controllers\VendorWalletController@index')->middleware('can:vendor_wallet.index');
  Route::post('debit/vendorWallet','App\Http\Controllers\VendorWalletController@debit')->middleware('can:vendor_wallet.debit');
  Route::post('credit/vendorWallet','App\Http\Controllers\VendorWalletController@credit')->middleware('can:vendor_wallet.credit');

  // Commission Histories
  Route::apiResource('commissionHistory', 'App\Http\Controllers\CommissionHistoryController',[
    'only' => ['index', 'show'],
  ]);

  // Withdraw Request
  Route::apiResource('withdrawRequest', 'App\Http\Controllers\WithdrawRequestController');

  // Orders
  Route::apiResource('order', 'App\Http\Controllers\OrderController');
  Route::post('checkout','App\Http\Controllers\CheckoutController@verifyCheckout');
  Route::post('rePayment', 'App\Http\Controllers\OrderController@rePayment');
  Route::get('trackOrder/{order_number}', 'App\Http\Controllers\OrderController@trackOrder');
  Route::get('verifyPayment/{order_number}', 'App\Http\Controllers\OrderController@verifyPayment');

  // Order Status
  Route::apiResource('orderStatus', 'App\Http\Controllers\OrderStatusController',[
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::post('orderStatus/deleteAll', 'App\Http\Controllers\OrderStatusController@deleteAll')->middleware('can:order_status.destroy');
  Route::put('orderStatus/{id}/{status}', 'App\Http\Controllers\OrderStatusController@status')->middleware('can:order_status.edit');

  // Attachments
  Route::post('attachment/deleteAll', 'App\Http\Controllers\AttachmentController@deleteAll')->middleware('can:attachment.destroy');
  Route::post('attachment/sync-cloudinary', 'App\Http\Controllers\AttachmentController@syncCloudinary')->middleware('can:attachment.create');
  Route::apiResource('attachment', 'App\Http\Controllers\AttachmentController');

  // Blogs
  Route::apiResource('blog', 'App\Http\Controllers\BlogController',[
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::post('blog/deleteAll', 'App\Http\Controllers\BlogController@deleteAll')->middleware('can:blog.destroy');
  Route::put('blog/{id}/{status}', 'App\Http\Controllers\BlogController@status')->middleware('can:blog.edit');

  // Pages
  Route::apiResource('page', 'App\Http\Controllers\PageController',[
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::post('page/deleteAll', 'App\Http\Controllers\PageController@deleteAll')->middleware('can:page.destroy');
  Route::put('page/{id}/{status}', 'App\Http\Controllers\PageController@status')->middleware('can:page.edit');

  // Tax
  Route::apiResource('tax', 'App\Http\Controllers\TaxController',[
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::post('tax/deleteAll', 'App\Http\Controllers\TaxController@deleteAll')->middleware('can:tax.destroy');
  Route::put('tax/{id}/{status}', 'App\Http\Controllers\TaxController@status')->middleware('can:tax.edit');

  // GST Report (admin only)
  Route::get('gst-report', 'App\Http\Controllers\GstReportController@index')->middleware('can:order.index');

  // Network Printer (WiFi/LAN thermal printer via raw TCP)
  Route::post('printer/print', 'App\Http\Controllers\NetworkPrinterController@print');
  Route::get('printer/ping',  'App\Http\Controllers\NetworkPrinterController@ping');

  // Offer Banner
  Route::apiResource('offer-banner', 'App\Http\Controllers\OfferBannerController',[
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::post('offer-banner/deleteAll', 'App\Http\Controllers\OfferBannerController@deleteAll')->middleware('can:offer_banner.destroy');
  Route::put('offer-banner/{id}/{status}', 'App\Http\Controllers\OfferBannerController@status')->middleware('can:offer_banner.edit');

  // Shipping
  Route::apiResource('shipping', 'App\Http\Controllers\ShippingController');
  Route::put('shipping/{id}/{status}', 'App\Http\Controllers\ShippingController@status')->middleware('can:shipping.edit');

  // Shipping Rule
  Route::apiResource('shippingRule', 'App\Http\Controllers\ShippingRuleController');
  Route::put('shippingRule/{id}/{status}', 'App\Http\Controllers\ShippingRuleController@status')->middleware('can:shipping.edit');

  // Coupon
  Route::apiResource('coupon', 'App\Http\Controllers\CouponController',[
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::put('coupon/{id}/{status}', 'App\Http\Controllers\CouponController@status')->middleware('can:coupon.edit');
  Route::post('coupon/deleteAll', 'App\Http\Controllers\CouponController@deleteAll')->middleware('can:coupon.destroy');

  // Currencies
  Route::apiResource('currency', 'App\Http\Controllers\CurrencyController',[
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::put('currency/{id}/{status}', 'App\Http\Controllers\CurrencyController@status')->middleware('can:currency.edit');
  Route::post('currency/deleteAll', 'App\Http\Controllers\CurrencyController@deleteAll')->middleware('can:currency.destroy');

  // Points
  Route::get('points/consumer', 'App\Http\Controllers\PointsController@index')->middleware('can:point.index');
  Route::post('credit/points','App\Http\Controllers\PointsController@credit')->middleware('can:point.credit');
  Route::post('debit/points','App\Http\Controllers\PointsController@debit')->middleware('can:point.debit');

  // Wallets
  Route::get('wallet/consumer', 'App\Http\Controllers\WalletController@index')->middleware('can:wallet.index');
  Route::post('credit/wallet','App\Http\Controllers\WalletController@credit')->middleware('can:wallet.credit');
  Route::post('debit/wallet','App\Http\Controllers\WalletController@debit')->middleware('can:wallet.debit');

  // Reviews
  Route::apiResource('review', 'App\Http\Controllers\ReviewController');
  Route::post('review/deleteAll', 'App\Http\Controllers\ReviewController@deleteAll')->middleware('can:review.destroy');

  // faqs
  Route::apiResource('faq', 'App\Http\Controllers\FaqController',[
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::put('faq/{id}/{status}', 'App\Http\Controllers\FaqController@status')->middleware('can:faq.edit');
  Route::post('faq/deleteAll', 'App\Http\Controllers\FaqController@deleteAll')->middleware('can:faq.destroy');

  // Quention And Answer
  Route::apiResource('question-and-answer', 'App\Http\Controllers\QuestionAndAnswerController', [
    'only' => ['store', 'update', 'destroy'],
  ]);
  Route::post('question-and-answer/feedback', 'App\Http\Controllers\QuestionAndAnswerController@feedback')->middleware('can:question_and_answer.create');

  // Themes
  Route::apiResource('theme', 'App\Http\Controllers\ThemeController',[
      'only' => ['update']
  ]);

  // Home
  Route::apiResource('home', 'App\Http\Controllers\HomePageController', [
    'only' => ['update'],
  ]);

  // Theme Options
  Route::put('themeOptions', 'App\Http\Controllers\ThemeOptionController@update')->middleware('can:theme_option.edit');

  // Settings
  Route::put('settings', 'App\Http\Controllers\SettingController@update')->middleware('can:setting.edit');
});
