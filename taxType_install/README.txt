0) Копируем sql.php из папки /payment/Sberbank/taxType_install/ в корень сайта
1) Идем по адресу http://sitename/sql.php
2) Открываем /api/Products.php
3) Ищем функции get_products и get_product
4) Ищем в этих функциях "p.visible," и после добавляем "p.taxType,"
5) Открываем /simpla/design/html/product.tpl, ищем "Параметры страницы"
6) Добавляем в этот блок:
    <select name="taxType">
    	<option value='0' {if $product->taxType=='0'}selected{/if}>без НДС</option>
    	<option value='1' {if $product->taxType=='1'}selected{/if}>НДС по ставке 0%</option>
    	<option value='2' {if $product->taxType=='2'}selected{/if}>НДС чека по ставке 10%</option>
    	<option value='3' {if $product->taxType=='3'}selected{/if}>НДС чека по ставке 18%</option>
    	<option value='4' {if $product->taxType=='4'}selected{/if}>НДС чека по расчетной ставке 10/110</option>
    	<option value='5' {if $product->taxType=='5'}selected{/if}>НДС чека по расчетной ставке 18/118</option>
    </select>

7) Открываем /simpla/ProductAdmin.php
8) Ищем "$product->visible = $this->request->post('visible', 'boolean');" и после добавляем "$product->taxType = $this->request->post('taxType', 'integer');"