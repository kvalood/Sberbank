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

        // Получаем payment_details отдельно, т.к. в get_order он не берется.
        $this->db->query($this->db->placehold("SELECT payment_details FROM __orders WHERE id=? LIMIT 1", $this->order->id));
        $payment_details = $this->db->result();
        $this->order->payment_details = json_decode($payment_details->payment_details, TRUE);

        // Debug mode
        $this->payment_settings['sbr_debug'] == 1 AND $_SESSION['admin'] ? $this->debug = 1 : $this->debug = 0;

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
                    "itemCode" => $purchase->variant_id . '-'. $key,
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

            // Добавляем доставку в чек
            if ($this->payment_settings['sbr_delivery'] == 'item' AND $this->order->delivery_id AND $this->order->delivery_price > 0 AND !$this->order->separate_delivery) {

                $delivery = $this->delivery->get_delivery($this->order->delivery_id);

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
                    "itemPrice" => $this->convert_price($this->order->delivery_price),

                    // ФФД 1.05
                    "itemAttributes" => [
                        'attributes' => [
                            [
                                'name' => 'paymentMethod',
                                'value' => $this->payment_settings['sbr_paymentMethod']
                            ],
                            [
                                'name' => 'paymentObject',
                                'value' => 4
                            ]
                        ]
                    ]
                ];
            }

            $orderBundle = json_encode($orderBundle);
        }


        /**
         * узнаем hash заказа
         * на случай если в заказе что-то менялось, а урл на оплату еже сгенерирован и есть в payment_details
         */
        $order_token_text = $this->order->id . $this->order->delivery_price . $this->order->total_price . $this->order->separate_delivery . $this->order->coupon_discount . $this->order->discount;
        $order_token = false; // Совпадают ли хеши?
        if (isset($this->order->payment_details['order_token']) AND $this->config->check_token($order_token_text, $this->order->payment_details['order_token'])) {
            $order_token = true;
        }

        // Узнаем статус заказа в ПШ
        if (isset($this->order->payment_details['orderId'])) {
            $order_status = $rbs->get_order_status_by_orderId($this->order->payment_details['orderId']);
        } else {
            $order_status = $rbs->get_order_status_by_orderNumber($this->order->id);
        }

        // Истекло время заказа в ПШ?
        $order_expiration = strtotime($this->order->payment_details['expirationDate']) > strtotime('now') ? true : false;


        /**
         * Заказ существует, не оплачен, можно оплатить
         */
        $result = '';
        if ($order_token AND isset($order_status['actionCode']) AND $rbs->allowed_actionCode($order_status['actionCode']) AND $order_expiration) {
            $result = '<a href="' . $this->order->payment_details['formUrl'] . '" class="checkout_button ' . $this->payment_settings['sbr_css_checkout_button'] . '">' . $button_text . ' </a>';
        } elseif (!$order_token OR $order_status['errorCode']==6 OR !isset($order_status['actionCode']) OR !$rbs->allowed_actionCode($order_status['actionCode'])) {

            /**
             * Заказ не создавался ИЛИ вернули плохой код, пересоздаем
             */
            $order_prefix = (!$order_token OR (isset($order_status['actionCode']) AND !$rbs->allowed_actionCode($order_status['actionCode']))) ? '-' . strtotime(date('Y/m/d H:i:s')) : '';

            /**
             * Создаем новый заказ в ПШ
             */
            // Дата истечения оплаты через 20 дней
            //$datetime = new DateTime(date("Y-m-d H:i:s", strtotime("+1 minutes")));
            $datetime = new DateTime(date("Y-m-d H:i:s", strtotime("+20 days")));
            $this->order->payment_details['expirationDate'] = $datetime->format(DateTime::ATOM);

            // Заказ не был создан, создаем.
            $response = $rbs->register_order(
                $this->order->id . $order_prefix,
                $this->convert_price($this->order->total_price),
                $return_url,
                $order_description,
                $orderBundle,
                [
                    'expirationDate' => $this->order->payment_details['expirationDate'],
                    'taxSystem' => $this->payment_settings['sbr_taxSystem']
                ]
            );

            if (!$response['errorCode']) {

                $this->order->payment_details['orderId'] = $response['orderId'];
                $this->order->payment_details['formUrl'] = $response['formUrl'];
                $this->order->payment_details['orderNumber'] = $response['orderNumber'];
                $this->order->payment_details['order_token'] = $this->config->token($order_token_text);

                // Обновим payment_details в заказе
                $this->orders->update_order($this->order->id, ['payment_details' => json_encode($this->order->payment_details)]);

                $result = '<a href="' . $this->order->payment_details['formUrl'] . '" class="checkout_button ' . $this->payment_settings['sbr_css_checkout_button'] . '">' . $button_text . ' </a>';
            } elseif ($response == NULL) {
                $result = '<p class="checkout_info">Невозможно подключиться к платежному шлюзу</p>';
            } else {
                $result = '<p class="checkout_info">' . $response['errorMessage'] . '</p>';
            }

        } elseif ($order_status['actionCode'] == '0') {
            // Заказ был оплачен
            $result = '<p class="checkout_info">Заказ был оплачен.</p>';
        } else {
            $result = '<p class="checkout_info">' . $order_status['actionCode'] . '</p>';
        }


        if ($this->debug) {
            print '<pre>';
            echo '<h3>Статус заказа от сбера:</h3>';
            var_dump($order_status);
            echo '<h3>Сумма заказа для Сбера:</h3>';
            var_dump($this->convert_price($this->order->total_price));
            echo '<h3>Текущий заказ в БД:</h3>';
            var_dump($this->order);
            echo '<h3>Корзина для ФЗ-54:</h3>';
            var_dump(json_decode($orderBundle));
            print '</pre>';
        }

        return $result;
    }

    /**
     * Подгоняет стоимость товаров в чеке, кроме доставки, к общей цене заказа
     *
     * @param object $purchases товары в заказе
     * @return object $purchases
     */
    private function normalize($purchases)
    {
        // Общая стоимость заказа (с учетом процентной скидки)
        $total_price = $this->order->total_price;

        // Если есть доставка, отнимаем стоимость доставки от общей суммы заказа
        if ($this->order->delivery_price && $this->order->delivery_price > 0 && !$this->order->separate_delivery) {
            $total_price -= $this->order->delivery_price;
        }

        // Добавляем стоимость скидки coupon_discount
        $total_price += $this->order->coupon_discount;

        $items_total_price = 0; // Общая стоимость позиций по отдельности.
        $corrected_purchases = []; // Корректирующий массив цен
        foreach ($purchases as $key => $item) {
            $items_total_price += $item->price * $item->amount;
            $corrected_purchases[$key] = $item->price;
        }

        /**
         * размазываем discount на все товары
         * discount - процентная скидка
         */
        if ($this->order->discount > 0) {

            // объем процентной скидки - вычитаю процент скидки / пользовательской скидки
            $discount_sum = ($items_total_price * $this->order->discount) / 100;

            foreach ($purchases as $key => $item) {
                $coefficient = ($item->amount * $item->price) * 100 / $items_total_price;
                $coefficient_discount = round((($discount_sum * $coefficient) / 100) / $item->amount, 2);
                $corrected_purchases[$key] -= $coefficient_discount;
            }
        }

        /**
         * размазываем coupon_discount на все товары
         * coupon_discount - скидка по купону
         */
        if ($this->order->coupon_discount > 0) {
            foreach ($purchases as $key => $item) {
                // Вычислим процентное соотношение item price * amount от общей суммы заказа
                $coefficient = ($item->amount * $item->price) * 100 / $items_total_price;
                $coefficient_discount = round((($this->order->coupon_discount) * $coefficient) / 100 / $item->amount, 2);
                $corrected_purchases[$key] -= $coefficient_discount;
            }
        }


        /*
         * DELIVERY
         * Добавляем доставку в каждый товар
        */
        if ($this->payment_settings['sbr_delivery'] == 'include_item' AND !$this->order->separate_delivery) {
            foreach ($purchases as $key => $item) {
                $coefficient = ($item->amount * $item->price) * 100 / $items_total_price;
                $coefficient_delivery = round(($this->order->delivery_price * $coefficient) / 100 / $item->amount, 2);
                $corrected_purchases[$key] += $coefficient_delivery;
            }
        }


        /*
         * Смотрим финальную разницу, если она есть, добавляем к последней позиции разницу.
         */
        $all_sum = 0;
        $all_sum_diff = 0;
        foreach ($purchases as $key => $item) {
            $item->price = $corrected_purchases[$key];
            $all_sum += $item->price * $item->amount;
        }

        if ($this->order->total_price != $all_sum) {
            $all_sum_diff = $this->order->total_price - $all_sum;
            $all_sum_diff = round($all_sum_diff, 2);

            // or `array_key_last` if php 7 >= 7.3.0
            end($purchases);
            $last_key = key($purchases);

            // Дублируем последнюю позицию в чеке, если их несколько для правильной корректировки цен
            if ($purchases[$last_key]->amount > 1) {
                $purchases[$last_key]->amount -= 1;
                $copy_item = clone $purchases[$last_key];
                $copy_item->amount = 1;
                $copy_item->price += $all_sum_diff;
                $purchases[] = $copy_item;
            } else {
                $purchases[$last_key]->price += $all_sum_diff / $purchases[$last_key]->amount;
            }

            if($this->debug) {
                echo '<h3>Корректировка сумы заказа</h3>';
                echo '$this->order->total_price: ' . $this->order->total_price . '<br/>';
                echo '$all_sum: ' . $all_sum . '<br/>';
                echo 'Отличие от суммы заказа: ' . $all_sum_diff . '<br/>';
            }
        }

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

    /**
     * Конвертируем цену из 100.00 в 10000 для Платежного Шлюза
     *
     * @param $price
     * @return integer
     */
    private function convert_price($price)
    {
        // return $this->money->convert($price, $this->payment_method->currency_id, false) * 100;
        return $result = round($price, 2) * 100;
    }
}