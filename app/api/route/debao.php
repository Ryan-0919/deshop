<?php

use think\facade\Route;

Route::group('debao/v1', function () {
    Route::get('categories$', 'debao/categories');
    Route::get('products$', 'debao/products');
    Route::get('products/<product_id>/skus$', 'debao/productskus')->pattern(['product_id'=>'[\w.-]+']);
    Route::get('products/<product_id>$', 'debao/product')->pattern(['product_id'=>'[\w.-]+']);
    Route::post('bindings/challenges$', 'debao/challenges');
    Route::get('bindings/challenges/<challenge_id>$', 'debao/challenge')->pattern(['challenge_id'=>'[\w.-]+']);
    Route::post('bindings/challenges/<challenge_id>/confirm$', 'debao/confirm')->pattern(['challenge_id'=>'[\w.-]+']);
    Route::get('bindings/status$', 'debao/status');
    Route::delete('bindings$', 'debao/unbind');
    Route::get('addresses$', 'debao/addresses');
    Route::post('addresses$', 'debao/addressescreate');
    Route::patch('addresses/<address_id>$', 'debao/addressupdate');
    Route::delete('addresses/<address_id>$', 'debao/addressdelete');
    Route::post('addresses/<address_id>/default$', 'debao/addressdefault');
    Route::post('sessions/sso$', 'debao/ssosession');
    Route::post('sessions/checkout$', 'debao/checkoutsession');
    Route::get('sessions/<session_id>$', 'debao/session');
    Route::post('orders/<order_id>/pay$', 'debao/orderpay');
    Route::get('orders$', 'debao/orders');
    Route::get('orders/<order_id>$', 'debao/order');
    Route::get('orders/<order_id>/logistics$', 'debao/orderlogistics');
    Route::post('webhooks/debao-events$', 'debao/webhook');
});
