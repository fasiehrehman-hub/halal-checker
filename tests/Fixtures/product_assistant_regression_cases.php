<?php

return [
    [
        'name' => 'sugar or salt uses any mode',
        'prompt' => 'show products with sugar or salt',
        'must_have_products' => true,
    ],

    [
        'name' => 'drinks with vitamin should not leak pasta',
        'prompt' => 'show me drinks that contains vitamin',
        'must_not_contain_products' => [
            'BARILLA ARTISANAL COLLECTION SPAGHETTI PASTA',
        ],
    ],

    [
        'name' => 'chocolates with vitamin should not leak drinks pasta',
        'prompt' => 'show me chocolates that contain vitamins',
        'must_not_contain_products' => [
            'BARILLA ARTISANAL COLLECTION SPAGHETTI PASTA',
            'Organic Apple Juice',
            'Alpro Almond Milk',
        ],
    ],

    [
        'name' => 'mixed grocery plus dairy milk gelatin',
        'prompt' => 'I am preparing a grocery list for my family, show me halal snacks, biscuits, drinks, and check if Dairy Milk contains gelatin.',
        'reply_must_contain' => [
            'Dairy Milk',
            'gelatin',
        ],
        'must_have_products' => true,
    ],

    [
        'name' => 'specific product alcohol check',
        'prompt' => 'Does Sprite contain alcohol?',
        'reply_must_contain' => [
            'Sprite',
            'alcohol',
        ],
    ],

    [
        'name' => 'origin montenegro',
        'prompt' => 'products from Montenegro',
        'must_have_products' => true,
        'origin_must_contain' => 'Montenegro',
    ],

    [
        'name' => 'burger deals',
        'prompt' => 'show me some great burger deal options',
        'must_have_products' => true,
        'reply_must_not_contain' => [
            'Sorry, something went wrong',
        ],
    ],
];