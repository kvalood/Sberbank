# Модуль оплаты Сбербанк Интернет Эквайринг для SimplaCMS 2.*

### Особенности
* Передача корзины товаров (кассовый чек 54-ФЗ)
* Поддержка ФФД 1.05
* Если оплата не прошла или прошла с ошибкой, оплату можно повторить, хоть спустя 20 дней...
* Работает через REST
* Поддержка одноэтапной и двухэтапной оплаты


### Требования:
* php 5.6 и выше
* curl
* SimplaCMS 2.*


### Установка модуля:
1. Просто распаковать архив с файлами модуля по адресу `/payment/Sberbank/`
2. В админ-панели добавить новый способ оплаты `Сбербанк-Эквайринг`
3. Настроить модуль в соответствии с вашими требованиями

### Настройка:
* `После оплаты сменить статус заказа на (ID статуса)` - если вы хотите что бы **после оплаты у заказа менялся статус**, укажите ID статуса заказа.  
Например `Новый` имеет id `0`, `Приняты` id `1`, `Выполнены` id - `2` итд...
* `режим оплаты (одностадийный)(по умолчанию)` - блокирование и списание средств происходит в один этап. Этот вид платежей предпочтительней, если товар или услуга предоставляется сразу после оплаты.
* `Режим оплаты (двухстадийный)` - `Внимание! обратитесь в банк для включения Двухстадийной оплаты.`Двухстадийные платежи следует использовать, если между решением покупателя произвести оплату и поставкой выбранного товара или услуги проходит какое-то время. 
Оплата производится в два этапа. На первом этапе происходит проверка наличия и блокирование средств плательщика (пре-авторизация); далее, на втором этапе, компания либо подтверждает необходимость списания средств, либо отменяет блокировку средств.

* `Передавать данные для печати чека (54-ФЗ)` - товары в заказе. Так-же нужно включить ФФД 1.05 в Кассе и Сбербанк-админке

* `Стоимость доставки включить`:  
`в стоимость товаров` - Цена доставки равномерно "размазывается" по каждой позиции в чеке, если в настройках выбранного метода доставки НЕ установлена галочка `Оплачивается отдельно`  
`как отдельную позицию в чеке` - тут все понятно. Должны быть коды оквэд у вашей организации.

* `CSS класс кнопки "перейти к оплате"` - если есть необходимость установить доп. классы css для кнопки "перейти к оплате", например 

### Установка "Разный НДС для товаров":
1. Копируем файл `/payment/Sberbank/_sql_taxType_install.php` в корень сайта
2. Идем по адресу `http://ИМЯ_ВАШЕГО_САЙТА/_sql_taxType_install.php`
3. Открываем файл `/api/Products.php`
4. Ищем функции `get_products` и `get_product`
5. Ищем в этих функциях `p.visible,` и после добавляем `p.taxType,`
6. Открываем файл `/simpla/design/html/product.tpl`, ищем `Параметры страницы`
7. Добавляем в этот блок: 
    ```
    <select name="taxType">
        <option value='0' {if $product->taxType=='0'}selected{/if}>без НДС</option>
        <option value='1' {if $product->taxType=='1'}selected{/if}>НДС по ставке 0%</option>
        <option value='2' {if $product->taxType=='2'}selected{/if}>НДС чека по ставке 10%</option>
        <option value='3' {if $product->taxType=='3'}selected{/if}>НДС чека по ставке 18%</option>
        <option value='4' {if $product->taxType=='4'}selected{/if}>НДС чека по расчетной ставке 10/110</option>
        <option value='5' {if $product->taxType=='5'}selected{/if}>НДС чека по расчетной ставке 18/118</option>
        <option value='6' {if $product->taxType=='6'}selected{/if}>НДС чека по ставке 20%</option>
        <option value='7' {if $product->taxType=='7'}selected{/if}>НДС чека по расчётной ставке 20/120</option>
    </select>
    ```
8. Открываем `/simpla/ProductAdmin.php`
9. Ищем `$product->visible = $this->request->post('visible', 'boolean');` и после добавляем `$product->taxType = $this->request->post('taxType', 'integer');`
10. В настройках платежного модуля, в пункте `Разный НДС у товаров? `, ставим `да`.


Офф. документация:
-
* https://securepayments.sberbank.ru/wiki/doku.php/integration:api:rest:requests:register_cart#items
* https://developer.sberbank.ru/doc/v1/acquiring/api-basket

Тестовые карты:
-
* https://developer.sberbank.ru/doc/v1/acquiring/rest-test-cards

Тестовая среда:
-
* ЛК оператора https://3dsec.sberbank.ru/mportal3   
* API: `storename-api`
* Оператор: `storename-operator`
* Пароль на оба логина: `storename`
