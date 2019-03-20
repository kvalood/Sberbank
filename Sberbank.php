<?php

require_once 'api/Simpla.php';
require_once 'RBS.php';

class Sberbank extends Simpla
{
    private $payment_method = [],
        $order = [],
        $payment_settings = [],
        $debug = 0;

    public function checkout_form($order_id, $button_text = null)
    {
        if (empty($button_text)) {
            $button_text = 'Перейти к оплате';
        }

        $this->order = $this->orders->get_order((int)$order_id);
        $this->payment_method = $this->payment->get_payment_method($this->order->payment_method_id);
        $this->payment_settings = $this->payment->get_payment_settings($this->payment_method->id);

        // Debug mode
        $this->payment_settings['sbr_debug'] == 1 AND $_SESSION['admin'] ? $this->debug = 1 : $this->debug = 0;

        $sber_session = $_SESSION['order'][$this->order->id];
        $return_url = $this->config->root_url . "/payment/Sberbank/callback.php?order=" . $this->order->id;
        $order_description = 'Оплата заказа №' . $this->order->id . ' на сайте ' . $this->settings->site_name;
        $order_description = (string)mb_substr($order_description, 0, 99);


        /**
         * Подключаемся к эквайрингу
         */
        $rbs = new RBS($this->payment_settings['sbr_login'], $this->payment_settings['sbr_password'], $this->payment_settings['two_stage'] ? TRUE : FALSE, $this->payment_settings['sbr_mode'] ? TRUE : FALSE);


        /**
         * Подготавливаем корзину для 54-ФЗ
         */
        $orderBundle = [];
        if ($this->payment_settings['sbr_orderBundle']) {

            // Информация о пользователе
            $orderBundle['customerDetails'] = [
                "email" => $this->order->email,
                "phone" => preg_replace('/[^0-9]/', '', $this->order->phone)
            ];

            // Добавляем товары в чек
            $purchases = $this->normalize($this->orders->get_purchases(['order_id' => $this->order->id]));
            foreach ($purchases as $key => $purchase) {
                $orderBundle['cartItems']['items'][$key] = [
                    "positionId" => $key + 1,
                    "name" => $purchase->product_name,
                    "quantity" => [
                        "value" => $purchase->amount,
                        "measure" => $this->settings->units
                    ],
                    "itemAmount" => $purchase->price * $purchase->amount,
                    "itemCode" => $purchase->variant_id,
                    "tax" => [
                        "taxType" => isset($purchase->taxType) ? $purchase->taxType : $this->payment_settings['sbr_taxType']
                    ],
                    "itemPrice" => $purchase->price,

                    // ФФД 1.05
                    "itemAttributes" => [
                        'attributes' => [
                            [
                                'name' => 'paymentMethod',
                                'value' => $this->payment_settings['sbr_paymentMethod']
                            ],
                            [
                                'name' => 'paymentObject',
                                'value' => $this->payment_settings['sbr_paymentObject']
                            ]
                        ]
                    ]
                ];
            }

            // Доставка
            if ($this->order->delivery_id && $this->order->delivery_price > 0 && !$this->order->separate_delivery) {
                $delivery = $this->delivery->get_delivery($this->order->delivery_id);

                // Добавляем доставку в чек
                if ($this->payment_settings['sbr_delivery'] == 'item') {

                    $key = count($orderBundle['cartItems']['items']);
                    $orderBundle['cartItems']['items'][$key] = [
                        "positionId" => count($orderBundle['cartItems']['items']) + 1,
                        "name" => $delivery->name,
                        "quantity" => [
                            "value" => 1,
                            "measure" => 'шт'
                        ],
                        "itemAmount" => $this->convert_price($this->order->delivery_price),
                        "itemCode" => 'DELIVERY-' . $delivery->id,
                        "tax" => [
                            "taxType" => $this->payment_settings['sbr_taxType']
                        ],
                        "itemPrice" => $this->convert_price($this->order->delivery_price)
                    ];
                }
            }

            $orderBundle = json_encode($orderBundle);
        }


        /**
         * Может мы пытались перерегестрировать заказ?
         */
        if (isset($sber_session['order_prefix'])) {
            if ($sber_session['order_prefix'] < strtotime(date('Y-m-d H:i:s', strtotime('-20 min')))) {
                $order_id_store = $this->order->id;
                unset($_SESSION['order'][$this->order->id]);
                unset($sber_session);
            } else {
                $order_id_store = $this->order->id . '-' . $sber_session['order_prefix'];
            }
        } else {
            $order_id_store = $this->order->id;
        }

        // Узнаем статус заказа у Сбербанка
        $order_status = $rbs->get_order_status_by_orderNumber($order_id_store);

        if ($this->debug) {
            print '<pre>';
            echo '<h1>Текущий заказ:</h1>';
            var_dump($this->order);
            echo '<h1>Сумма заказа для Сбера:</h1>';
            var_dump($this->convert_price($this->order->total_price));
            echo '<h1>Заказ в сессии:</h1>';
            var_dump($_SESSION['order']);
            echo '<h1>ID заказа:</h1>';
            var_dump($order_id_store);
            echo '<h1>Статус заказа от сбера:</h1>';
            var_dump($order_status);
            echo '<h1>Корзина для ФЗ-54:</h1>';
            var_dump(json_decode($orderBundle));
            print '</pre>';
        }

        /**
         * Истек срок ожидания ввода данных (заказ делали больше 20 минут назад)
         * поступила команда пересоздать заказ
         */
        if ($this->request->post('reorder', 'integer') == 1) {

            $order_prefix = strtotime(date('Y/m/d H:i:s'));

            // Регестрируем заказ с новым ID заказа (ID + order_prefix)
            $response = $rbs->register_order(
                $this->order->id . '-' . $order_prefix,
                $this->convert_price($this->order->total_price),
                $return_url,
                $order_description,
                $orderBundle,
                $this->payment_settings['sbr_taxSystem']
            );

            // Запомним новый номер заказа.
            $_SESSION['order'][$this->order->id] = [
                'orderId' => $response['orderId'],
                'formUrl' => $response['formUrl'],
                'order_prefix' => $order_prefix
            ];

            header('Location: ' . $response['formUrl']);
        }

        /**
         * Создаем новый заказ в ПШ
         */
        if (!isset($order_status['actionCode'])) {

            // Заказ небыл создан, создаем.
            $response = $rbs->register_order(
                $this->order->id,
                $this->convert_price($this->order->total_price),
                $return_url,
                $order_description,
                $orderBundle,
                $this->payment_settings['sbr_taxSystem']
            );

            if (!$response['errorCode']) {
                // Запомним новый номер заказа.
                $_SESSION['order'][$this->order->id] = [
                    'orderId' => $response['orderId'],
                    'formUrl' => $response['formUrl']
                ];
                return "<a href='" . $response['formUrl'] . "' class='checkout_button'>" . $button_text . ' </a>';
            } elseif ($response == NULL) {
                return 'Невозможно подключиться к платежному шлюзу';
            } else {
                return $response['errorMessage'];
            }

        } elseif (isset($order_status['actionCode']) AND $order_status['actionCode'] != 0) {

            // Заказ был создан, но не небыл оплачен, можно продолжить оплату
            // или нет префикса для продолжения заказа
            if (!isset($sber_session['formUrl']) OR $order_status['actionCode'] != '-100') {

                // Запрос отмены старого заказа
                $reverse_order_id = isset($sber_session['order_prefix']) ? $this->order->id . '-' . $sber_session['order_prefix'] : $this->order->id;
                $response_old_order = $rbs->reverse_order($reverse_order_id);
                // unset($_SESSION['order'][$this->order->id]);

                $button = '<p>Заказ небыл оплачен.</p>' .
                    '<p>' . $order_status['actionCodeDescription'] . '</p>' .
                    '<form action="' . $this->config->root_url . '/order/' . $this->order->url . '" method=POST>' .
                    '<input type=hidden name=reorder value="1">' .
                    '<input type=submit class=checkout_button value="Повторить оплату">' .
                    '</form>';

            } else {
                $button = '<p>Заказ небыл оплачен.</p>' .
                    '<p>' . $order_status['actionCodeDescription'] . '</p>' .
                    "<a href='" . $_SESSION['order'][$this->order->id]['formUrl'] . "' class='checkout_button'>" . $button_text . ' </a>';
            }

            return $button;

        } else {
            // Заказ был оплачен
            return '<p>Заказ был оплачен.</p>';
        }
    }


    /**
     * Конвертируем цену из 100.00 в 10000 для Платежного Шлюза
     * @param $price
     * @return integer
     */
    private function convert_price($price)
    {
        // return $this->money->convert($price, $this->payment_method->currency_id, false) * 100;
        return $result = round($price, 2) * 100;
    }


    /**
     * Подгоняет стоимость товаров в чеке, кроме доставки, к общей цене заказа
     * @param object $purchases товары в заказе
     * @return object $purchases
     */
    private function normalize($purchases)
    {
        $final_purchases = [];

        // Общая стоимость заказа (с учетом процентной скидки)
        $total_price = $this->order->total_price;

        // Если есть доставка, отнимаем стоимость доставки от общей суммы заказа
        if ($this->order->delivery_price && $this->order->delivery_price > 0 && !$this->order->separate_delivery) {
            $total_price -= $this->order->delivery_price;
        }

        // Добавляем стоимость скидки
        $total_price += $this->order->coupon_discount;

        /*
         * DELIVERY
         * Добавляем доставку в каждый товар
         */
        if ($this->payment_settings['sbr_delivery'] == 'include_item') {
            foreach ($purchases as $item) {
                $coefficient = round(($item->amount * $item->price) * 100 / $total_price, 2);
                $coefficient_delivery = round((($this->order->delivery_price) * $coefficient) / 100, 2);
                $item->price += $coefficient_delivery / $item->amount;
            }
        }

        /**
         * coupon_discount - скидка по купону
         */
        if (!empty($this->order->coupon_discount)) {
            foreach ($purchases as $item) {
                // Вычислим процентное соотношение item price * amount от общей суммы заказа
                $coefficient = round(($item->amount * $item->price) * 100 / $total_price, 2);
                $coefficient_discount = round((($this->order->coupon_discount) * $coefficient) / 100, 2);
                $item->price -= $coefficient_discount / $item->amount;
            }
        }

        /**
         * Подгоняем цены у товаров с учетом процентной скидки
         */
        $positions = [];
        foreach ($purchases as $item) {

            $p_discount = round($item->price * (100 - $this->order->discount) / 100, 2); // Цена товара в позиции со скидкой
            $p_all_discount = $p_discount * $item->amount;
            $p_all_no_discount = round($item->amount * $item->price, 2); // Цена всех товаров в позиции без скидки
            $p_all_discount_all = round($p_all_no_discount * (100 - $this->order->discount) / 100, 2); // Цена всех товаров в позиции со скидкой
            $difference = round($p_all_discount - $p_all_discount_all, 2); // Разница

            if ($this->order->discount) {
                $item->price *= (100 - $this->order->discount) / 100;
            }

            if ($this->debug) {
                echo 'Общая цена позиций со скидкой: ' . $p_all_discount . '<br>';
                echo 'Общая $p_all_discount_all: ' . $p_all_discount_all . '<br>';
            }

            /*
             * Если есть разница, создаем клон товара в позиции
             * и из его стоимости вычитаем разницу в товарах.
             * Таким образом будет 2 позиции с одним товаром,
             * но разными стоимостями.
             */
            if ($p_all_discount > $p_all_discount_all) {
                // Разница БОЛЬШЕ - Вычитаем из одного товара разницу

                if ($this->debug) {
                    echo 'Разница БОЛЬШЕ: ' . $difference . '<br>';
                }

                $item_1 = clone $item;
                $item_1->price = round($item_1->price, 2) - $difference;
                $item_1->amount = 1;
                $item_1->variant_id = $item_1->variant_id . '-1';
                $positions[] = $item_1;

                $item->amount -= 1;
                $positions[] = $item;

            } elseif ($p_all_discount < $p_all_discount_all) {
                // Прибавляем к одному товару разницу

                if ($this->debug) {
                    echo 'Разница МЕНЬШЕ: ' . $difference . '<br>';
                }

                $item_1 = clone $item;
                $item_1->price = round($item_1->price, 2) - $difference;
                $item_1->amount = 1;
                $item_1->variant_id = $item_1->variant_id . '-1';
                $positions[] = $item_1;

                $item->amount -= 1;
                $positions[] = $item;
            } else {

                if ($this->debug) {
                    echo 'Разница НЕТ: ' . $difference . '<br>';
                }
                $positions[] = $item;
            }
        }

        if ($this->debug) {
            print '<pre>';
            var_dump($positions);
            print '</pre>';
        }


        $purchases = $positions;

        /*
         * Формируем цены для сбера
         */
        foreach ($purchases as $item) {
            $item->price = $this->convert_price($item->price);
        }

        /**
         * Кастомный НДС
         * для каждого товара
         */
        if ($this->payment_settings['sbr_taxProduct']) {
            $products_ids = [];
            foreach ($purchases as $item) {
                $products_ids[] = $item->product_id;
            }
            $products = [];
            foreach ($this->products->get_products(['id' => $products_ids]) as $product) {
                $products[$product->id] = $product;
            }
            foreach ($purchases as $item) {
                if (isset($products[$item->product_id]->taxType)) {
                    $item->taxType = $products[$item->product_id]->taxType;
                }
            }
        }

        return $purchases;
    }
}